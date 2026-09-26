<?php

/**
 * Signs and verifies the OAuth2 state parameter passed through provider connection flows.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer\OAuth;

/**
 * Signed OAuth state for Google / Microsoft connection flows (CSRF + binding to connection ID).
 *
 * @since 1.0.0
 */
final class OAuthState
{
    /**
     * Maximum age, in seconds, that a state value remains valid after it was issued.
     *
     * @since 1.0.0
     * @var int
     */
    private const MAX_AGE_SECONDS = 600;

    /**
     * Decode and verify a state value produced by {@see encode()}.
     *
     * @since 1.0.0
     *
     * @param  string $state Base64-encoded, signed state value received from the provider callback.
     * @return array{connection_id: int, provider: string, ts: int, site_url: string, return_to: string|null}|null
     *         The decoded payload, or null when the value is malformed, the signature does not match,
     *         or it has expired. `return_to` names the admin screen the callback returns to
     *         (`onboard` for the setup wizard) or is null for the connection screen.
     */
    public static function decode(string $state): ?array
    {
        $raw = base64_decode($state, true);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! \is_array($decoded) || ! isset($decoded['payload'], $decoded['sig'])) {
            return null;
        }

        $payload = $decoded['payload'];
        if (! \is_array($payload) || ! isset($payload['connection_id'], $payload['provider'], $payload['ts'])) {
            return null;
        }

        $json     = wp_json_encode($payload);
        $expected = hash_hmac('sha256', (string) $json, wp_salt('auth'));
        if (! \is_string($decoded['sig']) || ! hash_equals($expected, $decoded['sig'])) {
            return null;
        }

        $age = time() - (int) $payload['ts'];
        if ($age < 0 || $age > self::MAX_AGE_SECONDS) {
            return null;
        }

        $returnTo = isset($payload['return_to']) && \is_string($payload['return_to']) && $payload['return_to'] !== ''
            ? $payload['return_to']
            : null;

        return [
            'connection_id' => (int) $payload['connection_id'],
            'provider'      => (string) $payload['provider'],
            'ts'            => (int) $payload['ts'],
            'site_url'      => (string) ($payload['site_url'] ?? ''),
            'return_to'     => $returnTo,
        ];
    }

    /**
     * Build a signed state value to send to the provider's OAuth authorization endpoint.
     *
     * @since 1.0.0
     *
     * @param  int         $connectionId Connection the completed OAuth flow will be applied to.
     * @param  string      $provider     Provider slug (google, microsoft).
     * @param  string|null $returnTo     Admin screen the callback should return to (`onboard` for the
     *                                   setup wizard); null returns to the connection screen.
     * @return string Base64-encoded payload and HMAC signature.
     */
    public static function encode(int $connectionId, string $provider, ?string $returnTo = null): string
    {
        $payload = [
            'connection_id' => $connectionId,
            'provider'      => $provider,
            'ts'            => time(),
            'site_url'      => site_url(),
        ];

        if ($returnTo !== null && $returnTo !== '') {
            $payload['return_to'] = $returnTo;
        }
        $json = wp_json_encode($payload);
        $sig  = hash_hmac('sha256', (string) $json, wp_salt('auth'));

        return base64_encode((string) wp_json_encode(['payload' => $payload, 'sig' => $sig]));
    }
}
