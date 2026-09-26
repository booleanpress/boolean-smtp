<?php
/**
 * WP-CLI command that prints email logs as JSON.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Console\Commands;

use BooleanSmtp\Core\Console\Command;
use BooleanSmtp\Repositories\EmailLogRepository;

/**
 * `wp boolean-smtp logs:export` — print the recent email logs as one JSON document on standard
 * output, so they can be piped or saved with a shell redirect (`wp boolean-smtp logs:export > logs.json`).
 * The command writes no file itself.
 *
 * @since 1.0.0
 */
class LogsExportCommand extends Command {
    /**
     * @since 1.0.0
     * @var string
     */
    protected string $name = 'logs:export';

    /**
     * @since 1.0.0
     * @var string
     */
    protected string $description = 'Print BooleanSMTP email logs as JSON (redirect the output to save it).';

    /**
     * @since 1.0.0
     * @var array<array<string, mixed>>
     */
    protected array $synopsis = [
        ['type' => 'assoc', 'name' => 'days', 'description' => 'Export logs from the last N days.', 'optional' => true, 'default' => 30],
        ['type' => 'assoc', 'name' => 'status', 'description' => 'Only logs with this status (delivered, failed, pending, simulated); comma-separate several.', 'optional' => true],
    ];

    /**
     * Logs fetched per page while exporting.
     *
     * @since 1.0.0
     * @var int
     */
    private const PAGE_SIZE = 100;

    /**
     * @since 1.0.0
     *
     * @param EmailLogRepository $logs Source of the log rows.
     */
    public function __construct(private readonly EmailLogRepository $logs) {}

    /**
     * Collect the matching logs page by page and print them as one JSON document.
     *
     * @since 1.0.0
     *
     * @param array<int, string>   $args      Positional arguments; unused.
     * @param array<string, mixed> $assocArgs `days` (int, default 30), `status` (string).
     */
    public function handle(array $args, array $assocArgs): void {
        $days   = max(1, (int) $this->option($assocArgs, 'days', 30));
        $status = trim((string) $this->option($assocArgs, 'status', ''));

        $json = json_encode($this->export($days, $status), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            $this->error('The email logs could not be encoded as JSON.');

            return;
        }

        \WP_CLI::line($json);
    }

    /**
     * The export document: metadata plus every log matching the window and status filter.
     *
     * @since 1.0.0
     *
     * @param  int    $days   Number of days to look back from now (UTC).
     * @param  string $status Status filter; empty for all statuses.
     * @return array{exported_at: string, total: int, filters: array<string, string>, logs: list<array<string, mixed>>}
     */
    public function export(int $days, string $status = ''): array {
        $filters = ['date_from' => gmdate('Y-m-d H:i:s', time() - ($days * 86400))];
        if ($status !== '') {
            $filters['status'] = $status;
        }

        $logs = [];
        $page = 1;

        do {
            $result = $this->logs->paginate(self::PAGE_SIZE, $page, $filters);
            $data   = $result['data'] ?? [];
            foreach ($data as $row) {
                $logs[] = $row;
            }
            $lastPage = (int) ($result['last_page'] ?? 1);
            $page++;
        } while ($page <= $lastPage && $data !== []);

        return [
            'exported_at' => gmdate('c'),
            'total'       => count($logs),
            'filters'     => $filters,
            'logs'        => $logs,
        ];
    }
}
