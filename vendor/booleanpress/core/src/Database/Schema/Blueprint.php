<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Database\Schema;

/**
 * Blueprint
 *
 * Provides a fluent interface for defining table columns and indexes.
 */
class Blueprint
{
    /**
     * The table name.
     */
    protected string $table;

    /**
     * Whether this is a table modification.
     */
    protected bool $modifying;

    /**
     * The columns to be created.
     *
     * @var array<array<string, mixed>>
     */
    protected array $columns = [];

    /**
     * The indexes to be created.
     *
     * @var array<array<string, mixed>>
     */
    protected array $indexes = [];

    /**
     * The primary key column(s).
     *
     * @var array<string>
     */
    protected array $primaryKey = [];

    /**
     * The SQL statements for table modification.
     *
     * @var array<string>
     */
    protected array $statements = [];

    /**
     * The current column being defined.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $currentColumn = null;

    /**
     * Create a new blueprint instance.
     */
    public function __construct(string $table, bool $modifying = false)
    {
        $this->table = $table;
        $this->modifying = $modifying;
    }

    /**
     * Add a big auto-incrementing integer column (primary key).
     *
     * @param string $column
     * @return static
     */
    public function id(string $column = 'id'): static
    {
        return $this->bigIncrements($column);
    }

    /**
     * Add an auto-incrementing big integer.
     *
     * @param string $column
     * @return static
     */
    public function bigIncrements(string $column): static
    {
        return $this->unsignedBigInteger($column)->autoIncrement()->primary();
    }

    /**
     * Add an auto-incrementing integer.
     *
     * @param string $column
     * @return static
     */
    public function increments(string $column): static
    {
        return $this->unsignedInteger($column)->autoIncrement()->primary();
    }

    /**
     * Add a big integer column.
     */
    public function bigInteger(string $column): static
    {
        return $this->addColumn('bigint(20)', $column);
    }

    /**
     * Add an unsigned big integer column.
     */
    public function unsignedBigInteger(string $column): static
    {
        return $this->addColumn('bigint(20) unsigned', $column);
    }

    /**
     * Add an integer column.
     */
    public function integer(string $column): static
    {
        return $this->addColumn('int(11)', $column);
    }

    /**
     * Add an unsigned integer column.
     */
    public function unsignedInteger(string $column): static
    {
        return $this->addColumn('int(10) unsigned', $column);
    }

    /**
     * Add a tiny integer column.
     */
    public function tinyInteger(string $column): static
    {
        return $this->addColumn('tinyint(4)', $column);
    }

    /**
     * Add an unsigned tiny integer column.
     */
    public function unsignedTinyInteger(string $column): static
    {
        return $this->addColumn('tinyint(3) unsigned', $column);
    }

    /**
     * Add a small integer column.
     */
    public function smallInteger(string $column): static
    {
        return $this->addColumn('smallint(6)', $column);
    }

    /**
     * Add a medium integer column.
     */
    public function mediumInteger(string $column): static
    {
        return $this->addColumn('mediumint(9)', $column);
    }

    /**
     * Add a boolean column.
     */
    public function boolean(string $column): static
    {
        return $this->tinyInteger($column);
    }

    /**
     * Add a decimal column.
     */
    public function decimal(string $column, int $precision = 8, int $scale = 2): static
    {
        return $this->addColumn("decimal({$precision},{$scale})", $column);
    }

    /**
     * Add a float column.
     */
    public function float(string $column): static
    {
        return $this->addColumn('float', $column);
    }

    /**
     * Add a double column.
     */
    public function double(string $column): static
    {
        return $this->addColumn('double', $column);
    }

    /**
     * Add a string column.
     */
    public function string(string $column, int $length = 255): static
    {
        return $this->addColumn("varchar({$length})", $column);
    }

    /**
     * Add a text column.
     */
    public function text(string $column): static
    {
        return $this->addColumn('text', $column);
    }

    /**
     * Add a medium text column.
     */
    public function mediumText(string $column): static
    {
        return $this->addColumn('mediumtext', $column);
    }

    /**
     * Add a long text column.
     */
    public function longText(string $column): static
    {
        return $this->addColumn('longtext', $column);
    }

    /**
     * Add a JSON column, stored as `longtext`.
     *
     * The framework encodes and decodes JSON in PHP (model casts `array`/`json`) and never queries these
     * columns with SQL JSON functions, so the portable text type is used instead of the native `json`
     * type: `json` exists only from MySQL 5.7.8 and MariaDB 10.2.7, and a `CREATE TABLE` that names it
     * fails silently under `dbDelta()` on the older servers WordPress still supports.
     */
    public function json(string $column): static
    {
        return $this->addColumn('longtext', $column);
    }

    /**
     * Add a date column.
     */
    public function date(string $column): static
    {
        return $this->addColumn('date', $column);
    }

