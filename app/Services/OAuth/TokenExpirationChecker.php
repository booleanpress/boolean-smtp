<?php

/**
 * Detects OAuth access tokens that are expired or about to expire.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\OAuth;

/**
 * Checks OAuth access token expiration ahead of use, so a send can trigger a refresh before the
 * token actually expires rather than failing mid-send.
 *
 * @since 1.0.0
 */
final class TokenExpirationChecker {
    /**
     * How many seconds before actual expiration a token is considered stale enough to refresh
     * preemptively. This is a shorter, more conservative window than the routine hourly refresh
     * performed by the scheduled OAuth refresh job.
     *
     * @since 1.0.0
     * @var int
     */
    public const PREEMPTIVE_REFRESH_SECONDS = 300;

    /**
     * Check whether a token is already expired or will expire within the preemptive threshold.
     *
     * @since 1.0.0
     *
     * @param  string  $expirationTime      Token expiration as an ISO 8601/RFC 3339 timestamp or
     *                                       a Unix timestamp (seconds, UTC).
     * @param  int     $preemptiveThreshold Seconds before actual expiration, from now, to still
     *                                       consider the token stale.
     * @return bool True when the token is expired or will expire within the threshold; false when
     *              no expiration is set.
     */
    public static function isExpiredOrStale(string $expirationTime, int $preemptiveThreshold = self::PREEMPTIVE_REFRESH_SECONDS): bool {
        $expiresAt = self::parseExpirationTime($expirationTime);

        if ($expiresAt <= 0) {
            return false;
        }

        $now           = \time();
        $thresholdTime = $now + $preemptiveThreshold;

        return $expiresAt <= $thresholdTime;
    }

    /**
     * Get the number of seconds remaining until a token expires.
     *
     * @since 1.0.0
     *
     * @param  string  $expirationTime Token expiration as an ISO 8601/RFC 3339 timestamp or a
     *                                  Unix timestamp (seconds, UTC).
     * @return int Seconds until expiration; negative when already expired, or `PHP_INT_MAX` when
     *             no expiration is set.
     */
    public static function secondsUntilExpiration(string $expirationTime): int {
        $expiresAt = self::parseExpirationTime($expirationTime);

        if ($expiresAt <= 0) {
            return \PHP_INT_MAX;
        }

        return $expiresAt-\time();
    }

    /**
     * Check whether a preemptive refresh should run for this token now.
     *
     * Equivalent to {@see self::isExpiredOrStale()}; provided as a named alias so callers that
     * refresh ahead of a send can express intent without a threshold-based method name.
     *
     * @since 1.0.0
     *
     * @param  string  $expirationTime      Token expiration as an ISO 8601/RFC 3339 timestamp or
     *                                       a Unix timestamp (seconds, UTC).
     * @param  int     $preemptiveThreshold Seconds before actual expiration, from now, to still
     *                                       consider the token stale.
     * @return bool True when a preemptive refresh should run.
     */
    public static function shouldPreemptivelyRefresh(string $expirationTime, int $preemptiveThreshold = self::PREEMPTIVE_REFRESH_SECONDS): bool {
        return self::isExpiredOrStale($expirationTime, $preemptiveThreshold);
    }

