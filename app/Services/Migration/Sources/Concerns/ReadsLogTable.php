<?php
/**
 * The keyset reader every source with a log table uses: count inside the window, find the
 * start row under the cap, read the next chunk ascending.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration\Sources\Concerns;

use BooleanSmtp\Services\Migration\Canonical\CanonicalEmailLog;
use BooleanSmtp\Services\Migration\Logs\LogAvailability;
use BooleanSmtp\Services\Migration\Logs\LogWindow;
use BooleanSmtp\Services\Migration\SourceContext;

/**
 * A source names its table, id and date columns, says which shape the date column has (the
 * `DATE_*` constants of the base class), lists the columns the mapper needs and maps one row.
 * Integers are inlined after casting; only the date bound is bound.
 *
 * @since 1.0.0
 */
trait ReadsLogTable
{
    /**
     * The columns of {@see logColumns()} this site's table really has, read once per run.
     *
     * @since 1.0.0
     * @var list<string>|null
     */
    private ?array $availableLogColumns = null;

    /**
     * The log table, without the site prefix.
     *
     * @since 1.0.0
     *
     * @return string
     */
    abstract protected function logTable(): string;

    /**
     * The column holding the row's timestamp.
     *
     * @since 1.0.0
     *
     * @return string
     */
    abstract protected function logDateColumn(): string;

    /**
     * Which shape the date column has (one of the base class's `DATE_*` constants).
     *
     * @since 1.0.0
     *
     * @return string
     */
    abstract protected function logDateKind(): string;

    /**
     * The columns the mapper reads.
     *
     * @since 1.0.0
     *
     * @return list<string>
     */
    abstract protected function logColumns(): array;

    /**
     * Map one row of the table.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $row The row.
     * @return CanonicalEmailLog
     */
    abstract protected function mapLogRow(array $row): CanonicalEmailLog;

    /**
     * The primary-key column.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected function logIdColumn(): string
    {
        return 'id';
    }

    /**
     * Whether an email log exists to import, with the reason when it does not.
     *
     * @since 1.0.0
     *
     * @param  SourceContext $context Site access.
     * @return LogAvailability
     */
    public function logsAvailable(SourceContext $context): LogAvailability
    {
        return $context->tableExists($this->logTable()) ? LogAvailability::yes() : LogAvailability::no($this->noLogReason());
    }

    /**
     * Rows inside the window, before the cap.
     *
     * @since 1.0.0
     *
     * @param  SourceContext $context Site access.
     * @param  LogWindow     $window Date bound and cap.
     * @return int
     */
    public function countLogs(SourceContext $context, LogWindow $window): int
    {
        if (! $context->tableExists($this->logTable())) {
            return 0;
        }
        [$where, $bindings] = $this->windowClause($window);

        return (int) $context->selectVar("SELECT COUNT(*) FROM {$this->quotedLogTable($context)} {$where}", $bindings);
    }

    /**
     * The id of the oldest row the import starts from: the cap-th newest row inside the window, or the oldest row inside it when there are fewer.
     *
     * @since 1.0.0
     *
     * @param  SourceContext $context Site access.
     * @param  LogWindow     $window Date bound and cap.
     * @return int|null Null when the window holds no rows.
     */
    public function logStartId(SourceContext $context, LogWindow $window): ?int
    {
        if (! $context->tableExists($this->logTable())) {
            return null;
        }
        [$where, $bindings] = $this->windowClause($window);
        $id    = '`' . $this->logIdColumn() . '`';
        $table = $this->quotedLogTable($context);

        if ($window->cap > 0) {
            $offset = $window->cap - 1;
            $capped = $context->selectVar("SELECT {$id} FROM {$table} {$where} ORDER BY {$id} DESC LIMIT 1 OFFSET {$offset}", $bindings);
            if ($capped !== null && $capped !== false && $capped !== '') {
                return (int) $capped;
            }
        }
        $oldest = $context->selectVar("SELECT MIN({$id}) FROM {$table} {$where}", $bindings);

        return $oldest === null || $oldest === false || $oldest === '' ? null : (int) $oldest;
    }

