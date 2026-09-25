<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Database\Schema;

use BooleanSmtp\Core\Database\Drivers\DriverInterface;
use BooleanSmtp\Core\Database\Drivers\SQLiteDriver;
use function BooleanSmtp\Core\app;

/**
 * Schema Builder
 *
 * Provides a fluent interface for creating and modifying database tables.
 * Uses WordPress's dbDelta for compatibility.
 */
class Schema
{
    /**
     * Get the database driver.
     *
     * Resolved from the container on every call: the container owns the driver's lifetime, and a
     * cached copy would outlive a re-bound driver (a test giving each case its own database).
     */
    protected static function driver(): DriverInterface
    {
        return app(DriverInterface::class);
    }

    /**
     * Get the table prefix.
     */
    public static function prefix(): string
    {
        return static::driver()->getPrefix();
    }

    /**
     * Get prefixed table name.
     */
    public static function tableName(string $table): string
    {
        return static::prefix() . $table;
    }

    /**
     * Create a new table.
     *
     * @param string $table The table name (without prefix)
     * @param callable(Blueprint): void $callback
     */
    public static function create(string $table, callable $callback): void
    {
        $blueprint = new Blueprint(static::tableName($table));
        $callback($blueprint);

        // SQLite has no dbDelta() equivalent and can't parse MySQL-flavoured
        // DDL (AUTO_INCREMENT combined with a separate PRIMARY KEY clause,
        // inline KEY/INDEX definitions), so it gets its own statement path
        // built directly from the blueprint's structured column/index data.
        if (static::driver() instanceof SQLiteDriver) {
            foreach ($blueprint->toSqliteStatements() as $statement) {
                static::driver()->statement($statement);
            }
            return;
        }

        $sql = $blueprint->toSql();
        $charset = static::driver()->getCharsetCollate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        \dbDelta($sql . ' ' . $charset);
    }

    /**
     * Modify an existing table.
     *
     * @param string $table The table name (without prefix)
     * @param callable(Blueprint): void $callback
     */
    public static function table(string $table, callable $callback): void
    {
        $blueprint = new Blueprint(static::tableName($table), true);
        $callback($blueprint);

        // SQLite takes one ADD COLUMN per statement and has no ADD INDEX clause, so an alteration
        // uses the SQLite statement path like a creation does.
        $statements = static::driver() instanceof SQLiteDriver
            ? $blueprint->toSqliteAlterStatements()
            : $blueprint->getStatements();

        foreach ($statements as $statement) {
            static::driver()->statement($statement);
        }
    }

    /**
     * Drop a table.
     */
    public static function drop(string $table): void
    {
        static::driver()->statement(
            sprintf('DROP TABLE IF EXISTS `%s`', static::tableName($table))
        );
    }

    /**
     * Drop a table if it exists.
     */
    public static function dropIfExists(string $table): void
    {
        static::drop($table);
    }

    /**
     * Rename a table.
     */
    public static function rename(string $from, string $to): void
    {
        static::driver()->statement(
            sprintf(
                'ALTER TABLE `%s` RENAME TO `%s`',
                static::tableName($from),
                static::tableName($to)
            )
        );
    }

    /**
     * Check if a table exists.
     */
    public static function hasTable(string $table): bool
    {
        return static::driver()->tableExists($table);
    }

    /**
     * Check if a column exists.
     */
    public static function hasColumn(string $table, string $column): bool
    {
        $columns = static::getColumnListing($table);
        return in_array($column, $columns, true);
    }

    /**
     * Get the column listing.
     *
     * @return array<string>
     */
    public static function getColumnListing(string $table): array
    {
        return static::driver()->getTableColumns($table);
    }

    /**
     * Validate a database identifier (table or column name).
     *
     * Prevents SQL injection through malicious identifiers.
     */
    public static function validateIdentifier(string $identifier): string
    {
        // Only allow alphanumeric characters, underscores, and hyphens
        // Must start with a letter or underscore
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*$/', $identifier)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid database identifier: %s', $identifier)
            );
        }

        // Check for reserved words or dangerous patterns
        $reserved = ['--', ';', '/*', '*/', 'DROP', 'DELETE', 'TRUNCATE', 'INSERT', 'UPDATE'];
        $upperIdentifier = strtoupper($identifier);
        foreach ($reserved as $word) {
            if (str_contains($upperIdentifier, $word)) {
                throw new \InvalidArgumentException(
                    sprintf('Identifier contains reserved pattern: %s', $identifier)
                );
            }
        }

        return $identifier;
    }

    /**
     * Drop all columns from a table.
     *
     * @param array<string> $columns
     */
    public static function dropColumns(string $table, array $columns): void
    {
        $tableName = static::tableName(static::validateIdentifier($table));

        foreach ($columns as $column) {
            $safeColumn = static::validateIdentifier($column);

            // SQLite refuses to drop a column an index still references; MySQL drops the index itself.
            if (static::driver() instanceof SQLiteDriver) {
                static::dropSqliteIndexesOn($tableName, $safeColumn);
            }

            static::driver()->statement(
                sprintf("ALTER TABLE `%s` DROP COLUMN `%s`", $tableName, $safeColumn)
            );
        }
    }

    /**
     * Drop every SQLite index that includes a column, ahead of dropping the column.
     *
     * @param string $tableName Prefixed table name.
     * @param string $column    Column about to be dropped.
     */
    protected static function dropSqliteIndexesOn(string $tableName, string $column): void
    {
        foreach (static::driver()->select(sprintf('PRAGMA index_list(`%s`)', $tableName)) as $index) {
            $index = (array) $index;
            $name  = (string) ($index['name'] ?? '');
            if ($name === '' || str_starts_with($name, 'sqlite_autoindex_')) {
                continue;
            }

            $columns = array_map(
                static fn ($row): string => (string) (((array) $row)['name'] ?? ''),
                static::driver()->select(sprintf('PRAGMA index_info(`%s`)', $name))
            );

            if (in_array($column, $columns, true)) {
                static::driver()->statement(sprintf('DROP INDEX IF EXISTS `%s`', $name));
            }
        }
    }
}
