<?php
/**
 * WP-CLI command that processes the email queue.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Console\Commands;

use BooleanSmtp\Core\Console\Command;
use BooleanSmtp\Jobs\ProcessQueueJob;

/**
 * `wp boolean-smtp queue:work` — send the queued emails now, without waiting for WP-Cron.
 *
 * @since 1.0.0
 */
class QueueWorkCommand extends Command {
    /**
     * @since 1.0.0
     * @var string
     */
    protected string $name = 'queue:work';

    /**
     * @since 1.0.0
     * @var string
     */
    protected string $description = 'Process the BooleanSMTP email queue.';

    /**
     * @since 1.0.0
     * @var array<array<string, mixed>>
     */
    protected array $synopsis = [
        ['type' => 'assoc', 'name' => 'limit', 'description' => 'Maximum number of emails to send in this run.', 'optional' => true, 'default' => 50],
        ['type' => 'flag', 'name' => 'retry', 'description' => 'Also retry emails that failed before.', 'optional' => true],
    ];

    /**
     * @since 1.0.0
     *
     * @param ProcessQueueJob $queue The job that drains the queue.
     */
    public function __construct(private readonly ProcessQueueJob $queue) {}

    /**
     * Send up to `--limit` queued emails, optionally including failed ones.
     *
     * @since 1.0.0
     *
     * @param array<int, string>   $args      Positional arguments; unused.
     * @param array<string, mixed> $assocArgs `limit` (int, default 50) and `retry` (flag).
     */
    public function handle(array $args, array $assocArgs): void {
        $limit = max(1, (int) $this->option($assocArgs, 'limit', 50));
        $retry = $this->hasOption($assocArgs, 'retry');

        $this->info("Processing email queue (limit: {$limit})...");

        $processed = $this->queue->handle($limit, $retry);

        $this->success("Processed {$processed} email(s).");
    }
}
