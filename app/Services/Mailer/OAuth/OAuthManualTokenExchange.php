<?php

/**
 * Exchanges or accepts a manually pasted OAuth value for a connection's provider.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer\OAuth;

use BooleanSmtp\Adapters\Contracts\HttpAdapterContract;
use BooleanSmtp\Adapters\WordPress\HttpAdapter;
use BooleanSmtp\Core\Foundation\Application;
use function BooleanSmtp\Core\app;

/**
 * Exchanges a pasted OAuth authorization code (or stores refresh token) for Google / Microsoft / Zoho.
 *
 * @since 1.0.0
 */
final class OAuthManualTokenExchange {
    /**
     * Apply a manually pasted OAuth value for a connection, routing to the provider-specific handler.
     *
     * @since 1.0.0
     *
     * @param  string                $driver       Provider slug (google, outlook, zoho).
     * @param  string                $pasted       Raw value pasted by the user (code, token, or refresh token).
     * @param  array<string, mixed>  $settings     Decrypted connection settings (must include client_id, client_secret)
     * @param  string|null           $deliveryMode Delivery mode override; falls back to settings, then "api".
     * @return array<string, mixed>|null Token fields to merge into settings, or null if the value could not be applied.
     *
     * @throws OAuthExchangeException When the provider answered the code exchange with an error of its own.
     */
    public static function applyPastedToken(string $driver, string $pasted, array $settings, ?string $deliveryMode = null): ?array {
        $pasted = trim($pasted);
        if ($pasted === '') {
            return null;
        }

        $mode = (string) ($deliveryMode ?? ($settings['delivery_mode'] ?? 'api'));

        return match ($driver) {
            'google'  => $mode === 'one_click'
            ? self::oneClickBearerToken($pasted)
            : self::google($pasted, $settings),
            'outlook' => $mode === 'one_click'
            ? self::oneClickBearerToken($pasted)
            : self::microsoft($pasted, $settings),
            'zoho'    => self::zoho($pasted, $settings),
            default   => null,
        };
    }

    /**
     * One Click's hosted proxy returns a bearer token directly, so no client_id/secret exchange
     * runs here. Shared by Google and Microsoft since both use the same hosted-proxy shape;
     * without this branch, a manual-paste fallback for a Google One Click connection would fall
     * through to {@see google()}'s authorization-code exchange, which always fails because
     * client_id and client_secret are not configured in one_click mode.
     *
     * @since 1.0.0
     *
     * @param  string $pasted Raw bearer token pasted by the user.
     * @return array<string, mixed>|null
     */
    private static function oneClickBearerToken(string $pasted): ?array {
        $value = self::normalizePastedOauthValue($pasted);
        if ($value === '') {
            return null;
        }

        if (\preg_match('/\s/', $value) === 1) {
            return null;
        }

        if (\strlen($value) < 20) {
            return null;
        }

        return [
            'one_click_bearer_token' => $value,
            'one_click_status'       => 'connected'
        ];
    }

    /**
     * Exchange a pasted Google authorization code for tokens, or accept a pasted refresh token directly.
     *
     * @since 1.0.0
     *
     * @param  string                $pasted   Authorization code or refresh token pasted by the user.
     * @param  array<string, mixed>  $settings Decrypted connection settings; reads client_id and client_secret.
     * @return array<string, mixed>|null
     *
     * @throws OAuthExchangeException When Google refused the code and the value is not a refresh token either.
     */
    private static function google(string $pasted, array $settings): ?array {
        $clientId     = (string) ($settings['client_id'] ?? '');
        $clientSecret = (string) ($settings['client_secret'] ?? '');
        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        $redirectUri = OAuthRedirectUri::google();
        $response    = self::http()->post('https://oauth2.googleapis.com/token', [
            'timeout' => 20,
            'body'    => [
                'code'          => $pasted,
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri'  => $redirectUri,
                'grant_type'    => 'authorization_code'
            ]
        ]);

        $refused = null;
        if (!self::http()->isError($response)) {
            $body = json_decode(self::http()->responseBody($response), true);
            if (\is_array($body) && !empty($body['access_token'])) {
                return self::googleTokenBody($body);
            }
            $refused = \is_array($body) ? OAuthExchangeException::fromTokenResponse($body) : null;
        }

        // Accept direct Google refresh tokens only when they match expected format.
        // This prevents one-time auth codes (commonly starting with "4/") from being
        // incorrectly persisted as refresh_token and causing later 401 refresh failures.
        if (self::looksLikeGoogleRefreshToken($pasted)) {
            return [
                'refresh_token'    => $pasted,
                'access_token'     => '',
                'token_expires_at' => 0
            ];
        }

        if ($refused !== null) {
            throw $refused;
        }

        return null;
    }

