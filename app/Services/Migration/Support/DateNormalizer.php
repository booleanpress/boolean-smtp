<?php
/**
 * One converter for every timestamp a source plugin stores, into the UTC `Y-m-d H:i:s` the
 * plugin's own log rows use.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration\Support;

/**
 * The source plugins write their log timestamps in three shapes: a site-local MySQL datetime
 * (`current_time('mysql')`), a site-local Unix timestamp (`current_time('timestamp')`, which is
 * the real time plus the site's UTC offset) and, for one of them, a UTC datetime. Every shape
 * goes through this class so the conversion rule lives in one place and is tested once.
 *
 * @since 1.0.0
 */
final class DateNormalizer
{
    /**
     * @since 1.0.0
     *
     * @param \Closure|null $toUtc   `fn (string $local, string $format): string` — WordPress's `get_gmt_from_date()` unless given, for tests.
     * @param \Closure|null $toLocal `fn (string $utc, string $format): string` — WordPress's `get_date_from_gmt()` unless given, for tests.
     */
    public function __construct(
        private readonly ?\Closure $toUtc = null,
        private readonly ?\Closure $toLocal = null,
    ) {}

    /**
     * A site-local MySQL datetime (`2026-09-22 00:04:17` in the site's timezone) to UTC.
     *
     * Uses WordPress's own conversion, which honours the site's timezone setting at the time of
     * the import; outside WordPress the value is returned as it is.
     *
     * @since 1.0.0
     *
     * @param  string $local Site-local datetime.
     * @return string|null UTC datetime, or null when the input is not a datetime.
     */
    public function fromLocalMysql(string $local): ?string
    {
        $local = trim($local);
        if ($local === '' || str_starts_with($local, '0000-00-00')) {
            return null;
        }
        $convert = $this->toUtc ?? (\function_exists('get_gmt_from_date') ? 'get_gmt_from_date' : null);
        if ($convert === null) {
            return $this->normalizeMysql($local);
        }

        $utc = $convert($local, 'Y-m-d H:i:s');

        return \is_string($utc) && $utc !== '' ? $utc : $this->normalizeMysql($local);
    }

    /**
     * A site-local Unix timestamp — the real time plus the site's UTC offset, which is what
     * `current_time('timestamp')` returns — to UTC.
     *
     * The offset is undone the same way it was added: by formatting the number as if it were UTC
     * and converting that "local" datetime.
     *
     * @since 1.0.0
     *
     * @param  int $localTimestamp Site-local Unix timestamp.
     * @return string|null UTC datetime, or null for a non-positive timestamp.
     */
    public function fromLocalUnix(int $localTimestamp): ?string
    {
        if ($localTimestamp <= 0) {
            return null;
        }

        return $this->fromLocalMysql(\gmdate('Y-m-d H:i:s', $localTimestamp));
    }

    /**
     * A UTC MySQL datetime, normalised.
     *
     * @since 1.0.0
     *
     * @param  string $utc UTC datetime.
     * @return string|null Normalised datetime, or null when the input is not a datetime.
     */
    public function fromUtcMysql(string $utc): ?string
    {
        $utc = trim($utc);
        if ($utc === '' || str_starts_with($utc, '0000-00-00')) {
            return null;
        }

        return $this->normalizeMysql($utc);
    }

    /**
     * A UTC Unix timestamp to the datetime the log table stores.
     *
     * @since 1.0.0
     *
     * @param  int $timestamp UTC Unix timestamp.
     * @return string|null UTC datetime, or null for a non-positive timestamp.
     */
    public function fromUtcUnix(int $timestamp): ?string
    {
        return $timestamp > 0 ? \gmdate('Y-m-d H:i:s', $timestamp) : null;
    }

    /**
     * A UTC datetime expressed in the site's local time, for comparing against a column the
     * source plugin wrote in local time.
     *
     * @since 1.0.0
     *
     * @param  string $utc UTC datetime.
     * @return string Site-local datetime; the input when WordPress is not loaded.
     */
    public function toLocalMysql(string $utc): string
    {
        $convert = $this->toLocal ?? (\function_exists('get_date_from_gmt') ? 'get_date_from_gmt' : null);
        if ($convert === null) {
            return $utc;
        }

        $local = $convert($utc, 'Y-m-d H:i:s');

        return \is_string($local) && $local !== '' ? $local : $utc;
    }

    /**
     * Re-format a datetime string as `Y-m-d H:i:s`.
     *
     * @since 1.0.0
     *
     * @param  string $value Datetime in any shape `strtotime()` reads.
     * @return string|null
     */
    private function normalizeMysql(string $value): ?string
    {
        $ts = strtotime($value . ' UTC');

        return $ts === false ? null : \gmdate('Y-m-d H:i:s', $ts);
    }
}
