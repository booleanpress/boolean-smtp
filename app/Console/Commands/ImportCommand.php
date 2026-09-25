<?php
/**
 * WP-CLI command that assesses or imports another SMTP plugin's connections and email log.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Console\Commands;

use BooleanSmtp\Core\Console\Command;
use BooleanSmtp\Services\Migration\MigrationScanner;
use BooleanSmtp\Services\Migration\SourceContext;

/**
 * `wp boolean-smtp import <source> [--dry-run] [--no-connections] [--logs] [--max-logs=<n>]
 * [--restart-logs] [--on-conflict=<keep|replace>] [--keys]`, plus `wp boolean-smtp import --probe`, which proves that every
 * installed source's stored SMTP credential decodes — printing its shape only, never the value.
 *
 * @since 1.0.0
 */
class ImportCommand extends Command {
    /**
     * @since 1.0.0
     * @var string
     */
    protected string $name = 'import';

    /**
     * @since 1.0.0
     * @var string
     */
    protected string $description = 'Assess or import connections and email logs from another SMTP plugin.';

    /**
     * @since 1.0.0
     * @var array<array<string, mixed>>
     */
    protected array $synopsis = [
        ['type' => 'positional', 'name' => 'source', 'description' => 'Source id: fluent-smtp, wp-mail-smtp, post-smtp, easy-wp-smtp, suremails or gosmtp.', 'optional' => true],
        ['type' => 'flag', 'name' => 'dry-run', 'description' => 'Assess only; write nothing.', 'optional' => true],
        ['type' => 'flag', 'name' => 'connections', 'description' => 'Import the connections; on by default, so `--no-connections` skips them.', 'optional' => true],
        ['type' => 'flag', 'name' => 'logs', 'description' => 'Import the email log too (or only, with --no-connections).', 'optional' => true],
        ['type' => 'assoc', 'name' => 'max-logs', 'description' => 'Cap on log rows, newest first (0 for no cap).', 'optional' => true],
        ['type' => 'flag', 'name' => 'restart-logs', 'description' => 'Start the log import over instead of resuming.', 'optional' => true],
        ['type' => 'assoc', 'name' => 'on-conflict', 'description' => 'When an imported connection uses a sender the site already has: keep the site\'s connection (default) or replace it with the imported one.', 'optional' => true, 'options' => ['keep', 'replace'], 'default' => 'keep'],
        ['type' => 'flag', 'name' => 'keys', 'description' => 'Print each connection\'s key inside the source (with --dry-run).', 'optional' => true],
        ['type' => 'flag', 'name' => 'probe', 'description' => 'Check that every installed source\'s stored SMTP credential decodes; prints length and shape only.', 'optional' => true],
    ];

    /**
     * Column headers of the assessment table.
     *
     * @since 1.0.0
     * @var list<string>
     */
    public const FIELDS = ['Name', 'Driver', 'Status', 'Missing', 'Issues', 'From', 'Default', 'Connection', 'Same sender as'];

    /**
     * @since 1.0.0
     *
     * @param MigrationScanner $migrations The import facade.
     * @param SourceContext    $context    Site access for the probe.
     */
    public function __construct(
        private readonly MigrationScanner $migrations,
        private readonly SourceContext $context,
    ) {}

