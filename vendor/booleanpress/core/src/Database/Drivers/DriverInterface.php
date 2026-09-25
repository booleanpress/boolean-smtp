<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Database\Drivers;

/**
 * Database Driver Interface
 *
 * Abstracts database operations to allow switching between MySQL ($wpdb) and SQLite (PDO).
 * All database access in the framework should go through this interface.
 */
interface DriverInterface
{
    /**
     * Execute a SELECT query and return results.
     *
     * @param string $query    SQL query with placeholders
     * @param array  $bindings Values to bind
     * @return array<int, array<string, mixed>>
     */
    public function select(string $query, array $bindings = []): array;

    /**
     * Execute a SELECT query and return a single value.
     *
     * @param string $query    SQL query with placeholders
     * @param array  $bindings Values to bind
     * @return mixed
     */
    public function selectVar(string $query, array $bindings = []): mixed;

    /**
     * Execute a SELECT query and return a single row.
     *
     * @param string $query    SQL query with placeholders
     * @param array  $bindings Values to bind
     * @return array<string, mixed>|null
     */
    public function selectRow(string $query, array $bindings = []): ?array;

    /**
     * Insert a row into a table.
     *
     * @param string               $table The table name (without prefix)
     * @param array<string, mixed> $data  Column => value pairs
     * @return int|false The insert ID, or false on failure
     */
    public function insert(string $table, array $data): int|false;

    /**
     * Update rows in a table.
     *
     * @param string               $table The table name (without prefix)
     * @param array<string, mixed> $data  Column => value pairs to update
     * @param array<string, mixed> $where Column => value conditions
     * @return int|false Number of rows affected, or false on failure
     */
    public function update(string $table, array $data, array $where): int|false;

    /**
     * Delete rows from a table.
     *
     * @param string               $table The table name (without prefix)
     * @param array<string, mixed> $where Column => value conditions
     * @return int|false Number of rows affected, or false on failure
     */
    public function delete(string $table, array $where): int|false;

    /**
     * Execute a raw SQL statement.
     *
     * @param string $query    SQL statement
     * @param array  $bindings Values to bind
     * @return bool|int
     */
    public function statement(string $query, array $bindings = []): bool|int;

    /**
     * Execute a raw query and return the result.
     *
     * @param string $query SQL query
     * @return mixed
     */
    public function raw(string $query): mixed;

    /**
     * Get the table prefix.
     */
    public function getPrefix(): string;

    /**
     * Get the full table name with prefix.
     */
    public function getTable(string $table): string;

    /**
     * Get the charset collation string for CREATE TABLE statements.
     */
    public function getCharsetCollate(): string;

    /**
     * Begin a database transaction.
     */
    public function beginTransaction(): void;

    /**
     * Commit the current transaction.
     */
    public function commit(): void;

    /**
     * Roll back the current transaction.
     */
    public function rollBack(): void;

    /**
     * Check if a table exists.
     */
    public function tableExists(string $table): bool;

    /**
     * Get column names for a table.
     *
     * @return array<string>
     */
    public function getTableColumns(string $table): array;

    /**
     * Get the last insert ID.
     */
    public function lastInsertId(): int;

    /**
     * Get the last error message.
     */
    public function lastError(): string;
}
