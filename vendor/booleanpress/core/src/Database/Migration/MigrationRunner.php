<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Database\Migration;

use BooleanSmtp\Core\Foundation\Application;
use BooleanSmtp\Core\Database\Drivers\DriverInterface;
use BooleanSmtp\Core\Database\Drivers\SQLiteDriver;
use function BooleanSmtp\Core\app;

/**
 * Migration Runner
 *
 * Manages database migration execution and tracking.
 * Uses a shared `booleanpress_migrations` table for all plugins.
 *
 * Every migration is owned by a plugin: the migration's own `$plugin` when it declares one
 * (framework migrations declare `core`), otherwise the plugin the runner was created for.
 * Rows are recorded under the owner, so a shared framework table is created once by whichever
 * BooleanPress plugin activates first and is never rolled back by a plugin runner.
 */
class MigrationRunner
{
    /**
     * The plugin identifier framework-owned migrations are recorded under.
     */
    public const CORE_PLUGIN = 'core';

    /**
     * Migration row status: the migration ran successfully.
     */
    public const STATUS_RAN = 'ran';

    /**
     * Migration row status: `up()` threw; the migration is pending again on the next run.
     */
    public const STATUS_FAILED = 'failed';

    /**
     * The migrations to run.
     *
     * @var array<string, class-string<Migration>|Migration>
     */
    protected array $migrations = [];

    /**
     * The migrations table name (shared across all plugins).
     */
    protected string $table = 'booleanpress_migrations';

    /**
     * The full table name with prefix.
     */
    protected string $tableName;

    /**
     * The database driver.
     */
    protected DriverInterface $driver;

    /**
     * The plugin identifier for filtering migrations.
     */
    protected string $plugin = self::CORE_PLUGIN;

    /**
     * The plugin version recorded on every row this runner writes.
     */
    protected string $version = '1.0.0';

    /**
     * The Application instance (optional).
     */
    protected ?Application $app = null;

    /**
     * Whether the migrations table has been checked for missing columns in this instance.
     */
    protected bool $schemaChecked = false;

    /**
     * Create a new migration runner.
     *
     * @param array<string, class-string<Migration>> $migrations
     * @param Application|string|null $appOrPlugin Application instance (plugin slug and version are read from it), or plugin identifier string
     */
    public function __construct(array $migrations = [], Application|string|null $appOrPlugin = null)
    {
        $this->driver = app(DriverInterface::class);
        $this->migrations = $migrations;
        $this->tableName = $this->driver->getTable($this->table);

        if ($appOrPlugin instanceof Application) {
            $this->app = $appOrPlugin;
            $this->plugin = $appOrPlugin->pluginSlug();
            $this->version = $appOrPlugin->pluginVersion();
        } elseif (is_string($appOrPlugin)) {
            $this->plugin = $appOrPlugin;
        }
    }

    /**
     * Set the plugin identifier.
     */
    public function setPlugin(string $plugin): static
    {
        $this->plugin = $plugin;
        return $this;
    }

    /**
     * Get the plugin identifier.
     */
    public function getPlugin(): string
    {
        return $this->plugin;
    }

    /**
     * Set the plugin version recorded on new rows.
     */
    public function setVersion(string $version): static
    {
        $this->version = $version;
        return $this;
    }

    /**
     * Get the plugin version recorded on new rows.
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Register a migration: a class name, or an instance loaded from a date-named file.
     *
     * @param class-string<Migration>|Migration $migration
     */
    public function register(string $name, string|Migration $migration): static
    {
        $this->migrations[$name] = $migration;
        return $this;
    }

    /**
     * Load one date-named migration file and register it under its file name.
     *
     * @since 0.2.1
     *
     * @param string $file Absolute path of a `YYYY_MM_DD_HHMMSS_<description>.php` file that
     *                     returns a migration instance.
     * @return Migration The registered migration.
     *
     * @throws \InvalidArgumentException When the file does not return a migration.
     */
    public function load(string $file): Migration
    {
        $key = basename($file, '.php');
        if (isset($this->migrations[$key]) && $this->migrations[$key] instanceof Migration) {
            return $this->migrations[$key];
        }

        $migration = require $file;
        if (!$migration instanceof Migration) {
            throw new \InvalidArgumentException("{$file} must return a migration instance.");
        }

        $migration->withName($key);
        $this->migrations[$key] = $migration;

        return $migration;
    }

