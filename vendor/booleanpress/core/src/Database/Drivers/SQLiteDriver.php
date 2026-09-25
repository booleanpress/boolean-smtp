<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Database\Drivers;

use PDO;
use PDOStatement;

/**
 * SQLite Driver
 *
 * Uses PDO with SQLite for testing environments.
 * Supports in-memory databases for fast unit tests.
 */
class SQLiteDriver implements DriverInterface
{
    /**
     * The PDO connection.
     */
    protected PDO $pdo;

    /**
     * The table prefix.
     */
    protected string $prefix;

    /**
     * The last insert ID.
     */
    protected int $lastId = 0;

    /**
     * Create a new SQLite driver.
     *
     * @param string $database Path to SQLite file, or ':memory:' for in-memory
     * @param string $prefix   Table name prefix
     */
    public function __construct(string $database = ':memory:', string $prefix = 'wp_test_')
    {
        $this->prefix = $prefix;
        $this->pdo = new PDO("sqlite:{$database}", null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // Enable WAL mode for better concurrency (if not in-memory)
        if ($database !== ':memory:') {
            $this->pdo->exec('PRAGMA journal_mode=WAL');
        }

        // Enable foreign keys
        $this->pdo->exec('PRAGMA foreign_keys=ON');
    }

    /**
     * Get the underlying PDO instance.
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * {@inheritdoc}
     */
    public function select(string $query, array $bindings = []): array
    {
        $query = $this->translateQuery($query);
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($bindings);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * {@inheritdoc}
     */
    public function selectVar(string $query, array $bindings = []): mixed
    {
        $query = $this->translateQuery($query);
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($bindings);

        $result = $stmt->fetchColumn();
        return $result === false ? null : $result;
    }

    /**
     * {@inheritdoc}
     */
    public function selectRow(string $query, array $bindings = []): ?array
    {
        $query = $this->translateQuery($query);
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($bindings);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * {@inheritdoc}
     */
    public function insert(string $table, array $data): int|false
    {
        $tableName = $this->getTable($table);
        $columns = implode(', ', array_map(fn($col) => "`{$col}`", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $query = "INSERT INTO `{$tableName}` ({$columns}) VALUES ({$placeholders})";

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute(array_values($data));
            $this->lastId = (int) $this->pdo->lastInsertId();
            return $this->lastId;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function update(string $table, array $data, array $where): int|false
    {
        $tableName = $this->getTable($table);
        $setParts = array_map(fn($col) => "`{$col}` = ?", array_keys($data));
        $whereParts = array_map(fn($col) => "`{$col}` = ?", array_keys($where));

        $query = "UPDATE `{$tableName}` SET " . implode(', ', $setParts) . " WHERE " . implode(' AND ', $whereParts);

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute(array_merge(array_values($data), array_values($where)));
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $table, array $where): int|false
    {
        $tableName = $this->getTable($table);
        $whereParts = array_map(fn($col) => "`{$col}` = ?", array_keys($where));

        $query = "DELETE FROM `{$tableName}` WHERE " . implode(' AND ', $whereParts);

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute(array_values($where));
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function statement(string $query, array $bindings = []): bool|int
    {
        $query = $this->translateQuery($query);

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($bindings);
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function raw(string $query): mixed
    {
        $query = $this->translateQuery($query);
        return $this->pdo->exec($query);
    }

    /**
     * {@inheritdoc}
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * {@inheritdoc}
     */
    public function getTable(string $table): string
    {
        if (str_starts_with($table, $this->prefix)) {
            return $table;
        }
        return $this->prefix . $table;
    }

    /**
     * {@inheritdoc}
     */
    public function getCharsetCollate(): string
    {
        // SQLite handles encoding internally
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    /**
     * {@inheritdoc}
     */
    public function commit(): void
    {
        $this->pdo->commit();
    }

    /**
     * {@inheritdoc}
     */
    public function rollBack(): void
    {
        $this->pdo->rollBack();
    }

    /**
     * {@inheritdoc}
     */
    public function tableExists(string $table): bool
    {
        $tableName = $this->getTable($table);
        $stmt = $this->pdo->prepare(
            "SELECT name FROM sqlite_master WHERE type='table' AND name=?"
        );
        $stmt->execute([$tableName]);

        return $stmt->fetch() !== false;
    }

    /**
     * {@inheritdoc}
     */
    public function getTableColumns(string $table): array
    {
        $tableName = $this->getTable($table);
        $stmt = $this->pdo->query("PRAGMA table_info(`{$tableName}`)");
        $columns = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = $row['name'];
        }

        return $columns;
    }

    /**
     * {@inheritdoc}
     */
    public function lastInsertId(): int
    {
        return $this->lastId;
    }

    /**
     * {@inheritdoc}
     */
    public function lastError(): string
    {
        $info = $this->pdo->errorInfo();
        return $info[2] ?? '';
    }

    /**
     * Translate MySQL-specific SQL to SQLite-compatible syntax.
     */
    protected function translateQuery(string $query): string
    {
        // Replace MySQL %s, %d, %f placeholders with ? for PDO
        $query = preg_replace('/%[sdf]/', '?', $query);

        // Remove MySQL-specific keywords
        $query = preg_replace('/\bUNSIGNED\b/i', '', $query);
        $query = str_ireplace('AUTO_INCREMENT', 'AUTOINCREMENT', $query);
        $query = preg_replace('/\bENGINE\s*=\s*\w+/i', '', $query);
        $query = preg_replace('/\bDEFAULT\s+CHARSET\s*=\s*\w+/i', '', $query);
        $query = preg_replace('/\bCOLLATE\s*=?\s*\w+/i', '', $query);
        $query = preg_replace('/\bCHARACTER\s+SET\s+\w+/i', '', $query);
        $query = preg_replace('/\bON\s+UPDATE\s+CURRENT_TIMESTAMP\b/i', '', $query);

        // Convert MySQL SHOW TABLES to SQLite
        if (preg_match('/SHOW\s+TABLES\s+LIKE\s+/i', $query)) {
            $query = preg_replace(
                "/SHOW\s+TABLES\s+LIKE\s+['\"]?(\?|[^'\"]+)['\"]?/i",
                "SELECT name FROM sqlite_master WHERE type='table' AND name=?",
                $query
            );
        }

        // Convert SHOW COLUMNS to PRAGMA
        if (preg_match('/SHOW\s+COLUMNS\s+FROM\s+`?(\w+)`?/i', $query, $m)) {
            $query = "PRAGMA table_info({$m[1]})";
        }

        return $query;
    }
}
