<?php

/**
 * Gmail API mail sender.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer\Api;

use BooleanSmtp\Adapters\Contracts\HttpAdapterContract;
use BooleanSmtp\Adapters\WordPress\HttpAdapter;
use BooleanSmtp\Core\Foundation\Application;
use function BooleanSmtp\Core\app;

/**
 * Sends mail through the Gmail API's `users.messages.send` endpoint, and supports Google's
 * One Click connection mode through a Pro-provided bearer token.
 *
 * The message is transmitted as a raw RFC 822 MIME document, base64url-encoded as the API
 * requires. OAuth access tokens are refreshed automatically when they are missing or close to
 * expiring.
 *
 * @since 1.0.0
 */
final class GmailApiSender {
    /**
     * How many seconds before its actual expiration an access token is treated as expired, so a
     * refresh can complete before the token is used to send a message.
     *
     * @since 1.0.0
     * @var int
     */
    private const TOKEN_REFRESH_LEEWAY_SECONDS = 300;

    /**
     * Diagnostic details about the most recent token resolution or One Click dispatch, for the
     * admin UI and logs.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    private array $authDebug = [];

    /**
     * Token fields refreshed during the most recent call, pending persistence by the caller.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    private array $persistableTokenFields = [];

    /**
     * HTTP client used to call the Gmail and Google OAuth APIs.
     *
     * @since 1.0.0
     * @var HttpAdapterContract
     */
    private HttpAdapterContract $http;

    /**
     * Resolve the HTTP adapter used to call the Gmail and Google OAuth APIs.
     *
     * Uses the given adapter when provided, otherwise resolves `HttpAdapterContract` from the
     * container, falling back to a direct WordPress-backed adapter when the container is
     * unavailable or resolution fails.
     *
     * @since 1.0.0
     *
     * @param HttpAdapterContract|null $http HTTP adapter to use, or null to resolve one.
     */
    public function __construct(?HttpAdapterContract $http = null) {
        if ($http instanceof HttpAdapterContract) {
            $this->http = $http;
            return;
        }

        if (Application::hasInstance()) {
            try {
                $resolved = app(HttpAdapterContract::class);
                if ($resolved instanceof HttpAdapterContract) {
                    $this->http = $resolved;
                    return;
                }
            } catch (\Throwable) {
                // Fallback to WordPress adapter below.
            }
        }

        $this->http = new HttpAdapter();
    }

    /**
     * Send a raw MIME message through the Gmail API.
     *
     * Delegates to the One Click dispatch path when the connection's delivery mode is
     * `one_click`; otherwise resolves a valid OAuth access token, refreshing it first if needed.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $settings Decrypted connection settings. Passed by reference
     *                                          because a token refresh updates it in place with
     *                                          the new access token, expiration and refresh token.
     * @param  string                $rawMime  Raw MIME message to send.
     * @return bool|\WP_Error True when Gmail accepted the message; a `WP_Error` describing the
     *                        failure otherwise.
     */
    public function sendRawMime(array &$settings, string $rawMime): bool | \WP_Error {
        if ((string) ($settings['delivery_mode'] ?? 'api') === 'one_click') {
            return $this->dispatchOneClick('send', $settings, $rawMime);
        }

        $token = $this->getValidAccessToken($settings);
        if ($token === '') {
            return new \WP_Error('booleansmtp_gmail_api', 'Gmail API requires a valid OAuth access or refresh token.');
        }

        return $this->sendWithToken($rawMime, $token);
    }