    /**
     * Add a datetime column.
     */
    public function datetime(string $column): static
    {
        return $this->addColumn('datetime', $column);
    }

    /**
     * Add a timestamp column.
     */
    public function timestamp(string $column): static
    {
        return $this->addColumn('timestamp', $column);
    }

    /**
     * Add a time column.
     */
    public function time(string $column): static
    {
        return $this->addColumn('time', $column);
    }

    /**
     * Add a year column.
     */
    public function year(string $column): static
    {
        return $this->addColumn('year(4)', $column);
    }

    /**
     * Add a binary column.
     */
    public function binary(string $column): static
    {
        return $this->addColumn('blob', $column);
    }

    /**
     * Add an enum column.
     *
     * @param array<string> $values
     */
    public function enum(string $column, array $values): static
    {
        $enumValues = implode("','", array_map('addslashes', $values));
        return $this->addColumn("enum('{$enumValues}')", $column);
    }

    /**
     * Add created_at and updated_at timestamp columns. The ORM stores UTC in both
     * ({@see \BooleanSmtp\Core\Database\Orm\Model::freshTimestamp()}).
     */
    public function timestamps(): static
    {
        $this->timestamp('created_at')->nullable()->useCurrent();
        $this->timestamp('updated_at')->nullable()->useCurrentOnUpdate();

        return $this;
    }

    /**
     * Add a soft deletes column.
     */
    public function softDeletes(string $column = 'deleted_at'): static
    {
        return $this->timestamp($column)->nullable();
    }

    /**
     * Add a foreign ID column.
     */
    public function foreignId(string $column): static
    {
        return $this->unsignedBigInteger($column);
    }

    /**
     * Mark the column as nullable.
     */
    public function nullable(): static
    {
        if ($this->currentColumn !== null) {
            $this->currentColumn['nullable'] = true;
        }
        return $this;
    }

    /**
     * Set the default value.
     */
    public function default(mixed $value): static
    {
        if ($this->currentColumn !== null) {
            $this->currentColumn['default'] = $value;
        }
        return $this;
    }

    /**
     * Mark the column as auto-incrementing.
     */
    public function autoIncrement(): static
    {
        if ($this->currentColumn !== null) {
            $this->currentColumn['autoIncrement'] = true;
        }
        return $this;
    }

    /**
     * Mark the column as primary key.
     */
    public function primary(): static
    {
        if ($this->currentColumn !== null) {
            $this->primaryKey[] = $this->currentColumn['name'];
        }
        return $this;
    }

    /**
     * Add an index.
     */
    public function index(string|array|null $columns = null): static
    {
        if ($columns === null && $this->currentColumn !== null) {
            $columns = $this->currentColumn['name'];
        }

        $this->indexes[] = [
            'type' => 'INDEX',
            'columns' => (array) $columns,
        ];

        return $this;
    }

    /**
     * Add a unique index.
     */
    public function unique(string|array|null $columns = null): static
    {
        if ($columns === null && $this->currentColumn !== null) {
            $columns = $this->currentColumn['name'];
        }

        $this->indexes[] = [
            'type' => 'UNIQUE',
            'columns' => (array) $columns,
        ];

        return $this;
    }

    /**
     * Use CURRENT_TIMESTAMP as default.
     */
    public function useCurrent(): static
    {
        if ($this->currentColumn !== null) {
            $this->currentColumn['default'] = 'CURRENT_TIMESTAMP';
            $this->currentColumn['defaultRaw'] = true;
        }
        return $this;
    }

    /**
     * Update to CURRENT_TIMESTAMP on update.
     */
    public function useCurrentOnUpdate(): static
    {
        if ($this->currentColumn !== null) {
            $this->currentColumn['onUpdate'] = 'CURRENT_TIMESTAMP';
        }
        return $this;
    }

    /**
     * Add a column.
     */
    protected function addColumn(string $type, string $name): static
    {
        // Save previous column
        if ($this->currentColumn !== null) {
            $this->columns[] = $this->currentColumn;
        }

        $this->currentColumn = [
            'type' => $type,
            'name' => $name,
            'nullable' => false,
            'autoIncrement' => false,
            'default' => null,
            'defaultRaw' => false,
            'onUpdate' => null,
        ];

        return $this;
    }

