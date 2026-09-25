<?php
/**
 * Daily job that enforces the plugin's retention settings.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Jobs;

use BooleanSmtp\Adapters\Contracts\ScheduleAdapterContract;
use BooleanSmtp\Core\Log\LogManager;
use BooleanSmtp\Repositories\DebugLogRepository;
use BooleanSmtp\Repositories\EmailLogRepository;
use BooleanSmtp\Support\Settings;

/**
 * Removes email logs older than the `log_retention_days` setting, as filtered by
 * `boolean_smtp_log_retention_days` (0 keeps them forever), and
 * debug session files older than {@see DebugLogRepository::RETENTION_DAYS} days, and — through the
 * framework's log manager — log files past their channel's retention and the oldest files past the
 * log directory's size ceiling (the manager also prunes whenever a channel starts a file). Runs once a
 * day on the `boolean_smtp_prune_logs` WP-Cron event; email logs go in batches so a large table
 * is never locked by one statement.
 *
 * Resolve it from the container; every collaborator is injected.
 *
 * @since 1.0.0
 */
class PruneLogsJob {
    /**
     * WP-Cron hook the job runs on.
     *
     * @since 1.0.0
     * @var string
     */
    public const HOOK = 'boolean_smtp_prune_logs';

    /**
     * @since 1.0.0
     *
     * @param Settings        $settings    Plugin settings (`log_retention_days`).
     * @param ScheduleAdapterContract   $scheduler   WP-Cron access.
     * @param EmailLogRepository        $emailLogs   Email log storage.
     * @param DebugLogRepository        $debugLogs   Debug session files.
     * @param LogManager                $logFiles    The plugin's log channels and their files.
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly ScheduleAdapterContract $scheduler,
        private readonly EmailLogRepository $emailLogs,
        private readonly DebugLogRepository $debugLogs,
        private readonly LogManager $logFiles,
    ) {}

    /**
     * Ensure the daily `boolean_smtp_prune_logs` event is scheduled.
     *
     * Called on WordPress `init` so a missing schedule (a fresh install, or one where WP-Cron
     * lost the event) self-heals. Does nothing when an event is already scheduled.
     *
     * @since 1.0.0
     */
    public function ensureScheduled(): void {
        if ($this->scheduler->nextScheduled(self::HOOK) !== false) {
            return;
        }

        $this->scheduler->scheduleEvent(\time() + 300, 'daily', self::HOOK);
    }

    /**
     * Prune every store according to its retention rule.
     *
     * @since 1.0.0
     *
     * @return array{email_logs: int, debug_logs: int, log_files: int} Rows and files deleted per store.
     */
    public function handle(): array {
        /**
         * Filters the number of days email logs are kept before the daily cleanup removes them.
         *
         * The value comes from the Settings screen (7 days to 1 year, default 30). Return `0` to
         * keep logs forever, or any number of days the screen does not offer.
         *
         * @since 1.0.0
         *
         * @param  int $days Days to keep email logs. `0` disables pruning.
         * @return int Days to keep email logs.
         */
        $retentionDays = (int) \apply_filters('boolean_smtp_log_retention_days', (int) $this->settings->get('log_retention_days', 30));

        return [
            'email_logs' => $this->emailLogs->cleanup($retentionDays),
            'debug_logs' => $this->debugLogs->pruneExpired(),
            'log_files'  => $this->logFiles->prune(),
        ];
    }
}
