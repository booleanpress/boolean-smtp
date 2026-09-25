<?php
/**
 * Arms the queue worker on demand and answers how much work one run takes.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Queue;

use BooleanSmtp\Adapters\Contracts\ScheduleAdapterContract;
use BooleanSmtp\Jobs\ProcessQueueJob;

/**
 * The queue has no recurring schedule: a single `boolean_smtp_process_queue` event is armed when a
 * message is queued or a retry is due, and the worker re-arms itself while rows remain. A site that
 * never queues anything therefore never runs a cron event for it.
 *
 * @since 1.0.0
 */
class QueueScheduler
{
    /**
     * Seconds from now the worker is armed for when nothing asks for a specific delay.
     *
     * @since 1.0.0
     * @var int
     */
    public const DEFAULT_DELAY_SECONDS = 60;

    /**
     * Messages one worker run sends when nothing filters the number.
     *
     * @since 1.0.0
     * @var int
     */
    public const DEFAULT_BATCH_SIZE = 50;

    /**
     * @since 1.0.0
     *
     * @param ScheduleAdapterContract $scheduler WP-Cron access.
     */
    public function __construct(private readonly ScheduleAdapterContract $scheduler)
    {
    }

    /**
     * Arm one worker run, unless one is already pending.
     *
     * @since 1.0.0
     *
     * @param  int $delaySeconds Seconds from now; defaults to {@see DEFAULT_DELAY_SECONDS}.
     * @return bool Whether an event was scheduled by this call.
     */
    public function arm(int $delaySeconds = self::DEFAULT_DELAY_SECONDS): bool
    {
        if ($this->scheduler->nextScheduled(ProcessQueueJob::HOOK) !== false) {
            return false;
        }

        return $this->scheduler->scheduleSingleEvent(time() + max(0, $delaySeconds), ProcessQueueJob::HOOK);
    }

    /**
     * Whether a worker run is pending.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isArmed(): bool
    {
        return $this->scheduler->nextScheduled(ProcessQueueJob::HOOK) !== false;
    }

    /**
     * How many messages one worker run sends.
     *
     * @since 1.0.0
     *
     * @return int At least 1.
     */
    public function batchSize(): int
    {
        /**
         * Filters how many queued messages one worker run sends.
         *
         * The worker runs from WP-Cron when a message is queued or a retry is due, and from
         * `wp boolean-smtp queue:work`; each run sends up to this many messages, oldest first,
         * and arms another run while rows remain.
         *
         * @since 1.0.0
         *
         * @param  int $size Messages per run. Default `50`.
         * @return int Messages per run.
         */
        return max(1, (int) \apply_filters('boolean_smtp_queue_batch_size', self::DEFAULT_BATCH_SIZE));
    }
}