    /**
     * Generate the CREATE TABLE SQL.
     */
    public function toSql(): string
    {
        // Save the last column
        if ($this->currentColumn !== null) {
            $this->columns[] = $this->currentColumn;
            $this->currentColumn = null;
        }

        $definitions = [];

        // Column definitions
        foreach ($this->columns as $column) {
            $definitions[] = $this->buildColumnDefinition($column);
        }

        // Primary key
        if (!empty($this->primaryKey)) {
            $pkCols = array_map(fn (string $c) => $this->wrapIdentifier($c), $this->primaryKey);
            $definitions[] = 'PRIMARY KEY  (' . implode(', ', $pkCols) . ')';
        }

        // Indexes
        foreach ($this->indexes as $index) {
            $columns = implode(', ', array_map(fn (string $c) => $this->wrapIdentifier($c), $index['columns']));
            $name = implode('_', $index['columns']) . '_' . strtolower($index['type']);
            $type = $index['type'] === 'INDEX' ? 'KEY' : $index['type'];
            $definitions[] = "{$type} {$name} ({$columns})";
        }

        return sprintf(
            "CREATE TABLE %s (\n  %s\n)",
            $this->wrapIdentifier($this->table),
            implode(",\n  ", $definitions)
        );
    }

    /**
     * Generate CREATE TABLE / CREATE INDEX statements for SQLite.
     *
     * dbDelta() (used by toSql()'s consumer for MySQL) understands only MySQL
     * syntax, and SQLite has no equivalent of AUTO_INCREMENT combined with a
     * separate table-level PRIMARY KEY, and no inline KEY/INDEX clauses inside
     * CREATE TABLE — indexes must be their own CREATE INDEX statements. This
     * builds SQLite-correct DDL directly from the structured column/index
     * data instead of post-processing the MySQL SQL string.
     *
     * @return array<string> The CREATE TABLE statement followed by any CREATE INDEX statements.
     */
    public function toSqliteStatements(): array
    {
        if ($this->currentColumn !== null) {
            $this->columns[] = $this->currentColumn;
            $this->currentColumn = null;
        }

        // A single-column integer primary key that auto-increments must be
        // declared inline as `INTEGER PRIMARY KEY AUTOINCREMENT` in SQLite —
        // it cannot also appear in a separate table-level PRIMARY KEY clause.
        $autoIncrementColumn = null;
        if (count($this->primaryKey) === 1) {
            foreach ($this->columns as $column) {
                if ($column['name'] === $this->primaryKey[0] && $column['autoIncrement']) {
                    $autoIncrementColumn = $column['name'];
                    break;
                }
            }
        }

        $definitions = [];
        foreach ($this->columns as $column) {
            $definitions[] = $this->buildSqliteColumnDefinition($column, $column['name'] === $autoIncrementColumn);
        }

        if (!empty($this->primaryKey) && $autoIncrementColumn === null) {
            $pkCols = array_map(fn (string $c) => $this->wrapIdentifier($c), $this->primaryKey);
            $definitions[] = 'PRIMARY KEY (' . implode(', ', $pkCols) . ')';
        }

        $statements = [sprintf(
            "CREATE TABLE IF NOT EXISTS %s (\n  %s\n)",
            $this->wrapIdentifier($this->table),
            implode(",\n  ", $definitions)
        )];

        foreach ($this->indexes as $index) {
            $unique = $index['type'] === 'UNIQUE' ? 'UNIQUE ' : '';
            $indexName = $this->table . '_' . implode('_', $index['columns']) . '_' . strtolower($index['type']);
            $columns = implode(', ', array_map(fn (string $c) => $this->wrapIdentifier($c), $index['columns']));
            $statements[] = sprintf(
                'CREATE %sINDEX IF NOT EXISTS %s ON %s (%s)',
                $unique,
                $this->wrapIdentifier($indexName),
                $this->wrapIdentifier($this->table),
                $columns
            );
        }

        return $statements;
    }

    /**
     * Build a column definition for SQLite (type-affinity based, no dbDelta involved).
     *
     * @param array<string, mixed> $column
     */
    protected function buildSqliteColumnDefinition(array $column, bool $isAutoIncrementPrimaryKey): string
    {
        $name = $this->wrapIdentifier($column['name']);

        if ($isAutoIncrementPrimaryKey) {
            return "{$name} INTEGER PRIMARY KEY AUTOINCREMENT";
        }

        $sql = $name . ' ' . $this->sqliteColumnType($column['type']);

        if (!$column['nullable']) {
            $sql .= ' NOT NULL';
        }

        if ($column['default'] !== null) {
            if ($column['defaultRaw'] ?? false) {
                $sql .= " DEFAULT {$column['default']}";
            } else {
                $default = $column['default'];
                if (is_bool($default)) {
                    $default = $default ? '1' : '0';
                } elseif (is_string($default)) {
                    $default = "'" . addslashes($default) . "'";
                }
                $sql .= " DEFAULT {$default}";
            }
        }

        // SQLite has no ON UPDATE CURRENT_TIMESTAMP; dropped for the test schema
        // (RefreshDatabase re-creates the table per test anyway).

        return $sql;
    }

