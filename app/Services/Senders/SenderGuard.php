<?php
/**
 * Applies the sender rule to a connection being saved, activated or imported.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Senders;

use BooleanSmtp\Contracts\EncryptorContract;
use BooleanSmtp\Models\Connection;
use BooleanSmtp\Repositories\ConnectionRepository;
use BooleanSmtp\Services\Mailer\MailerManager;
use BooleanSmtp\Services\Mailer\MailRoutingDataBuilder;
use BooleanSmtp\Core\Foundation\Application;

/**
 * Builds the view of the site's connections the sender rule needs (sender, provider, active
 * state) and asks the rule bound for this site whether a connection may use its sender.
 *
 * @since 1.0.0
 */
final class SenderGuard
{
    /**
     * @since 1.0.0
     *
     * @param Application          $app         Container; the sender rule is resolved from it on each check.
     * @param ConnectionRepository $connections Reads the site's connections.
     * @param EncryptorContract    $encryptor   Decrypts connection settings.
     * @param MailerManager        $mailer      Resolves provider names.
     */
    public function __construct(
        private readonly Application $app,
        private readonly ConnectionRepository $connections,
        private readonly EncryptorContract $encryptor,
        private readonly MailerManager $mailer,
    ) {}

    /**
     * The normalized sender in a connection's settings (`from_email`), or an empty string.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $settings Decrypted or plain connection settings.
     * @return string
     */
    public static function senderOf(array $settings): string
    {
        return MailRoutingDataBuilder::normalizeAddress((string) ($settings['from_email'] ?? ''));
    }

    /**
     * Describe a connection that is about to be saved, activated or imported.
     *
     * @since 1.0.0
     *
     * @param  string               $driver       Transport driver key.
     * @param  array<string, mixed> $settings     Plain connection settings.
     * @param  string               $name         Connection name.
     * @param  int|null             $connectionId Id of the connection being changed; null for a new one.
     * @param  string               $reason       One of the `SenderCandidate::REASON_*` constants.
     * @return SenderCandidate
     */
    public function candidate(string $driver, array $settings, string $name, ?int $connectionId, string $reason): SenderCandidate
    {
        return new SenderCandidate(
            self::senderOf($settings),
            $driver,
            SenderCandidate::providerKey($driver, $settings),
            $this->providerLabel($driver, $settings),
            $name,
            $connectionId,
            $reason
        );
    }

    /**
     * Describe a saved connection.
     *
     * @since 1.0.0
     *
     * @param  Connection $connection Saved connection.
     * @return SenderEntry
     */
    public function entry(Connection $connection): SenderEntry
    {
        $settings = $this->decrypted($connection);
        $driver   = (string) ($connection->driver ?? '');

        return new SenderEntry(
            (int) $connection->id,
            (string) ($connection->name ?? ''),
            self::senderOf($settings),
            $driver,
            SenderCandidate::providerKey($driver, $settings),
            $this->providerLabel($driver, $settings),
            (bool) $connection->is_active
        );
    }

    /**
     * Describe a connection accepted earlier in the same import (not saved yet).
     *
     * @since 1.0.0
     *
     * @param  SenderCandidate $candidate The accepted candidate.
     * @return SenderEntry
     */
    public static function entryFromCandidate(SenderCandidate $candidate): SenderEntry
    {
        return new SenderEntry(
            $candidate->connectionId,
            $candidate->name,
            $candidate->sender,
            $candidate->driver,
            $candidate->providerKey,
            $candidate->providerLabel,
            false
        );
    }

    /**
     * Every saved connection that uses a sender, except the one being changed.
     *
     * @since 1.0.0
     *
     * @param  string   $sender    Normalized sender.
     * @param  int|null $excludeId Connection to leave out (the one being checked).
     * @return list<SenderEntry>
     */
    public function entriesFor(string $sender, ?int $excludeId = null): array
    {
        if ($sender === '') {
            return [];
        }

        $entries = [];
        foreach ($this->connections->all() as $connection) {
            if ($excludeId !== null && (int) $connection->id === $excludeId) {
                continue;
            }

            $entry = $this->entry($connection);
            if ($entry->sender === $sender) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Ask the sender rule whether a candidate may use its sender.
     *
     * @since 1.0.0
     *
     * @param  SenderCandidate   $candidate The connection being checked.
     * @param  list<SenderEntry> $pending   Connections accepted earlier in the same import that share the sender.
     * @return SenderConflict|null
     */
    public function check(SenderCandidate $candidate, array $pending = []): ?SenderConflict
    {
        if ($candidate->sender === '') {
            return null;
        }

        $entries = array_merge($this->entriesFor($candidate->sender, $candidate->connectionId), $pending);
        if ($entries === []) {
            return null;
        }

        return $this->app->make(SenderRule::class)->conflict($candidate, $entries);
    }

    /**
     * Ask the sender rule about a candidate against a given set of connections only, for
     * connections that are not saved yet (the rest of an import).
     *
     * @since 1.0.0
     *
     * @param  SenderCandidate   $candidate The connection being checked.
     * @param  list<SenderEntry> $entries   Connections to check it against.
     * @return SenderConflict|null
     */
    public function ruleConflict(SenderCandidate $candidate, array $entries): ?SenderConflict
    {
        if ($candidate->sender === '' || $entries === []) {
            return null;
        }

        return $this->app->make(SenderRule::class)->conflict($candidate, $entries);
    }

    /**
     * The provider name used in messages: the transport's name, and for Custom SMTP its host.
     *
     * @since 1.0.0
     *
     * @param  string               $driver   Transport driver key.
     * @param  array<string, mixed> $settings Connection settings.
     * @return string
     */
    public function providerLabel(string $driver, array $settings): string
    {
        try {
            $label = $this->mailer->resolveTransportByDriver($driver)->getName();
        } catch (\Throwable) {
            // intentionally silent: an unknown driver is named by its key.
            $label = $driver;
        }

        $host = trim((string) ($settings['host'] ?? ''));

        return $driver === 'smtp' && $host !== '' ? $label . ' ' . strtolower($host) : $label;
    }

    /**
     * Decrypted, normalized settings of a saved connection.
     *
     * @since 1.0.0
     *
     * @param  Connection $connection Saved connection.
     * @return array<string, mixed>
     */
    private function decrypted(Connection $connection): array
    {
        $raw = $connection->settings ?? [];

        return MailerManager::normalizeConnectionSettings(\is_array($raw) ? $this->encryptor->decryptArray($raw) : []);
    }
}
