<?php

/**
 * Encrypted temporary configuration for reconnecting an active OAuth mailer.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer\OAuth;

use BooleanSmtp\Contracts\EncryptorContract;
use BooleanSmtp\Core\Settings\SettingsRepository;

/**
 * Keeps a replacement OAuth configuration out of the active delivery path until final save.
 *
 * @since 1.0.0
 */
final class OAuthPendingConnection {
    /**
     * Pending configurations expire after 30 minutes.
     *
     * @since 1.0.0
     * @var int
     */
    private const TTL_SECONDS = 1800;

    /**
     * @since 1.0.0
     *
     * @param SettingsRepository $settings Plugin-scoped temporary store.
     * @param EncryptorContract  $encryptor Encrypts the complete pending payload at rest.
     */
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly EncryptorContract $encryptor
    ) {}

    /**
     * Save a pending mailer configuration, replacing any older pending draft.
     *
     * @since 1.0.0
     *
     * @param int                  $connectionId Active connection being reconnected.
     * @param array<string, mixed> $payload      Name, priority, and decrypted settings.
     * @return bool Whether the encrypted transient was stored.
     */
    public function put(int $connectionId, array $payload): bool {
        $json = wp_json_encode($payload);
        if (!is_string($json)) {
            return false;
        }

        return $this->settings->setTransient(
            $this->key($connectionId),
            $this->encryptor->encrypt($json),
            self::TTL_SECONDS
        );
    }

    /**
     * Read a pending configuration, or null when absent, expired, or unreadable.
     *
     * @since 1.0.0
     *
     * @param int $connectionId Active connection being reconnected.
     * @return array<string, mixed>|null Pending name, priority, and decrypted settings.
     */
    public function get(int $connectionId): ?array {
        $encrypted = $this->settings->getTransient($this->key($connectionId));
        if (!is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            $decoded = json_decode($this->encryptor->decrypt($encrypted), true);
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) && is_array($decoded['settings'] ?? null) ? $decoded : null;
    }

    /**
     * Remove a completed or abandoned pending configuration.
     *
     * @since 1.0.0
     *
     * @param int $connectionId Active connection being reconnected.
     * @return bool Whether the transient was removed.
     */
    public function delete(int $connectionId): bool {
        return $this->settings->deleteTransient($this->key($connectionId));
    }

    /**
     * Build a per-connection key for the shared options store.
     *
     * @since 1.0.0
     *
     * @param int $connectionId Active connection being reconnected.
     * @return string Transient key.
     */
    private function key(int $connectionId): string {
        return 'oauth_pending_connection_' . $connectionId;
    }
}