    /**
     * Map a MySQL column type string to a SQLite type-affinity keyword.
     */
    protected function sqliteColumnType(string $mysqlType): string
    {
        $type = strtolower($mysqlType);

        if (str_contains($type, 'int')) {
            return 'INTEGER';
        }

        if (str_contains($type, 'decimal') || str_contains($type, 'float') || str_contains($type, 'double')) {
            return 'REAL';
        }

        if (str_contains($type, 'blob') || str_contains($type, 'binary')) {
            return 'BLOB';
        }

        // varchar, char, text variants, json, enum, date, datetime, timestamp, time, year
        return 'TEXT';
    }

    /**
     * Quote a table or column identifier for MySQL/MariaDB (reserved words e.g. `to`, `order`).
     * SQLite also accepts backtick-quoted identifiers (MySQL-compatibility mode), so this is
     * shared by toSqliteStatements() too.
     */
    protected function wrapIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * Build a column definition.
     *
     * @param array<string, mixed> $column
     */
    protected function buildColumnDefinition(array $column): string
    {
        $sql = $this->wrapIdentifier($column['name']) . ' ' . $column['type'];

        if (!$column['nullable']) {
            $sql .= ' NOT NULL';
        }

        if ($column['autoIncrement']) {
            $sql .= ' AUTO_INCREMENT';
        }

        if ($column['default'] !== null) {
            if ($column['defaultRaw'] ?? false) {
                $sql .= " DEFAULT {$column['default']}";
            } else {
                $default = $column['default'];
                if (is_bool($default)) {
                    $default = $default ? '1' : '0';
                } elseif (is_string($default)) {
                    $default = "'" . addslashes($default) . "'";
                }
                $sql .= " DEFAULT {$default}";
            }
        }

        if ($column['onUpdate'] !== null) {
            $sql .= " ON UPDATE {$column['onUpdate']}";
        }

        return $sql;
    }

    /**
     * Generate ALTER TABLE / CREATE INDEX statements for SQLite.
     *
     * SQLite accepts one `ADD COLUMN` per ALTER statement with its own column grammar and has no
     * `ADD INDEX` clause at all, so an alteration gets the same treatment {@see toSqliteStatements()}
     * gives a creation: SQLite column definitions, and every index as its own `CREATE INDEX`.
     *
     * @return array<string> The ADD COLUMN statements followed by any CREATE INDEX statements.
     */
    public function toSqliteAlterStatements(): array
    {
        if ($this->currentColumn !== null) {
            $this->columns[] = $this->currentColumn;
            $this->currentColumn = null;
        }

        $statements = [];

        foreach ($this->columns as $column) {
            $definition   = $this->buildSqliteColumnDefinition($column, false);
            $statements[] = sprintf('ALTER TABLE %s ADD COLUMN %s', $this->wrapIdentifier($this->table), $definition);
        }

        foreach ($this->indexes as $index) {
            $unique    = $index['type'] === 'UNIQUE' ? 'UNIQUE ' : '';
            $indexName = $this->table . '_' . implode('_', $index['columns']) . '_' . strtolower($index['type']);
            $columns   = implode(', ', array_map(fn (string $c) => $this->wrapIdentifier($c), $index['columns']));
            $statements[] = sprintf(
                'CREATE %sINDEX IF NOT EXISTS %s ON %s (%s)',
                $unique,
                $this->wrapIdentifier($indexName),
                $this->wrapIdentifier($this->table),
                $columns
            );
        }

        return array_merge($this->statements, $statements);
    }

    /**
     * Get modification statements.
     *
     * @return array<string>
     */
    public function getStatements(): array
    {
        // Save the last column
        if ($this->currentColumn !== null) {
            $this->columns[] = $this->currentColumn;
            $this->currentColumn = null;
        }

        $statements = [];

        foreach ($this->columns as $column) {
            $definition = $this->buildColumnDefinition($column);
            $statements[] = "ALTER TABLE `{$this->table}` ADD {$definition}";
        }

        foreach ($this->indexes as $index) {
            $columns = implode(', ', array_map(fn($c) => "`{$c}`", $index['columns']));
            $name = implode('_', $index['columns']) . '_' . strtolower($index['type']);
            $statements[] = "ALTER TABLE `{$this->table}` ADD {$index['type']} `{$name}` ({$columns})";
        }

        return array_merge($this->statements, $statements);
    }

    /**
     * Drop a column.
     */
    public function dropColumn(string $column): static
    {
        $this->statements[] = "ALTER TABLE `{$this->table}` DROP COLUMN `{$column}`";
        return $this;
    }

    /**
     * Rename a column.
     */
    public function renameColumn(string $from, string $to): static
    {
        $this->statements[] = "ALTER TABLE `{$this->table}` RENAME COLUMN `{$from}` TO `{$to}`";
        return $this;
    }

    /**
     * Drop an index.
     */
    public function dropIndex(string $name): static
    {
        $this->statements[] = "ALTER TABLE `{$this->table}` DROP INDEX `{$name}`";
        return $this;
    }
}