    /**
     * Run the command.
     *
     * @since 1.0.0
     *
     * @param array<int, string>   $args      `[source]`.
     * @param array<string, mixed> $assocArgs The flags.
     */
    public function handle(array $args, array $assocArgs): void {
        if (! empty($assocArgs['probe'])) {
            $this->probe();

            return;
        }
        $source = trim((string) ($args[0] ?? ''));
        if ($source === '') {
            $this->error('Name a source: ' . implode(', ', array_column(MigrationScanner::catalog(), 'id')) . ' — or pass --probe.');

            return;
        }
        $dryRun  = ! empty($assocArgs['dry-run']);
        $options = [
            'dry_run'            => $dryRun,
            // WP-CLI turns `--no-connections` into `connections => false`.
            'import_connections' => ($assocArgs['connections'] ?? true) !== false,
            'import_logs'        => ! empty($assocArgs['logs']),
            'restart_logs'       => ! empty($assocArgs['restart-logs']),
            'resolutions'        => ['*' => ($assocArgs['on-conflict'] ?? 'keep') === 'replace' ? 'replace' : 'keep'],
        ];
        if (isset($assocArgs['max-logs']) && $assocArgs['max-logs'] !== '') {
            $options['max_logs'] = (int) $assocArgs['max-logs'];
        }

        $result = $this->migrations->importFrom($source, $options);
        if (isset($result['error'])) {
            $this->error((string) $result['error']);

            return;
        }

        if ($options['import_connections']) {
            $this->printConnections($result['connections'] ?? [], ! empty($assocArgs['keys']));
            if (! $dryRun) {
                $this->printDecisions($result['connections'] ?? []);
            }
        }

        if ($options['import_logs']) {
            $logs = $result['logs'] ?? [];
            if (empty($logs['available'])) {
                $this->line('logs: not available — ' . (string) ($logs['reason'] ?? ''));
            } elseif ($dryRun) {
                $this->line(sprintf('logs: %d rows would be imported (%d in the retention window)', (int) ($logs['total'] ?? 0), (int) ($logs['rows_in_window'] ?? 0)));
            } else {
                $imported = (int) ($logs['imported'] ?? 0);
                $total    = (int) ($logs['total'] ?? 0);
                while (empty($logs['done'])) {
                    $this->line(sprintf('logs: %d of %d imported…', $imported, $total));
                    $next = $this->migrations->importFrom($source, ['dry_run' => false, 'import_connections' => false, 'import_logs' => true] + (isset($options['max_logs']) ? ['max_logs' => $options['max_logs']] : []));
                    $logs = $next['logs'] ?? ['done' => true];
                    $imported = (int) ($logs['imported'] ?? $imported);
                }
                $skipped = $logs['skipped'] ?? [];
                $this->line(sprintf(
                    'logs: imported %d, skipped %d (pending %d, existing %d, invalid %d)',
                    $imported,
                    array_sum(array_map('intval', $skipped)),
                    (int) ($skipped['pending'] ?? 0),
                    (int) ($skipped['existing'] ?? 0),
                    (int) ($skipped['invalid'] ?? 0)
                ));
            }
        }

        foreach ($result['errors'] ?? [] as $error) {
            $this->warning((string) $error);
        }
        if (! empty($result['errors'])) {
            $this->error('The run finished with errors.');

            return;
        }
        $this->success($dryRun ? 'Assessment complete; nothing was written.' : 'Import complete.');
    }

    /**
     * Print the assessment table.
     *
     * @since 1.0.0
     *
     * @param list<array<string, mixed>> $connections The assessed connections.
     * @param bool                       $withKeys    Whether to add the source key column.
     */
    private function printConnections(array $connections, bool $withKeys): void {
        if ($connections === []) {
            $this->line('connections: none found');

            return;
        }
        $fields = self::FIELDS;
        if ($withKeys) {
            $fields[] = 'Key';
        }
        $rows = [];
        foreach ($connections as $c) {
            $row = [
                'Name'       => (string) ($c['name'] ?? ''),
                'Driver'     => (string) ($c['driver'] ?? '—'),
                'Status'     => (string) ($c['status'] ?? ''),
                'Missing'    => empty($c['missing']) ? '—' : implode(', ', $c['missing']),
                'Issues'     => empty($c['issues']) ? '—' : implode('; ', array_map(static fn (array $i): string => $i['key'] . ': ' . $i['message'], $c['issues'])),
                'From'       => (string) ($c['settings']['from_email'] ?? ''),
                'Default'    => ! empty($c['was_default']) ? 'default' : '',
                'Connection' => isset($c['connection_id']) ? (string) $c['connection_id'] : '',
                'Same sender as' => self::sameSenderAs($c),
            ];
            if (! empty($c['reason'])) {
                $row['Issues'] = (string) $c['reason'];
            }
            if ($withKeys) {
                $row['Key'] = (string) ($c['source_key'] ?? '');
            }
            $rows[] = $row;
        }
        $this->line(sprintf('connections: %d', \count($rows)));
        \WP_CLI\Utils\format_items('table', $rows, $fields);
    }

