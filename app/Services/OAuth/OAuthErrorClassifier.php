<?php

/**
 * Provider-agnostic classification of OAuth token refresh errors.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\OAuth;

/**
 * Maps provider-specific error codes and messages to standardized BooleanSMTP error codes.
 *
 * Used by the OAuth refresh job to make consistent retry and notification decisions across
 * Google and Microsoft, whose token endpoints each report failures with different codes
 * and message formats.
 *
 * @since 1.0.0
 */
final class OAuthErrorClassifier {
    /**
     * Refresh token or grant has expired; requires the user to re-authorize the connection.
     *
     * @since 1.0.0
     * @var string
     */
    public const ERROR_EXPIRED         = 'oauth_refresh_expired';

    /**
     * Grant has been revoked or is otherwise invalid; requires the user to re-authorize the
     * connection.
     *
     * @since 1.0.0
     * @var string
     */
    public const ERROR_INVALID_GRANT   = 'oauth_refresh_invalid_grant';

    /**
     * Provider rejected the request due to rate limiting; retryable with backoff.
     *
     * @since 1.0.0
     * @var string
     */
    public const ERROR_RATE_LIMIT      = 'oauth_refresh_rate_limit';

    /**
     * Request to the provider timed out or the connection failed; retryable with backoff.
     *
     * @since 1.0.0
     * @var string
     */
    public const ERROR_NETWORK_TIMEOUT = 'oauth_refresh_network_timeout';

    /**
     * Provider returned a server-side (5xx) error; retryable with backoff, then deferred if it
     * persists.
     *
     * @since 1.0.0
     * @var string
     */
    public const ERROR_PROVIDER_ERROR  = 'oauth_refresh_provider_error';

    /**
     * Error did not match any known pattern; treated as non-retryable pending investigation.
     *
     * @since 1.0.0
     * @var string
     */
    public const ERROR_UNKNOWN         = 'oauth_refresh_unknown';

    /**
     * Classify an OAuth error and determine its recovery strategy.
     *
     * @since 1.0.0
     *
     * @param  \WP_Error|string  $error    Provider error, either a `WP_Error` or a raw error code
     *                                      string.
     * @param  string            $provider Provider driver key (`google` or `outlook`).
     * @return array{code: string, label: string, retryable: bool, recoverable: bool, message: string}
     *         Standardized error code, a human-readable label, whether an automatic retry should
     *         be attempted, whether the connection is expected to recover without user action,
     *         and a descriptive message.
     */
    public static function classify($error, string $provider = 'unknown'): array {
        $code    = '';
        $message = '';

        if ($error instanceof \WP_Error) {
            $code    = $error->get_error_code();
            $message = $error->get_error_message();
        } else {
            $code = (string) $error;
        }

        return self::classifyCode($code, $message, $provider);
    }

