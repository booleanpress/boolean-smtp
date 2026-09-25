<?php
/**
 * WP-CLI command that lists the configured mail connections.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Console\Commands;

use BooleanSmtp\Core\Console\Command;
use BooleanSmtp\Models\Connection;
use BooleanSmtp\Repositories\ConnectionRepository;

/**
 * `wp boolean-smtp connections:list` — every connection with its driver, status and health.
 *
 * @since 1.0.0
 */
class ConnectionsListCommand extends Command {
    /**
     * @since 1.0.0
     * @var string
     */
    protected string $name = 'connections:list';

    /**
     * @since 1.0.0
     * @var string
     */
    protected string $description = 'List the configured BooleanSMTP connections.';

    /**
     * @since 1.0.0
     * @var array<array<string, mixed>>
     */
    protected array $synopsis = [
        ['type' => 'assoc', 'name' => 'format', 'description' => 'Output format.', 'optional' => true, 'default' => 'table', 'options' => ['table', 'json', 'csv', 'yaml', 'count']],
    ];

    /**
     * Column headers, in output order.
     *
     * @since 1.0.0
     * @var list<string>
     */
    public const FIELDS = ['ID', 'Name', 'Driver', 'Active', 'Health', 'Last Used'];

    /**
     * @since 1.0.0
     *
     * @param ConnectionRepository $connections Source of the connection list.
     */
    public function __construct(private readonly ConnectionRepository $connections) {}

    /**
     * Print the connections in the requested format.
     *
     * @since 1.0.0
     *
     * @param array<int, string>   $args      Positional arguments; unused.
     * @param array<string, mixed> $assocArgs `format` (table|json|csv|yaml|count, default table).
     */
    public function handle(array $args, array $assocArgs): void {
        $rows = $this->rows();

        if ($rows === []) {
            $this->info('No connections configured.');

            return;
        }

        \WP_CLI\Utils\format_items((string) $this->option($assocArgs, 'format', 'table'), $rows, self::FIELDS);
    }

    /**
     * One row per connection, keyed by {@see FIELDS}.
     *
     * @since 1.0.0
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array {
        return $this->connections->all()
            ->map(static fn (Connection $connection): array => [
                'ID'        => (int) $connection->id,
                'Name'      => (string) $connection->name,
                'Driver'    => (string) $connection->driver,
                'Active'    => $connection->is_active ? 'Yes' : 'No',
                'Health'    => (string) ($connection->health_status ?: 'unknown'),
                'Last Used' => (string) ($connection->last_used_at ?: 'Never'),
            ])
            ->values()
            ->toArray();
    }
}
