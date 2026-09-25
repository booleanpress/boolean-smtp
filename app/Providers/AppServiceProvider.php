<?php
/**
 * Registers the plugin's core service bindings and wires its WordPress integration.
 *
 * The central service provider for the free plugin: container bindings, the public hook
 * catalog, the admin page and asset loading, cron schedules, and the Pro addon extension
 * points all originate here.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Providers;

use BooleanSmtp\Adapters\Contracts\ScheduleAdapterContract;
use BooleanSmtp\Core\Container\ServiceProvider;
use BooleanSmtp\Core\Contracts\LoggerContract;
use BooleanSmtp\Core\Log\LogManager;
use BooleanSmtp\Core\Hooks\HookRegistry;
use BooleanSmtp\Contracts\ProCapabilityContract;
use BooleanSmtp\Contracts\TranslatorContract;
use BooleanSmtp\Jobs\HealthCheckJob;
use BooleanSmtp\Jobs\OAuthRefreshJob;
use BooleanSmtp\Jobs\ProcessQueueJob;
use BooleanSmtp\Jobs\PruneLogsJob;
use BooleanSmtp\Repositories\DebugLogRepository;
use BooleanSmtp\Models\EmailLog;
use BooleanSmtp\Observers\EmailLogObserver;
use BooleanSmtp\Support\BooleanSmtpWpMail;
use BooleanSmtp\Support\Hooks\HookCatalog;
use BooleanSmtp\Support\PluginLoadOrder;
use BooleanSmtp\Support\Settings;
use BooleanSmtp\Services\Mailer\OAuth\OAuthRedirectUri;
use BooleanSmtp\Services\Migration\MigrationScanner;
use BooleanSmtp\Support\NullProCapability;

/**
 * Boots the free plugin: container bindings, hooks, cron, admin UI, and Pro extension points.
 *
 * @since 1.0.0
 */
class AppServiceProvider extends ServiceProvider {
    /**
     * Script handles printed as ES modules: the admin app, the add-on's app and, while
     * `pnpm dev` runs, the Vite client.
     *
     * @since 1.0.0
     * @var list<string>
     */
    private const MODULE_SCRIPT_HANDLES = [
        'boolean-smtp-app',
        'boolean-smtp-pro-app',
        'boolean-smtp-vite-client',
    ];

    /**
     * WP-Cron events earlier builds scheduled under names the plugin no longer handles: the
     * framework scheduler's per-minute tick and the four jobs under their pre-release names.
     * Cleared on `init` when still present so they stop firing on sites that were never
     * re-activated.
     *
     * @since 1.0.0
     * @var list<string>
     */
    private const LEGACY_CRON_HOOKS = [
        'boolean-smtp_schedule_run',
        'booleansmtp_health_check',
        'booleansmtp_oauth_refresh',
        'booleansmtp_process_queue',
        'booleansmtp_prune_logs',
    ];

    /**
     * Cached translator instance, resolved lazily on first use.
     *
     * @since 1.0.0
     * @var TranslatorContract|null
     */
    private ?TranslatorContract $translator = null;

    /**
     * Bind the plugin's core service contracts and register the use case provider.
     *
     * Binds {@see \BooleanSmtp\Contracts\MailerContract} to `MailerManager`,
     * {@see \BooleanSmtp\Contracts\EncryptorContract} to `AesEncryptor`, a singleton
     * `ConnectionHealthProbe`, {@see ProCapabilityContract} to {@see NullProCapability} (the
     * Pro addon overrides this binding when active) and the framework's {@see HookRegistry} to a
     * lazily filled copy of the generated {@see HookCatalog}.
     *
     * @since 1.0.0
     */
    public function register(): void {
        $this->app->singleton(
            \BooleanSmtp\Contracts\MailerContract::class,
            \BooleanSmtp\Services\Mailer\MailerManager::class
        );

        // One settings facade per request, so every consumer shares its cache.
        $this->app->singleton(Settings::class);

        // Debug sessions are files in the plugin's log root (never database rows); the file names
        // are keyed with the site's auth salt so they cannot be guessed. Nothing is created until a
        // connection with debugging switched on sends.
        $this->app->singleton(DebugLogRepository::class, static function ($app): DebugLogRepository {
            $root = $app->make(LogManager::class)->root();

            return new DebugLogRepository(
                $root->path('debug-sessions'),
                static fn (): string => function_exists('wp_salt') ? (string) wp_salt('auth') : (defined('AUTH_KEY') ? (string) constant('AUTH_KEY') : ''),
                static fn (): bool => $root->ensure()
            );
        });

        $this->app->singleton(
            \BooleanSmtp\Contracts\EncryptorContract::class,
            \BooleanSmtp\Services\Encryption\AesEncryptor::class
        );

        $this->app->singleton(
            \BooleanSmtp\Services\Connection\ConnectionHealthProbe::class,
            fn() => new \BooleanSmtp\Services\Connection\ConnectionHealthProbe(
                $this->app->make(\BooleanSmtp\Services\Mailer\MailerManager::class),
                $this->app->make(\BooleanSmtp\Contracts\EncryptorContract::class),
                $this->app->make(\BooleanSmtp\Repositories\ConnectionRepository::class),
                $this->app->make(\BooleanSmtp\Services\Settings\ConstantSettingsResolver::class),
            )
        );

        $this->app->singleton(
            ProCapabilityContract::class,
            NullProCapability::class
        );

        \BooleanSmtp\Services\Editions\EditionPolicies::bind($this->app);

        // The hook catalog is generated from the hook docblocks; it only loads when something
        // (the `hooks:list` command) asks the registry for it.
        $this->app->singleton(HookRegistry::class, static function (): HookRegistry {
            $registry = new HookRegistry();
            foreach (HookCatalog::all() as $hook) {
                $registry->define($hook['name'], [
                    'type'        => $hook['kind'],
                    'description' => $hook['summary'],
                    'params'      => $hook['params'],
                    'return'      => $hook['return'],
                    'plugin'      => $hook['plugin'],
                    'since'       => $hook['since'],
                ]);
            }

            return $registry;
        });
    }