    /**
     * Classify an error by its code and message against each known pattern, in priority order.
     *
     * @since 1.0.0
     *
     * @param  string  $errorCode    Provider error code.
     * @param  string  $errorMessage Provider error message.
     * @param  string  $provider     Provider driver key (`google`, `outlook` or `unknown`).
     * @return array{code: string, label: string, retryable: bool, recoverable: bool, message: string}
     *         Standardized error code, a human-readable label, whether an automatic retry should
     *         be attempted, whether the connection is expected to recover without user action,
     *         and a descriptive message.
     */
    private static function classifyCode(string $errorCode, string $errorMessage, string $provider): array {
        $errorCode    = \strtolower(\trim($errorCode));
        $errorMessage = \strtolower(\trim($errorMessage));

        if (self::isExpiredError($errorCode, $errorMessage, $provider)) {
            return [
                'code'        => self::ERROR_EXPIRED,
                'label'       => 'Refresh Token Expired',
                'retryable'   => false,
                'recoverable' => false,
                'message'     => 'Refresh token expired. User must re-authorize connection.'
            ];
        }

        if (self::isInvalidGrantError($errorCode, $errorMessage, $provider)) {
            return [
                'code'        => self::ERROR_INVALID_GRANT,
                'label'       => 'Invalid Grant',
                'retryable'   => false,
                'recoverable' => false,
                'message'     => 'Grant revoked or invalid. User must re-authorize connection.'
            ];
        }

        if (self::isRateLimitError($errorCode, $errorMessage, $provider)) {
            return [
                'code'        => self::ERROR_RATE_LIMIT,
                'label'       => 'Rate Limit Exceeded',
                'retryable'   => true,
                'recoverable' => true,
                'message'     => 'Provider rate limit hit. Will retry with exponential backoff.'
            ];
        }

        if (self::isNetworkError($errorCode, $errorMessage, $provider)) {
            return [
                'code'        => self::ERROR_NETWORK_TIMEOUT,
                'label'       => 'Network Error',
                'retryable'   => true,
                'recoverable' => true,
                'message'     => 'Network timeout or connection error. Will retry with backoff.'
            ];
        }

        if (self::isProviderError($errorCode, $errorMessage, $provider)) {
            return [
                'code'        => self::ERROR_PROVIDER_ERROR,
                'label'       => 'Provider Server Error',
                'retryable'   => true,
                'recoverable' => true,
                'message'     => 'Provider returned 5xx error. Will retry, then defer if persistent.'
            ];
        }

        return [
            'code'        => self::ERROR_UNKNOWN,
            'label'       => 'Unknown Error',
            'retryable'   => false,
            'recoverable' => false,
            'message'     => 'Unknown error: ' . $errorCode . '. Check logs for details.'
        ];
    }

