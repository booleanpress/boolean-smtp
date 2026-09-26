<?php
/**
 * Detects other SMTP plugins installed on the site and runs the import against them.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration;

use BooleanSmtp\Contracts\EncryptorContract;
use BooleanSmtp\Core\Foundation\Application;
use BooleanSmtp\Repositories\ConnectionRepository;
use BooleanSmtp\Services\Connection\ConnectionHealthProbe;
use BooleanSmtp\Services\Senders\SenderGuard;
use BooleanSmtp\Services\Migration\Assessment\ConnectionAssessment;
use BooleanSmtp\Services\Migration\Assessment\ConnectionAssessor;
use BooleanSmtp\Services\Migration\Logs\LogMapper;
use BooleanSmtp\Services\Migration\Logs\LogWindow;
use BooleanSmtp\Services\Migration\Sources\EasyWpSmtpSource;
use BooleanSmtp\Services\Migration\Sources\FluentSmtpSource;
use BooleanSmtp\Services\Migration\Sources\GoSmtpSource;
use BooleanSmtp\Services\Migration\Sources\PostSmtpSource;
use BooleanSmtp\Services\Migration\Sources\SureMailSource;
use BooleanSmtp\Services\Migration\Sources\WpMailSmtpSource;
use BooleanSmtp\Support\Settings;

/**
 * The facade the REST layer, the setup wizard, the CLI command and the onboarding Apply step
 * use: the catalog of sources, the scan of what is present, and one import run.
 *
 * @since 1.0.0
 */
class MigrationScanner
{
    /**
     * Supported source plugins, keyed by migration source id.
     *
     * Each entry names the source plugin, lists the relative plugin file(s) WordPress uses to
     * determine whether that plugin is installed or active, the adapter that reads it, and
     * whether the adapter is supported for import — proven against a fixture of the plugin's
     * real settings. The setup wizard offers an import only for supported sources.
     *
     * @since 1.0.0
     * @var array<string, array{name: string, plugin_files: list<string>, source: class-string, supported: bool}>
     */
    private const SOURCES = [
        'fluent-smtp' => [
            'name'         => 'FluentSMTP',
            'plugin_files' => ['fluent-smtp/fluent-smtp.php'],
            'source'       => FluentSmtpSource::class,
            'supported'    => true,
        ],
        'wp-mail-smtp' => [
            'name'         => 'WP Mail SMTP',
            'plugin_files' => ['wp-mail-smtp/wp_mail_smtp.php', 'wp-mail-smtp-pro/wp_mail_smtp.php'],
            'source'       => WpMailSmtpSource::class,
            'supported'    => true,
        ],
        'post-smtp' => [
            'name'         => 'Post SMTP',
            'plugin_files' => ['post-smtp/postman-smtp.php'],
            'source'       => PostSmtpSource::class,
            'supported'    => true,
        ],
        'suremails' => [
            'name'         => 'SureMail',
            'plugin_files' => ['suremails/suremails.php'],
            'source'       => SureMailSource::class,
            'supported'    => true,
        ],
        'easy-wp-smtp' => [
            'name'         => 'Easy WP SMTP',
            'plugin_files' => ['easy-wp-smtp/easy-wp-smtp.php', 'easy-wp-smtp-pro/easy-wp-smtp.php'],
            'source'       => EasyWpSmtpSource::class,
            'supported'    => true,
        ],
        'gosmtp' => [
            'name'         => 'GoSMTP',
            'plugin_files' => ['gosmtp-pro/gosmtp-pro.php', 'gosmtp/gosmtp.php'],
            'source'       => GoSmtpSource::class,
            'supported'    => true,
        ],
    ];

    /**
     * The runner, built on first use with every source registered.
     *
     * @since 1.0.0
     * @var MigrationRunner|null
     */
    private ?MigrationRunner $runner = null;

    /**
     * The migration sources the plugin knows, without looking at the site: id, display name and
     * whether a migrator exists for it. The admin UI names only the supported ones.
     *
     * @since 1.0.0
     *
     * @return list<array{id: string, name: string, supported: bool}>
     */
    public static function catalog(): array
    {
        $catalog = [];
        foreach (self::SOURCES as $id => $source) {
            $catalog[] = ['id' => $id, 'name' => $source['name'], 'supported' => (bool) $source['supported']];
        }

        return $catalog;
    }