    /**
     * Wire the plugin's admin UI, hooks, cron schedules, and Pro extension points.
     *
     * @since 1.0.0
     */
    public function boot(): void {
        $this->registerAdminPage();
        $this->registerHooks();
        $this->registerProExtensionPoints();
        $this->registerObservers();
        $this->registerHealthCheckCron();
        $this->registerCliCommands();
        $this->addAction('admin_init', [$this, 'redirectSlashedAdminPage']);
        $this->loadOrder()->register();
        $this->addAction('activated_plugin', [$this, 'keepLoadOrderFirst']);
        $this->addAction('admin_init', [$this, 'repairLoadOrder']);
        $this->addAction('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        $this->addAction('admin_notices', [$this, 'maybeWpMailTakeoverNotice']);
        $this->addAction('all_admin_notices', [$this, 'printNoticeAnchor'], PHP_INT_MAX);
        $this->addAction('admin_bar_menu', [$this, 'registerSimulationAdminBarNotice'], 100);
        $this->addAction('admin_head', [$this, 'injectSimulationAdminBarCss']);
    }

    /**
     * Register the plugin's WP-CLI commands with the framework's Console component.
     *
     * They appear as `wp boolean-smtp queue:work`, `connections:list`, `logs:export`,
     * `test-email` and `import`, next to the framework's `migrate` and `hooks:list`. Nothing is
     * registered outside WP-CLI.
     *
     * @since 1.0.0
     */
    protected function registerCliCommands(): void {
        $this->commands([
            \BooleanSmtp\Console\Commands\QueueWorkCommand::class,
            \BooleanSmtp\Console\Commands\ConnectionsListCommand::class,
            \BooleanSmtp\Console\Commands\LogsExportCommand::class,
            \BooleanSmtp\Console\Commands\TestEmailCommand::class,
            \BooleanSmtp\Console\Commands\ImportCommand::class,
        ]);
    }

    /**
     * Wire the health check and OAuth token refresh cron schedules and their handlers.
     *
     * Registers the `cron_schedules` filter (see {@see registerHealthCheckCronSchedule()})
     * and, on `init`, ensures the `boolean_smtp_health_check`, `boolean_smtp_oauth_refresh` and
     * daily `boolean_smtp_prune_logs` WP-Cron events are scheduled. Their handlers run the health
     * check, OAuth refresh and retention jobs respectively.
     *
     * @since 1.0.0
     */
    protected function registerHealthCheckCron(): void {
        $this->addFilter('cron_schedules', [$this, 'registerHealthCheckCronSchedule']);
        $this->addAction('init', [$this, 'maybeEnsureHealthCheckCron'], 20);
        $this->addAction('boolean_smtp_health_check', [$this, 'runScheduledHealthCheck']);
        $this->addAction('init', [$this, 'maybeEnsureOAuthRefreshCron'], 21);
        $this->addAction('boolean_smtp_oauth_refresh', [$this, 'runScheduledOAuthRefresh']);
        $this->addAction('init', [$this, 'clearLegacyScheduleCron'], 22);
        $this->addAction('init', [$this, 'maybeEnsurePruneLogsCron'], 23);
        $this->addAction(PruneLogsJob::HOOK, [$this, 'runScheduledPruneLogs']);
    }

    /**
     * Add the plugin's custom WP-Cron schedules.
     *
     * Adds a fixed `every_minute` interval (60 seconds) plus two configurable intervals read
     * from settings: `boolean_smtp_health` (from `health_check_interval`, minutes, default
     * 15) and `boolean_smtp_oauth_refresh` (from `oauth_refresh_interval`, minutes, default
     * 15). Both configurable intervals fall back to the unmodified `$schedules` array if
     * the settings repository cannot be resolved.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $schedules Existing WP-Cron schedules, keyed by schedule name.
     * @return array<string, mixed> The schedules array with the plugin's intervals added.
     */
    public function registerHealthCheckCronSchedule(array $schedules): array {
        $schedules['every_minute'] = [
            'interval' => 60,
            'display'  => 'Every minute'
        ];

        try {
            $repo    = $this->app->make(Settings::class);
            $minutes = max(1, (int) $repo->get('health_check_interval', 15));
        } catch (\Throwable) {
            return $schedules;
        }

        $schedules['boolean_smtp_health'] = [
            'interval' => $minutes * 60,
            'display'  => 'BooleanSMTP health check'
        ];

        $oauthRefreshMinutes                    = max(1, (int) $repo->get('oauth_refresh_interval', 15));
        $schedules['boolean_smtp_oauth_refresh'] = [
            'interval' => $oauthRefreshMinutes * 60,
            'display'  => 'BooleanSMTP OAuth token refresh'
        ];

        return $schedules;
    }

    /**
     * Schedule the health check WP-Cron event if it is not already scheduled.
     *
     * @since 1.0.0
     */
    public function maybeEnsureHealthCheckCron(): void {
        $this->app->make(HealthCheckJob::class)->ensureScheduled();
    }

    /**
     * Schedule the OAuth token refresh WP-Cron event if it is not already scheduled.
     *
     * @since 1.0.0
     */
    public function maybeEnsureOAuthRefreshCron(): void {
        $this->app->make(OAuthRefreshJob::class)->ensureScheduled();
    }

    /**
     * Schedule the daily retention job if it is not already scheduled.
     *
     * @since 1.0.0
     */
    public function maybeEnsurePruneLogsCron(): void {
        $this->app->make(PruneLogsJob::class)->ensureScheduled();
    }

    /**
     * Run the retention job from WP-Cron.
     *
     * @since 1.0.0
     */
    public function runScheduledPruneLogs(): void {
        try {
            $this->app->make(PruneLogsJob::class)->handle();
        } catch (\Throwable $e) {
            $this->app->make(LoggerContract::class)->warning('The retention job failed: ' . $e->getMessage());
        }
    }

    /**
     * Remove the WP-Cron events left behind by earlier builds ({@see self::LEGACY_CRON_HOOKS}).
     * Nothing handles those events any more; without this they would keep firing on sites that
     * were never re-activated.
     *
     * @since 1.0.0
     */
    public function clearLegacyScheduleCron(): void {
        $scheduler = $this->app->make(ScheduleAdapterContract::class);

        foreach (self::LEGACY_CRON_HOOKS as $hook) {
            if ($scheduler->nextScheduled($hook) !== false) {
                $scheduler->clearScheduledHook($hook);
            }
        }
    }

    /**
     * Run the health check job from WP-Cron, when the feature is enabled.
     *
     * @since 1.0.0
     */
    public function runScheduledHealthCheck(): void {
        try {
            $settings = $this->app->make(Settings::class);
            if (!$settings->get('health_check_enabled')) {
                return;
            }
            $this->app->make(HealthCheckJob::class)->handle();
        } catch (\Throwable) {
            // A scheduled health check failure must not break WP-Cron.
        }
    }

    /**
     * Run the OAuth token refresh job from WP-Cron.
     *
     * @since 1.0.0
     */
    public function runScheduledOAuthRefresh(): void {
        try {
            $this->app->make(OAuthRefreshJob::class)->handle();
        } catch (\Throwable) {
            // A scheduled OAuth refresh failure must not break WP-Cron.
        }
    }

    /**
     * Add an admin bar notice reminding the user that email simulation is on.
     *
     * Shown only to users who can manage options, only in wp-admin, and only while the
     * `simulation_enabled` setting is on (no real email is sent while it is active).
     *
     * @since 1.0.0
     *
     * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance supplied by `admin_bar_menu`.
     */
    public function registerSimulationAdminBarNotice($wp_admin_bar): void {
        if (!$wp_admin_bar instanceof \WP_Admin_Bar) {
            return;
        }
        if (!\function_exists('is_admin') || !\is_admin()) {
            return;
        }
        if (!\function_exists('current_user_can') || !\current_user_can('manage_options')) {
            return;
        }

        try {
            $settings = $this->app->make(Settings::class);
        } catch (\Throwable $e) {
            return;
        }

        if (!$settings->get('simulation_enabled')) {
            return;
        }

        $wp_admin_bar->add_node([
            'id'     => 'boolean-smtp-simulation-active',
            'parent' => 'top-secondary',
            'title'  => '<span class="ab-label boolean-smtp-simulation-label" style="display:inline-block;background:#b91c1c;color:#fff;padding:0 8px;line-height:32px;height:32px;box-sizing:border-box;font-size:12px;font-weight:600;vertical-align:top;">'
            . \esc_html($this->translator()->translate('Email: Disabled'))
            . '</span>',
            'href'   => \admin_url('admin.php?page=boolean-smtp#/settings'),
            'meta'   => [
                'class' => 'boolean-smtp-simulation-mode-active'
            ]
        ]);
    }

    /**
     * Print inline CSS that removes the default admin bar item padding for the simulation
     * notice so its colored badge sits flush against the admin bar.
     *
     * @since 1.0.0
     */
    public function injectSimulationAdminBarCss(): void {
        try {
            $settings = $this->app->make(Settings::class);
            if (!$settings->get('simulation_enabled')) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        echo '<style id="boolean-smtp-simulation-css">
            #wp-admin-bar-boolean-smtp-simulation-active .ab-item { padding: 0 !important; }
        </style>';
    }

    /**
     * Show an admin notice when another plugin took `wp_mail()` before BooleanSMTP could.
     *
     * Suppressed once the `BOOLEAN_SMTP_WP_MAIL_TAKEOVER` constant is defined and truthy,
     * which the plugin sets once it confirms it registered `wp_mail()` successfully, and on the
     * plugin's own screens, where the admin UI shows the same warning in its own layout instead
     * of WordPress dropping it over the app's header.
     *
     * @since 1.0.0
     */
    public function maybeWpMailTakeoverNotice(): void {
        if (!\function_exists('is_admin') || !\is_admin()) {
            return;
        }
        if (!\function_exists('current_user_can') || !\current_user_can('manage_options')) {
            return;
        }
        if (BooleanSmtpWpMail::isTakeoverActive() || $this->isPluginScreen()) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>BooleanSMTP:</strong> ';
        echo \esc_html($this->wpMailTakeoverMessage());
        echo '</p></div>';
    }

    /**
     * Mark where WordPress should park admin notices on the plugin's screens.
     *
     * WordPress moves every notice to the first `.wp-header-end` in the page and, when there is
     * none, to the first heading inside `.wrap` — which on these screens is a heading the React
     * app rendered. The notice then lives inside the app's own layout, over its header, where it
     * breaks the page and is at the mercy of the next re-render. This anchor, printed after the
     * notices themselves, keeps them in WordPress's own area above the app.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function printNoticeAnchor(): void {
        if (! $this->isPluginScreen()) {
            return;
        }

        echo '<hr class="wp-header-end">';
    }

    /**
     * Whether the screen being rendered is one of the plugin's own admin pages.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    private function isPluginScreen(): bool {
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash((string) $_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the current screen, no state changes.

        return $page !== '' && str_starts_with($page, 'boolean-smtp');
    }

    /**
     * What the site owner is told when another plugin holds `wp_mail()`: one sentence naming it
     * and what it costs until it is deactivated.
     *
     * @since 1.0.0
     *
     * @return string
     */
    private function wpMailTakeoverMessage(): string {
        $plugin = BooleanSmtpWpMail::takenOverBy();

        return $plugin !== null
            ? $this->translator()->translate('{{plugin}} claimed WordPress mail first — until it is deactivated, your site\'s email bypasses the email log and failure alerts.', ['plugin' => $plugin])
            : $this->translator()->translate('Another plugin claimed WordPress mail first — until it is deactivated, your site\'s email bypasses the email log and failure alerts.');
    }

    /**
     * Move the plugin back to the front of the load order after any plugin is activated.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function keepLoadOrderFirst(): void {
        $this->loadOrder()->ensureFirst();
    }

    /**
     * On an admin screen, put the plugin back in front while another plugin holds `wp_mail()`.
     *
     * Only for users who may manage plugins: `admin_init` fires for every logged-in user who opens
     * wp-admin, and reordering the active plugins is plugin management.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function repairLoadOrder(): void {
        if (!\function_exists('current_user_can') || !\current_user_can('activate_plugins')) {
            return;
        }

        $this->loadOrder()->repairWhenOutranked();
    }

    /**
     * The load-order helper for this plugin's entry file.
     *
     * @since 1.0.0
     *
     * @return PluginLoadOrder
     */
    private function loadOrder(): PluginLoadOrder {
        return new PluginLoadOrder(\plugin_basename(BOOLEAN_SMTP_FILE));
    }

    /**
     * Send `admin.php?page=boolean-smtp/<screen>` to the screen it means.
     *
     * The admin URL carries the app's route in the fragment (`…page=boolean-smtp#/logs`).
     * Browsers and editors drop the `#` when a link is retyped or copied loosely, and
     * WordPress then answers "Sorry, you are not allowed to access this page" for a menu slug
     * it never registered. Anything under the plugin's own slug is redirected to the real page
     * with the route restored.
     *
     * @since 1.0.0
     */
    public function redirectSlashedAdminPage(): void {
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash((string) $_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the current screen, no state changes.
        if ($page === '' || ! str_starts_with($page, 'boolean-smtp/')) {
            return;
        }

        $route = trim(substr($page, strlen('boolean-smtp')), '/');
        $url   = admin_url('admin.php?page=boolean-smtp') . ($route !== '' ? '#/' . $route : '');
        wp_safe_redirect($url);
        exit;
    }

    /**
     * Register the plugin's admin menu page and its React frontend entry point.
     *
     * Mounts the "BooleanSMTP" admin page under the `boolean-smtp` menu slug, into the
     * `#boolean-smtp-app` DOM node, loading `src/main.jsx`, and bootstraps it with the
     * props from {@see adminSpaBootstrapProps()}.
     *
     * @since 1.0.0
     */
    protected function registerAdminPage(): void {
        $admin = $this->app->make(\BooleanSmtp\Core\Admin\Admin::class);
        $admin->page('BooleanSMTP', 'boolean-smtp')
            ->mountId('boolean-smtp-app')
            ->icon('dashicons-email')
            ->frontend('src/main.jsx')
            ->props(fn() => $this->adminSpaBootstrapProps());
    }

    /**
     * Wire the queue-processing WP-Cron listener.
     *
     * The plugin's hook catalog itself is no longer declared here: {@see HookCatalog} is
     * generated from the hook docblocks and fills the framework's {@see HookRegistry} lazily,
     * the first time something (the `hooks:list` command) resolves it.
     *
     * @since 1.0.0
     */
    protected function registerHooks(): void {
        $this->addAction(ProcessQueueJob::HOOK, [$this, 'runScheduledQueueProcessing']);
    }

    /**
     * Fire the extension point the Pro addon uses to register its own providers.
     *
     * @since 1.0.0
     */
    protected function registerProExtensionPoints(): void {
        /**
         * Fires during plugin boot so the Pro addon can register its service providers.
         *
         * The free plugin never contains Pro logic; this is the extension point the Pro
         * addon hooks into to attach its own bindings, routes, and admin UI.
         *
         * @since 1.0.0
         *
         * @param \BooleanSmtp\Core\Container\Application $app The application container.
         */
        \do_action('boolean_smtp_pro_register_providers', $this->app);
    }

    /**
     * Register the model observers used by the plugin.
     *
     * Attaches {@see EmailLogObserver} to the {@see EmailLog} model's lifecycle events.
     *
     * @since 1.0.0
     */
    protected function registerObservers(): void {
        EmailLog::observe(EmailLogObserver::class);
    }

    /**
     * Enqueue the admin UI script and styles for the plugin's admin page.
     *
     * Only runs on the plugin's own admin page hook. Loads from the Vite dev server when
     * one is configured (see {@see viteDevServerUrl()}); otherwise loads the built assets
     * listed in `public/manifest.json`, falling back to a legacy `assets/main-*.js` glob
     * when no manifest is present. Shows an admin notice instead when neither is available.
     *
     * @since 1.0.0
     *
     * @param string $hook Current admin page hook, supplied by `admin_enqueue_scripts`.
     */
    public function enqueueAssets(string $hook): void {
        if ($hook !== 'toplevel_page_boolean-smtp') {
            return;
        }

        $publicPath   = $this->app->basePath('public');
        $publicUrl    = $this->app->pluginUrl('public');
        $manifestPath = $publicPath . '/manifest.json';

        // Dev mode is opt-in via the Vite "hot file" written by `pnpm dev`
        // or an explicit BOOLEAN_SMTP_VITE_DEV_SERVER constant.
        $devServerUrl = $this->viteDevServerUrl($publicPath);

        if ($devServerUrl !== '') {
            $this->addAction('admin_head', [$this, 'injectViteDevPreamble']);

            wp_enqueue_script(
                'boolean-smtp-vite-client',
                $devServerUrl . '/@vite/client',
                [],
                BOOLEAN_SMTP_VERSION,
                false
            );

            // Same handle in dev and prod so add-ons can depend on 'boolean-smtp-app' unconditionally.
            wp_enqueue_script(
                'boolean-smtp-app',
                $devServerUrl . '/src/main.jsx',
                ['boolean-smtp-vite-client'],
                BOOLEAN_SMTP_VERSION,
                true
            );
            $this->addFilter('script_loader_src', [$this, 'removeDevServerVersion'], 10, 2);
            $this->addFilter('script_loader_tag', [$this, 'addTypeModule'], 10, 3);
            $this->addAdminBootstrapScript('boolean-smtp-app');
            return;
        }

        if (!file_exists($manifestPath)) {
            $this->addAction('admin_notices', [$this, 'renderMissingAdminAssetsNotice']);
            return;
        }

        $assets = $this->resolveBuiltAdminAssets($publicPath);
        if ($assets === []) {
            $this->addAction('admin_notices', [$this, 'renderMissingAdminAssetsNotice']);
            return;
        }

        foreach ($assets['css'] as $css) {
            wp_enqueue_style(
                'boolean-smtp-css-' . md5($css),
                $publicUrl . '/' . $css,
                [],
                BOOLEAN_SMTP_VERSION
            );
        }

        // No version query: the file name carries the build hash, and the lazy chunks import this
        // entry by its bare URL. A `?ver=` would make the browser load it a second time as a
        // different module, re-running the app and losing what the add-on registered.
        wp_enqueue_script(
            'boolean-smtp-app',
            $publicUrl . '/' . $assets['js'],
            [],
            null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- the hashed file name is the version.
            true
        );

        $this->addFilter('script_loader_tag', [$this, 'addTypeModule'], 10, 3);
        $this->addAdminBootstrapScript('boolean-smtp-app');
    }

    /**
     * Resolve the built admin JS and CSS asset paths for the current build.
     *
     * Reads `public/manifest.json` and follows its `src/main.jsx` entry (including CSS
     * pulled in through nested imports); falls back to the newest `assets/main-*.js` /
     * `assets/main-*.css` glob match when no manifest is present. Returns an empty array
     * when no usable build output can be found on disk.
     *
     * @since 1.0.0
     *
     * @param  string $publicPath Absolute path to the plugin's `public` directory.
     * @return array{js:string, css:list<string>}|array{} The resolved asset paths, relative
     *         to `$publicPath`, or an empty array when the build output is missing.
     */
    protected function resolveBuiltAdminAssets(string $publicPath): array {
        $manifestPath = $publicPath . '/manifest.json';

        if (file_exists($manifestPath)) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (!is_array($manifest)) {
                return [];
            }

            $entry = $manifest['src/main.jsx'] ?? null;
            if (is_array($entry) && isset($entry['file']) && is_string($entry['file'])) {
                $assets = [
                    'js'  => $entry['file'],
                    'css' => $this->collectManifestCss($manifest, 'src/main.jsx'),
                ];

                return $this->builtFilesExist($publicPath, $this->collectManifestFiles($manifest)) ? $assets : [];
            }
        }

        $legacyScripts = glob($publicPath . '/assets/main-*.js') ?: [];
        $legacyStyles  = glob($publicPath . '/assets/main-*.css') ?: [];

        if ($legacyScripts === []) {
            return [];
        }

        sort($legacyScripts);
        sort($legacyStyles);

        return [
            'js'  => str_replace($publicPath . '/', '', $legacyScripts[0]),
            'css' => array_map(
                static fn(string $file): string => str_replace($publicPath . '/', '', $file),
                $legacyStyles
            ),
        ];
    }

    /**
     * Collect the CSS files a Vite manifest entry pulls in, including nested imports.
     *
     * Recurses through the entry's `imports` so CSS attached to imported chunks is
     * included alongside CSS attached to the entry itself.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $manifest Decoded `manifest.json` contents.
     * @param  string               $entryKey Manifest key to resolve CSS for.
     * @param  array<string, bool>  $visited  Entry keys already visited, to guard against
     *                                         import cycles. Callers omit this argument.
     * @return list<string> CSS file paths, relative to the manifest's base directory.
     */
    protected function collectManifestCss(array $manifest, string $entryKey, array $visited = []): array {
        if (isset($visited[$entryKey]) || !isset($manifest[$entryKey]) || !is_array($manifest[$entryKey])) {
            return [];
        }

        $visited[$entryKey] = true;
        $entry              = $manifest[$entryKey];
        $css                = [];

        foreach (($entry['css'] ?? []) as $file) {
            if (is_string($file) && $file !== '') {
                $css[$file] = true;
            }
        }

        foreach (($entry['imports'] ?? []) as $importKey) {
            if (!is_string($importKey)) {
                continue;
            }

            foreach ($this->collectManifestCss($manifest, $importKey, $visited) as $file) {
                $css[$file] = true;
            }
        }

        return array_values(array_keys($css));
    }

    /**
     * Collect every output file path referenced anywhere in a Vite manifest.
     *
     * Gathers each entry's `file`, plus any files listed under its `assets` and `css`
     * arrays, so the full build output can be checked for existence on disk.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $manifest Decoded `manifest.json` contents.
     * @return list<string> File paths, relative to the manifest's base directory.
     */
    protected function collectManifestFiles(array $manifest): array {
        $files = [];

        foreach ($manifest as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (isset($entry['file']) && is_string($entry['file']) && $entry['file'] !== '') {
                $files[$entry['file']] = true;
            }

            foreach (['assets', 'css'] as $key) {
                foreach (($entry[$key] ?? []) as $file) {
                    if (is_string($file) && $file !== '') {
                        $files[$file] = true;
                    }
                }
            }
        }

        return array_values(array_keys($files));
    }

    /**
     * Check that every listed build file exists on disk under the public directory.
     *
     * @since 1.0.0
     *
     * @param  string        $publicPath Absolute path to the plugin's `public` directory.
     * @param  list<string>  $files      File paths relative to `$publicPath`.
     * @return bool True when every file exists.
     */
    protected function builtFilesExist(string $publicPath, array $files): bool {
        foreach ($files as $file) {
            if (!is_string($file) || $file === '' || !file_exists($publicPath . '/' . ltrim($file, '/'))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Inject the admin SPA's bootstrap data as an inline script before the given handle.
     *
     * Encodes {@see adminSpaBootstrapProps()} as `window.BooleanSmtpAdmin` so the React
     * app can read its initial configuration without an extra REST round trip.
     *
     * @since 1.0.0
     *
     * @param string $handle Registered script handle to attach the inline script to.
     */
    protected function addAdminBootstrapScript(string $handle): void {
        $props = $this->adminSpaBootstrapProps();
        $json  = wp_json_encode($props, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        if (!is_string($json) || $json === '') {
            $json = '{}';
        }

        wp_add_inline_script(
            $handle,
            'window.BooleanSmtpAdmin = ' . $json . ';',
            'before'
        );
    }

    /**
     * Show an admin notice when the admin UI build output cannot be found.
     *
     * @since 1.0.0
     */
    public function renderMissingAdminAssetsNotice(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $message = $this->translator()->translate(
            'BooleanSMTP admin UI assets are missing. From the plugin root run `pnpm build` (production) or `pnpm dev` (development).'
        );

        echo '<div class="notice notice-error boolean-smtp-notice"><p>';
        echo esc_html($message);
        echo '</p></div>';
    }

    /**
     * Build the bootstrap data passed to the admin React app.
     *
     * Includes the REST API base and nonce, plugin/WordPress/PHP versions, locale and
     * text direction, the current user's basic profile, translated UI strings, the OAuth
     * redirect URIs for each provider, and the Pro addon's status.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    protected function adminSpaBootstrapProps(): array {
        $currentUser      = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        $currentUserEmail = '';
        $user             = null;
        if ($currentUser && isset($currentUser->user_email)) {
            $currentUserEmail = sanitize_email((string) $currentUser->user_email);
            $user             = [
                'display_name' => (string) $currentUser->display_name,
                'first_name'   => (string) $currentUser->first_name,
                'user_email'   => $currentUserEmail,
                'avatar_url'   => function_exists('get_avatar_url') ? (string) get_avatar_url((int) $currentUser->ID, ['size' => 96]) : '',
            ];
        }

        $locale = '';
        if (\function_exists('determine_locale')) {
            $locale = (string) \determine_locale();
        } elseif (\function_exists('get_locale')) {
            $locale = (string) \get_locale();
        }

        /** @var ProCapabilityContract $proCapability */
        $proCapability = $this->app->make(ProCapabilityContract::class);

        return [
            'apiBase'           => get_rest_url(null, 'booleansmtp/v1'),
            'adminUrl'          => \admin_url(),
            'nonce'             => wp_create_nonce('wp_rest'),
            'version'           => BOOLEAN_SMTP_VERSION,
            'wpVersion'         => get_bloginfo('version'),
            'phpVersion'        => PHP_VERSION,
            'locale'            => $locale,
            'isRtl'             => $this->isRtlLocale($locale),
            'currentUserEmail'  => $currentUserEmail,
            'user'              => $user,
            'siteName'          => wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
            'i18n'              => $this->buildTranslationPayload(),
            'oauthRedirectUris' => [
                'google'    => OAuthRedirectUri::google(),
                'microsoft' => OAuthRedirectUri::microsoft(),
                'zoho'      => OAuthRedirectUri::zoho()
            ],
            /**
             * Filters whether the admin UI shows raw OAuth provider diagnostics.
             *
             * Off by default: raw OAuth provider error bodies can be large and are not
             * meant for end users. Return true to opt in. This filter also gates the raw
             * diagnostic data returned by the REST API; see
             * {@see \BooleanSmtp\Http\Controllers\ConnectionController::refreshOAuthNow()}
             * and {@see \BooleanSmtp\Http\Controllers\ConnectionController::getOAuthRefreshHistory()}.
             *
             * @since 1.0.0
             *
             * @param  bool $enabled Whether to show raw OAuth diagnostics. Default false.
             * @return bool The filtered value.
             */
            'oauthDebug' => (bool) apply_filters('boolean_smtp_oauth_debug_ui', false),
            'migrationSources' => MigrationScanner::catalog(),
            // The admin UI shows its own warning when another plugin holds wp_mail(); the
            // WordPress notice is suppressed on these screens.
            'mailTakeover' => [
                'active' => BooleanSmtpWpMail::isTakeoverActive(),
                'plugin' => BooleanSmtpWpMail::takenOverBy(),
            ],
            'extensions' => $this->adminExtensions(),
            'pro' => [
                'installed'     => $proCapability->isInstalled(),
                'licensed'      => $proCapability->isLicensed(),
                'licenseStatus' => $proCapability->getLicenseStatus(),
                'licenseHealth' => $proCapability->getLicenseHealth(),
                'plan'          => $proCapability->getPlan(),
                'features'      => $proCapability->getFeatures(),
                'upgradeUrl'    => $proCapability->getUpgradeUrl(),
            ],
        ];
    }

    /**
     * Collect the navigation entries and dashboard cards other plugins add to the admin UI.
     *
     * Entries are plain links: the admin UI renders menu items under its own navigation and
     * cards on the overview page, each pointing at the URL the entry carries. Entries without a
     * string `id`/`slug`, `label`/`title` and `url` are dropped; URLs go through `esc_url_raw()`.
     *
     * @since 1.0.0
     *
     * @return array{menuItems: list<array<string, mixed>>, dashboardCards: list<array<string, mixed>>}
     */
    protected function adminExtensions(): array {
        /**
         * Filters the navigation entries other plugins add to the admin UI.
         *
         * Each entry is rendered as a link in the admin sidebar. Return the array with your
         * entries appended.
         *
         * @since 1.0.0
         *
         * @param  list<array{slug: string, label: string, url: string, description?: string}> $items Navigation entries; empty by default.
         * @return list<array{slug: string, label: string, url: string, description?: string}> The filtered entries.
         */
        $items = apply_filters('boolean_smtp_admin_menu_items', []);

        /**
         * Filters the cards other plugins add to the admin overview page.
         *
         * Each card is rendered with its title and description and links to its URL. Return
         * the array with your cards appended.
         *
         * @since 1.0.0
         *
         * @param  list<array{id: string, title: string, url: string, description?: string}> $cards Overview cards; empty by default.
         * @return list<array{id: string, title: string, url: string, description?: string}> The filtered cards.
         */
        $cards = apply_filters('boolean_smtp_dashboard_cards', []);

        return [
            'menuItems'      => $this->normalizeAdminExtensionEntries($items, 'slug', 'label'),
            'dashboardCards' => $this->normalizeAdminExtensionEntries($cards, 'id', 'title'),
        ];
    }

    /**
     * Keep the well-formed entries of an admin extension list and sanitise their fields.
     *
     * @since 1.0.0
     *
     * @param  mixed  $entries  Value returned by the filter; anything but a list of arrays yields no entries.
     * @param  string $idKey    Key holding the entry identifier (`slug` or `id`).
     * @param  string $labelKey Key holding the entry text (`label` or `title`).
     * @return list<array<string, string>> Entries with the identifier, text, `url` and optional `description`.
     */
    protected function normalizeAdminExtensionEntries(mixed $entries, string $idKey, string $labelKey): array {
        if (!is_array($entries)) {
            return [];
        }

        $kept = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id    = isset($entry[$idKey]) && is_string($entry[$idKey]) ? sanitize_key($entry[$idKey]) : '';
            $label = isset($entry[$labelKey]) && is_string($entry[$labelKey]) ? sanitize_text_field($entry[$labelKey]) : '';
            $url   = isset($entry['url']) && is_string($entry['url']) ? esc_url_raw($entry['url']) : '';
            if ($id === '' || $label === '' || $url === '') {
                continue;
            }
            $normalized = [$idKey => $id, $labelKey => $label, 'url' => $url];
            if (isset($entry['description']) && is_string($entry['description'])) {
                $normalized['description'] = sanitize_text_field($entry['description']);
            }
            $kept[] = $normalized;
        }

        return $kept;
    }

    /**
     * Resolve and cache the translator used for server-rendered admin UI strings.
     *
     * @since 1.0.0
     *
     * @return TranslatorContract
     */
    protected function translator(): TranslatorContract {
        if ($this->translator === null) {
            $this->translator = $this->app->make(TranslatorContract::class);
        }

        return $this->translator;
    }

    /**
     * Build the translated UI strings passed to the admin React app.
     *
     * Grouped by page/section (`common`, `connections`, `settings`, `email_logs`,
     * `routing`, `onboarding`, `dashboard`, `help`, `providers`); each leaf value is the
     * translated string for that key.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    protected function buildTranslationPayload(): array {
        $t = $this->translator();

        return [
            'common'      => [
                'cancel'   => $t->translate('Cancel'),
                'delete'   => $t->translate('Delete'),
                'refresh'  => $t->translate('Refresh'),
                'search'   => $t->translate('Search'),
                'save'     => $t->translate('Save'),
                'back'     => $t->translate('Back'),
                'next'     => $t->translate('Next'),
                'continue' => $t->translate('Continue')
            ],
            'connections' => [
                'title'             => $t->translate('Mailer Connections'),
                'load_failed'       => $t->translate('Failed to load connections'),
                'deleted'           => $t->translate('Connection deleted'),
                'quick_test'        => $t->translate('Quick Test'),
                'edit_connection'   => $t->translate('Edit Connection'),
                'delete_connection' => $t->translate('Delete Connection')
            ],
            'settings'    => [
                'title'        => $t->translate('General Settings'),
                'load_failed'  => $t->translate('Failed to load settings data'),
                'save_success' => $t->translate('Settings saved successfully'),
                'save_failed'  => $t->translate('Error saving settings')
            ],
            'email_logs'  => [
                'title'          => $t->translate('Email Logs'),
                'load_failed'    => $t->translate('Failed to load logs'),
                'resend_success' => $t->translate('Email resent successfully.'),
                'refreshing'     => $t->translate('Refreshing logs...'),
                'refreshed'      => $t->translate('Logs refreshed')
            ],
            'routing'     => [
                'title'       => $t->translate('Smart Routing Rules'),
                'load_failed' => $t->translate('Failed to load routing rules'),
                'created'     => $t->translate('Routing rule created.'),
                'updated'     => $t->translate('Routing rule updated.'),
                'deleted'     => $t->translate('Routing rule deleted.')
            ],
            'onboarding'  => [
                'step'         => [
                    'start'    => ['title' => $t->translate('Start'), 'subtitle' => $t->translate('New connection or import')],
                    'provider' => ['title' => $t->translate('Provider'), 'subtitle' => $t->translate('Choose how this site sends')],
                    'connect'  => ['title' => $t->translate('Connect'), 'subtitle' => $t->translate('Sender identity and credentials')],
                    'verify'   => ['title' => $t->translate('Verify'), 'subtitle' => $t->translate('Send a test email')],
                    'review'   => ['title' => $t->translate('Review'), 'subtitle' => $t->translate('Apply the setup')],
                ],
                'finish_later' => $t->translate('Finish later'),
                'footnote'     => $t->translate('Nothing is switched on until you apply it in the last step. Your site keeps sending the way it does now.'),
            ],
            'dashboard'   => [
                'title'           => $t->translate('Dashboard Overview'),
                'quick_setup'     => $t->translate('Quick Setup'),
                'traffic_volume'  => $t->translate('Email Traffic Volume'),
                'recent_activity' => $t->translate('Recent activity')
            ],
            'help'        => [
                'help_button' => $t->translate('Help & Support'),
                'faq'         => $t->translate('Frequently Asked Questions')
            ],
            'providers'   => [
                'smtp'         => [
                    'name'        => $t->translate('Custom SMTP'),
                    'description' => $t->translate('Connect any custom SMTP server.')
                ],
                'ses'          => [
                    'name'        => $t->translate('Amazon SES'),
                    'description' => $t->translate('Perfect for high-volume bulk email campaigns requiring high deliverability and low cost.')
                ],
                'google'       => [
                    'name'        => $t->translate('Google Workspace'),
                    'description' => $t->translate('Ideal for professional business emails with easy setup and moderate daily volume limits.')
                ],
                'outlook'      => [
                    'name'        => $t->translate('Microsoft Outlook'),
                    'description' => $t->translate('Deep integration with Office 365 services.')
                ],
                'zoho'         => [
                    'name'        => $t->translate('Zoho Mail'),
                    'description' => $t->translate('Secure email with SMTP and OAuth integration for business.')
                ],
                'mailgun'      => [
                    'name'        => $t->translate('Mailgun'),
                    'description' => $t->translate('Powerful APIs for developers.')
                ],
                'postmark'     => [
                    'name'        => $t->translate('Postmark'),
                    'description' => $t->translate('Lightning fast delivery for apps.')
                ],
                'sendgrid'     => [
                    'name'        => $t->translate('SendGrid'),
                    'description' => $t->translate('Leader in marketing & transactional email.')
                ],
                'brevo'        => [
                    'name'        => $t->translate('Brevo'),
                    'description' => $t->translate('Formerly Sendinblue. Great all-in-one suite.')
                ],
                'sparkpost'    => [
                    'name'        => $t->translate('SparkPost'),
                    'description' => $t->translate('High-performance email delivery for large volumes.')
                ],
                'netcore'      => [
                    'name'        => $t->translate('Netcore'),
                    'description' => $t->translate('Global cloud email service with robust delivery.')
                ],
                'smtp2go'      => [
                    'name'        => $t->translate('SMTP2GO'),
                    'description' => $t->translate('Reliable SMTP and API service with detailed reporting.')
                ],
                'mailersend'   => [
                    'name'        => $t->translate('MailerSend'),
                    'description' => $t->translate('Transactional email service with intuitive API and analytics.')
                ],
                'mandrill'     => [
                    'name'        => $t->translate('Mandrill'),
                    'description' => $t->translate('Mailchimp Transactional Email with powerful deliverability.')
                ],
                'sendlayer'    => [
                    'name'        => $t->translate('SendLayer'),
                    'description' => $t->translate('Simple and reliable transactional email delivery.')
                ],
                'smtpcom'      => [
                    'name'        => $t->translate('SMTP.com'),
                    'description' => $t->translate('Enterprise-grade SMTP relay with channel-based sending.')
                ],
                'elasticemail' => [
                    'name'        => $t->translate('Elastic Email'),
                    'description' => $t->translate('Cost-effective email delivery for transactional and marketing.')
                ],
                'php'          => [
                    'name'        => $t->translate('PHP Mail'),
                    'description' => $t->translate('Default server mail. Not recommended.')
                ]
            ]
        ];
    }

    /**
     * Determine whether the given locale (or the current site locale) reads right to left.
     *
     * Prefers WordPress's own `is_rtl()` when available; otherwise falls back to a
     * language-prefix check against Arabic, Farsi, Hebrew, and Urdu.
     *
     * @since 1.0.0
     *
     * @param  string $locale Locale to check; defaults to the current site locale when empty.
     * @return bool True when the locale is right-to-left.
     */
    protected function isRtlLocale(string $locale = ''): bool {
        if (\function_exists('is_rtl')) {
            return (bool) \is_rtl();
        }

        $candidate = $locale;
        if ($candidate === '' && \function_exists('get_locale')) {
            $candidate = (string) \get_locale();
        }

        $prefix = \strtolower((string) \substr($candidate, 0, 2));

        return \in_array($prefix, ['ar', 'fa', 'he', 'ur'], true);
    }

    /**
     * Resolve the Vite dev-server origin, or an empty string when production assets should
     * be used instead.
     *
     * Resolution order, each overriding the previous: the `public/hot` file written by the
     * running `pnpm dev` server, the `BOOLEAN_SMTP_VITE_DEV_SERVER` constant (a boolean or
     * an explicit URL), the `BOOLEAN_SMTP_VITE_DEV_SERVER_URL` constant, then the
     * `boolean_smtp_vite_dev_server_url` filter. The result is validated as an `http(s)://`
     * URL; anything else resolves to an empty string.
     *
     * @since 1.0.0
     *
     * @param  string $publicPath Absolute path to the plugin's `public` directory; resolved
     *                             automatically when omitted.
     * @return string The dev-server origin, or an empty string to use production assets.
     */
    protected function viteDevServerUrl(string $publicPath = ''): string {
        $url = '';

        if ($publicPath === '') {
            $publicPath = $this->app->basePath('public');
        }

        $hotFile = rtrim($publicPath, '/') . '/hot';
        if (is_readable($hotFile)) {
            $url = trim((string) file_get_contents($hotFile));
        }

        if (\defined('BOOLEAN_SMTP_VITE_DEV_SERVER')) {
            $value = \constant('BOOLEAN_SMTP_VITE_DEV_SERVER');

            if (\is_string($value)) {
                $url = trim($value);
            } elseif ($value === true) {
                $url = 'http://127.0.0.1:5173';
            } elseif ($value === false) {
                $url = '';
            }
        }

        if (\defined('BOOLEAN_SMTP_VITE_DEV_SERVER_URL')) {
            $value = \constant('BOOLEAN_SMTP_VITE_DEV_SERVER_URL');

            if (\is_string($value) && trim($value) !== '') {
                $url = trim($value);
            }
        }

        if (\function_exists('apply_filters')) {
            /**
             * Filters the resolved Vite dev-server URL.
             *
             * Return the modified URL to override where the admin UI loads its dev-mode
             * script from. Return an empty string to force production assets.
             *
             * @since 1.0.0
             *
             * @param  string $url URL resolved so far from the hot file and dev-server constants.
             * @return string The filtered URL.
             */
            $url = \apply_filters('boolean_smtp_vite_dev_server_url', $url);
        }
        $url = \is_string($url) ? rtrim(trim($url), '/') : '';

        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return '';
        }

        return $url;
    }

    /**
     * Mark the admin app's script tags as ES modules.
     *
     * Registered on WordPress's `script_loader_tag` filter. The admin bundle, and in
     * development the Vite client, are ES modules, which the tag `wp_enqueue_script()`
     * prints does not declare. The tag WordPress built is kept; only its external
     * `<script src>` element gains `type="module"`, replacing a `text/javascript` type that
     * themes without HTML5 script support add. Inline scripts attached to the handle keep
     * their classic type.
     *
     * @since 1.0.0
     *
     * @param  string $tag    Script tag HTML generated by WordPress.
     * @param  string $handle Script handle being printed.
     * @param  string $src    Script source URL.
     * @return string The tag with `type="module"` on the external script for the admin app
     *                handles; unchanged for any other handle.
     */
    public function addTypeModule(string $tag, string $handle, string $src): string {
        if (!in_array($handle, self::MODULE_SCRIPT_HANDLES, true)) {
            return $tag;
        }

        $rewritten = preg_replace_callback(
            '#<script\b([^>]*\ssrc=[^>]*)>#i',
            static function (array $match): string {
                $attributes = (string) preg_replace('#\stype=([\'"])[^\'"]*\1#i', '', $match[1]);

                return '<script type="module"' . $attributes . '>';
            },
            $tag,
            1
        );

        return is_string($rewritten) ? $rewritten : $tag;
    }

    /**
     * Drop the `ver` query argument from the scripts served by the Vite dev server.
     *
     * Registered on `script_loader_src` only while a Vite dev server is active. Vite picks
     * a module's loader from its URL, and a `?ver=` query makes it serve `main.jsx` without
     * the JSX transform. The dev server never caches, so the version adds nothing there.
     *
     * @since 1.0.0
     *
     * @param  string $src    Script source URL.
     * @param  string $handle Script handle being printed.
     * @return string The URL without `ver` for the dev-server handles; unchanged otherwise.
     */
    public function removeDevServerVersion(string $src, string $handle): string {
        if (!in_array($handle, ['boolean-smtp-app', 'boolean-smtp-vite-client'], true)) {
            return $src;
        }

        return remove_query_arg('ver', $src);
    }

    /**
     * Print the React Fast Refresh preamble the Vite dev server requires.
     *
     * Registered on `admin_head` only while a Vite dev server is active; the Vite client
     * itself is enqueued as `boolean-smtp-vite-client`. Without this preamble, React's
     * dev-mode Fast Refresh client fails to attach. Printed through
     * {@see wp_print_inline_script_tag()} as an inline module, so it runs before the
     * deferred app module.
     *
     * @since 1.0.0
     */
    public function injectViteDevPreamble(): void {
        $devServerUrl = $this->viteDevServerUrl();

        if ($devServerUrl === '') {
            return;
        }

        $refreshUrl = wp_json_encode(esc_url_raw($devServerUrl . '/@react-refresh'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        if (!is_string($refreshUrl) || $refreshUrl === '') {
            return;
        }

        wp_print_inline_script_tag(
            'import { injectIntoGlobalHook } from ' . $refreshUrl . ';'
            . 'injectIntoGlobalHook(window);'
            . 'window.$RefreshReg$ = () => {};'
            . 'window.$RefreshSig$ = () => (type) => type;'
            . 'window.__vite_plugin_react_preamble_installed__ = true;',
            ['type' => 'module', 'id' => 'boolean-smtp-vite-preamble']
        );
    }

    /**
     * Run a batch of the outgoing mail queue from the `boolean_smtp_process_queue` WP-Cron
     * event: queued messages and the retries that are due.
     *
     * @since 1.0.0
     */
    public function runScheduledQueueProcessing(): void {
        try {
            $batchSize = $this->app->make(\BooleanSmtp\Services\Queue\QueueScheduler::class)->batchSize();
            $this->app->make(\BooleanSmtp\Jobs\ProcessQueueJob::class)->handle($batchSize, true);
        } catch (\Throwable) {
            // intentionally silent: a scheduled queue run failure must not break WP-Cron.
        }
    }
}