    /**
     * The migration registered under a name, instantiated when it was registered as a class.
     *
     * @since 0.2.1
     *
     * @param string $name Migration key.
     * @return Migration
     */
    protected function instance(string $name): Migration
    {
        $migration = $this->migrations[$name];

        return $migration instanceof Migration ? $migration : new $migration();
    }

    /**
     * The registered migrations, keyed by name.
     *
     * @return array<string, class-string<Migration>|Migration>
     */
    public function getMigrations(): array
    {
        return $this->migrations;
    }

    /**
     * Register every migration class found in a directory that declares its own `$name`.
     *
     * Two file shapes are recognised. A **date-named file** — `YYYY_MM_DD_HHMMSS_<description>.php`,
     * the Laravel convention — returns a migration instance (`return new class extends Migration { … };`)
     * and is registered under its file name, which is therefore its frozen key. Any other `*.php`
     * file is expected to hold one PSR-4 class (the file name is the class name) and is registered
     * under its `$name` when it extends {@see Migration}, is not abstract, declares a non-empty
     * `$name` and is not already registered. Files without either shape are left to the explicit map.
     *
     * @param string $directory Absolute path of the migrations directory.
     * @return array<string, class-string<Migration>|Migration> The migrations added by this call.
     */
    public function discover(string $directory): array
    {
        $added = [];

        if (!is_dir($directory)) {
            return $added;
        }

        $files = glob(rtrim($directory, '/\\') . '/*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            $key = basename($file, '.php');

            if (preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+$/', $key) === 1) {
                if (isset($this->migrations[$key])) {
                    continue;
                }

                try {
                    $added[$key] = $this->load($file);
                } catch (\InvalidArgumentException) {
                    // Not a migration file; left to the explicit map.
                }
                continue;
            }

            $class = $this->classInFile($file);
            if ($class === null || !class_exists($class) || !is_subclass_of($class, Migration::class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }

            $name = (string) ($reflection->getDefaultProperties()['name'] ?? '');
            if ($name === '' || isset($this->migrations[$name]) || in_array($class, $this->migrations, true)) {
                continue;
            }

            $this->migrations[$name] = $class;
            $added[$name] = $class;
        }

        return $added;
    }

    public function install(): void
    {
        if ($this->driver instanceof SQLiteDriver) {
            // dbDelta() below is MySQL/WordPress-only; SQLite gets its own
            // DDL (INTEGER PRIMARY KEY AUTOINCREMENT, indexes as separate
            // CREATE INDEX statements — see Blueprint::toSqliteStatements()).
            $this->driver->statement("CREATE TABLE IF NOT EXISTS `{$this->tableName}` (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration TEXT NOT NULL,
                plugin TEXT NOT NULL DEFAULT 'core',
                version TEXT NOT NULL DEFAULT '1.0.0',
                batch INTEGER NOT NULL,
                table_name TEXT DEFAULT NULL,
                columns TEXT DEFAULT NULL,
                action TEXT DEFAULT 'create',
                status TEXT NOT NULL DEFAULT 'ran',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )");
            $this->driver->statement("CREATE INDEX IF NOT EXISTS `{$this->tableName}_plugin_idx` ON `{$this->tableName}` (plugin)");
            $this->driver->statement("CREATE INDEX IF NOT EXISTS `{$this->tableName}_batch_idx` ON `{$this->tableName}` (batch)");
            $this->driver->statement("CREATE UNIQUE INDEX IF NOT EXISTS `{$this->tableName}_plugin_migration_unique` ON `{$this->tableName}` (plugin, migration)");

            $this->upgradeTable();

            return;
        }

        $charset = $this->driver->getCharsetCollate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->tableName} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            migration varchar(255) NOT NULL,
            plugin varchar(100) NOT NULL DEFAULT 'core',
            version varchar(20) NOT NULL DEFAULT '1.0.0',
            batch int(11) NOT NULL,
            table_name varchar(255) DEFAULT NULL,
            columns text DEFAULT NULL,
            action varchar(20) DEFAULT 'create',
            status varchar(20) NOT NULL DEFAULT 'ran',
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_plugin (plugin),
            KEY idx_batch (batch),
            UNIQUE KEY uniq_plugin_migration (plugin(40), migration(150))
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        \dbDelta($sql);

        // Upgrade existing table if columns are missing
        $this->upgradeTable();
    }