    /**
     * Dispatch a send or probe for a Google One Click connection.
     *
     * The actual bearer-token request against the Gmail API is implemented by the BooleanSMTP Pro
     * add-on, which supplies its result through the `boolean_smtp_google_send_one_click` and
     * `boolean_smtp_google_probe_one_click` filters (the same dispatch pattern used elsewhere for
     * Pro-only provider logic). This method only validates that a bearer token is present before
     * delegating; when the filter returns a value that is not a recognized result array, Pro is
     * not active, and the call fails rather than silently doing nothing.
     *
     * @since 1.0.0
     *
     * @param  string                $kind     Operation to dispatch: `send` or `probe`.
     * @param  array<string, mixed>  $settings Decrypted connection settings. Passed by reference
     *                                          because a successful dispatch may update
     *                                          `one_click_status` in place.
     * @param  string                $rawMime  Raw MIME message to send; ignored for `probe`.
     * @return bool|\WP_Error True on success; a `WP_Error` describing the failure otherwise.
     */
    private function dispatchOneClick(string $kind, array &$settings, string $rawMime = ''): bool | \WP_Error {
        $token           = trim((string) ($settings['one_click_bearer_token'] ?? ''));
        $this->authDebug = [
            'token_source'            => $token === '' ? 'missing_one_click_bearer' : 'one_click_bearer_token',
            'refresh_attempted'       => false,
            'refresh_succeeded'       => false,
            'token_expires_at'        => null,
            'token_refresh_at'        => null,
            'token_seconds_remaining' => null
        ];
        $this->persistableTokenFields = [];

        if ($token === '') {
            return new \WP_Error('booleansmtp_gmail_one_click', 'Google One Click requires a valid bearer token. Reconnect the account.');
        }

        /**
         * Filters the result of a Google One Click send.
         *
         * Implemented by the BooleanSMTP Pro add-on. A non-array result, or one missing the
         * `success` key, is treated as Pro not being active.
         *
         * @since 1.0.0
         *
         * @param  mixed                 $result   Default value; null unless a callback has run.
         *                                          Return `array{success: bool, error?: \WP_Error,
         *                                          status?: string}` describing the outcome.
         * @param  string                $rawMime  Raw MIME message being sent.
         * @param  array<string, mixed>  $settings Decrypted connection settings.
         * @return mixed The result to use, or the default to run the built-in behaviour.
         */
        $outcome = $kind === 'send'
            ? \apply_filters('boolean_smtp_google_send_one_click', null, $rawMime, $settings)
            /**
             * Filters the result of a Google One Click connection probe.
             *
             * Implemented by the BooleanSMTP Pro add-on. A non-array result, or one missing the
             * `success` key, is treated as Pro not being active.
             *
             * @since 1.0.0
             *
             * @param  mixed                 $result   Default value; null unless a callback has run.
             *                                          Return `array{success: bool, error?: \WP_Error,
             *                                          status?: string}` describing the outcome.
             * @param  array<string, mixed>  $settings Decrypted connection settings.
             * @return mixed The result to use, or the default to run the built-in behaviour.
             */
            : \apply_filters('boolean_smtp_google_probe_one_click', null, $settings);

        if (!\is_array($outcome) || !\array_key_exists('success', $outcome)) {
            return new \WP_Error('booleansmtp_gmail_one_click', 'Google One Click requires the BooleanSMTP Pro add-on to be active. Reconnect the account or contact support.');
        }

        if (!empty($outcome['status'])) {
            $settings['one_click_status'] = $outcome['status'];
            $this->persistableTokenFields = ['one_click_status' => $outcome['status']];
        }

        if ($outcome['success']) {
            return true;
        }

        return $outcome['error'] instanceof \WP_Error
            ? $outcome['error']
            : new \WP_Error('booleansmtp_gmail_one_click_http', 'Google One Click request failed.');
    }

    /**
     * Send a raw MIME message to the Gmail API using an already-resolved bearer token.
     *
     * @since 1.0.0
     *
     * @param  string  $rawMime Raw MIME message to send.
     * @param  string  $token   OAuth access token.
     * @return bool|\WP_Error True when Gmail accepted the message; a `WP_Error` describing the
     *                        failure otherwise.
     */
    private function sendWithToken(string $rawMime, string $token): bool | \WP_Error {
        $raw = rtrim(strtr(base64_encode($rawMime), '+/', '-_'), '=');

        $response = $this->http->post(
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
            [
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json'
                ],
                'body'    => wp_json_encode(['raw' => $raw])
            ]
        );

        if ($this->http->isError($response)) {
            return new \WP_Error('booleansmtp_gmail_http', $this->http->getErrorMessage($response));
        }

        $code = $this->http->responseCode($response);
        if ($code >= 300) {
            $body = $this->http->responseBody($response);
            return new \WP_Error('booleansmtp_gmail_http', $body, ['status' => $code, 'body' => $body]);
        }