    /**
     * Extract the token fields BooleanSMTP stores from a Google token endpoint response body.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $body Decoded JSON response from the Google token endpoint.
     * @return array<string, mixed>
     */
    private static function googleTokenBody(array $body): array {
        $fields = [
            'access_token'     => (string) $body['access_token'],
            'refresh_token'    => isset($body['refresh_token']) ? (string) $body['refresh_token'] : '',
            'token_expires_at' => isset($body['expires_in']) ? time() + (int) $body['expires_in'] : 0
        ];

        // A code exchange carries the signed-in account in the id token (`openid email` scopes).
        $account = OAuthAccountIdentity::fromGoogleIdToken((string) ($body['id_token'] ?? ''));
        if ($account !== '') {
            $fields['oauth_account_email'] = $account;
        }

        return $fields;
    }

    /**
     * Determine whether a pasted value has the shape of a Google refresh token.
     *
     * @since 1.0.0
     *
     * @param  string $value Value to inspect.
     * @return bool
     */
    private static function looksLikeGoogleRefreshToken(string $value): bool {
        $value = trim($value);

        return str_starts_with($value, '1//') && strlen($value) > 20;
    }

    /**
     * Exchange a pasted Microsoft value (authorization code, refresh token, access token, or a
     * JSON/key-value block containing one) for tokens.
     *
     * @since 1.0.0
     *
     * @param  string                $pasted   Value pasted by the user.
     * @param  array<string, mixed>  $settings Decrypted connection settings; reads client_id, client_secret, tenant_id.
     * @return array<string, mixed>|null
     *
     * @throws OAuthExchangeException When Microsoft refused the value as a code and as a refresh token.
     */
    private static function microsoft(string $pasted, array $settings): ?array {
        $pasted = self::normalizePastedOauthValue($pasted);

        // Users sometimes paste an access token (JWT) or a JSON/key-value block.
        // When an access token can be extracted and its expiry inferred, accept it directly.
        $extracted = self::extractMicrosoftPastedToken($pasted);
        if (isset($extracted['type']) && $extracted['type'] === 'access_token') {
            $accessToken = (string) $extracted['value'];
            $expiresAt   = self::decodeJwtExp($accessToken);
            if ($expiresAt !== null) {
                return [
                    'access_token'     => $accessToken,
                    'refresh_token'    => '',
                    'token_expires_at' => $expiresAt
                ];
            }
            // When expiry cannot be safely inferred, fall through to the exchange logic.
        }

        if (isset($extracted['type']) && in_array($extracted['type'], ['refresh_token', 'code'], true)) {
            $pasted = (string) $extracted['value'];
        }

        $clientId = (string) ($settings['client_id'] ?? '');
        // Hardening: Azure is strict about the exact secret value.
        $clientSecret = \trim((string) ($settings['client_secret'] ?? ''));
        $tenant       = (string) ($settings['tenant_id'] ?? 'common');
        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        /**
         * Filters the Microsoft identity platform token endpoint used to exchange or refresh
         * delegated and application tokens.
         *
         * Return a different URL to override the resolved endpoint, for example for a sovereign
         * cloud. Fired wherever the plugin or the Pro add-on talks to the token endpoint: the
         * manual and scheduled refreshes, the Graph sender, the credential check and the
         * application-permission mailer.
         *
         * @since 1.0.0
         *
         * @param  string $url    Token endpoint URL built from the tenant: `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token`.
         * @param  string $tenant Microsoft tenant id or domain, or `common`, `organizations`, `consumers`.
         * @return string The filtered URL.
         */
        $tokenUrl = (string) apply_filters(
            'boolean_smtp_microsoft_token_url',
            "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token",
            $tenant
        );

        // 1) Try as authorization code.
        $authCodeResponse = self::http()->post($tokenUrl, [
            'timeout' => 20,
            'body'    => [
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'code'          => $pasted,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => OAuthRedirectUri::microsoft(),
                'scope'         => 'offline_access https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/User.Read'
            ]
        ]);

        $refused = null;
        if (!self::http()->isError($authCodeResponse)) {
            $body = json_decode(self::http()->responseBody($authCodeResponse), true);
            $refused = \is_array($body) && empty($body['access_token']) ? OAuthExchangeException::fromTokenResponse($body) : null;
            if (\is_array($body) && !empty($body['access_token'])) {
                $fields = [
                    'access_token'     => (string) $body['access_token'],
                    'refresh_token'    => isset($body['refresh_token']) ? (string) $body['refresh_token'] : '',
                    'token_expires_at' => isset($body['expires_in']) ? time() + (int) $body['expires_in'] : 0
                ];

                // `User.Read` lets one Graph call name the signed-in mailbox; a failure is not a failed exchange.
                $account = OAuthAccountIdentity::fromMicrosoftGraph($fields['access_token'], self::http());
                if ($account !== '') {
                    $fields['oauth_account_email'] = $account;
                }

                return $fields;
            }
        }

        // 2) Fallback: try interpreting pasted value as refresh token.
        $refreshResponse = self::http()->post($tokenUrl, [
            'timeout' => 20,
            'body'    => [
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $pasted,
                'grant_type'    => 'refresh_token',
                'scope'         => 'offline_access https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/User.Read'
            ]
        ]);

        if (!self::http()->isError($refreshResponse)) {
            $body = json_decode(self::http()->responseBody($refreshResponse), true);
            if (\is_array($body) && !empty($body['access_token'])) {
                return [
                    'access_token'     => (string) $body['access_token'],
                    'refresh_token'    => isset($body['refresh_token']) ? (string) $body['refresh_token'] : (string) $pasted,
                    'token_expires_at' => isset($body['expires_in']) ? time() + (int) $body['expires_in'] : 0
                ];
            }
        }

        if ($refused !== null) {
            throw $refused;
        }

        return null;
    }