    /**
     * Upgrade an existing migrations table by adding any tracking column it lacks.
     */
    protected function upgradeTable(): void
    {
        $this->schemaChecked = true;

        $columns = $this->driver->getTableColumns($this->table);
        $sqlite = $this->driver instanceof SQLiteDriver;

        // column => [definition, MySQL position]
        $expected = [
            'plugin' => ["varchar(100) NOT NULL DEFAULT 'core'", 'migration'],
            'version' => ["varchar(20) NOT NULL DEFAULT '1.0.0'", 'plugin'],
            'table_name' => ['varchar(255) DEFAULT NULL', 'batch'],
            'columns' => ['text DEFAULT NULL', 'table_name'],
            'action' => ["varchar(20) DEFAULT 'create'", 'columns'],
            'status' => ["varchar(20) NOT NULL DEFAULT 'ran'", 'action'],
        ];

        foreach ($expected as $column => [$definition, $after]) {
            if (in_array($column, $columns, true)) {
                continue;
            }

            $sql = "ALTER TABLE {$this->tableName} ADD COLUMN {$column} {$definition}";
            if (!$sqlite) {
                $sql .= " AFTER {$after}";
            }

            $this->driver->statement($sql);
        }

        if (!$sqlite) {
            $this->ensureUniqueKey();
        }
    }

    /**
     * Add the `(plugin, migration)` unique key to a MySQL migrations table created before it
     * existed. Skipped while duplicate rows are present (the key could not be built; reads
     * already ignore duplicates).
     *
     * @since 0.2.1
     */
    protected function ensureUniqueKey(): void
    {
        $indexed = (int) $this->driver->selectVar(
            'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
            [$this->tableName, 'uniq_plugin_migration']
        );
        if ($indexed > 0) {
            return;
        }

        $duplicates = (int) $this->driver->selectVar(
            "SELECT COUNT(*) FROM (SELECT plugin, migration FROM {$this->tableName} GROUP BY plugin, migration HAVING COUNT(*) > 1) AS duplicated"
        );
        if ($duplicates > 0) {
            return;
        }

        $this->driver->statement("ALTER TABLE {$this->tableName} ADD UNIQUE KEY uniq_plugin_migration (plugin(40), migration(150))");
    }

    /**
     * Make sure an existing migrations table carries every tracking column before it is read.
     */
    protected function ensureSchema(): void
    {
        if ($this->schemaChecked || !$this->tableExists()) {
            return;
        }

        $this->upgradeTable();
    }

