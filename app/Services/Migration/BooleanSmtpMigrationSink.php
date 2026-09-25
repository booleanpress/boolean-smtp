<?php
/**
 * Writes assessed connections and mapped log rows into the plugin's own tables.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration;

use BooleanSmtp\Contracts\EncryptorContract;
use BooleanSmtp\Models\Connection;
use BooleanSmtp\Models\EmailLog;
use BooleanSmtp\Models\MigratedEmailLog;
use BooleanSmtp\Repositories\ConnectionRepository;
use BooleanSmtp\Services\Migration\Assessment\ConnectionAssessment;
use BooleanSmtp\Services\Migration\Contracts\DestinationInterface;

/**
 * Every imported connection is an inactive draft with its credentials encrypted at rest; a
 * re-run updates the draft an earlier run created for the same source connection instead of
 * creating a twin, and never touches whether the user has activated it since. A log row is
 * stored once, keyed by the message id the mapper derives from the source row.
 *
 * @since 1.0.0
 */
final class BooleanSmtpMigrationSink implements DestinationInterface
{
    /**
     * @since 1.0.0
     *
     * @param EncryptorContract    $encryptor   Encrypts credentials before they are stored.
     * @param ConnectionRepository $connections Stores the drafts.
     * @param ImportRecords        $records     Remembers which draft belongs to which source connection.
     */
    public function __construct(
        private readonly EncryptorContract $encryptor,
        private readonly ConnectionRepository $connections,
        private readonly ImportRecords $records,
    ) {}

    /**
     * Create the draft for an assessed connection, or update the draft an earlier run created for the same source connection.
     *
     * @since 1.0.0
     *
     * @param  ConnectionAssessment $assessment Assessed connection; never `unsupported`.
     * @return int The connection id.
     */
    public function importConnection(ConnectionAssessment $assessment): int
    {
        $settings = $assessment->settings;
        foreach ($assessment->missing as $key) {
            $settings[$key] = '';
        }
        $encrypted = $this->encryptor->encryptArray($settings);

        $existingId = $this->records->connectionFor($assessment->source, $assessment->sourceKey);
        if ($existingId !== null && $this->connections->find($existingId) !== null) {
            $this->connections->update($existingId, MigrationConnectionAttributes::filtered($assessment->source, [
                'name'     => $assessment->name,
                'driver'   => (string) $assessment->driver,
                'settings' => $encrypted,
            ]));
            // The source may have changed provider since the draft was written.
            $this->records->rememberConnection($assessment->source, $assessment->sourceKey, $existingId, (string) $assessment->driver);

            return $existingId;
        }

        $connection = $this->connections->create(MigrationConnectionAttributes::filtered($assessment->source, [
            'name'          => $this->uniqueName($assessment->name),
            'driver'        => (string) $assessment->driver,
            'settings'      => $encrypted,
            'is_active'     => false,
            'health_status' => 'unknown',
        ]));
        $this->records->rememberConnection($assessment->source, $assessment->sourceKey, (int) $connection->id, (string) $assessment->driver);

        return (int) $connection->id;
    }

    /**
     * Overwrite an existing site connection with an assessed one, in place. Its id, active state
     * and priority are kept, so the default connection, the fallback connection, routing rules
     * and the email log still point at it; the import record now maps the source connection to
     * it, so a later run updates the same connection.
     *
     * @since 1.0.0
     *
     * @param  ConnectionAssessment $assessment   Assessed connection; never `unsupported`.
     * @param  int                  $connectionId The site connection to overwrite.
     * @return int The connection id.
     */
    public function replaceConnection(ConnectionAssessment $assessment, int $connectionId): int
    {
        $settings = $assessment->settings;
        foreach ($assessment->missing as $key) {
            $settings[$key] = '';
        }

        $this->connections->update($connectionId, MigrationConnectionAttributes::filtered($assessment->source, [
            'name'          => $assessment->name,
            'driver'        => (string) $assessment->driver,
            'settings'      => $this->encryptor->encryptArray($settings),
            'health_status' => 'unknown',
            'last_error'    => null,
        ]));
        $this->records->rememberConnection($assessment->source, $assessment->sourceKey, $connectionId, (string) $assessment->driver);

        return $connectionId;
    }

    /**
     * Store one mapped log row unless a row with its message id already exists.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $row Attributes for the email-log table.
     * @return bool True when stored, false when it already existed.
     */
    public function importLog(array $row): bool
    {
        $messageId = (string) ($row['message_id'] ?? '');
        if ($messageId !== '' && EmailLog::query()->where('message_id', $messageId)->count() > 0) {
            return false;
        }
        MigratedEmailLog::create($row);

        return true;
    }

    /**
     * The name for a new draft: the proposed one, with ` (imported)` appended while a
     * connection of that name exists.
     *
     * @since 1.0.0
     *
     * @param  string $proposed Name the assessment proposes.
     * @return string
     */
    private function uniqueName(string $proposed): string
    {
        $taken = [];
        foreach ($this->connections->all() as $connection) {
            /** @var Connection $connection */
            $taken[strtolower(trim((string) $connection->name))] = true;
        }
        $name = $proposed;
        while (isset($taken[strtolower($name)])) {
            $name .= ' (imported)';
        }

        return $name;
    }
}
