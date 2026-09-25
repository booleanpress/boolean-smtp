<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Database\Drivers;

/**
 * MySQL Driver
 *
 * Wraps WordPress $wpdb for database operations.
 * This is the default driver for production environments.
 */
class MySQLDriver implements DriverInterface
{
    /**
     * The WordPress database connection.
     */
    protected \wpdb $wpdb;

    /**
     * Create a new MySQL driver.
     */
    public function __construct(?\wpdb $wpdb = null)
    {
        if ($wpdb) {
            $this->wpdb = $wpdb;
        } else {
            global $wpdb;
            $this->wpdb = $wpdb;
        }
    }

    /**
     * Get the underlying wpdb instance.
     */
    public function getWpdb(): \wpdb
    {
        return $this->wpdb;
    }

    /**
     * {@inheritdoc}
     */
    public function select(string $query, array $bindings = []): array
    {
        if (!empty($bindings)) {
            $query = $this->wpdb->prepare($query, ...$bindings);
        }

        $results = $this->wpdb->get_results($query, ARRAY_A);
        return $results ?: [];
    }

    /**
     * {@inheritdoc}
     */
    public function selectVar(string $query, array $bindings = []): mixed
    {
        if (!empty($bindings)) {
            $query = $this->wpdb->prepare($query, ...$bindings);
        }

        return $this->wpdb->get_var($query);
    }

    /**
     * {@inheritdoc}
     */
    public function selectRow(string $query, array $bindings = []): ?array
    {
        if (!empty($bindings)) {
            $query = $this->wpdb->prepare($query, ...$bindings);
        }

        $row = $this->wpdb->get_row($query, ARRAY_A);
        return $row ?: null;
    }

    /**
     * {@inheritdoc}
     */
    public function insert(string $table, array $data): int|false
    {
        $tableName = $this->getTable($table);
        $formats = $this->getFormats($data);

        $result = $this->wpdb->insert($tableName, $data, $formats);

        if ($result === false) {
            return false;
        }

        return (int) $this->wpdb->insert_id;
    }

    /**
     * {@inheritdoc}
     */
    public function update(string $table, array $data, array $where): int|false
    {
        $tableName = $this->getTable($table);

        $result = $this->wpdb->update(
            $tableName,
            $data,
            $where,
            $this->getFormats($data),
            $this->getFormats($where)
        );

        return $result === false ? false : (int) $result;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $table, array $where): int|false
    {
        $tableName = $this->getTable($table);

        $result = $this->wpdb->delete(
            $tableName,
            $where,
            $this->getFormats($where)
        );

        return $result === false ? false : (int) $result;
    }

    /**
     * {@inheritdoc}
     */
    public function statement(string $query, array $bindings = []): bool|int
    {
        if (!empty($bindings)) {
            $query = $this->wpdb->prepare($query, ...$bindings);
        }

        $result = $this->wpdb->query($query);
        return $result === false ? false : (int) $result;
    }

    /**
     * {@inheritdoc}
     */
    public function raw(string $query): mixed
    {
        return $this->wpdb->query($query);
    }

    /**
     * {@inheritdoc}
     */
    public function getPrefix(): string
    {
        return $this->wpdb->prefix;
    }

    /**
     * {@inheritdoc}
     */
    public function getTable(string $table): string
    {
        // If table already has the prefix, return as-is
        if (str_starts_with($table, $this->wpdb->prefix)) {
            return $table;
        }

        return $this->wpdb->prefix . $table;
    }

    /**
     * {@inheritdoc}
     */
    public function getCharsetCollate(): string
    {
        return $this->wpdb->get_charset_collate();
    }

    /**
     * {@inheritdoc}
     */
    public function beginTransaction(): void
    {
        $this->wpdb->query('START TRANSACTION');
    }

    /**
     * {@inheritdoc}
     */
    public function commit(): void
    {
        $this->wpdb->query('COMMIT');
    }

    /**
     * {@inheritdoc}
     */
    public function rollBack(): void
    {
        $this->wpdb->query('ROLLBACK');
    }

    /**
     * {@inheritdoc}
     */
    public function tableExists(string $table): bool
    {
        $tableName = $this->getTable($table);
        $result = $this->wpdb->get_var(
            $this->wpdb->prepare("SHOW TABLES LIKE %s", $tableName)
        );

        return $result === $tableName;
    }

    /**
     * {@inheritdoc}
     */
    public function getTableColumns(string $table): array
    {
        $tableName = $this->getTable($table);
        return $this->wpdb->get_col("SHOW COLUMNS FROM `{$tableName}`");
    }

    /**
     * {@inheritdoc}
     */
    public function lastInsertId(): int
    {
        return (int) $this->wpdb->insert_id;
    }

    /**
     * {@inheritdoc}
     */
    public function lastError(): string
    {
        return $this->wpdb->last_error;
    }

    /**
     * Determine the format strings for wpdb methods.
     *
     * @param array<string, mixed> $data
     * @return array<string>
     */
    protected function getFormats(array $data): array
    {
        $formats = [];
        foreach ($data as $value) {
            if (is_int($value)) {
                $formats[] = '%d';
            } elseif (is_float($value)) {
                $formats[] = '%f';
            } else {
                $formats[] = '%s';
            }
        }
        return $formats;
    }
}