    /**
     * Check whether an error indicates an expired or revoked refresh token.
     *
     * Checks the error code first, since it is more reliable than the message text; message
     * pattern matching is skipped when the code already looks like an invalid-grant error, so
     * the two classifications do not overlap.
     *
     * @since 1.0.0
     *
     * @param  string  $code     Provider error code, already lowercased and trimmed.
     * @param  string  $message  Provider error message, already lowercased and trimmed.
     * @param  string  $provider Provider driver key.
     * @return bool True when the error indicates an expired or revoked refresh token.
     */
    private static function isExpiredError(string $code, string $message, string $provider): bool {
        $expiredCodes = [
            'token_revoked',
            'refresh_token_revoked',
            'token_expired',
            'refresh_expired',
            'invalid_oauth_token',
            'aadsts700082' // Microsoft Entra ID: refresh token expired due to inactivity.
        ];

        foreach ($expiredCodes as $expiredCode) {
            if (\strtolower($code) === $expiredCode) {
                return true;
            }
        }

        $grantCodes = ['invalid_grant', 'access_denied', 'unauthorized_client'];
        if (\in_array(\strtolower($code), $grantCodes, true)) {
            return false;
        }

        $patterns = [
            'token.*revoked'   => true,
            'refresh.*expired' => true
        ];

        foreach ($patterns as $pattern => $match) {
            if (\preg_match('/' . $pattern . '/i', $message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether an error indicates a revoked or otherwise invalid OAuth grant.
     *
     * @since 1.0.0
     *
     * @param  string  $code     Provider error code, already lowercased and trimmed.
     * @param  string  $message  Provider error message, already lowercased and trimmed.
     * @param  string  $provider Provider driver key.
     * @return bool True when the error indicates a revoked or invalid grant.
     */
    private static function isInvalidGrantError(string $code, string $message, string $provider): bool {
        $patterns = [
            'invalid_grant'       => true,
            'not_authorized'      => true,
            'grant_revoked'       => true,
            'unauthorized_client' => true,
            'aadsts65001'         => true, // Microsoft Entra ID: user or admin has not consented.
            'access_denied'       => true  // Google: access was revoked or denied.
        ];

        foreach ($patterns as $pattern => $match) {
            if (\preg_match('/' . $pattern . '/i', $code . ' ' . $message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether an error indicates the provider is rate-limiting requests.
     *
     * @since 1.0.0
     *
     * @param  string  $code     Provider error code, already lowercased and trimmed.
     * @param  string  $message  Provider error message, already lowercased and trimmed.
     * @param  string  $provider Provider driver key.
     * @return bool True when the error indicates a rate limit or quota was exceeded.
     */
    private static function isRateLimitError(string $code, string $message, string $provider): bool {
        $patterns = [
            'rate_limit'                => true,
            'too_many_request'          => true,
            'quota_exceeded'            => true,
            'quota_user_limit_exceeded' => true,
            'userrateLimitExceeded'     => true,
            '429'                       => true, // HTTP 429 Too Many Requests.
            'aadsts9002138'             => true, // Microsoft Entra ID: throttled.
            'rateLimitExceeded'         => true  // Google API rate limit exceeded.
        ];

        foreach ($patterns as $pattern => $match) {
            if (\preg_match('/' . $pattern . '/i', $code . ' ' . $message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether an error indicates a network failure or request timeout.
     *
     * @since 1.0.0
     *
     * @param  string  $code     Provider error code, already lowercased and trimmed.
     * @param  string  $message  Provider error message, already lowercased and trimmed.
     * @param  string  $provider Provider driver key.
     * @return bool True when the error indicates a network failure or timeout.
     */
    private static function isNetworkError(string $code, string $message, string $provider): bool {
        $patterns = [
            'timeout'                  => true,
            'connection.*fail'         => true,
            'connection.*refused'      => true,
            'network'                  => true,
            'timed_out'                => true,
            '503'                      => true,
            '504'                      => true,
            'temporarily.*unavailable' => true
        ];

        foreach ($patterns as $pattern => $match) {
            if (\preg_match('/' . $pattern . '/i', $code . ' ' . $message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether an error indicates a provider-side server error.
     *
     * @since 1.0.0
     *
     * @param  string  $code     Provider error code, already lowercased and trimmed.
     * @param  string  $message  Provider error message, already lowercased and trimmed.
     * @param  string  $provider Provider driver key.
     * @return bool True when the error indicates a provider-side (5xx) failure.
     */
    private static function isProviderError(string $code, string $message, string $provider): bool {
        $patterns = [
            'internal.*error'          => true,
            'server.*error'            => true,
            '500'                      => true,
            '502'                      => true,
            'service.*unavailable'     => true,
            'temporarily.*unavailable' => true
        ];

        foreach ($patterns as $pattern => $match) {
            if (\preg_match('/' . $pattern . '/i', $code . ' ' . $message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get a legend describing every standardized error type and its recovery strategy.
     *
     * Intended for the admin UI, to explain to the site owner what a logged OAuth error code
     * means and whether it requires action.
     *
     * @since 1.0.0
     *
     * @return array<string, array{label: string, retryable: bool, action: string, description: string}>
     *         Legend entries keyed by standardized error code.
     */
    public static function errorLegend(): array {
        return [
            self::ERROR_EXPIRED         => [
                'label'       => 'Token Expired',
                'retryable'   => false,
                'action'      => 'User must re-authorize',
                'description' => 'Refresh token or grant has expired. Requires user interaction.'
            ],
            self::ERROR_INVALID_GRANT   => [
                'label'       => 'Invalid Grant',
                'retryable'   => false,
                'action'      => 'User must re-authorize',
                'description' => 'Grant has been revoked or is invalid.'
            ],
            self::ERROR_RATE_LIMIT      => [
                'label'       => 'Rate Limited',
                'retryable'   => true,
                'action'      => 'Retry with exponential backoff',
                'description' => 'Provider enforced rate limits. Temporary backoff will resolve.'
            ],
            self::ERROR_NETWORK_TIMEOUT => [
                'label'       => 'Network Error',
                'retryable'   => true,
                'action'      => 'Retry with exponential backoff',
                'description' => 'Network connectivity issue. Transient, will resolve.'
            ],
            self::ERROR_PROVIDER_ERROR  => [
                'label'       => 'Provider Error',
                'retryable'   => true,
                'action'      => 'Retry with exponential backoff, then defer',
                'description' => 'Provider server returned 5xx error. Likely temporary.'
            ],
            self::ERROR_UNKNOWN         => [
                'label'       => 'Unknown Error',
                'retryable'   => false,
                'action'      => 'Review logs and contact provider support',
                'description' => 'Unexpected error. Manual investigation required.'
            ]
        ];
    }
}