    /**
     * The display name of a source id.
     *
     * @since 1.0.0
     *
     * @param  string $source Source id.
     * @return string|null Null for an unknown id.
     */
    public static function nameOf(string $source): ?string
    {
        return self::SOURCES[$source]['name'] ?? null;
    }

    /**
     * @since 1.0.0
     *
     * @param Application              $app       Builds the sources and the runner's collaborators.
     * @param SourceContext            $context   Site access for the sources.
     * @param ConnectionAssessor       $assessor  Assesses each source connection.
     * @param LogMapper                $mapper    Maps log rows.
     * @param ImportRecords            $records   Re-run records and log cursors.
     * @param BooleanSmtpMigrationSink $sink      Writes drafts and log rows.
     * @param EncryptorContract        $encryptor Masks credentials in the results.
     * @param Settings                 $settings  The log retention the window follows.
     */
    public function __construct(
        private readonly Application $app,
        private readonly SourceContext $context,
        private readonly ConnectionAssessor $assessor,
        private readonly LogMapper $mapper,
        private readonly ImportRecords $records,
        private readonly BooleanSmtpMigrationSink $sink,
        private readonly EncryptorContract $encryptor,
        private readonly Settings $settings,
    ) {}

    /**
     * Scans for supported source plugins and previews what each one would import.
     *
     * A source is included when its plugin file is installed, or when its settings/tables are
     * present even without the plugin file (a "ghost" install left behind after deactivation).
     * Each entry carries `last_import`, the source's last import ({@see ImportRecords::lastRun()}),
     * or null when it was never imported.
     *
     * @since 1.0.0
     *
     * @param  bool $withCounts Preview the connection and log counts of each detected source. The
     *                          log count is one query on the source plugin's log table; a caller
     *                          that only needs to know what is there passes false and gets `null`
     *                          for both counts.
     * @return array<string, array<string, mixed>> Detected sources, keyed by migration source id.
     */
    public function scan(bool $withCounts = true): array
    {
        $detected = [];
        $runner   = $this->runner();
        $window   = LogWindow::forRetention($this->retentionDays(), $this->maxLogRows());

        foreach (self::SOURCES as $slug => $meta) {
            $source = $runner->getSource($slug);
            if ($source === null) {
                continue;
            }

            $installed = $this->isAnyPluginInstalled($meta['plugin_files']);
            $active    = $this->isAnyPluginActive($meta['plugin_files']);
            $available = $source->isAvailable($this->context);

            if (! $installed && ! $available) {
                continue;
            }

            $preview = $withCounts
                ? $runner->previewCounts($slug, $window)
                : ['connections' => null, 'logs' => null, 'logs_available' => null, 'logs_reason' => null];

            $detected[$slug] = [
                'name'                => $meta['name'],
                'slug'                => $slug,
                'supported'           => $meta['supported'],
                'is_installed'        => $installed,
                'is_active'           => $active,
                'has_settings'        => $available,
                'is_ghost'            => ! $installed && $available,
                'preview_connections' => $preview['connections'],
                'preview_logs'        => $preview['logs'],
                'logs_available'      => $preview['logs_available'],
                'logs_reason'         => $preview['logs_reason'],
                'details'             => [
                    'connection_count' => $preview['connections'],
                    'log_count'        => $preview['logs'],
                ],
                'last_import'         => $this->records->lastRun($slug),
            ];
        }

        return $detected;
    }

