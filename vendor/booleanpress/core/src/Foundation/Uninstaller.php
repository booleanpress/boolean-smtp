<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Foundation;

use BooleanSmtp\Core\Database\Drivers\DriverInterface;
use function BooleanSmtp\Core\app;

/**
 * Removes a plugin's database footprint on uninstall.
 *
 * Usable from a bare uninstall.php: pass a driver and no application needs to be booted.
 */
class Uninstaller
{
    /**
     * Tables shared by every BooleanPress plugin on the site (without prefix).
     *
     * @var array<string>
     */
    public const SHARED_TABLES = ['booleanpress_options', 'booleanpress_jobs', 'booleanpress_revisions', 'booleanpress_migrations'];

    /**
     * The database driver.
     */
    protected DriverInterface $driver;

    /**
     * Create a new uninstaller instance.
     *
     * @param DriverInterface|null $driver The driver to use; resolved from the container when omitted
     */
    public function __construct(?DriverInterface $driver = null)
    {
        $this->driver = $driver ?? app(DriverInterface::class);
    }

    /**
     * Drop the specified tables.
     *
     * @param array<string> $tables Table names relative to prefix (e.g. 'booleansmtp_tasks')
     */
    public function dropTables(array $tables): void
    {
        foreach ($tables as $table) {
            $table = $this->validateTableName($table);
            $tableName = $this->driver->getTable($table);
            $result = $this->driver->statement("DROP TABLE IF EXISTS `{$tableName}`");

            if ($result === false) {
                error_log("BooleanPress Uninstaller Error: Failed to drop table {$tableName}. Error: " . $this->driver->lastError());
            }
        }
    }

    /**
     * Remove rows from a shared table for a specific plugin.
     *
     * @param string $table      The table name relative to prefix (e.g. 'booleanpress_jobs')
     * @param string $pluginSlug The plugin identifier
     */
    public function clearSharedData(string $table, string $pluginSlug): void
    {
        $table = $this->validateTableName($table);

        $result = $this->driver->delete($table, ['plugin' => $pluginSlug]);

        if ($result === false) {
            $tableName = $this->driver->getTable($table);
            error_log("BooleanPress Uninstaller Error: Failed to clear shared data from {$tableName}. Error: " . $this->driver->lastError());
        }
    }

    /**
     * Remove migration entries for a specific plugin.
     */
    public function removeMigrations(string $pluginSlug): void
    {
        $result = $this->driver->delete('booleanpress_migrations', ['plugin' => $pluginSlug]);

        if ($result === false) {
            error_log("BooleanPress Uninstaller Error: Failed to remove migrations for {$pluginSlug}. Error: " . $this->driver->lastError());
        }
    }

    /**
     * Delete options for a specific plugin from the booleanpress_options table.
     *
     * @param string $pluginSlug The plugin slug to clear options for
     */
    public function clearOptions(string $pluginSlug): void
    {
        $result = $this->driver->delete('booleanpress_options', ['plugin' => $pluginSlug]);

        if ($result === false) {
            error_log("BooleanPress Uninstaller Error: Failed to clear options for {$pluginSlug}. Error: " . $this->driver->lastError());
        }
    }

    /**
     * Delete specific option keys for a plugin from the booleanpress_options table.
     *
     * @param string        $pluginSlug The plugin slug
     * @param array<string> $keys       Option names to delete
     */
    public function deleteOptions(string $pluginSlug, array $keys): void
    {
        foreach ($keys as $key) {
            $result = $this->driver->delete('booleanpress_options', [
                'plugin'      => $pluginSlug,
                'option_name' => $key,
            ]);

            if ($result === false) {
                error_log("BooleanPress Uninstaller Error: Failed to delete option '{$key}' for {$pluginSlug}. Error: " . $this->driver->lastError());
            }
        }
    }

    /**
     * Delete WordPress options and transients whose names start with the given prefix.
     *
     * Covers `{prefix}*`, `_transient_{prefix}*`, `_transient_timeout_{prefix}*` and the
     * site-transient equivalents in the site's options table.
     *
     * @param string $prefix The option-name prefix (e.g. 'boolean_smtp_')
     */
    public function deleteWordPressOptions(string $prefix): void
    {
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $prefix)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid option prefix: %s. Only alphanumeric characters, underscores and dashes are allowed.', $prefix)
            );
        }

        $table = $this->driver->getTable('options');

        foreach (['', '_transient_', '_transient_timeout_', '_site_transient_', '_site_transient_timeout_'] as $namespace) {
            // Escape LIKE wildcards so underscores in the prefix match literally.
            $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $namespace . $prefix) . '%';

            $result = $this->driver->statement(
                "DELETE FROM `{$table}` WHERE option_name LIKE %s",
                [$like]
            );

            if ($result === false) {
                error_log("BooleanPress Uninstaller Error: Failed to delete options matching {$namespace}{$prefix}*. Error: " . $this->driver->lastError());
            }
        }
    }

    /**
     * Drop the framework's shared tables when no plugin has migration records left.
     *
     * Call after removeMigrations(); another BooleanPress plugin's rows keep the tables alive.
     *
     * @return bool True when the shared tables were dropped
     */
    public function dropSharedTablesIfUnused(): bool
    {
        if (!$this->driver->tableExists('booleanpress_migrations')) {
            $this->dropTables(self::SHARED_TABLES);

            return true;
        }

        // Rows recorded under `core` belong to the shared tables themselves, not to a plugin.
        $table = $this->driver->getTable('booleanpress_migrations');
        $remaining = (int) $this->driver->selectVar("SELECT COUNT(*) FROM `{$table}` WHERE plugin <> %s", ['core']);

        if ($remaining > 0) {
            return false;
        }

        $this->dropTables(self::SHARED_TABLES);

        return true;
    }

    /**
     * Full cleanup for a plugin: remove tables, migrations, options, and shared data.
     *
     * @param string        $pluginSlug The plugin slug
     * @param array<string> $tables     Plugin-specific table names (without prefix)
     */
    public function fullCleanup(string $pluginSlug, array $tables = []): void
    {
        // Drop plugin-specific tables
        if (!empty($tables)) {
            $this->dropTables($tables);
        }

        // Clear migration records
        $this->removeMigrations($pluginSlug);

        // Clear plugin options
        $this->clearOptions($pluginSlug);

        // Clear jobs (only plugins using the queue component create this table)
        if ($this->driver->tableExists('booleanpress_jobs')) {
            $this->clearSharedData('booleanpress_jobs', $pluginSlug);
        }
    }

    /**
     * Validate a table name to prevent SQL injection.
     *
     * @throws \InvalidArgumentException If the table name is invalid
     */
    protected function validateTableName(string $table): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid table name: %s. Only alphanumeric characters and underscores are allowed.', $table)
            );
        }
        return $table;
    }
}
