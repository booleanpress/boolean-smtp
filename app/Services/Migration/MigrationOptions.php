<?php
/**
 * What one import run does: assess only or write, connections and/or logs, and where in the
 * log it continues.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration;

/**
 * Built from the REST request or the CLI flags by {@see MigrationScanner::importFrom()}.
 *
 * @since 1.0.0
 */
final class MigrationOptions
{
    /**
     * Default cap on imported log rows; `boolean_smtp_migration_max_log_rows` filters it.
     *
     * @since 1.0.0
     * @var int
     */
    public const DEFAULT_MAX_LOG_ROWS = 5000;

    /**
     * Rows read and written per run when importing logs.
     *
     * @since 1.0.0
     * @var int
     */
    public const CHUNK_SIZE = 200;

    /**
     * @since 1.0.0
     *
     * @param bool     $dryRun            Assess and count only; nothing is written.
     * @param bool     $importConnections Whether to assess/import the connections.
     * @param bool     $importLogs        Whether to import one chunk of the log.
     * @param int      $maxLogRows        Cap on log rows, newest first; 0 for no cap.
     * @param int      $retentionDays     The log retention in days; rows older than it are not read. 0 keeps everything.
     * @param bool     $restartLogs       Forget the stored cursor and start the log import over.
     * @param int      $chunkSize         Log rows per run.
     * @param array<string, string> $resolutions For each source connection (by its key in the source) whose sender the
     *                                    site already uses: `keep` the site's connection or `replace` it. A missing answer is `keep`;
     *                                    the key `*` answers for every connection without its own answer.
     */
    public function __construct(
        public readonly bool $dryRun = false,
        public readonly bool $importConnections = true,
        public readonly bool $importLogs = false,
        public readonly int $maxLogRows = self::DEFAULT_MAX_LOG_ROWS,
        public readonly int $retentionDays = 0,
        public readonly bool $restartLogs = false,
        public readonly int $chunkSize = self::CHUNK_SIZE,
        public readonly array $resolutions = [],
    ) {}

    /**
     * The answer for a source connection whose sender the site already uses.
     *
     * @since 1.0.0
     *
     * @param  string $sourceKey The connection's key inside the source.
     * @return string `replace` or `keep`.
     */
    public function resolutionFor(string $sourceKey): string
    {
        return ($this->resolutions[$sourceKey] ?? $this->resolutions['*'] ?? 'keep') === 'replace' ? 'replace' : 'keep';
    }
}
