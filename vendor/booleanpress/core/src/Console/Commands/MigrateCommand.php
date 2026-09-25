<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Console\Commands;

use BooleanSmtp\Core\Console\Command;
use BooleanSmtp\Core\Database\Migration\MigrationRunner;

/**
 * Migrate Command
 *
 * WP-CLI command for managing a plugin's database migrations. Registered by the Console
 * component under the plugin's slug.
 *
 * Usage:
 *   wp <plugin-slug> migrate              Run pending migrations
 *   wp <plugin-slug> migrate --rollback   Rollback last batch
 *   wp <plugin-slug> migrate --status     Show migration status
 *   wp <plugin-slug> migrate --reset      Rollback all migrations
 */
class MigrateCommand extends Command
{
    protected string $name = 'migrate';
    protected string $description = 'Run database migrations';

    /**
     * The console command synopsis.
     *
     * @var array<array<string, mixed>>
     */
    protected array $synopsis = [
        ['type' => 'flag', 'name' => 'status', 'description' => 'Show each migration and whether it ran.', 'optional' => true],
        ['type' => 'flag', 'name' => 'rollback', 'description' => 'Roll back the last batch.', 'optional' => true],
        ['type' => 'flag', 'name' => 'reset', 'description' => 'Roll back every migration of the plugin.', 'optional' => true],
    ];

    /**
     * Execute the command.
     *
     * @param array<string> $args       Positional arguments
     * @param array<string, string> $assocArgs Named arguments
     */
    public function handle(array $args = [], array $assocArgs = []): void
    {
        if (isset($assocArgs['status'])) {
            $this->showStatus();
            return;
        }

        if (isset($assocArgs['rollback'])) {
            $this->rollback();
            return;
        }

        if (isset($assocArgs['reset'])) {
            $this->reset();
            return;
        }

        $this->runMigrations();
    }

    /**
     * Run pending migrations.
     */
    protected function runMigrations(): void
    {
        $runner = $this->getRunner();
        $migrated = $runner->run();

        if (empty($migrated)) {
            $this->info('Nothing to migrate.');
            return;
        }

        foreach ($migrated as $migration) {
            $this->success("Migrated: {$migration}");
        }

        $this->success(count($migrated) . ' migration(s) ran successfully.');
    }

    /**
     * Rollback last batch.
     */
    protected function rollback(): void
    {
        $runner = $this->getRunner();
        $rolledBack = $runner->rollback();

        if (empty($rolledBack)) {
            $this->info('Nothing to rollback.');
            return;
        }

        foreach ($rolledBack as $migration) {
            $this->success("Rolled back: {$migration}");
        }
    }

    /**
     * Reset all migrations.
     */
    protected function reset(): void
    {
        $runner = $this->getRunner();
        $rolledBack = $runner->reset();

        if (empty($rolledBack)) {
            $this->info('Nothing to reset.');
            return;
        }

        foreach ($rolledBack as $migration) {
            $this->success("Rolled back: {$migration}");
        }

        $this->success('All migrations reset.');
    }

    /**
     * Show migration status.
     */
    protected function showStatus(): void
    {
        $runner = $this->getRunner();
        $status = $runner->status();

        if (empty($status)) {
            $this->info('No migrations registered.');
            return;
        }

        $headers = ['Migration', 'Status', 'Batch', 'Version'];
        $rows = [];

        foreach ($status as $item) {
            $rows[] = [
                $item['name'],
                $item['ran'] ? 'Ran' : 'Pending',
                $item['batch'] ?? '-',
                $item['version'] ?? '-',
            ];
        }

        $this->table($headers, $rows);
    }

    /**
     * The plugin's migration runner: the one its `Plugin` binds (declared plus discovered
     * migrations), or an empty runner for the application's plugin.
     */
    protected function getRunner(): MigrationRunner
    {
        $app = $this->getApp();

        if ($app->bound(MigrationRunner::class)) {
            return $app->make(MigrationRunner::class);
        }

        return new MigrationRunner([], $app);
    }
}
