<?php
/**
 * WordPress implementation of the schedule adapter contract.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Adapters\WordPress;

use BooleanSmtp\Adapters\Contracts\ScheduleAdapterContract;

/**
 * Schedules and inspects background jobs using WordPress cron functions.
 *
 * @since 1.0.0
 */
final class ScheduleAdapter implements ScheduleAdapterContract {
    /**
     * Custom recurrence intervals registered through registerSchedule(), keyed by name.
     *
     * @since 1.0.0
     * @var array<string, array{interval:int,display:string}>
     */
    private array $customSchedules = [];

    /**
     * Whether the cron_schedules filter has already been registered.
     *
     * @since 1.0.0
     * @var bool
     */
    private bool $filterRegistered = false;

    /**
     * Schedule a recurring WordPress cron event.
     *
     * @since 1.0.0
     *
     * @param int $timestamp Unix timestamp of the first run.
     * @param string $recurrence Name of a registered recurrence interval.
     * @param string $hook Hook to fire on each run.
     * @param array<int, mixed> $args Arguments passed to the hook on each run.
     * @return bool True when the event was scheduled.
     */
    public function scheduleEvent(int $timestamp, string $recurrence, string $hook, array $args = []): bool {
        if (!\function_exists('wp_schedule_event')) {
            return false;
        }

        return \wp_schedule_event($timestamp, $recurrence, $hook, $args) !== false;
    }

    /**
     * Schedule a one-time WordPress cron event.
     *
     * @since 1.0.0
     *
     * @param int $timestamp Unix timestamp when the event should run.
     * @param string $hook Hook to fire when the event runs.
     * @param array<int, mixed> $args Arguments passed to the hook.
     * @return bool True when the event was scheduled.
     */
    public function scheduleSingleEvent(int $timestamp, string $hook, array $args = []): bool {
        if (!\function_exists('wp_schedule_single_event')) {
            return false;
        }

        return \wp_schedule_single_event($timestamp, $hook, $args) !== false;
    }

    /**
     * Clear scheduled events for a hook.
     *
     * @since 1.0.0
     *
     * @param string $hook Hook whose scheduled events should be cleared.
     * @param array<int, mixed> $args Arguments identifying the specific scheduled event to clear.
     * @return int Number of events cleared.
     */
    public function clearScheduledHook(string $hook, array $args = []): int {
        if (!\function_exists('wp_clear_scheduled_hook')) {
            return 0;
        }

        $cleared = \wp_clear_scheduled_hook($hook, $args);
        return \is_int($cleared) ? $cleared : 0;
    }

    /**
     * Return the next scheduled run time for a hook.
     *
     * @since 1.0.0
     *
     * @param string $hook Hook to look up.
     * @param array<int, mixed> $args Arguments identifying the specific scheduled event.
     * @return int|false The next scheduled Unix timestamp, or false when not scheduled.
     */
    public function nextScheduled(string $hook, array $args = []): int | false {
        if (!\function_exists('wp_next_scheduled')) {
            return false;
        }

        return \wp_next_scheduled($hook, $args);
    }

    /**
     * Register a custom recurring schedule interval.
     *
     * Stores the interval and, on first use, registers a "cron_schedules" filter that exposes
     * every interval registered so far to WordPress.
     *
     * @since 1.0.0
     *
     * @param string $name Unique identifier for the interval, referenced by scheduleEvent().
     * @param int $interval Interval length in seconds.
     * @param string $display Human-readable label for the interval.
     */
    public function registerSchedule(string $name, int $interval, string $display): void {
        $this->customSchedules[$name] = [
            'interval' => $interval,
            'display'  => $display
        ];

        if ($this->filterRegistered || !\function_exists('add_filter')) {
            return;
        }

        \add_filter('cron_schedules', function (array $schedules): array {
            foreach ($this->customSchedules as $key => $schedule) {
                $schedules[$key] = [
                    'interval' => $schedule['interval'],
                    'display'  => $schedule['display']
                ];
            }

            return $schedules;
        });

        $this->filterRegistered = true;
    }
}
