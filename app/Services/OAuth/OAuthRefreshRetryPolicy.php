<?php

/**
 * Retry policy for OAuth token refresh operations.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\OAuth;

/**
 * Retries a failed OAuth token refresh with exponential backoff and jitter.
 *
 * Permanent failures (expired refresh tokens, revoked grants, access denied) are not retried;
 * only transient failures are, up to {@see self::MAX_RETRIES} attempts.
 *
 * @see https://tools.ietf.org/html/rfc6749#section-6 (OAuth 2.0 Token Refresh)
 *
 * @since 1.0.0
 */
final class OAuthRefreshRetryPolicy {
    /**
     * Maximum number of attempts per refresh, including the initial attempt.
     *
     * @since 1.0.0
     * @var int
     */
    public const MAX_RETRIES = 3;

    /**
     * Backoff before the first retry, in milliseconds.
     *
     * @since 1.0.0
     * @var int
     */
    public const INITIAL_BACKOFF_MS = 1000;

    /**
     * Upper bound on backoff between attempts, in milliseconds.
     *
     * @since 1.0.0
     * @var int
     */
    public const MAX_BACKOFF_MS = 8000;

    /**
     * Run a token refresh callback, retrying transient failures with exponential backoff.
     *
     * Blocks the current request for the duration of any backoff between attempts (`usleep()`),
     * so it must only be called from a background job, never from a synchronous HTTP request.
     *
     * @since 1.0.0
     *
     * @param  callable  $refreshFn  Performs one refresh attempt; returns `true` on success or a
     *                                `WP_Error` on failure.
     * @param  int       $maxRetries Maximum number of attempts.
     * @return array{success: bool, result: mixed, attempts: int, lastError: string|null}
     *         Whether the refresh ultimately succeeded, the last attempt's raw result, the number
     *         of attempts made, and the last error message, if any.
     */
    public static function executeWithRetry(callable $refreshFn, int $maxRetries = self::MAX_RETRIES): array {
        $attempts  = 0;
        $lastError = null;
        $result    = null;

        for ($attempt = 1; $attempt <= $maxRetries; ++$attempt) {
            ++$attempts;

            try {
                $result = $refreshFn();

                if ($result === true) {
                    return [
                        'success'   => true,
                        'result'    => $result,
                        'attempts'  => $attempts,
                        'lastError' => null
                    ];
                }

                if ($result instanceof \WP_Error) {
                    $lastError = $result->get_error_message();
                    $errorCode = $result->get_error_code();

                    if (self::isPermanentError($errorCode)) {
                        return [
                            'success'   => false,
                            'result'    => $result,
                            'attempts'  => $attempts,
                            'lastError' => $lastError
                        ];
                    }
                }
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }

            if ($attempt >= $maxRetries) {
                break;
            }

            $backoffMs = self::calculateBackoff($attempt);
            \usleep($backoffMs * 1000);
        }

        return [
            'success'   => false,
            'result'    => $result,
            'attempts'  => $attempts,
            'lastError' => $lastError
        ];
    }

    /**
     * Calculate the backoff delay before a given attempt, with jitter applied.
     *
     * Formula: `min(INITIAL_BACKOFF_MS * 2^(n-1), MAX_BACKOFF_MS)`, then adjusted by up to ±10%
     * jitter to avoid many connections retrying at the exact same moment. With the default
     * constants this yields roughly 1s, 2s, 4s, 8s for attempts 1 through 4.
     *
     * @since 1.0.0
     *
     * @param  int  $attemptNumber 1-based attempt number; the first retry is attempt 2.
     * @return int Backoff delay in milliseconds.
     */
    public static function calculateBackoff(int $attemptNumber): int {
        $exponentialMs = self::INITIAL_BACKOFF_MS * (2 ** ($attemptNumber - 1));
        $capped        = (int) \min($exponentialMs, self::MAX_BACKOFF_MS);

        // wp_rand() returns a non-negative value, so draw 0..20 and shift to -10..10.
        $jitterPercent = (\wp_rand(0, 20) - 10) / 100.0;
        $jitter        = (int) ($capped * $jitterPercent);

        return max(0, $capped + $jitter);
    }

    /**
     * Check whether an error code represents a permanent failure that must not be retried.
     *
     * Covers refresh tokens that have expired, grants that have been revoked, and access denied
     * by the user or provider; these all require the user to re-authorize the connection rather
     * than benefiting from a retry.
     *
     * @since 1.0.0
     *
     * @param  string  $errorCode OAuth error code from the provider or from BooleanSMTP's own
     *                             classification.
     * @return bool True when the error is permanent and should not be retried.
     */
    private static function isPermanentError(string $errorCode): bool {
        $permanentCodes = [
            'booleansmtp_oauth_refresh_expired',
            'booleansmtp_oauth_refresh_invalid_grant',
            'booleansmtp_oauth_refresh_permission',
            'unauthorized_client',
            'access_denied',
            'invalid_grant',
            'invalid_client',
            'invalid_request'
        ];

        return \in_array($errorCode, $permanentCodes, true);
    }

    /**
     * Build a human-readable description of the retry policy's backoff schedule.
     *
     * @since 1.0.0
     *
     * @param  int  $maxRetries Maximum retry count to describe.
     * @return string Description of the retry policy, including each backoff in seconds.
     */
    public static function description(int $maxRetries = self::MAX_RETRIES): string {
        $backoffs = [];
        for ($i = 1; $i < $maxRetries; ++$i) {
            $backoffs[] = (int) (self::calculateBackoff($i) / 1000) . 's';
        }

        return \sprintf(
            'Retry up to %d times with exponential backoff: %s',
            $maxRetries,
            \implode(' → ', $backoffs)
        );
    }
}
