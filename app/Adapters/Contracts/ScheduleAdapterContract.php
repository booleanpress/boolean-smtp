<?php
/**
 * Contract for scheduling recurring and one-time jobs.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Adapters\Contracts;

/**
 * Guarantees a way to schedule and inspect background jobs that does not depend on the
 * underlying host application's scheduler.
 *
 * @since 1.0.0
 */
interface ScheduleAdapterContract {
    /**
     * Schedule a recurring event.
     *
     * @since 1.0.0
     *
     * @param  int                $timestamp  Unix timestamp of the first run.
     * @param  string             $recurrence Name of a registered recurrence interval.
     * @param  string             $hook       Hook to fire on each run.
     * @param  array<int, mixed>  $args       Arguments passed to the hook on each run.
     * @return bool True when the event was scheduled.
     */
    public function scheduleEvent(int $timestamp, string $recurrence, string $hook, array $args = []): bool;

    /**
     * Schedule a one-time event.
     *
     * @since 1.0.0
     *
     * @param  int                $timestamp Unix timestamp when the event should run.
     * @param  string             $hook      Hook to fire when the event runs.
     * @param  array<int, mixed>  $args      Arguments passed to the hook.
     * @return bool True when the event was scheduled.
     */
    public function scheduleSingleEvent(int $timestamp, string $hook, array $args = []): bool;

    /**
     * Clear scheduled events for a hook.
     *
     * @since 1.0.0
     *
     * @param  string             $hook Hook whose scheduled events should be cleared.
     * @param  array<int, mixed>  $args Arguments identifying the specific scheduled event to clear.
     * @return int Number of events cleared.
     */
    public function clearScheduledHook(string $hook, array $args = []): int;

    /**
     * Return the next scheduled run time for a hook.
     *
     * @since 1.0.0
     *
     * @param  string             $hook Hook to look up.
     * @param  array<int, mixed>  $args Arguments identifying the specific scheduled event.
     * @return int|false The next scheduled Unix timestamp, or false when not scheduled.
     */
    public function nextScheduled(string $hook, array $args = []): int | false;

    /**
     * Register a custom recurring schedule interval.
     *
     * @since 1.0.0
     *
     * @param  string $name     Unique identifier for the interval, referenced by scheduleEvent().
     * @param  int    $interval Interval length in seconds.
     * @param  string $display  Human-readable label for the interval.
     */
    public function registerSchedule(string $name, int $interval, string $display): void;
}
