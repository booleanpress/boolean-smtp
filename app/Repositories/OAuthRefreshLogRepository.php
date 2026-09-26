<?php
/**
 * Records and queries OAuth token refresh attempts.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Repositories;

use BooleanSmtp\Core\Contracts\LoggerContract;
use BooleanSmtp\Core\Pagination\LengthAwarePaginator;
use BooleanSmtp\Models\Connection;
use BooleanSmtp\Services\OAuth\OAuthRefreshRetryPolicy;

/**
 * OAuth refresh attempts kept on the connection they belong to: the `oauth_refresh_history`
 * column of `boolean_smtp_connections` holds the last {@see MAX_ENTRIES} attempts as a JSON
 * list, newest last. The history lives and dies with its connection and needs no retention job.
 *
 * @since 1.0.0
 */
class OAuthRefreshLogRepository
{
    /**
     * Attempts kept per connection; older ones drop off the front.
     *
     * @since 1.0.0
     * @var int
     */
    public const MAX_ENTRIES = 100;

    /**
     * @since 1.0.0
     *
     * @param LoggerContract $logger Receives a warning when an attempt cannot be recorded.
     */
    public function __construct(private readonly LoggerContract $logger)
    {
    }

    /**
     * Record one refresh attempt on its connection.
     *
     * A failed attempt below the third schedules `next_retry_at` from
     * {@see OAuthRefreshRetryPolicy::calculateBackoff()}.
     *
     * @since 1.0.0
     *
     * @param  int         $connectionId  Connection the token belongs to.
     * @param  string      $provider      OAuth provider (`google`, `microsoft`).
     * @param  string      $status        `success` or `failed`.
     * @param  string|null $errorCode     Provider or classifier error code on failure.
     * @param  string|null $errorMessage  Human-readable failure detail.
     * @param  int|null    $responseTms   Provider response time in milliseconds.
     * @param  int         $attemptNumber 1-based attempt number within one refresh cycle.
     * @return bool True when the attempt was written; false when the connection does not exist.
     */
    public function log(
        int $connectionId,
        string $provider,
        string $status,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        ?int $responseTms = null,
        int $attemptNumber = 1
    ): bool {
        $now         = \gmdate('Y-m-d H:i:s');
        $nextRetryAt = null;

        if ($status !== 'success' && $attemptNumber < 3) {
            $backoffMs   = OAuthRefreshRetryPolicy::calculateBackoff($attemptNumber + 1);
            $nextRetryAt = \gmdate('Y-m-d H:i:s', \time() + (int) ($backoffMs / 1000));
        }

        try {
            $connection = Connection::query()->where('id', $connectionId)->first();
            if ($connection === null) {
                return false;
            }

            $entries = $this->entries($connection);
            $nextId  = 1;
            foreach ($entries as $entry) {
                $nextId = max($nextId, (int) ($entry['id'] ?? 0) + 1);
            }

            $entries[] = [
                'id'               => $nextId,
                'connection_id'    => $connectionId,
                'provider'         => \trim($provider),
                'status'           => \trim($status),
                'error_code'       => $errorCode !== null ? \trim($errorCode) : null,
                'error_message'    => $errorMessage !== null ? \trim($errorMessage) : null,
                'attempted_at'     => $now,
                'next_retry_at'    => $nextRetryAt,
                'attempt_number'   => $attemptNumber,
                'response_time_ms' => $responseTms,
                'created_at'       => $now,
            ];

            if (count($entries) > self::MAX_ENTRIES) {
                $entries = array_slice($entries, -self::MAX_ENTRIES);
            }

            return $connection->update(['oauth_refresh_history' => array_values($entries)]);
        } catch (\Throwable $e) {
            $this->logger->error('Recording the OAuth refresh attempt failed: ' . $e->getMessage(), ['connection_id' => $connectionId]);

            return false;
        }
    }