    /**
     * Assesses (dry run) or imports one supported source plugin: its connections and, when
     * asked, one chunk of its email log.
     *
     * Fires `boolean_smtp_before_migration_import` before the run starts, and
     * `boolean_smtp_after_migration_import` once for every outcome: unknown source, unavailable
     * source, a thrown exception, or a completed run.
     *
     * A real run (not a dry run) is recorded as the source's last import; every completed run
     * returns that record as `last_import`.
     *
     * @since 1.0.0
     *
     * @param  string               $source         Migration source id, one of the keys of {@see SOURCES}.
     * @param  array<string, mixed> $requestOptions `dry_run`, `import_connections`, `import_logs`, `max_logs`, `restart_logs`, `retention_days` (the window's retention instead of the stored setting, for a dry run), `resolutions` (source connection key => `keep`|`replace`, for a connection whose sender the site already uses; `keep` when absent).
     * @return array<string, mixed> The run's result (see {@see MigrationResult::toArray()}) with `last_import`, plus `error` when the run could not start.
     */
    public function importFrom(string $source, array $requestOptions = []): array
    {
        /**
         * Fires before a migration import from another plugin starts.
         *
         * @since 1.0.0
         *
         * @param string               $source         Migration source id.
         * @param array<string, mixed> $requestOptions Raw request options for the import.
         */
        \do_action('boolean_smtp_before_migration_import', $source, $requestOptions);

        if (! isset(self::SOURCES[$source])) {
            return $this->finish($source, ['success' => false, 'error' => "Unknown source plugin: {$source}"]);
        }

        $options = new MigrationOptions(
            dryRun: (bool) ($requestOptions['dry_run'] ?? false),
            importConnections: (bool) ($requestOptions['import_connections'] ?? true),
            importLogs: (bool) ($requestOptions['import_logs'] ?? false),
            maxLogRows: isset($requestOptions['max_logs']) ? (int) $requestOptions['max_logs'] : $this->maxLogRows(),
            retentionDays: isset($requestOptions['retention_days']) ? max(0, (int) $requestOptions['retention_days']) : $this->retentionDays(),
            restartLogs: (bool) ($requestOptions['restart_logs'] ?? false),
            resolutions: self::resolutions($requestOptions['resolutions'] ?? []),
        );

        try {
            $run = $this->runner()->run($source, $options);
        } catch (\Throwable $e) {
            return $this->finish($source, ['success' => false, 'error' => $e->getMessage()]);
        }

        if (! $options->dryRun) {
            $this->testReplaced($run->connections);
        }

        $result = $run->toArray($this->encryptor);
        if (! $run->ok() && $run->connections === [] && $run->logs === []) {
            $result['error'] = $run->errors[0];
        }

        if (! $options->dryRun) {
            $this->rememberRun($source, $options, $result);
        }
        $result['last_import'] = $this->records->lastRun($source);

        return $this->finish($source, $result);
    }

    /**
     * Record what a real run did, so the screens can say the source was already imported.
     *
     * @since 1.0.0
     *
     * @param  string               $source  Source id.
     * @param  MigrationOptions     $options The run's options.
     * @param  array<string, mixed> $result  The run's result as returned to the caller.
     * @return void
     */
    private function rememberRun(string $source, MigrationOptions $options, array $result): void
    {
        $connections = \is_array($result['connections'] ?? null) ? $result['connections'] : [];
        if ($options->importConnections && $connections !== []) {
            $count = static fn (callable $test): int => \count(array_filter($connections, $test));
            $this->records->rememberConnectionRun(
                $source,
                $count(static fn (array $c): bool => ($c['outcome'] ?? null) === 'imported'),
                $count(static fn (array $c): bool => ($c['outcome'] ?? null) === 'replaced'),
                $count(static fn (array $c): bool => ($c['outcome'] ?? null) === 'kept'),
                $count(static fn (array $c): bool => ($c['outcome'] ?? null) === 'skipped'),
            );
        }

        $logs = \is_array($result['logs'] ?? null) ? $result['logs'] : [];
        if ($options->importLogs && ! empty($logs['done']) && ! empty($logs['available'])) {
            $this->records->rememberLogRun($source, (int) ($logs['imported'] ?? 0));
        }
    }

    /**
     * The runner with every catalogued source registered.
     *
     * @since 1.0.0
     *
     * @return MigrationRunner
     */
    public function runner(): MigrationRunner
    {
        if ($this->runner === null) {
            $this->runner = new MigrationRunner($this->context, $this->sink, $this->assessor, $this->mapper, $this->records, $this->app->make(SenderGuard::class));
            foreach (self::SOURCES as $meta) {
                $this->runner->register($this->app->make($meta['source']));
            }
        }

        return $this->runner;
    }

