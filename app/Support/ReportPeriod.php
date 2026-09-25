<?php

/**
 * Inclusive calendar-day window used to scope dashboard report queries.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Inclusive calendar-day window for dashboard reports.
 *
 * Email log timestamps are stored in UTC, so day boundaries are UTC days. Resolved from `from` /
 * `to` (`Y-m-d`) query parameters, falling back to `days` (a window that ends today). The window
 * is capped at {@see MAX_DAYS}.
 *
 * @since 1.0.0
 */
final class ReportPeriod
{
    /**
     * Default window length, in days, when no `from`/`to`/`days` query parameter is given.
     *
     * @since 1.0.0
     */
    public const DEFAULT_DAYS = 7;

    /**
     * Maximum window length, in days, that a report query may span.
     *
     * @since 1.0.0
     */
    public const MAX_DAYS     = 366;

    /**
     * @since 1.0.0
     *
     * @param DateTimeImmutable $start First day of the window, at 00:00:00 UTC.
     * @param DateTimeImmutable $end   Last day of the window, at 23:59:59 UTC.
     */
    private function __construct(
        public readonly DateTimeImmutable $start,
        public readonly DateTimeImmutable $end,
    ) {}

    /**
     * Resolve a report period from request query parameters.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>    $query Query parameters; reads `from`, `to`, and `days`.
     * @param  DateTimeImmutable|null  $now   Reference "now" for tests; defaults to the current time.
     * @return self
     */
    public static function fromQuery(array $query, ?DateTimeImmutable $now = null): self
    {
        $utc   = new DateTimeZone('UTC');
        $today = ($now ?? new DateTimeImmutable('now', $utc))->setTimezone($utc)->setTime(0, 0, 0);

        $from = self::parseDay($query['from'] ?? null);
        $to   = self::parseDay($query['to'] ?? null);

        if ($from === null && $to === null) {
            $days = (int) ($query['days'] ?? self::DEFAULT_DAYS);
            $days = max(1, min(self::MAX_DAYS, $days));
            $to   = $today;
            $from = $today->modify('-' . ($days - 1) . ' days');
        } else {
            $to   ??= $today;
            $from ??= $to;
            if ($from > $to) {
                [$from, $to] = [$to, $from];
            }
        }

        $earliest = $to->modify('-' . (self::MAX_DAYS - 1) . ' days');
        if ($from < $earliest) {
            $from = $earliest;
        }

        return new self($from, $to->setTime(23, 59, 59));
    }

    /**
     * Number of calendar days in the window.
     *
     * @since 1.0.0
     *
     * @return int Always 1 or greater.
     */
    public function days(): int
    {
        return (int) $this->start->diff($this->end->setTime(0, 0, 0))->days + 1;
    }

    /**
     * Get the window of the same length that ends the day before this one starts.
     *
     * Used to compute period-over-period comparisons on the dashboard.
     *
     * @since 1.0.0
     *
     * @return self
     */
    public function previous(): self
    {
        $end   = $this->start->modify('-1 day')->setTime(23, 59, 59);
        $start = $this->start->modify('-' . $this->days() . ' days');

        return new self($start, $end);
    }

    /**
     * Format the start of the window for a SQL `WHERE` clause.
     *
     * @since 1.0.0
     *
     * @return string `Y-m-d H:i:s` in UTC.
     */
    public function startSql(): string
    {
        return $this->start->format('Y-m-d H:i:s');
    }

    /**
     * Format the end of the window for a SQL `WHERE` clause.
     *
     * @since 1.0.0
     *
     * @return string `Y-m-d H:i:s` in UTC.
     */
    public function endSql(): string
    {
        return $this->end->format('Y-m-d H:i:s');
    }

    /**
     * Midnight of every day in the window, oldest first.
     *
     * @since 1.0.0
     *
     * @return list<DateTimeImmutable>
     */
    public function eachDay(): array
    {
        $days = [];
        for ($i = 0, $n = $this->days(); $i < $n; $i++) {
            $days[] = $this->start->modify('+' . $i . ' days');
        }

        return $days;
    }

    /**
     * Parse a `Y-m-d` query parameter into a UTC midnight instant.
     *
     * @since 1.0.0
     *
     * @param  mixed $value Raw query parameter value.
     * @return DateTimeImmutable|null Null when the value is missing or not a valid `Y-m-d` date.
     */
    private static function parseDay(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));

        return ($day !== false && $day->format('Y-m-d') === $value) ? $day : null;
    }
}
