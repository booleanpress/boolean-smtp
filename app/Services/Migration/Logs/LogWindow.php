<?php
/**
 * Which rows of a source's log an import reads: how far back, and how many at most.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration\Logs;

/**
 * The window follows the retention the user chose: a row older than the retention would be
 * pruned by the nightly job right after the import, so it is not read at all. The cap keeps the
 * newest rows.
 *
 * @since 1.0.0
 */
final class LogWindow
{
    /**
     * @since 1.0.0
     *
     * @param string|null $sinceUtc Oldest UTC datetime (`Y-m-d H:i:s`) to read, null for no lower bound.
     * @param int         $cap      Maximum number of rows, newest first; 0 for no cap.
     */
    public function __construct(
        public readonly ?string $sinceUtc,
        public readonly int $cap,
    ) {}

    /**
     * The window for a retention setting: `retentionDays` days back from now, or unbounded for 0.
     *
     * @since 1.0.0
     *
     * @param  int $retentionDays Log retention in days; 0 keeps logs forever.
     * @param  int $cap           Maximum number of rows.
     * @param  int|null $now      UTC Unix time, for tests.
     * @return self
     */
    public static function forRetention(int $retentionDays, int $cap, ?int $now = null): self
    {
        $now = $now ?? time();
        $since = $retentionDays > 0 ? \gmdate('Y-m-d H:i:s', $now - $retentionDays * 86400) : null;

        return new self($since, max(0, $cap));
    }
}
