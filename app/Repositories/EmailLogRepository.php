<?php
/**
 * Data access for sent-email log entries: listing, filtering, and reporting.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Repositories;

use BooleanSmtp\Core\Database\Drivers\DriverInterface;
use BooleanSmtp\Core\Database\Orm\QueryBuilder;
use BooleanSmtp\Core\Pagination\LengthAwarePaginator;
use BooleanSmtp\Core\Support\Collection;
use BooleanSmtp\Models\EmailLog;
use BooleanSmtp\Support\ReportPeriod;
use function BooleanSmtp\Core\app;

/**
 * Queries the `boolean_smtp_email_logs` table for the Email Logs admin screen and the
 * dashboard reporting widgets.
 *
 * @since 1.0.0
 */
class EmailLogRepository
{
    /**
     * Rows deleted per statement by {@see cleanup()}.
     *
     * @since 1.0.0
     * @var int
     */
    public const CLEANUP_BATCH = 1000;

    /**
     * Upper bound on batches per {@see cleanup()} call.
     *
     * @since 1.0.0
     * @var int
     */
    public const CLEANUP_MAX_BATCHES = 50;

    /**
     * Check whether the email logs table has been created by its migration.
     *
     * @since 1.0.0
     *
     * @return bool True when the `boolean_smtp_email_logs` table exists.
     */
    protected function emailLogsTableExists(): bool
    {
        return app(DriverInterface::class)->tableExists('boolean_smtp_email_logs');
    }

    /**
     * Get a page of email logs, optionally filtered.
     *
     * Supported `$filters` keys: `status` (a single status or a comma-separated list,
     * matched with an `IN (...)` clause), `provider` (exact match), `search` (matched with
     * `LIKE` against the `to`, `subject`, and `message_id` columns), `date_from` and
     * `date_to` (inclusive range on `created_at`). Results are ordered by
     * `COALESCE(last_attempted_at, created_at)` descending, most recent activity first.
     * Returns an empty page without querying when the email logs table does not exist yet.
     *
     * @since 1.0.0
     *
     * @param  int                   $perPage Number of logs per page.
     * @param  int                   $page    1-based page number.
     * @param  array<string, mixed>  $filters Filter criteria; see above.
     * @return array{
     *     data: list<array<string, mixed>>,
     *     meta: array{current_page:int, last_page:int, per_page:int, total:int, from:int|null, to:int|null}
     * }
     */
    public function paginate(int $perPage = 25, int $page = 1, array $filters = []): array
    {
        if (!$this->emailLogsTableExists()) {
            return (new LengthAwarePaginator(new Collection(), 0, $perPage, $page))->toArray();
        }

        return $this->filteredQuery($filters)
            ->orderByRaw('COALESCE(last_attempted_at, created_at) DESC')
            ->paginate($perPage, $page)
            ->toArray();
    }

    /**
     * Get the IDs of every log matching the given filters, without pagination.
     *
     * Accepts the same `$filters` keys as {@see paginate()}. Used for bulk operations
     * (such as bulk delete) that need every matching ID rather than one page of rows.
     * Returns an empty array when the email logs table does not exist yet.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $filters Filter criteria; see {@see paginate()}.
     * @return list<int> Matching log IDs.
     */
    public function getIdsByFilters(array $filters = []): array
    {
        if (!$this->emailLogsTableExists()) {
            return [];
        }

        return $this->filteredQuery($filters)
            ->select(['id'])
            ->get()
            ->map(static fn (EmailLog $log): int => (int) $log->id)
            ->all();
    }

