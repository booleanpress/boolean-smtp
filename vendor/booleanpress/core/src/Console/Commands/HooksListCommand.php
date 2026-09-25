<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Console\Commands;

use BooleanSmtp\Core\Console\Command;
use BooleanSmtp\Core\Hooks\HookRegistry;

/**
 * Hooks List Command
 *
 * WP-CLI command to list all registered hooks in the ecosystem.
 *
 * Usage:
 *   wp boolean hooks:list              List all hooks
 *   wp boolean hooks:list --plugin=x   List hooks for a specific plugin
 *   wp boolean hooks:list --type=filter List only filters
 */
class HooksListCommand extends Command
{
    protected string $name = 'hooks:list';
    protected string $description = 'List all registered BooleanPress hooks';

    /**
     * Execute the command.
     *
     * @param array<string> $args
     * @param array<string, string> $assocArgs
     */
    public function handle(array $args = [], array $assocArgs = []): void
    {
        $registry = $this->getApp()->make(HookRegistry::class);

        $hooks = $registry->all();

        // Filter by plugin
        if (isset($assocArgs['plugin'])) {
            $hooks = $registry->forPlugin($assocArgs['plugin']);
        }

        // Filter by type
        if (isset($assocArgs['type'])) {
            $type = $assocArgs['type'];
            $hooks = array_filter($hooks, fn($h) => $h->type === $type);
        }

        if (empty($hooks)) {
            $this->info('No hooks registered.');
            return;
        }

        $headers = ['Hook Name', 'Type', 'Plugin', 'Since', 'Description'];
        $rows = [];

        foreach ($hooks as $hook) {
            $rows[] = [
                $hook->name,
                $hook->type,
                $hook->plugin,
                $hook->since,
                mb_substr($hook->description, 0, 60),
            ];
        }

        $this->table($headers, $rows);
        $this->info(count($hooks) . ' hook(s) registered.');
    }
}
