<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Console\Commands;

use BooleanSmtp\Core\Console\Command;
use BooleanSmtp\Core\Queue\Worker;

class QueueWorkCommand extends Command
{
    protected string $name = 'queue:work';
    protected string $description = 'Process jobs on the queue';

    public function __construct(?string $name = null)
    {
        if ($name) {
            $this->name = $name;
        }
    }

    protected array $synopsis = [
        [
            'type' => 'assoc',
            'name' => 'queue',
            'description' => 'The queue to process',
            'optional' => true,
            'default' => 'default',
        ],
        [
            'type' => 'assoc',
            'name' => 'memory',
            'description' => 'Memory limit in MB',
            'optional' => true,
            'default' => '128',
        ],
        [
            'type' => 'assoc',
            'name' => 'max-jobs',
            'description' => 'Max jobs before stopping (0=unlimited)',
            'optional' => true,
            'default' => '0',
        ],
        [
            'type' => 'assoc',
            'name' => 'timeout',
            'description' => 'Max runtime in seconds (0=unlimited)',
            'optional' => true,
            'default' => '0',
        ],
        [
            'type' => 'flag',
            'name' => 'once',
            'description' => 'Only process the next job',
            'optional' => true,
        ],
    ];

    public function handle(array $args, array $assocArgs): void
    {
        $queue = $assocArgs['queue'] ?? 'default';
        $once = isset($assocArgs['once']);
        $memoryLimit = (int) ($assocArgs['memory'] ?? 128);
        $maxJobs = (int) ($assocArgs['max-jobs'] ?? 0);
        $timeout = (int) ($assocArgs['timeout'] ?? 0);

        /** @var Worker $worker */
        $worker = $this->app->make(Worker::class);

        $this->info("Starting worker for queue: {$queue}");

        $shouldQuit = false;
        $jobsProcessed = 0;
        $startTime = time();

        // Register signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function () use (&$shouldQuit): void {
                $shouldQuit = true;
            });
            pcntl_signal(SIGINT, function () use (&$shouldQuit): void {
                $shouldQuit = true;
            });
        }

        while (!$shouldQuit) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $processed = $worker->runNextJob('database', $queue);

            if ($processed) {
                $jobsProcessed++;
            }

            if ($once) {
                if ($processed) {
                    $this->success("Processed job.");
                } else {
                    $this->info("No jobs available.");
                }
                break;
            }

            if ($maxJobs > 0 && $jobsProcessed >= $maxJobs) {
                $this->info("Max jobs limit ({$maxJobs}) reached. Stopping.");
                break;
            }

            if ($timeout > 0 && (time() - $startTime) >= $timeout) {
                $this->info("Timeout ({$timeout}s) reached. Stopping.");
                break;
            }

            if (memory_get_usage(true) / 1024 / 1024 >= $memoryLimit) {
                $this->info("Memory limit ({$memoryLimit}MB) reached. Stopping.");
                break;
            }

            if (!$processed) {
                sleep(3);
            }
        }

        if ($shouldQuit) {
            $this->info("Received shutdown signal. Stopping gracefully.");
        }

        $this->info("Worker stopped. Processed {$jobsProcessed} job(s).");
    }
}