    /**
     * Build the list query for a set of filters.
     *
     * The `search` filter is one OR group over three columns with the term treated as data
     * (the builder escapes LIKE's wildcards); every other filter is a plain where clause.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $filters Filter criteria; see {@see paginate()}.
     * @return QueryBuilder
     */
    private function filteredQuery(array $filters): QueryBuilder
    {
        $query = EmailLog::query();

        if (!empty($filters['status'])) {
            $statuses = array_values(array_filter(array_map('trim', explode(',', (string) $filters['status']))));
            if (count($statuses) > 1) {
                $query->whereIn('status', $statuses);
            } elseif ($statuses !== []) {
                $query->where('status', $statuses[0]);
            }
        }

        if (!empty($filters['provider'])) {
            $query->where('provider', $filters['provider']);
        }

        if (!empty($filters['search'])) {
            $term = (string) $filters['search'];
            $query->where(static function (QueryBuilder $q) use ($term): void {
                $q->whereLike('to', $term)
                    ->orWhereLike('subject', $term)
                    ->orWhereLike('message_id', $term);
            });
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query;
    }

    /**
     * Find an email log by ID.
     *
     * @since 1.0.0
     *
     * @param  int $id Email log ID.
     * @return EmailLog|null The log, or null when no log matches the ID.
     */
    public function find(int $id): ?EmailLog
    {
        return EmailLog::find($id);
    }

    /**
     * Find an email log by ID.
     *
     * @since 1.0.0
     *
     * @param  int $id Email log ID.
     * @return EmailLog
     *
     * @throws \RuntimeException When no log matches the ID.
     */
    public function findOrFail(int $id): EmailLog
    {
        return EmailLog::findOrFail($id);
    }

    /**
     * Find the email log a provider message id belongs to.
     *
     * @since 1.0.0
     *
     * @param  string $messageId Provider message id stored on the log when the message was sent.
     * @return EmailLog|null The log, or null when no log carries the id.
     */
    public function findByMessageId(string $messageId): ?EmailLog
    {
        if ($messageId === '') {
            return null;
        }

        return EmailLog::query()->where('message_id', $messageId)->first();
    }

    /**
     * Create a new email log entry.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $data Log attributes.
     * @return EmailLog The created log.
     */
    public function create(array $data): EmailLog
    {
        return EmailLog::create($data);
    }

    /**
     * Delete an email log entry.
     *
     * @since 1.0.0
     *
     * @param  int $id Email log ID.
     *
     * @throws \RuntimeException When no log matches the ID.
     */
    public function delete(int $id): void
    {
        $log = $this->findOrFail($id);
        $log->delete();
    }

    /**
     * Get send/delivery/failure totals for a reporting period, with trend versus the
     * preceding period of equal length.
     *
     * Returns all-zero totals without querying when the email logs table does not exist
     * yet. A trend is 100 when the current count is positive and the previous count was
     * zero, and 0 when both are zero.
     *
     * @since 1.0.0
     *
     * @param  ReportPeriod $period Reporting period to aggregate.
     * @return array{
     *     total_sent:int, delivered:int, failed:int,
     *     total_sent_trend:float|int, delivered_trend:float|int, failed_trend:float|int
     * } Counts and percentage trends for the period.
     */
    public function getStats(ReportPeriod $period): array
    {
        if (!$this->emailLogsTableExists()) {
            return [
                'total_sent'         => 0,
                'delivered'          => 0,
                'failed'             => 0,
                'total_sent_trend'   => 0,
                'delivered_trend'    => 0,
                'failed_trend'       => 0,
            ];
        }

        $previous = $period->previous();

        $total     = $this->countBetween($period);
        $delivered = $this->countBetween($period, 'delivered');
        $failed    = $this->countBetween($period, 'failed');

        // Previous period of the same length, for trend
        $prevTotal     = $this->countBetween($previous);
        $prevDelivered = $this->countBetween($previous, 'delivered');
        $prevFailed    = $this->countBetween($previous, 'failed');

        /** @var callable(int, int): float|int $calculateTrend */
        $calculateTrend = function (int $current, int $previous) {
            if ($previous === 0) {
                return $current > 0 ? 100 : 0;
            }
            return round((($current - $previous) / $previous) * 100, 1);
        };

        return [
            'total_sent'         => $total,
            'delivered'          => $delivered,
            'failed'             => $failed,
            'total_sent_trend'   => $calculateTrend($total, $prevTotal),
            'delivered_trend'    => $calculateTrend($delivered, $prevDelivered),
            'failed_trend'       => $calculateTrend($failed, $prevFailed),
        ];
    }

    /**
     * Count email logs created within a period, optionally filtered by status.
     *
     * @since 1.0.0
     *
     * @param  ReportPeriod  $period Period to count within (inclusive of both endpoints).
     * @param  string|null   $status Status to filter by, or null to count every status.
     * @return int The matching log count.
     */
    protected function countBetween(ReportPeriod $period, ?string $status = null): int
    {
        $query = EmailLog::query()
            ->where('created_at', '>=', $period->startSql())
            ->where('created_at', '<=', $period->endSql());

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->count();
    }

    /**
     * Build a daily send-count series for a reporting period, for the traffic volume chart.
     *
     * Includes one entry per calendar day in the period, even days with zero sends.
     *
     * @since 1.0.0
     *
     * @param  ReportPeriod $period Reporting period to chart.
     * @return list<array{date:string, label:string, count:int}> One entry per day, in
     *         period order, with `date` as `Y-m-d` and `label` as the short weekday name.
     */
    public function getVolumeChart(ReportPeriod $period): array
    {
        $counts = [];

        if ($this->emailLogsTableExists()) {
            $rows = EmailLog::query()
                ->where('created_at', '>=', $period->startSql())
                ->where('created_at', '<=', $period->endSql())
                ->selectRaw('DATE(created_at) as day, COUNT(*) as count')
                ->groupBy('day')
                ->get()
                ->toArray();

            foreach ($rows as $row) {
                $counts[(string) $row['day']] = (int) $row['count'];
            }
        }

        $chart = [];
        foreach ($period->eachDay() as $day) {
            $date    = $day->format('Y-m-d');
            $chart[] = [
                'date'  => $date,
                'label' => $day->format('D'),
                'count' => $counts[$date] ?? 0,
            ];
        }

        return $chart;
    }

    /**
     * Build a send-count breakdown by day of week and hour, for the activity heatmap.
     *
     * Returns an empty array when the email logs table does not exist yet.
     *
     * @since 1.0.0
     *
     * @param  ReportPeriod $period Reporting period to aggregate.
     * @return list<array{day:int, hour:int, count:int}> One row per non-empty
     *         day/hour combination. `day` follows SQL `DAYOFWEEK()`: 1 = Sunday through
     *         7 = Saturday. `hour` is 0-23.
     */
    public function getActivityHeatmap(ReportPeriod $period): array
    {
        if (!$this->emailLogsTableExists()) {
            return [];
        }

        // SQL DAYOFWEEK(): 1 = Sunday, 2 = Monday, ..., 7 = Saturday.
        return EmailLog::query()
            ->where('created_at', '>=', $period->startSql())
            ->where('created_at', '<=', $period->endSql())
            ->selectRaw('DAYOFWEEK(created_at) as day, HOUR(created_at) as hour, COUNT(*) as count')
            ->groupBy('day', 'hour')
            ->get()
            ->toArray();
    }

    /**
     * The latest messages of one outcome within a period, for the dashboard's activity card.
     *
     * The limit is clamped to between 1 and 10. Returns an empty array when the email logs
     * table does not exist yet.
     *
     * @since 1.0.0
     *
     * @param  string       $status Log status to list: `failed` or `delivered`.
     * @param  ReportPeriod $period Period the messages were created in (inclusive of both endpoints).
     * @param  int          $limit  Requested number of entries; clamped to the range 1-10.
     * @return list<array{id:int, to:string, subject:string, status:string, provider:string|null, created_at:string}> Newest first.
     */
    public function getRecentActivity(string $status, ReportPeriod $period, int $limit = 5): array
    {
        if (!$this->emailLogsTableExists()) {
            return [];
        }

        $limit = max(1, min(10, $limit));
        $result = EmailLog::query()
            ->where('status', $status)
            ->where('created_at', '>=', $period->startSql())
            ->where('created_at', '<=', $period->endSql())
            ->select(['id', 'to', 'subject', 'status', 'provider', 'created_at'])
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->paginate(perPage: $limit, page: 1)
            ->toArray();

        return $result['data'] ?? [];
    }

    /**
     * Whether the queue worker has rows to process: queued messages, or failed messages the retry
     * ladder scheduled (due or not — the worker is armed for the earliest one).
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function hasQueueWork(): bool
    {
        if (!$this->emailLogsTableExists()) {
            return false;
        }

        if (EmailLog::query()->whereNull('reserved_at')->where('status', 'queued')->count() > 0) {
            return true;
        }

        return EmailLog::query()->whereNull('reserved_at')->where('status', 'failed')->whereNotNull('next_attempt_at')->count() > 0;
    }

    /**
     * Delete email logs older than the retention period, in batches.
     *
     * Rows are removed {@see CLEANUP_BATCH} at a time so a first run on a large table never
     * holds one long-running DELETE; at most {@see CLEANUP_MAX_BATCHES} batches run per call and
     * the next scheduled run continues. A retention of 0 keeps everything.
     *
     * @since 1.0.0
     *
     * @param  int $retentionDays Days of logs to keep; 0 disables pruning.
     * @return int The number of deleted logs.
     */
    public function cleanup(int $retentionDays): int
    {
        if ($retentionDays <= 0 || !$this->emailLogsTableExists()) {
            return 0;
        }

        $cutoff  = gmdate('Y-m-d H:i:s', time() - ($retentionDays * 86400));
        $deleted = 0;

        for ($batch = 0; $batch < self::CLEANUP_MAX_BATCHES; $batch++) {
            $ids = EmailLog::query()
                ->select('id')
                ->where('created_at', '<', $cutoff)
                ->orderBy('id', 'ASC')
                ->limit(self::CLEANUP_BATCH)
                ->get()
                ->map(static fn (EmailLog $log): int => (int) $log->id)
                ->all();

            if ($ids === []) {
                break;
            }

            $deleted += EmailLog::query()->whereIn('id', $ids)->delete();

            if (count($ids) < self::CLEANUP_BATCH) {
                break;
            }
        }

        return $deleted;
    }
}
