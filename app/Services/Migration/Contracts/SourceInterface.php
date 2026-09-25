<?php
/**
 * Contract for reading one other SMTP plugin's connections and email log.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration\Contracts;

use BooleanSmtp\Services\Migration\Canonical\CanonicalConnection;
use BooleanSmtp\Services\Migration\Canonical\CanonicalEmailLog;
use BooleanSmtp\Services\Migration\Logs\LogAvailability;
use BooleanSmtp\Services\Migration\Logs\LogWindow;
use BooleanSmtp\Services\Migration\SourceContext;

/**
 * One implementation per source plugin. A source reads only; it never writes the other plugin's
 * options or tables, decodes credentials in memory and maps everything to the plugin's own
 * driver ids, setting keys, statuses and UTC timestamps.
 *
 * @since 1.0.0
 */
interface SourceInterface
{
    /**
     * Stable source id, e.g. `fluent-smtp`.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function id(): string;

    /**
     * The source plugin's display name.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function name(): string;

    /**
     * Whether the source's settings are present on this site (installed or left behind).
     *
     * @since 1.0.0
     *
     * @param  SourceContext $context Site access.
     * @return bool
     */
    public function isAvailable(SourceContext $context): bool;

    /**
     * Every connection the source holds.
     *
     * @since 1.0.0
     *
     * @param  SourceContext $context Site access.
     * @return list<CanonicalConnection>
     */
    public function extractConnections(SourceContext $context): array;

    /**
     * Whether an email log exists to import, with the reason when it does not.
     *
     * @since 1.0.0
     *
     * @param  SourceContext $context Site access.
     * @return LogAvailability
     */
    public function logsAvailable(SourceContext $context): LogAvailability;

    /**
     * Rows inside the window, before the cap.
     *
     * @since 1.0.0
     *
     * @param  SourceContext $context Site access.
     * @param  LogWindow     $window  Date bound.
     * @return int
     */
    public function countLogs(SourceContext $context, LogWindow $window): int;

    /**
     * The id of the oldest row the import starts from: the cap-th newest row inside the window,
     * or the oldest row inside it when there are fewer.
     *
     * @since 1.0.0
     *
     * @param  SourceContext $context Site access.
     * @param  LogWindow     $window  Date bound and cap.
     * @return int|null Null when the window holds no rows.
     */
    public function logStartId(SourceContext $context, LogWindow $window): ?int;

    /**
     * The next chunk of rows: inside the window, with an id above `$afterId`, ascending.
     *
     * @since 1.0.0
     *
     * @param  SourceContext $context Site access.
     * @param  LogWindow     $window  Date bound.
     * @param  int           $afterId Last id already imported (or the start id minus one).
     * @param  int           $limit   Chunk size.
     * @return list<CanonicalEmailLog>
     */
    public function extractLogs(SourceContext $context, LogWindow $window, int $afterId, int $limit): array;

    /**
     * Global values the source holds that become suggestions on the Review step.
     *
     * @since 1.0.0
     *
     * @param  SourceContext $context Site access.
     * @return array{retention_days?: int}
     */
    public function suggestions(SourceContext $context): array;
}