    /**
     * The next chunk of rows: inside the window, with an id above `$afterId`, ascending.
     *
     * @since 1.0.0
     *
     * @param  SourceContext $context Site access.
     * @param  LogWindow     $window Date bound and cap.
     * @param  int           $afterId Last id already imported (or the start id minus one).
     * @param  int           $limit Chunk size.
     * @return list<CanonicalEmailLog>
     */
    public function extractLogs(SourceContext $context, LogWindow $window, int $afterId, int $limit): array
    {
        if ($limit <= 0 || ! $context->tableExists($this->logTable())) {
            return [];
        }
        [$where, $bindings] = $this->windowClause($window);
        $id      = '`' . $this->logIdColumn() . '`';
        $columns = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $this->presentLogColumns($context)));
        $where   = $where === '' ? "WHERE {$id} > {$afterId}" : "{$where} AND {$id} > {$afterId}";

        $rows = $context->select("SELECT {$columns} FROM {$this->quotedLogTable($context)} {$where} ORDER BY {$id} ASC LIMIT {$limit}", $bindings);
        $this->beforeChunk($context, $rows);

        return array_map(fn (array $row): CanonicalEmailLog => $this->mapLogRow($row), $rows);
    }

    /**
     * The columns to select: the ones the mapper wants, minus any this site's table does not
     * have (an older edition of the source plugin, or its free one).
     *
     * @since 1.0.0
     *
     * @param  SourceContext $context Site access.
     * @return list<string>
     */
    private function presentLogColumns(SourceContext $context): array
    {
        if ($this->availableLogColumns === null) {
            $present                   = array_map('strtolower', $context->columns($this->logTable()));
            $this->availableLogColumns = array_values(array_filter(
                $this->logColumns(),
                static fn (string $column): bool => \in_array(strtolower($column), $present, true)
            ));
        }

        return $this->availableLogColumns;
    }

    /**
     * Called with a chunk's rows before they are mapped, so a source can read in one query what
     * every row of that chunk needs (attachment names, for instance).
     *
     * @since 1.0.0
     *
     * @param  SourceContext              $context Site access.
     * @param  list<array<string, mixed>> $rows    The chunk's rows.
     * @return void
     */
    protected function beforeChunk(SourceContext $context, array $rows): void {}

    /**
     * The `WHERE` clause and bindings for the window's lower bound, in the date column's shape.
     *
     * @since 1.0.0
     *
     * @param  LogWindow $window The window.
     * @return array{0: string, 1: list<mixed>}
     */
    protected function windowClause(LogWindow $window): array
    {
        if ($window->sinceUtc === null) {
            return ['', []];
        }
        $column = '`' . $this->logDateColumn() . '`';

        return match ($this->logDateKind()) {
            self::DATE_LOCAL_UNIX  => ["WHERE {$column} >= " . (int) strtotime($this->dates->toLocalMysql($window->sinceUtc) . ' UTC'), []],
            self::DATE_LOCAL_MYSQL => ["WHERE {$column} >= %s", [$this->dates->toLocalMysql($window->sinceUtc)]],
            default                => ["WHERE {$column} >= %s", [$window->sinceUtc]],
        };
    }

    /**
     * Convert a row's timestamp in the column's shape to UTC.
     *
     * @since 1.0.0
     *
     * @param  mixed $value Column value.
     * @return string|null
     */
    protected function rowDate(mixed $value): ?string
    {
        return match ($this->logDateKind()) {
            self::DATE_LOCAL_UNIX  => $this->dates->fromLocalUnix((int) $value),
            self::DATE_LOCAL_MYSQL => $this->dates->fromLocalMysql((string) $value),
            default                => $this->dates->fromUtcMysql((string) $value),
        };
    }

    /**
     * The prefixed log table, quoted.
     *
     * @since 1.0.0
     *
     * @param  SourceContext $context Site access.
     * @return string
     */
    private function quotedLogTable(SourceContext $context): string
    {
        return '`' . $context->table($this->logTable()) . '`';
    }
}
