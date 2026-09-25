<?php

/**
 * REST controller for the debug log viewer, the active-plugin list and the migration tools.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Http\Controllers;

use BooleanSmtp\Core\Http\Controller;
use BooleanSmtp\Core\Http\JsonResponse;
use BooleanSmtp\Core\Http\Request;
use BooleanSmtp\Http\Requests\MigrationImportRequest;
use BooleanSmtp\Http\Concerns\ResolvesPagination;
use BooleanSmtp\Repositories\DebugLogRepository;
use BooleanSmtp\Services\Migration\MigrationScanner;

/**
 * Backs the Tools screen: debug logs, the active-plugin list for pickers, and migration from
 * other SMTP plugins.
 *
 * @since 1.0.0
 */
class ToolsController extends Controller {
    use ResolvesPagination;

    /**
     * Handle `GET /booleansmtp/v1/tools/debug-logs`.
     *
     * Reads the `per_page`, `page`, and `level` query parameters and returns a page of recent
     * debug log sessions.
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying the `per_page`, `page`, and `level` query
     *                          parameters.
     * @return JsonResponse Paginated debug log sessions.
     */
    public function debugLogs(Request $request): JsonResponse {
        ['page' => $page, 'per_page' => $perPage] = $this->resolvePagination($request, 50);
        $level = $request->get('level');

        $repo = $this->make(DebugLogRepository::class);

        return $this->ok($repo->listRecent($page, $perPage, $level)->toArray());
    }

    /**
     * Handle `DELETE /booleansmtp/v1/tools/debug-logs`.
     *
     * Deletes every stored debug log session.
     *
     * @since 1.0.0
     *
     * @param Request $request Unused; every stored debug log session is deleted.
     * @return JsonResponse Confirmation message with the number of sessions removed.
     */
    public function clearDebugLogs(Request $request): JsonResponse {
        $repo    = $this->make(DebugLogRepository::class);
        $deleted = $repo->clear();

        return $this->ok(null, "Debug logs cleared ({$deleted} sessions removed).");
    }

    /**
     * Handle `GET /booleansmtp/v1/tools/migration/scan`.
     *
     * Detects other SMTP plugins with settings present on the site that BooleanSMTP can migrate.
     *
     * @since 1.0.0
     *
     * @param Request $request Optional `counts` query parameter; `false`/`0` skips the per-source
     *                          connection and log counts (the log count queries the source's table).
     * @return JsonResponse The detected source plugins and what would be imported from each.
     */
    public function migrationScan(Request $request): JsonResponse {
        $scanner    = $this->make(MigrationScanner::class);
        $withCounts = !\in_array(strtolower((string) $request->query('counts', '1')), ['0', 'false', 'no'], true);
        $detected   = $scanner->scan($withCounts);

        return $this->ok($detected);
    }

    /**
     * Handle `POST /booleansmtp/v1/tools/migration/import`.
     *
     * Assesses (`dry_run`) or imports one source plugin's connections as inactive drafts and,
     * when `import_logs` is set, one chunk of its email log; the response carries the assessed
     * connections with credentials masked, the log progress (`cursor`, `done`) and the
     * Review-step suggestions. A caller repeats the request until `logs.done` is true.
     *
     * @since 1.0.0
     *
     * @param MigrationImportRequest $request `source`, `dry_run`, `import_connections`, `import_logs`, `max_logs`, `restart_logs`, `retention_days` (dry run only), `resolutions` (source connection key => `keep`|`replace` for a connection whose sender the site already uses; `keep` when absent).
     * @return JsonResponse The run's result, or a 422 error when the source is unknown or unavailable.
     */
    public function migrationImport(MigrationImportRequest $request): JsonResponse {
        $source  = trim((string) $request->get('source', ''));
        $options = [
            'dry_run'            => (bool) $request->get('dry_run', false),
            'import_connections' => (bool) $request->get('import_connections', true),
            'import_logs'        => (bool) $request->get('import_logs', false),
            'restart_logs'       => (bool) $request->get('restart_logs', false),
            'resolutions'        => (array) ($request->get('resolutions') ?? []),
        ];
        if ($request->get('max_logs') !== null && $request->get('max_logs') !== '') {
            $options['max_logs'] = (int) $request->get('max_logs');
        }
        if ($options['dry_run'] && $request->get('retention_days') !== null && $request->get('retention_days') !== '') {
            $options['retention_days'] = (int) $request->get('retention_days');
        }

        $result = $this->make(MigrationScanner::class)->importFrom($source, $options);
        if (isset($result['error'])) {
            return $this->error((string) $result['error'], 422);
        }

        return $this->ok($result, $options['dry_run'] ? 'Assessment complete.' : 'Import complete.');
    }

    /**
     * Handle `GET /booleansmtp/v1/tools/plugins`.
     *
     * Lists the active plugins (network-wide on multisite) as `value`/`label` pairs, sorted
     * alphabetically by name, for use in exclusion and routing-rule pickers.
     *
     * @since 1.0.0
     *
     * @param Request $request Unused; active plugins are read directly from WordPress.
     * @return JsonResponse Active plugins as a list of `{value, label}` entries.
     */
    public function activePlugins(Request $request): JsonResponse {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins    = get_plugins();
        $active_plugins = get_option('active_plugins', []);

        if (is_multisite()) {
            $network_active = get_site_option('active_sitewide_plugins', []);
            $active_plugins = array_merge($active_plugins, array_keys($network_active));
        }

        $active_plugins = array_unique($active_plugins);
        $result         = [];

        foreach ($active_plugins as $plugin_file) {
            if (isset($all_plugins[$plugin_file])) {
                $result[] = [
                    'value' => $plugin_file,
                    'label' => $all_plugins[$plugin_file]['Name']
                ];
            }
        }

        // Sort alphabetically by name
        usort($result, fn($a, $b) => strcmp($a['label'], $b['label']));

        return $this->ok($result);
    }
}