    /**
     * Run pending migrations.
     *
     * A migration whose `up()` throws is recorded with status `failed` (so the failure is
     * visible in `history()`), stays pending, and the exception is rethrown.
     *
     * @return array<string> The migrations that were run
     */
    public function run(): array
    {
        $this->install();

        $pending = $this->getPending();

        if (empty($pending)) {
            return [];
        }

        $batch = $this->getNextBatch();
        $migrated = [];

        foreach ($pending as $name) {
            $migration = $this->instance($name);

            try {
                $migration->up();
                $this->log($name, $batch, $migration);
                $migrated[] = $name;
            } catch (\Throwable $e) {
                $this->log($name, $batch, $migration, self::STATUS_FAILED);

                throw new \RuntimeException(
                    "Migration {$name} failed: " . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        return $migrated;
    }

    /**
     * Re-run the `create` migrations whose table has gone missing.
     *
     * Only the physical table is recreated; the migration rows are left untouched, so a
     * repair never opens a new batch.
     *
     * @return array<string> The migrations whose table was recreated
     */
    public function repair(): array
    {
        $repaired = [];

        foreach (array_keys($this->migrations) as $name) {
            $migration = $this->instance($name);
            $table = $migration->getTableName();

            if ($migration->getAction() !== 'create' || $table === '' || $this->driver->tableExists($table)) {
                continue;
            }

            $migration->up();
            $repaired[] = $name;
        }

        return $repaired;
    }

    /**
     * Rollback the last batch of migrations.
     *
     * Framework-owned migrations (shared tables) are skipped unless this runner is the
     * framework's own.
     *
     * @return array<string> The migrations that were rolled back
     */
    public function rollback(): array
    {
        $batch = $this->getLastBatch();

        if ($batch === 0) {
            return [];
        }

        return $this->rollbackMigrations(array_reverse($this->getMigrationsForBatch($batch)), 'Rollback');
    }

    /**
     * Rollback all migrations for this plugin.
     *
     * @return array<string> The migrations that were rolled back
     */
    public function reset(): array
    {
        return $this->rollbackMigrations(array_reverse($this->getRan()), 'Reset');
    }

    /**
     * Run `down()` for the given migration names, in the order given, and delete their rows.
     *
     * @param array<string> $names
     * @return array<string>
     */
    protected function rollbackMigrations(array $names, string $label): array
    {
        $rolledBack = [];

        foreach ($names as $name) {
            if (!isset($this->migrations[$name]) || !$this->canRollBack($name)) {
                continue;
            }

            $migration = $this->instance($name);

            try {
                $migration->down();
                $this->delete($name);
                $rolledBack[] = $name;
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    "{$label} of {$name} failed: " . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        return $rolledBack;
    }

    /**
     * Whether this runner may roll a migration back: its own always, a framework-owned one
     * only when the runner is the framework's.
     */
    protected function canRollBack(string $name): bool
    {
        return $this->plugin === self::CORE_PLUGIN || $this->ownerOf($name) !== self::CORE_PLUGIN;
    }

    /**
     * The plugin a registered migration is recorded under: `core` for a framework-owned
     * migration (one that declares `$plugin = 'core'`), otherwise this runner's plugin — a
     * migration copied from another plugin keeps its file, not its old owner, so its rows are
     * always found again by the runner that registered it.
     *
     * @since 0.2.1 A declared `$plugin` other than `core` no longer changes the owner.
     */
    public function ownerOf(string $name): string
    {
        $class = $this->migrations[$name] ?? null;
        if ($class === null) {
            return $this->plugin;
        }

        $declared = (string) ((new \ReflectionClass($class))->getDefaultProperties()['plugin'] ?? '');

        return $declared === self::CORE_PLUGIN ? self::CORE_PLUGIN : $this->plugin;
    }

    /**
     * Get the migrations that have been run for this plugin.
     *
     * A migration counts as run when a `ran` row exists under its owner, or under this
     * plugin for rows written before framework ownership was recorded.
     *
     * @return array<string>
     */
    public function getRan(): array
    {
        return array_column($this->ranRows(), 'migration');
    }

    /**
     * The `ran` rows that belong to this runner: this plugin's own rows plus the
     * framework-owned rows of the migrations it registers.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function ranRows(): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $this->ensureSchema();

        $rows = $this->driver->select(
            "SELECT migration, plugin, batch, version, table_name, action, created_at FROM {$this->tableName}
             WHERE plugin IN (%s, %s) AND status = %s ORDER BY batch, migration",
            [$this->plugin, self::CORE_PLUGIN, self::STATUS_RAN]
        );

        $seen = [];
        $mine = [];
        foreach ($rows as $row) {
            $name = $row['migration'];

            if ($row['plugin'] !== $this->plugin && $row['plugin'] !== $this->ownerOf($name)) {
                continue;
            }
            if (isset($seen[$name])) {
                continue;
            }

            $seen[$name] = true;
            $mine[] = $row;
        }

        return $mine;
    }

    /**
     * Get all migrations that have been run (across all plugins).
     *
     * @return array<array<string, mixed>>
     */
    public function getAllRan(): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        return $this->driver->select(
            "SELECT * FROM {$this->tableName} ORDER BY created_at DESC"
        );
    }

    /**
     * Get pending migrations.
     *
     * @return array<string>
     */
    public function getPending(): array
    {
        $ran = $this->getRan();
        return array_values(array_diff(array_keys($this->migrations), $ran));
    }

    /**
     * Check if migrations table exists.
     */
    protected function tableExists(): bool
    {
        return $this->driver->tableExists($this->table);
    }

    /**
     * Record a migration run under its owner, with the runner's plugin version.
     *
     * A `failed` row for the same migration is replaced, so a retry leaves one row.
     */
    protected function log(string $migrationName, int $batch, Migration $migration, string $status = self::STATUS_RAN): void
    {
        $metadata = $migration->getMetadata();
        $owner = $this->ownerOf($migrationName);

        $this->driver->delete($this->table, [
            'migration' => $migrationName,
            'plugin' => $owner,
            'status' => self::STATUS_FAILED,
        ]);

        $this->driver->insert($this->table, [
            'migration' => $migrationName,
            'plugin' => $owner,
            'version' => $this->version,
            'batch' => $batch,
            'table_name' => $metadata['table_name'],
            'columns' => $metadata['columns'],
            'action' => $metadata['action'],
            'status' => $status,
        ]);
    }

    /**
     * Delete a migration record.
     */
    protected function delete(string $migration): void
    {
        $this->driver->delete($this->table, [
            'migration' => $migration,
            'plugin' => $this->ownerOf($migration),
        ]);
    }

    /**
     * Get the next batch number.
     */
    protected function getNextBatch(): int
    {
        return $this->getLastBatch() + 1;
    }

    /**
     * Get the last batch number for this plugin.
     */
    protected function getLastBatch(): int
    {
        if (!$this->tableExists()) {
            return 0;
        }

        $batch = $this->driver->selectVar(
            "SELECT MAX(batch) FROM {$this->tableName} WHERE plugin = %s",
            [$this->plugin]
        );

        return (int) ($batch ?? 0);
    }

    /**
     * Get migrations for a batch.
     *
     * @return array<string>
     */
    protected function getMigrationsForBatch(int $batch): array
    {
        $rows = $this->driver->select(
            "SELECT migration FROM {$this->tableName} WHERE batch = %d AND plugin = %s ORDER BY migration",
            [$batch, $this->plugin]
        );

        return array_column($rows, 'migration');
    }

    /**
     * Get migration status for this plugin.
     *
     * @return array<array{name: string, ran: bool, batch: int|null, version: string|null, table_name: string|null, action: string|null, created_at: string|null}>
     */
    public function status(): array
    {
        $ran = [];
        foreach ($this->ranRows() as $row) {
            $ran[$row['migration']] = $row;
        }

        $status = [];
        foreach (array_keys($this->migrations) as $name) {
            $status[] = [
                'name' => $name,
                'ran' => isset($ran[$name]),
                'batch' => isset($ran[$name]) ? (int) $ran[$name]['batch'] : null,
                'version' => $ran[$name]['version'] ?? null,
                'table_name' => $ran[$name]['table_name'] ?? null,
                'action' => $ran[$name]['action'] ?? null,
                'created_at' => $ran[$name]['created_at'] ?? null,
            ];
        }

        return $status;
    }

    /**
     * Get migration history for this plugin (every row, `failed` ones included).
     *
     * @return array<array<string, mixed>>
     */
    public function history(): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $this->ensureSchema();

        return $this->driver->select(
            "SELECT * FROM {$this->tableName} WHERE plugin = %s ORDER BY created_at DESC, id DESC",
            [$this->plugin]
        );
    }

    /**
     * The fully qualified class declared in a PHP file, from its `namespace` and `class` statements.
     */
    protected function classInFile(string $file): ?string
    {
        $source = (string) file_get_contents($file, false, null, 0, 65536);

        if (!preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $source, $class)) {
            return null;
        }

        $namespace = preg_match('/^\s*namespace\s+([^;\s]+)\s*;/m', $source, $ns) ? $ns[1] . '\\' : '';

        return $namespace . $class[1];
    }
}