    /**
     * The "Same sender as" cell: the site connection that already uses the sender, or the
     * connection of the same source that keeps it.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $c An assessed connection.
     * @return string
     */
    private static function sameSenderAs(array $c): string {
        if (! empty($c['sender_conflict'])) {
            return sprintf('%s (#%d)', (string) $c['sender_conflict']['existing_name'], (int) $c['sender_conflict']['existing_id']);
        }
        if (! empty($c['skipped_reason'])) {
            return sprintf("skipped — same sender as '%s' in this source", (string) $c['skipped_reason']);
        }

        return '—';
    }

    /**
     * One line per connection the run imported, replaced, kept or skipped, the test result of
     * each replaced one, and a summary.
     *
     * @since 1.0.0
     *
     * @param list<array<string, mixed>> $connections The assessed connections.
     */
    private function printDecisions(array $connections): void {
        $counts = ['imported' => 0, 'kept' => 0, 'replaced' => 0, 'skipped' => 0];
        foreach ($connections as $c) {
            $name     = (string) ($c['name'] ?? '');
            $conflict = $c['sender_conflict'] ?? null;
            switch ($c['outcome'] ?? null) {
                case 'imported':
                    $this->line(sprintf('Imported: %s', $name));
                    break;
                case 'kept':
                    $this->line(sprintf('Kept existing: %s (#%d) — not imported: %s', (string) $conflict['existing_name'], (int) $conflict['existing_id'], $name));
                    break;
                case 'replaced':
                    $this->line(sprintf('Replaced: %s (#%d) with %s', (string) $conflict['existing_name'], (int) $conflict['existing_id'], $name));
                    $test = $c['test'] ?? null;
                    if (\is_array($test)) {
                        $this->line(sprintf('Tested: %s (#%d) — %s', $name, (int) $c['connection_id'], $test['healthy'] ? 'healthy' : 'failed: ' . (string) $test['error']));
                    }
                    break;
                case 'skipped':
                    $this->line(sprintf("Skipped: %s — same sender as '%s' in this source", $name, (string) $c['skipped_reason']));
                    break;
                default:
                    continue 2;
            }
            $counts[$c['outcome']]++;
        }

        $this->line(sprintf(
            'connections: %d imported, %d kept existing, %d replaced, %d skipped',
            $counts['imported'],
            $counts['kept'],
            $counts['replaced'],
            $counts['skipped']
        ));
    }

    /**
     * For every installed source, decode the stored SMTP credential in memory and print only
     * whether it decoded and what shape it has.
     *
     * @since 1.0.0
     */
    private function probe(): void {
        $runner = $this->migrations->runner();
        $found  = 0;
        foreach ($runner->registeredSourceIds() as $id) {
            $source = $runner->getSource($id);
            if ($source === null || ! $source->isAvailable($this->context)) {
                continue;
            }
            foreach ($source->extractConnections($this->context) as $connection) {
                if ($connection->driver !== 'smtp' || empty($connection->settings['authentication'])) {
                    continue;
                }
                $found++;
                $password = (string) ($connection->settings['password'] ?? '');
                $decoded  = $password !== '' && ! \in_array('password', $connection->missingSecrets, true);
                $this->line(sprintf('%-14s %-28s decoded=%s shape=%s', $id, \mb_substr($connection->name, 0, 28), $decoded ? 'yes' : 'no', self::shape($password)));
            }
        }
        if ($found === 0) {
            $this->line('No source with an authenticated SMTP connection is installed.');
        }
    }

    /**
     * The shape of a decoded credential, never its value.
     *
     * @since 1.0.0
     *
     * @param  string $value The credential.
     * @return string `app-password` (16 letters, spaces allowed), `empty`, or `other:<length>`.
     */
    public static function shape(string $value): string {
        if ($value === '') {
            return 'empty';
        }
        if (preg_match('/^[a-z]{16}$/', str_replace(' ', '', $value))) {
            return 'app-password';
        }

        return 'other:' . strlen($value);
    }
}