    /**
     * Normalize pasted OAuth values (remove accidental whitespace/prefixes/quotes).
     *
     * @since 1.0.0
     *
     * @param  string $pasted Raw value pasted by the user.
     * @return string
     */
    private static function normalizePastedOauthValue(string $pasted): string {
        $pasted = trim($pasted);

        // Strip optional wrapping quotes.
        if (\strlen($pasted) >= 2) {
            $first = $pasted[0];
            $last  = $pasted[\strlen($pasted) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $pasted = substr($pasted, 1, -1);
            }
        }

        // Common prefix when users copy Authorization header.
        if (\str_starts_with(strtolower($pasted), 'bearer ')) {
            $pasted = trim(substr($pasted, 7));
        }

        // Tokens/codes should not contain whitespace; remove it to harden against copy/paste issues.
        $pasted = \preg_replace('/\s+/', '', $pasted) ?: $pasted;

        return $pasted;
    }

    /**
     * Try to extract code/refresh_token/access_token from pasted text.
     *
     * @since 1.0.0
     *
     * @param  string $pasted Normalized value pasted by the user.
     * @return array{type: string, value: string}|null
     */
    private static function extractMicrosoftPastedToken(string $pasted): ?array {
        if ($pasted === '') {
            return null;
        }

        // JSON blobs: {"access_token":"...","refresh_token":"..."} or {"code":"..."}
        if (\str_starts_with($pasted, '{') || \str_starts_with($pasted, '[')) {
            $json = \json_decode($pasted, true);
            if (\is_array($json)) {
                if (isset($json['refresh_token']) && \is_string($json['refresh_token']) && $json['refresh_token'] !== '') {
                    return ['type' => 'refresh_token', 'value' => $json['refresh_token']];
                }
                if (isset($json['access_token']) && \is_string($json['access_token']) && $json['access_token'] !== '') {
                    return ['type' => 'access_token', 'value' => $json['access_token']];
                }
                if (isset($json['code']) && \is_string($json['code']) && $json['code'] !== '') {
                    return ['type' => 'code', 'value' => $json['code']];
                }
                if (isset($json['authorization_code']) && \is_string($json['authorization_code']) && $json['authorization_code'] !== '') {
                    return ['type' => 'code', 'value' => $json['authorization_code']];
                }
            }
        }

        // Key/value copies: access_token=... | refresh_token=... | code=...
        if (\preg_match('/(?:^|[\\s,;])access_token\\s*=?\\s*(.+)$/i', $pasted, $m)) {
            return ['type' => 'access_token', 'value' => trim((string) $m[1])];
        }
        if (\preg_match('/(?:^|[\\s,;])refresh_token\\s*=?\\s*(.+)$/i', $pasted, $m)) {
            return ['type' => 'refresh_token', 'value' => trim((string) $m[1])];
        }
        if (\preg_match('/(?:^|[\\s,;])code\\s*=?\\s*(.+)$/i', $pasted, $m)) {
            return ['type' => 'code', 'value' => trim((string) $m[1])];
        }

        // Microsoft authorization codes commonly start with "0." or "1." and are very long.
        // Classify these explicitly as auth codes so they are exchanged at the token endpoint.
        if ((\str_starts_with($pasted, '0.') || \str_starts_with($pasted, '1.')) && \strlen($pasted) > 20) {
            return ['type' => 'code', 'value' => $pasted];
        }

        // JWT access tokens: three dot-separated segments.
        // Used only as a hint; exchange logic still runs if expiry cannot be inferred.
        if (\substr_count($pasted, '.') === 2 && \strlen($pasted) > 20) {
            return ['type' => 'access_token', 'value' => $pasted];
        }

        return null;
    }