    /**
     * Page through a connection's attempts, newest first.
     *
     * @since 1.0.0
     *
     * @param  int $connectionId Connection identifier.
     * @param  int $perPage      Rows per page (at least 1).
     * @param  int $page         1-based page number.
     * @return LengthAwarePaginator Items are arrays shaped for the REST history endpoint.
     */
    public function paginate(int $connectionId, int $perPage = 20, int $page = 1): LengthAwarePaginator
    {
        $perPage = \max(1, $perPage);
        $page    = \max(1, $page);
        $entries = array_reverse($this->entriesFor($connectionId));

        $items = array_map(static fn (array $item): array => [
            'id'               => (int) ($item['id'] ?? 0),
            'connection_id'    => (int) ($item['connection_id'] ?? $connectionId),
            'provider'         => (string) ($item['provider'] ?? ''),
            'status'           => (string) ($item['status'] ?? ''),
            'error_code'       => (string) ($item['error_code'] ?? ''),
            'error_message'    => (string) ($item['error_message'] ?? ''),
            'attempted_at'     => (string) ($item['attempted_at'] ?? ''),
            'attempt_number'   => (int) ($item['attempt_number'] ?? 1),
            'response_time_ms' => isset($item['response_time_ms']) ? (int) $item['response_time_ms'] : null,
        ], array_slice($entries, ($page - 1) * $perPage, $perPage));

        return new LengthAwarePaginator($items, count($entries), $perPage, $page);
    }

    /**
     * The most recent attempts for a connection.
     *
     * @since 1.0.0
     *
     * @param  int $connectionId Connection identifier.
     * @param  int $limit        Number of attempts, clamped to 1–100.
     * @return array<int, array<string, mixed>>
     */
    public function getRecentAttempts(int $connectionId, int $limit = 20): array
    {
        return $this->paginate($connectionId, \max(1, \min($limit, 100)), 1)->items()->all();
    }

    /**
     * Success rate, average response time and the top error codes for a period.
     *
     * @since 1.0.0
     *
     * @param  int    $connectionId Connection identifier.
     * @param  string $period       `7d` (default), `30d` or `90d`.
     * @return array{period: string, total_attempts: int, successful: int, failed: int, success_rate: int, avg_response_time_ms: int, top_errors: list<array{error_code: string, error_count: int}>}
     */
    public function getStatistics(int $connectionId, string $period = '7d'): array
    {
        $periodDays = match ($period) {
            '30d'   => 30,
            '90d'   => 90,
            default => 7,
        };

        $cutoff = \gmdate('Y-m-d H:i:s', \time() - ($periodDays * 86400));
        $rows   = array_values(array_filter(
            $this->entriesFor($connectionId),
            static fn (array $entry): bool => (string) ($entry['created_at'] ?? '') >= $cutoff
        ));

        $totalAttempts = count($rows);
        $successful    = 0;
        $errorCounts   = [];
        $responseTotal = 0;
        $responseCount = 0;

        foreach ($rows as $row) {
            if (($row['status'] ?? '') === 'success') {
                ++$successful;
            }
            if (isset($row['response_time_ms']) && \is_numeric($row['response_time_ms'])) {
                $responseTotal += (int) $row['response_time_ms'];
                ++$responseCount;
            }
            $code = (string) ($row['error_code'] ?? '');
            if ($code !== '') {
                $errorCounts[$code] = ($errorCounts[$code] ?? 0) + 1;
            }
        }

        \arsort($errorCounts);
        $topErrors = [];
        foreach (\array_slice($errorCounts, 0, 5, true) as $code => $count) {
            $topErrors[] = ['error_code' => (string) $code, 'error_count' => $count];
        }

        return [
            'period'               => $period,
            'total_attempts'       => $totalAttempts,
            'successful'           => $successful,
            'failed'               => $totalAttempts - $successful,
            'success_rate'         => $totalAttempts > 0 ? (int) (($successful / $totalAttempts) * 100) : 0,
            'avg_response_time_ms' => $responseCount > 0 ? (int) \round($responseTotal / $responseCount) : 0,
            'top_errors'           => $topErrors,
        ];
    }

    /**
     * The stored attempts of a connection, oldest first.
     *
     * @since 1.0.0
     *
     * @param  int $connectionId Connection identifier.
     * @return array<int, array<string, mixed>>
     */
    private function entriesFor(int $connectionId): array
    {
        $connection = Connection::query()->where('id', $connectionId)->first();

        return $connection === null ? [] : $this->entries($connection);
    }

    /**
     * The attempts held on a connection row.
     *
     * @since 1.0.0
     *
     * @param  Connection $connection Connection row.
     * @return array<int, array<string, mixed>>
     */
    private function entries(Connection $connection): array
    {
        $history = $connection->oauth_refresh_history;

        return is_array($history) ? array_values(array_filter($history, 'is_array')) : [];
    }
}
