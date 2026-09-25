<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Hooks;

/**
 * Hook Definition
 *
 * Represents metadata for a registered action or filter hook.
 * Used by the HookRegistry to document available extension points.
 */
class HookDefinition
{
    /**
     * The full hook name (e.g., 'booleanpress/smtp/before_send').
     */
    public readonly string $name;

    /**
     * The hook type: 'action' or 'filter'.
     */
    public readonly string $type;

    /**
     * Human-readable description of what this hook does.
     */
    public readonly string $description;

    /**
     * Parameters passed to the hook callback.
     *
     * @var array<string, string> Parameter name => type/class
     */
    public readonly array $params;

    /**
     * Return type for filters.
     */
    public readonly ?string $return;

    /**
     * The plugin that registered this hook.
     */
    public readonly string $plugin;

    /**
     * Version when this hook was introduced.
     */
    public readonly string $since;

    /**
     * Create a new hook definition.
     *
     * @param string               $name        Full hook name
     * @param string               $type        'action' or 'filter'
     * @param string               $description What the hook does
     * @param array<string, string> $params      Parameter name => type map
     * @param string|null          $return      Return type for filters
     * @param string               $plugin      Plugin that registered it
     * @param string               $since       Version introduced
     */
    public function __construct(
        string $name,
        string $type = 'action',
        string $description = '',
        array $params = [],
        ?string $return = null,
        string $plugin = 'core',
        string $since = '1.0.0'
    ) {
        $this->name = $name;
        $this->type = $type;
        $this->description = $description;
        $this->params = $params;
        $this->return = $return;
        $this->plugin = $plugin;
        $this->since = $since;
    }

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name'        => $this->name,
            'type'        => $this->type,
            'description' => $this->description,
            'params'      => $this->params,
            'return'      => $this->return,
            'plugin'      => $this->plugin,
            'since'       => $this->since,
        ];
    }
}