    /**
     * Decode JWT "exp" claim into a unix timestamp.
     *
     * @since 1.0.0
     *
     * @param  string $jwt JSON Web Token to decode.
     * @return int|null
     */
    private static function decodeJwtExp(string $jwt): ?int {
        $parts = \explode('.', $jwt);
        if (\count($parts) !== 3) {
            return null;
        }

        $payloadB64  = $parts[1];
        $payloadJson = self::base64UrlDecode($payloadB64);
        if ($payloadJson === '') {
            return null;
        }

        $payload = \json_decode($payloadJson, true);
        if (!\is_array($payload) || !isset($payload['exp'])) {
            return null;
        }

        $exp = $payload['exp'];
        if (!\is_int($exp) && !(\is_string($exp) && \ctype_digit($exp))) {
            return null;
        }

        return (int) $exp;
    }

    /**
     * Decode a base64url-encoded string (JWT segment encoding).
     *
     * @since 1.0.0
     *
     * @param  string $data Base64url-encoded data.
     * @return string Decoded data, or an empty string when decoding fails.
     */
    private static function base64UrlDecode(string $data): string {
        $remainder = \strlen($data) % 4;
        if ($remainder) {
            $padLen = 4 - $remainder;
            $data .= \str_repeat('=', $padLen);
        }

        $data    = \strtr($data, '-_', '+/');
        $decoded = \base64_decode($data, true);
        return $decoded === false ? '' : $decoded;
    }

    /**
     * Zoho OAuth authorization domains keyed by data-center region.
     *
     * @since 1.0.0
     * @var array<string, string>
     */
    private const ZOHO_AUTH_DOMAINS = [
        'us' => 'https://accounts.zoho.com',
        'eu' => 'https://accounts.zoho.eu',
        'in' => 'https://accounts.zoho.in',
        'cn' => 'https://accounts.zoho.com.cn',
        'au' => 'https://accounts.zoho.com.au',
        'jp' => 'https://accounts.zoho.jp',
        'ca' => 'https://accounts.zohocloud.ca'
    ];

    /**
     * Exchange a pasted Zoho authorization code for tokens, or accept a pasted refresh token directly.
     *
     * @since 1.0.0
     *
     * @param  string                $pasted   Authorization code or refresh token pasted by the user.
     * @param  array<string, mixed>  $settings Decrypted connection settings; reads client_id, client_secret, region.
     * @return array<string, mixed>|null
     */
    private static function zoho(string $pasted, array $settings): ?array {
        $clientId     = (string) ($settings['client_id'] ?? '');
        $clientSecret = (string) ($settings['client_secret'] ?? '');
        $region       = (string) ($settings['region'] ?? 'us');
        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        $authDomain = self::ZOHO_AUTH_DOMAINS[$region] ?? self::ZOHO_AUTH_DOMAINS['us'];
        $tokenUrl   = $authDomain . '/oauth/v2/token';

        $response = self::http()->post($tokenUrl, [
            'timeout' => 20,
            'body'    => [
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'code'          => $pasted,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => OAuthRedirectUri::zoho()
            ]
        ]);

        if (!self::http()->isError($response)) {
            $body = json_decode(self::http()->responseBody($response), true);
            if (\is_array($body) && !empty($body['access_token'])) {
                return [
                    'access_token'     => (string) $body['access_token'],
                    'refresh_token'    => isset($body['refresh_token']) ? (string) $body['refresh_token'] : '',
                    'token_expires_at' => isset($body['expires_in']) ? time() + (int) $body['expires_in'] : 0,
                    'api_domain'       => (string) ($body['api_domain'] ?? 'https://mail.zoho.com')
                ];
            }
        }

        if (strlen($pasted) > 12) {
            return [
                'refresh_token'    => $pasted,
                'access_token'     => '',
                'token_expires_at' => 0
            ];
        }

        return null;
    }

    /**
     * Resolve the HTTP adapter used to call provider token endpoints.
     *
     * @since 1.0.0
     *
     * @return HttpAdapterContract
     */
    private static function http(): HttpAdapterContract {
        if (Application::hasInstance()) {
            try {
                $resolved = app(HttpAdapterContract::class);
                if ($resolved instanceof HttpAdapterContract) {
                    return $resolved;
                }
            } catch (\Throwable) {
                // Fallback below.
            }
        }

        return new HttpAdapter();
    }
}