    /**
     * The log retention in days the import window follows (0 keeps everything).
     *
     * @since 1.0.0
     *
     * @return int
     */
    public function retentionDays(): int
    {
        return max(0, (int) $this->settings->get('log_retention_days', 30));
    }

    /**
     * The cap on imported log rows.
     *
     * @since 1.0.0
     *
     * @return int
     */
    public function maxLogRows(): int
    {
        /**
         * Filters the maximum number of email-log rows an import reads from another plugin.
         *
         * @since 1.0.0
         *
         * @param int $max Rows, newest first; 0 for no cap. Default 5000.
         * @return int
         */
        return max(0, (int) \apply_filters('boolean_smtp_migration_max_log_rows', MigrationOptions::DEFAULT_MAX_LOG_ROWS));
    }

    /**
     * The keep/replace answers from a request, keyed by source connection key.
     *
     * @since 1.0.0
     *
     * @param  mixed $raw `resolutions` from the request.
     * @return array<string, string>
     */
    private static function resolutions(mixed $raw): array
    {
        $out = [];
        foreach (\is_array($raw) ? $raw : [] as $key => $answer) {
            $out[(string) $key] = $answer === 'replace' ? 'replace' : 'keep';
        }

        return $out;
    }

    /**
     * Test every connection the run replaced, record the outcome on it and on the assessment,
     * so the result shows at once whether the new settings send.
     *
     * @since 1.0.0
     *
     * @param  list<ConnectionAssessment> $assessments The run's assessments.
     * @return void
     */
    private function testReplaced(array $assessments): void
    {
        $repository = $this->app->make(ConnectionRepository::class);
        $probe      = $this->app->make(ConnectionHealthProbe::class);

        foreach ($assessments as $assessment) {
            if ($assessment->outcome !== 'replaced' || $assessment->connectionId === null) {
                continue;
            }

            $connection = $repository->find($assessment->connectionId);
            if ($connection === null) {
                continue;
            }

            try {
                $raw    = $connection->settings ?? [];
                $result = $probe->probe($connection, \is_array($raw) ? $this->encryptor->decryptArray($raw) : [], false);
            } catch (\Throwable $e) {
                $result = ['healthy' => false, 'error' => $e->getMessage()];
            }

            $healthy = (bool) ($result['healthy'] ?? false);
            $error   = $healthy ? null : (string) ($result['error'] ?? 'Connection test failed.');
            $repository->updateHealthStatus((int) $connection->id, $healthy ? 'healthy' : 'error', $error);
            $assessment->test = ['healthy' => $healthy, 'error' => $error];
        }
    }

    /**
     * Fire the after-import hook and hand the result back.
     *
     * @since 1.0.0
     *
     * @param  string               $source Migration source id.
     * @param  array<string, mixed> $result The run's result.
     * @return array<string, mixed>
     */
    private function finish(string $source, array $result): array
    {
        /**
         * Fires after a migration import from another plugin has finished.
         *
         * @since 1.0.0
         *
         * @param string               $source Migration source id.
         * @param array<string, mixed> $result Import result, including success flag and any errors.
         */
        \do_action('boolean_smtp_after_migration_import', $source, $result);

        return $result;
    }

    /**
     * Determines whether any of the given plugin files exist in the plugins directory.
     *
     * @since 1.0.0
     *
     * @param  list<string> $files Relative plugin file paths to check.
     * @return bool
     */
    private function isAnyPluginInstalled(array $files): bool
    {
        if (! \defined('WP_PLUGIN_DIR')) {
            return false;
        }
        foreach ($files as $relativePath) {
            if (\file_exists(\WP_PLUGIN_DIR . '/' . $relativePath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determines whether any of the given plugin files are active.
     *
     * @since 1.0.0
     *
     * @param  list<string> $files Relative plugin file paths to check.
     * @return bool
     */
    private function isAnyPluginActive(array $files): bool
    {
        if (! \function_exists('is_plugin_active')) {
            return false;
        }
        foreach ($files as $relativePath) {
            if (\is_plugin_active($relativePath)) {
                return true;
            }
        }

        return false;
    }
}
