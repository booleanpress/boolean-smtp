<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Database\Migration;

use BooleanSmtp\Core\Database\Drivers\DriverInterface;
use function BooleanSmtp\Core\app;
use function BooleanSmtp\Core\class_basename;

/**
 * Base Migration Class
 *
 * Abstract class for database migrations in WordPress environment.
 * Uses DriverInterface for database operations.
 */
abstract class Migration
{
    /**
     * The migration name.
     */
    protected string $name = '';

    /**
     * The plugin that owns this migration (e.g., 'todo', 'shop', 'blog').
     *
     * Leave empty to record the migration under the plugin whose runner executes it.
     * Framework migrations that create shared tables declare `core`.
     */
    protected string $plugin = '';

    /**
     * The plugin version when this migration was created.
     */
    protected string $version = '1.0.0';

    /**
     * The target table name (without prefix).
     */
    protected string $tableName = '';

    /**
     * The columns being added/modified/removed.
     *
     * @var array<string, string>
     */
    protected array $columns = [];

    /**
     * The migration action type.
     */
    protected string $action = 'create';

    /**
     * The database driver.
     */
    protected ?DriverInterface $driver = null;

    /**
     * Create a new migration instance.
     */
    public function __construct()
    {
        $this->driver = app(DriverInterface::class);
    }

    /**
     * Run the migrations.
     */
    abstract public function up(): void;

    /**
     * Reverse the migrations.
     */
    abstract public function down(): void;

    /**
     * Get the table name with prefix.
     */
    protected function table(string $name): string
    {
        return $this->driver->getTable($name);
    }

    /**
     * Get the database driver.
     */
    protected function db(): DriverInterface
    {
        return $this->driver;
    }

    /**
     * Execute a raw SQL query.
     */
    protected function query(string $sql): bool|int
    {
        return $this->driver->statement($sql);
    }

    /**
     * Execute dbDelta for safe table creation/updates.
     *
     * @param array<string>|string $sql
     * @return array<string>
     */
    protected function dbDelta(array|string $sql): array
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        return \dbDelta($sql);
    }

    /**
     * Get the migration name.
     */
    public function getName(): string
    {
        return $this->name ?: class_basename($this);
    }

    /**
     * Set the migration name — for a migration loaded from a date-named file, the file name.
     *
     * @since 0.2.1
     *
     * @param string $name Migration key, e.g. `2026_01_01_000001_create_items_table`.
     */
    public function withName(string $name): static
    {
        $this->name = $name;

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
     * Get the plugin version.
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Get the target table name.
     */
    public function getTableName(): string
    {
        return $this->tableName;
    }

    /**
     * Get the columns being modified.
     *
     * @return array<string, string>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Get the migration action type.
     */
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * Get all migration metadata for tracking.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return [
            'plugin' => $this->plugin,
            'version' => $this->version,
            'table_name' => $this->tableName,
            'columns' => json_encode($this->columns),
            'action' => $this->action,
        ];
    }
}