    /**
     * Build a human-readable status summary for a token's expiration.
     *
     * @since 1.0.0
     *
     * @param  string  $expirationTime Token expiration as an ISO 8601/RFC 3339 timestamp or a
     *                                  Unix timestamp (seconds, UTC).
     * @return array{status: string, expiresIn: string, isSoonExpiring: bool}
     *         `status` is one of `EXPIRED`, `EXPIRING_SOON`, `VALID_SHORT_TERM` (expires within an
     *         hour) or `VALID`; `expiresIn` is a human-readable duration; `isSoonExpiring` is true
     *         for `EXPIRED` and `EXPIRING_SOON`.
     */
    public static function getStatus(string $expirationTime): array {
        $secondsLeft = self::secondsUntilExpiration($expirationTime);

        if ($secondsLeft <= 0) {
            return [
                'status'         => 'EXPIRED',
                'expiresIn'      => 'Already expired',
                'isSoonExpiring' => true
            ];
        }

        if ($secondsLeft < self::PREEMPTIVE_REFRESH_SECONDS) {
            return [
                'status'         => 'EXPIRING_SOON',
                'expiresIn'      => self::formatSeconds($secondsLeft),
                'isSoonExpiring' => true
            ];
        }

        if ($secondsLeft < 3600) {
            return [
                'status'         => 'VALID_SHORT_TERM',
                'expiresIn'      => self::formatSeconds($secondsLeft),
                'isSoonExpiring' => false
            ];
        }

        return [
            'status'         => 'VALID',
            'expiresIn'      => self::formatSeconds($secondsLeft),
            'isSoonExpiring' => false
        ];
    }

    /**
     * Parse a token expiration value into a Unix timestamp.
     *
     * Accepts an ISO 8601 timestamp (`2026-03-31T12:00:00Z`), an RFC 3339 timestamp
     * (`2026-03-31T12:00:00+00:00`), or a numeric Unix timestamp (`1743300000`). Values with an
     * explicit offset or trailing `Z` are converted to UTC; a value with no timezone information
     * is interpreted as UTC.
     *
     * @since 1.0.0
     *
     * @param  string  $expirationTime Expiration time in any of the supported formats.
     * @return int Unix timestamp (UTC), or 0 when the value is empty or could not be parsed.
     */
    private static function parseExpirationTime(string $expirationTime): int {
        if ($expirationTime === '' || $expirationTime === '0') {
            return 0;
        }

        if (\ctype_digit($expirationTime)) {
            $timestamp = (int) $expirationTime;
            return $timestamp > 0 ? $timestamp : 0;
        }

        try {
            $utc = new \DateTimeZone('UTC');

            if (\str_ends_with($expirationTime, 'Z')) {
                $baseTime = \substr($expirationTime, 0, -1);
                $dateTime = \DateTime::createFromFormat('Y-m-d\TH:i:s', $baseTime, $utc);
                if ($dateTime !== false) {
                    return (int) $dateTime->format('U');
                }
            }

            $dateTime = \DateTime::createFromFormat(\DateTime::ATOM, $expirationTime, $utc);
            if ($dateTime !== false) {
                $dateTime->setTimezone($utc);
                return (int) $dateTime->format('U');
            }

            $dateTime = new \DateTime($expirationTime, $utc);
            $dateTime->setTimezone($utc);
            return (int) $dateTime->format('U');
        } catch (\Throwable) {
            // intentionally silent: an expiry that does not parse reads as 0 (already expired), so the
            // refresh job attempts a refresh and the provider's answer replaces the bad value.
            return 0;
        }
    }

    /**
     * Format a duration in seconds as a human-readable string.
     *
     * @since 1.0.0
     *
     * @param  int  $seconds Duration in seconds.
     * @return string Human-readable duration, for example `2 hours, 5 minutes`.
     */
    private static function formatSeconds(int $seconds): string {
        if ($seconds < 60) {
            return $seconds . ' seconds';
        }

        if ($seconds < 3600) {
            $minutes = (int) ($seconds / 60);
            return $minutes . ' minute' . ($minutes !== 1 ? 's' : '');
        }

        if ($seconds < 86400) {
            $hours     = (int) ($seconds / 3600);
            $remaining = $seconds % 3600;
            $minutes   = (int) ($remaining / 60);

            $result = $hours . ' hour' . ($hours !== 1 ? 's' : '');

            if ($minutes > 0) {
                $result .= ', ' . $minutes . ' minute' . ($minutes !== 1 ? 's' : '');
            }

            return $result;
        }

        $days = (int) ($seconds / 86400);
        return $days . ' day' . ($days !== 1 ? 's' : '');
    }
}
