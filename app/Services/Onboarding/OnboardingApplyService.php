<?php

/**
 * Applies a reviewed onboarding draft: activates it and writes the preferences chosen with it.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Onboarding;

use BooleanSmtp\Contracts\EncryptorContract;
use BooleanSmtp\Core\Exceptions\ValidationException;
use BooleanSmtp\Models\Connection;
use BooleanSmtp\Repositories\ConnectionRepository;
use BooleanSmtp\Services\Migration\MigrationScanner;
use BooleanSmtp\Support\Settings;

/**
 * The one action that switches an onboarding draft on.
 *
 * Until it runs, the wizard's connection is an inactive draft that no routing, fallback or retry
 * path can pick. Applying activates the draft, makes it the default connection when asked to or
 * when it is the only active one, fills the empty global sender defaults from it, stores the log
 * retention, optionally imports a migration source's historical logs, and records the onboarding as reviewed. Every step is idempotent:
 * applying the same connection twice changes nothing the second time.
 *
 * @since 1.0.0
 */
final class OnboardingApplyService
{
    /**
     * @since 1.0.0
     *
     * @param ConnectionRepository          $connections Activates the draft and lists the other active connections.
     * @param Settings                      $settings    Default connection, sender defaults and log retention.
     * @param EncryptorContract             $encryptor   Decrypts the draft's settings to read its sender identity.
     * @param OnboardingState               $state       Records the applied connection and the reviewed step.
     * @param MigrationScanner              $migrations  Imports historical logs for the migration branch.
     */
    public function __construct(
        private readonly ConnectionRepository $connections,
        private readonly Settings $settings,
        private readonly EncryptorContract $encryptor,
        private readonly OnboardingState $state,
        private readonly MigrationScanner $migrations,
    ) {
    }

    /**
     * Apply the reviewed draft.
     *
     * @since 1.0.0
     *
     * @param  int         $connectionId    The draft (or already active connection) to apply.
     * @param  bool        $makePrimary     Make it the default connection even when others are active.
     * @param  int|null    $retentionDays   Log retention to store, or null to leave the setting alone.
     * @param  bool        $importLogs      Import the migration source's historical logs.
     * @param  string|null $migrationSource Migration source id the logs come from.
     * @return array{connection: array{id: int, name: string, driver: string, is_active: bool}, made_primary: bool, logs_imported: int, logs_import: array<string, mixed>|null, onboarding: array<string, bool|int|string|null>}
     *
     * @throws \BooleanSmtp\Core\Exceptions\ModelNotFoundException When the connection does not exist.
     * @throws ValidationException When an OAuth API draft has no renewable grant.
     */
    public function apply(
        int $connectionId,
        bool $makePrimary = true,
        ?int $retentionDays = null,
        bool $importLogs = false,
        ?string $migrationSource = null
    ): array {
        $connection = $this->connections->findOrFail($connectionId);
        $this->assertOAuthReady($connection);

        if (!$connection->is_active) {
            $connection = $this->connections->update($connectionId, ['is_active' => true]);
        }

        $madePrimary = $this->applyPrimary($connection, $makePrimary);
        $this->applySenderDefaults($connection);

        if ($retentionDays !== null) {
            $this->settings->set('log_retention_days', $retentionDays);
        }

        $logsImport = $importLogs && $migrationSource !== null && $migrationSource !== ''
            ? $this->importLogs($migrationSource)
            : null;

        $onboarding = $this->state->update([
            'start'                 => true,
            'provider'              => true,
            'connect'               => true,
            'review'                => true,
            'applied_connection_id' => $connectionId,
            'draft_connection_id'   => null,
        ]);

        /**
         * Fires after the onboarding wizard has applied a reviewed connection.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $result {
         *     @type int         $connection_id      Id of the connection that was activated.
         *     @type string      $driver             Transport driver key of that connection.
         *     @type bool        $made_primary       Whether it became the default connection.
         *     @type string|null $migration_source   Migration source the wizard imported from, `null` for a fresh set-up.
         *     @type int|null    $log_retention_days Log retention stored with it, `null` when left unchanged.
         * }
         */
        \do_action('boolean_smtp_onboarding_applied', [
            'connection_id'      => $connectionId,
            'driver'             => (string) $connection->driver,
            'made_primary'       => $madePrimary,
            'migration_source'   => $migrationSource !== '' ? $migrationSource : null,
            'log_retention_days' => $retentionDays,
        ]);

        return [
            'connection'            => [
                'id'        => (int) $connection->id,
                'name'      => (string) $connection->name,
                'driver'    => (string) $connection->driver,
                'is_active' => true,
            ],
            'made_primary'          => $madePrimary,
            'logs_imported'         => (int) ($logsImport['imported'] ?? 0),
            // The first chunk's progress; the Done screen keeps calling the import until `done`.
            'logs_import'           => $logsImport,
            'onboarding'            => $onboarding,
        ];
    }

