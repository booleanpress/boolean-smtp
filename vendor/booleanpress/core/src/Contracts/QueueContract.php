<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Contracts;

use BooleanSmtp\Core\Queue\Job;

/**
 * Queue Contract
 *
 * Provides a standardized interface for queue implementations,
 * allowing different drivers (database, redis, sync, etc.) to be swapped.
 */
interface QueueContract
{
    /**
     * Push a new job onto the queue.
     *
     * @param Job $job
     * @param string|null $queue
     * @return string|int|null The job ID
     */
    public function push(Job $job, ?string $queue = null): string|int|null;

    /**
     * Push a job onto the queue after a delay.
     *
     * @param Job $job
     * @param int $delay Delay in seconds
     * @param string|null $queue
     * @return string|int|null The job ID
     */
    public function later(Job $job, int $delay, ?string $queue = null): string|int|null;

    /**
     * Pop the next job off of the queue.
     *
     * @param string|null $queue
     * @return Job|null
     */
    public function pop(?string $queue = null): ?Job;

    /**
     * Get the size of the queue.
     *
     * @param string|null $queue
     * @return int
     */
    public function size(?string $queue = null): int;

    /**
     * Delete a job from the queue.
     *
     * @param string|int $id
     * @return bool
     */
    public function delete(string|int $id): bool;
}
