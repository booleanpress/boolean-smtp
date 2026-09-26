<?php

/**
 * Validate a hosted OAuth return before exchanging its authorization code.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer\OAuth;

use BooleanSmtp\Core\Settings\SettingsRepository;

/**
 * Binds a returned authorization code to this site, provider, and saved connection.
 *
 * @since 1.0.0
 */
final class OAuthReturnGuard {
    /**
     * Seconds to retain a consumed state, matching the state's validity window.
     *
     * @since 1.0.0
     * @var int
     */
    private const REPLAY_TTL_SECONDS = 600;

    /**
     * @since 1.0.0
     *
     * @param SettingsRepository $settings Shared plugin-scoped transient store.
     */
    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * Check a signed state from the OAuth return without consuming it.
     *
     * @since 1.0.0
     *
     * @param string $state        Signed state returned by the provider.
     * @param int    $connectionId Connection receiving the authorization.
     * @param string $provider     Expected provider slug.
     * @return bool Whether the state is valid, bound to this request, and unused.
     */
    public function accepts(string $state, int $connectionId, string $provider): bool {
        $decoded = OAuthState::decode($state);
        if ($decoded === null || $decoded['connection_id'] !== $connectionId || $decoded['provider'] !== $provider) {
            return false;
        }

        $site = (string) $decoded['site_url'];
        if ($site === '' || !hash_equals(site_url(), $site)) {
            return false;
        }

        return !$this->settings->getTransient($this->replayKey($state));
    }

    /**
     * Mark a state consumed after its authorization code has been exchanged successfully.
     *
     * @since 1.0.0
     *
     * @param string $state Signed state returned by the provider.
     * @return bool Whether the replay marker was persisted.
     */
    public function consume(string $state): bool {
        return $this->settings->setTransient($this->replayKey($state), '1', self::REPLAY_TTL_SECONDS);
    }

    /**
     * Build a bounded, non-secret key for the consumed-state transient.
     *
     * @since 1.0.0
     *
     * @param string $state Signed state returned by the provider.
     * @return string Transient key.
     */
    private function replayKey(string $state): string {
        return 'oauth_return_used_' . hash('sha256', $state);
    }
}