    /**
     * Prevent a deep link to Review from activating an OAuth draft without a refresh token.
     *
     * @since 1.0.0
     *
     * @param Connection $connection Draft being applied.
     * @return void
     * @throws ValidationException When the API connection has no renewable grant.
     */
    private function assertOAuthReady(Connection $connection): void
    {
        if (!\in_array((string) $connection->driver, ['google', 'outlook'], true)) {
            return;
        }

        try {
            $settings = $this->encryptor->decryptArray(\is_array($connection->settings) ? $connection->settings : []);
        } catch (\Throwable) {
            $settings = [];
        }

        if ((string) ($settings['delivery_mode'] ?? 'api') === 'api'
            && trim((string) ($settings['refresh_token'] ?? '')) === '') {
            throw new ValidationException([
                'connection_id' => ['Connect the Google or Microsoft account before activating this mailer.'],
            ]);
        }
    }

    /**
     * Store the connection as the default when asked to, or when no other connection is active.
     *
     * @since 1.0.0
     *
     * @param  Connection $connection  The connection being applied.
     * @param  bool       $makePrimary Whether the user asked for it to be primary.
     * @return bool Whether `default_connection_id` now points at it.
     */
    private function applyPrimary(Connection $connection, bool $makePrimary): bool
    {
        $id = (int) $connection->id;

        if ((int) $this->settings->get('default_connection_id') === $id) {
            return true;
        }

        $otherActive = $this->connections->active()
            ->filter(static fn (Connection $other): bool => (int) $other->id !== $id)
            ->isNotEmpty();

        if (!$makePrimary && $otherActive) {
            return false;
        }

        $this->settings->set('default_connection_id', $id);

        return true;
    }

    /**
     * Fill the empty global sender defaults from the connection's own sender identity.
     *
     * A global value that is already set is never overwritten.
     *
     * @since 1.0.0
     *
     * @param  Connection $connection The connection being applied.
     * @return void
     */
    private function applySenderDefaults(Connection $connection): void
    {
        $raw = $connection->settings ?? [];
        if (!\is_array($raw)) {
            return;
        }

        try {
            $decrypted = $this->encryptor->decryptArray($raw);
        } catch (\Throwable) {
            return;
        }

        foreach (['from_email', 'from_name'] as $key) {
            $current = trim((string) $this->settings->get($key, ''));
            $value   = trim((string) ($decrypted[$key] ?? ''));

            if ($current === '' && $value !== '') {
                $this->settings->set($key, $value);
            }
        }
    }


    /**
     * Import the first chunk of the migration source's historical log (connections are not
     * touched); the Done screen continues from the stored cursor until the import is done.
     *
     * @since 1.0.0
     *
     * @param  string $source Migration source id.
     * @return array<string, mixed> The log progress: `imported`, `total`, `cursor`, `done`, and `available` with a `reason` when the source has no log.
     */
    private function importLogs(string $source): array
    {
        $result = $this->migrations->importFrom($source, [
            'dry_run'            => false,
            'import_connections' => false,
            'import_logs'        => true,
            'restart_logs'       => true,
        ]);

        $logs = \is_array($result['logs'] ?? null) ? $result['logs'] : [];

        return $logs + ['source' => $source, 'imported' => 0, 'total' => 0, 'done' => true, 'available' => false, 'reason' => $result['error'] ?? null];
    }
}
