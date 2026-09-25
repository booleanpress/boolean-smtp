<?php
/**
 * Decides whether, when and through which connection a failed message is retried.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Queue;

use BooleanSmtp\Contracts\Editions\SenderRouterContract;
use BooleanSmtp\Core\Foundation\Application;
use BooleanSmtp\Models\Connection;
use BooleanSmtp\Models\EmailLog;
use BooleanSmtp\Repositories\ConnectionRepository;
use BooleanSmtp\Support\Settings;

/**
 * Multi-connection retrying. When a send fails and the "Retry on other connections" setting is on,
 * the log row is scheduled for the queue worker, which retries it on the first active connection
 * (by priority) that has not been tried yet — the row's `attempts` list is the memory — with a
 * growing delay between attempts and up to `boolean_smtp_max_retry_attempts` retries. When no
 * untried connection or attempt is left the failure is final and the alert channels fire.
 *
 * The worker owns the decision for the rows it processes ({@see beginWorkerAttempt()}); the
 * failure handler consults the ladder only for a send that happened outside the worker.
 *
 * @since 1.0.0
 */
class RetryLadder
{
    /**
     * Retries after the first failed send when nothing filters the number.
     *
     * @since 1.0.0
     * @var int
     */
    public const DEFAULT_MAX_ATTEMPTS = 2;

    /**
     * Delay before the first, second and every later retry, in seconds.
     *
     * @since 1.0.0
     * @var list<int>
     */
    public const BACKOFF_SECONDS = [60, 300, 900];

    /**
     * True while the queue worker sends; the failure handler then leaves the decision to it.
     *
     * @since 1.0.0
     * @var bool
     */
    private bool $workerAttempt = false;

    /**
     * @since 1.0.0
     *
     * @param Settings             $settings    Plugin settings (`auto_retry`).
     * @param ConnectionRepository $connections Active connections in priority order.
     * @param QueueScheduler       $scheduler   Arms the worker for a scheduled retry.
     * @param Application          $app         Container; the sender-routing policy that orders retries is resolved from it on each use.
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly ConnectionRepository $connections,
        private readonly QueueScheduler $scheduler,
        private readonly Application $app,
    ) {
    }

    /**
     * Whether retrying on other connections is switched on.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return (bool) $this->settings->get('auto_retry', true);
    }

    /**
     * How many retries a failed message gets after its first failed send.
     *
     * @since 1.0.0
     *
     * @return int Zero or more.
     */
    public function limit(): int
    {
        /**
         * Filters how many times a failed message is retried on other connections.
         *
         * Applies when "Retry on other connections" is on: after the first failed send the queue
         * worker retries the message on the active connections it has not tried yet, one per
         * attempt, up to this many times. The add-on's "Maximum Attempts" setting is delivered
         * through this filter. Return `0` to disable retries without turning the setting off.
         *
         * @since 1.0.0
         *
         * @param  int $attempts Retries after the first failed send. Default `2`.
         * @return int Retries after the first failed send.
         */
        return max(0, (int) \apply_filters('boolean_smtp_max_retry_attempts', self::DEFAULT_MAX_ATTEMPTS));
    }

    /**
     * The first active connection the message has not been sent through yet, in the order the
     * site's sender-routing policy gives (by priority, then id, unless an add-on reorders it).
     *
     * @since 1.0.0
     *
     * @param  EmailLog $log Log row of the failed message.
     * @return Connection|null `null` when every active connection has been tried.
     */
    public function nextConnectionFor(EmailLog $log): ?Connection
    {
        $tried      = $this->triedConnectionIds($log);
        $candidates = $this->app->make(SenderRouterContract::class)->retryOrder(array_values($this->connections->activeByPriority()->all()), $log);

        foreach ($candidates as $connection) {
            if (!in_array((int) $connection->id, $tried, true)) {
                return $connection;
            }
        }

        return null;
    }

    /**
     * Whether a failed message still has a rung left: the setting is on, the retry limit is not
     * reached and an untried active connection exists.
     *
     * @since 1.0.0
     *
     * @param  EmailLog $log Log row of the failed message, `retries` already counting the attempt that just failed.
     * @return bool
     */
    public function canRetry(EmailLog $log): bool
    {
        if (!$this->isEnabled() || (int) $log->retries >= $this->limit()) {
            return false;
        }

        return $this->nextConnectionFor($log) !== null;
    }

    /**
     * Schedule the next retry of a failed message and arm the worker for it.
     *
     * @since 1.0.0
     *
     * @param  EmailLog|null $log Log row of the failed message; `null` when the send was not logged.
     * @return bool Whether a retry was scheduled. `false` means the failure is final.
     */
    public function scheduleRetry(?EmailLog $log): bool
    {
        if ($log === null || !$this->canRetry($log)) {
            return false;
        }

        $delay = $this->backoff((int) $log->retries);

        $log->update(['next_attempt_at' => gmdate('Y-m-d H:i:s', time() + $delay)]);
        $this->scheduler->arm($delay);

        return true;
    }

    /**
     * Seconds to wait before the next retry, growing with the retries already made.
     *
     * @since 1.0.0
     *
     * @param  int $retries Retries already made for the message.
     * @return int Seconds.
     */
    public function backoff(int $retries): int
    {
        $index = max(0, $retries);

        return self::BACKOFF_SECONDS[$index] ?? self::BACKOFF_SECONDS[count(self::BACKOFF_SECONDS) - 1];
    }

    /**
     * Mark the start of a worker send: the failure handler leaves the retry decision to the worker.
     *
     * @since 1.0.0
     */
    public function beginWorkerAttempt(): void
    {
        $this->workerAttempt = true;
    }

    /**
     * Mark the end of a worker send.
     *
     * @since 1.0.0
     */
    public function endWorkerAttempt(): void
    {
        $this->workerAttempt = false;
    }

    /**
     * Whether the queue worker is sending right now.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isWorkerAttempt(): bool
    {
        return $this->workerAttempt;
    }

    /**
     * Ids of the connections a message has already been sent through.
     *
     * @since 1.0.0
     *
     * @param  EmailLog $log Log row with its `attempts` list.
     * @return list<int>
     */
    private function triedConnectionIds(EmailLog $log): array
    {
        $ids = [];

        foreach (is_array($log->attempts) ? $log->attempts : [] as $attempt) {
            $id = (int) ($attempt['connection_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        if ($log->connection_id !== null && (int) $log->connection_id > 0) {
            $ids[] = (int) $log->connection_id;
        }

        return array_values(array_unique($ids));
    }
}