        return true;
    }

    /**
     * Verify that a connection's Gmail API credentials are usable.
     *
     * Delegates to the One Click dispatch path when the connection's delivery mode is
     * `one_click`; otherwise resolves a valid OAuth access token, refreshing it first if needed.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $settings Decrypted connection settings. Passed by reference
     *                                          because a token refresh updates it in place.
     * @return bool|\WP_Error True when the credentials are usable; a `WP_Error` describing the
     *                        failure otherwise.
     */
    public function probe(array &$settings): bool | \WP_Error {
        if ((string) ($settings['delivery_mode'] ?? 'api') === 'one_click') {
            return $this->dispatchOneClick('probe', $settings);
        }

        $token = $this->getValidAccessToken($settings);
        if ($token === '') {
            return new \WP_Error('booleansmtp_gmail_api', 'Gmail API requires a valid OAuth access or refresh token.');
        }

        return $this->probeWithToken($token);
    }

    /**
     * Verify a Gmail OAuth access token by calling `users.getProfile`.
     *
     * `users.getProfile` requires a broader scope (`gmail.readonly`, `gmail.modify`,
     * `gmail.metadata`, or similar) than the `gmail.send`-only scope this integration requests (see
     * {@see \BooleanSmtp\Http\Controllers\OAuthController::googleAuthorize()}), so Google rejects
     * the call with a 403 `insufficientPermissions` /
     * `ACCESS_TOKEN_SCOPE_INSUFFICIENT` error even for a valid, working token. That rejection is a
     * property of the probe endpoint, not evidence the token cannot send mail (actual sending uses
     * `messages.send`, which the `gmail.send` scope does cover), so it is treated as success.
     *
     * @since 1.0.0
     *
     * @param  string  $token OAuth access token.
     * @return bool|\WP_Error True when the token is valid or only fails on the insufficient-scope
     *                        limitation described above; a `WP_Error` describing any other
     *                        failure.
     */
    private function probeWithToken(string $token): bool | \WP_Error {
        $response = $this->http->get('https://gmail.googleapis.com/gmail/v1/users/me/profile', [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $token
            ]
        ]);

        if ($this->http->isError($response)) {
            return new \WP_Error('booleansmtp_gmail_http', $this->http->getErrorMessage($response));
        }

        $code = $this->http->responseCode($response);
        if ($code >= 300) {
            $body = $this->http->responseBody($response);

            if ($code === 403 && self::isInsufficientScopeError($body)) {
                return true;
            }

            return new \WP_Error('booleansmtp_gmail_http', $body, ['status' => $code, 'body' => $body]);
        }

        return true;
    }

    /**
     * Check whether a Gmail API error body reports an insufficient OAuth scope.
     *
     * @since 1.0.0
     *
     * @param  string  $body Raw response body from the Gmail API.
     * @return bool True when the body indicates the token's scope was insufficient for the call.
     */
    private static function isInsufficientScopeError(string $body): bool {
        return stripos($body, 'insufficientPermissions') !== false
            || stripos($body, 'ACCESS_TOKEN_SCOPE_INSUFFICIENT') !== false
            || stripos($body, 'insufficient authentication scopes') !== false;
    }

    /**
     * Get diagnostic details about the most recent token resolution or One Click dispatch.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> Diagnostic details for the admin UI and logs.
     */
    public function getAuthDebug(): array {
        return $this->authDebug;
    }

    /**
     * Take and clear the token fields refreshed during the most recent call.
     *
     * Callers use this to persist a refreshed access token, expiration and refresh token back
     * onto the connection; the internal buffer is cleared so the same fields are not persisted
     * twice.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> Refreshed token fields keyed by setting name; empty when
     *                              nothing was refreshed since the last call.
     */
    public function consumePersistableTokenFields(): array {
        $fields                       = $this->persistableTokenFields;
        $this->persistableTokenFields = [];
        return $fields;
    }

    /**
     * Resolve a usable Gmail OAuth access token, refreshing it first if it is missing or close
     * to expiring.
     *
     * A token is treated as expired when `force_refresh` is set, when no expiration is recorded,
     * or when fewer than {@see self::TOKEN_REFRESH_LEEWAY_SECONDS} seconds remain before its
     * recorded expiration (a Unix timestamp, UTC). When a refresh is attempted and fails, an
     * already-expired token still returns an empty string, while a token that has not yet expired
     * is returned as-is so a send can still be attempted with it.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $settings Decrypted connection settings. Passed by reference
     *                                          because a successful refresh updates
     *                                          `access_token`, `token_expires_at` and
     *                                          `refresh_token` in place.
     * @return string Valid access token, or an empty string when none could be resolved.
     */
    private function getValidAccessToken(array &$settings): string {
        $accessToken  = (string) ($settings['access_token'] ?? '');
        $refreshToken = (string) ($settings['refresh_token'] ?? '');
        $expiresAt    = (int) ($settings['token_expires_at'] ?? 0);
        $forceRefresh = !empty($settings['force_refresh']);
        $now          = time();
        $refreshAt    = $expiresAt > 0 ? ($expiresAt - self::TOKEN_REFRESH_LEEWAY_SECONDS) : 0;
        $isExpired    = $forceRefresh || $expiresAt <= 0 || ($refreshAt <= $now);

        $this->authDebug = [
            'token_source'            => 'unknown',
            'refresh_attempted'       => false,
            'refresh_succeeded'       => false,
            'force_refresh'           => $forceRefresh,
            'token_expires_at'        => $expiresAt,
            'token_refresh_at'        => $refreshAt,
            'token_seconds_remaining' => $expiresAt > 0 ? ($expiresAt - $now) : null
        ];
        $this->persistableTokenFields = [];

        if (!$isExpired && $accessToken !== '') {
            $this->authDebug['token_source'] = 'access_token_cached';
            return $accessToken;
        }

        if ($refreshToken === '' || empty($settings['client_id']) || empty($settings['client_secret'])) {
            $this->authDebug['token_source'] = 'missing_refresh_or_client';
            if ($isExpired) {
                return '';
            }
            return $accessToken;
        }

        $this->authDebug['refresh_attempted'] = true;

        $response = $this->http->post('https://oauth2.googleapis.com/token', [
            'body' => [
                'client_id'     => $settings['client_id'],
                'client_secret' => $settings['client_secret'],
                'refresh_token' => $refreshToken,
                'grant_type'    => 'refresh_token'
            ]
        ]);

        if ($this->http->isError($response)) {
            $this->authDebug['token_source']  = 'refresh_failed_network';
            $this->authDebug['refresh_error'] = $this->http->getErrorMessage($response);
            if ($isExpired) {
                return '';
            }
            return $accessToken;
        }

        $status  = $this->http->responseCode($response);
        $rawBody = $this->http->responseBody($response);
        $body    = json_decode($rawBody, true);
        if (is_array($body) && isset($body['access_token']) && is_string($body['access_token']) && $body['access_token'] !== '') {
            $newAccessToken = (string) $body['access_token'];
            $newExpiresAt   = isset($body['expires_in']) ? (time() + (int) $body['expires_in']) : 0;
            $newRefresh     = isset($body['refresh_token']) ? (string) $body['refresh_token'] : $refreshToken;

            $settings['access_token']     = $newAccessToken;
            $settings['token_expires_at'] = $newExpiresAt;
            if ($newRefresh !== '') {
                $settings['refresh_token'] = $newRefresh;
            }

            $this->persistableTokenFields = [
                'access_token'     => $newAccessToken,
                'token_expires_at' => $newExpiresAt,
                'refresh_token'    => $newRefresh
            ];

            $this->authDebug['token_source']            = 'refresh_token_exchange';
            $this->authDebug['refresh_succeeded']       = true;
            $this->authDebug['refresh_status_code']     = $status;
            $this->authDebug['token_expires_at']        = $newExpiresAt;
            $this->authDebug['token_seconds_remaining'] = $newExpiresAt > 0 ? ($newExpiresAt - time()) : null;

            return $newAccessToken;
        }

        $this->authDebug['token_source']        = 'refresh_failed_response';
        $this->authDebug['refresh_status_code'] = $status;
        if (is_array($body)) {
            $this->authDebug['refresh_error'] = (string) ($body['error_description'] ?? $body['error'] ?? 'Token refresh failed.');
            if (isset($body['error'])) {
                $this->authDebug['refresh_error_code'] = (string) $body['error'];
            }
            if (isset($body['error_description'])) {
                $this->authDebug['refresh_error_description'] = (string) $body['error_description'];
            }
            $this->authDebug['refresh_provider_body'] = $body;
        } else {
            $this->authDebug['refresh_error']         = $rawBody !== '' ? $rawBody : 'Token refresh failed.';
            $this->authDebug['refresh_provider_body'] = $rawBody;
        }

        if ($isExpired) {
            return '';
        }
        return $accessToken;
    }
}
