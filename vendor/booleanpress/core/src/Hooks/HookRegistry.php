<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Hooks;

/**
 * Hook Registry
 *
 * Centralized registry for all BooleanPress action and filter hooks.
 * Enables addon developers to discover available extension points.
 */
class HookRegistry
{
    /**
     * All registered hook definitions.
     *
     * @var array<string, HookDefinition>
     */
    protected array $hooks = [];

    /**
     * Define (register) a hook in the registry.
     *
     * @param string               $name   Full hook name
     * @param array<string, mixed> $config Configuration: type, description, params, return, plugin, since
     */
    public function define(string $name, array $config = []): void
    {
        $this->hooks[$name] = new HookDefinition(
            name: $name,
            type: $config['type'] ?? 'action',
            description: $config['description'] ?? '',
            params: $config['params'] ?? [],
            return: $config['return'] ?? null,
            plugin: $config['plugin'] ?? 'core',
            since: $config['since'] ?? '1.0.0',
        );
    }

    /**
     * Define an action hook.
     *
     * @param string               $name        Full hook name
     * @param string               $description What the hook does
     * @param array<string, string> $params      Parameter map
     * @param string               $plugin      Plugin slug
     * @param string               $since       Version introduced
     */
    public function action(
        string $name,
        string $description = '',
        array $params = [],
        string $plugin = 'core',
        string $since = '1.0.0'
    ): void {
        $this->define($name, [
            'type'        => 'action',
            'description' => $description,
            'params'      => $params,
            'plugin'      => $plugin,
            'since'       => $since,
        ]);
    }

    /**
     * Define a filter hook.
     *
     * @param string               $name        Full hook name
     * @param string               $description What the hook does
     * @param array<string, string> $params      Parameter map
     * @param string               $return      Return type
     * @param string               $plugin      Plugin slug
     * @param string               $since       Version introduced
     */
    public function filter(
        string $name,
        string $description = '',
        array $params = [],
        string $return = 'mixed',
        string $plugin = 'core',
        string $since = '1.0.0'
    ): void {
        $this->define($name, [
            'type'        => 'filter',
            'description' => $description,
            'params'      => $params,
            'return'      => $return,
            'plugin'      => $plugin,
            'since'       => $since,
        ]);
    }

    /**
     * Get a hook definition by name.
     */
    public function get(string $name): ?HookDefinition
    {
        return $this->hooks[$name] ?? null;
    }

    /**
     * Check if a hook is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->hooks[$name]);
    }

    /**
     * Get all registered hooks.
     *
     * @return array<string, HookDefinition>
     */
    public function all(): array
    {
        return $this->hooks;
    }

    /**
     * Get all hooks for a specific plugin.
     *
     * @return array<string, HookDefinition>
     */
    public function forPlugin(string $plugin): array
    {
        return array_filter($this->hooks, fn(HookDefinition $h) => $h->plugin === $plugin);
    }

    /**
     * Get all action hooks.
     *
     * @return array<string, HookDefinition>
     */
    public function actions(): array
    {
        return array_filter($this->hooks, fn(HookDefinition $h) => $h->type === 'action');
    }

    /**
     * Get all filter hooks.
     *
     * @return array<string, HookDefinition>
     */
    public function filters(): array
    {
        return array_filter($this->hooks, fn(HookDefinition $h) => $h->type === 'filter');
    }

    /**
     * Export all hooks as an array (for CLI/docs output).
     *
     * @return array<int, array<string, mixed>>
     */
    public function export(): array
    {
        return array_map(fn(HookDefinition $h) => $h->toArray(), array_values($this->hooks));
    }

    /**
     * Get the total count of registered hooks.
     */
    public function count(): int
    {
        return count($this->hooks);
    }
}
