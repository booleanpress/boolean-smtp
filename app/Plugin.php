<?php

/**
 * Plugin bootstrap: service provider registration, activation, deactivation, and migrations.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp;

use BooleanSmtp\Core\Database\Migration\MigrationRunner;
use BooleanSmtp\Core\Foundation\Plugin as BasePlugin;
use BooleanSmtp\Adapters\Contracts\AuthAdapterContract;
use BooleanSmtp\Adapters\Contracts\RouterAdapterContract;
use BooleanSmtp\Adapters\Contracts\ScheduleAdapterContract;
use BooleanSmtp\Core\Contracts\LoggerContract;
use BooleanSmtp\Core\Foundation\Application;
use function BooleanSmtp\Core\app;

/**
 * BooleanSMTP's plugin class: wires service providers into the framework and handles the
 * WordPress activation/deactivation lifecycle and database migrations.
 *
 * @since 1.0.0
 */
class Plugin extends BasePlugin {
    /**
     * Framework components this plugin uses; everything else in the framework stays unloaded
     * and is left out of the release build.
     *
     * `Admin` mounts the React admin page; `Console` provides the `wp boolean-smtp …` commands.
     *
     * @since 1.0.0
     * @var list<string>
     */
    protected array $components = ['Admin', 'Console'];

    /**
     * Service providers registered when the plugin boots, in registration order.
     *
     * @since 1.0.0
     * @var list<class-string>
     */
    protected array $providers = [
        Providers\LoggingServiceProvider::class,
        Providers\AppServiceProvider::class,
        Providers\RouteServiceProvider::class,
        Providers\MailServiceProvider::class,
        Providers\NotificationServiceProvider::class,
        Adapters\Providers\AdapterServiceProvider::class
    ];

    /**
     * Run on plugin activation: clear legacy cron hooks, run migrations, and schedule cron jobs.
     *
     * @since 1.0.0
     */
    public function activate(): void {
        $scheduler = $this->app->make(ScheduleAdapterContract::class);

        // Earlier builds scheduled the framework's per-minute schedule runner; nothing handles it now.
        $scheduler->clearScheduledHook('boolean-smtp_schedule_run');

        $runner = $this->app->make(MigrationRunner::class);
        $runner->run();
        $runner->repair();

        try {
            $this->app->make(Jobs\HealthCheckJob::class)->sync();
            $this->app->make(Jobs\OAuthRefreshJob::class)->sync();

            // The queue worker has no recurring schedule: it is armed when a message is queued or
            // a retry is due, and it re-arms itself while rows remain. Rows left from before a
            // deactivation get their run back here.
            if ($this->app->make(\BooleanSmtp\Repositories\EmailLogRepository::class)->hasQueueWork()) {
                $this->app->make(\BooleanSmtp\Services\Queue\QueueScheduler::class)->arm();
            }
        } catch (\Throwable) {
            // Cron registration may be unavailable in some activation contexts
        }

        // Only an activation from the Plugins screen opens the setup wizard; a command-line
        // activation leaves the owner where they are.
        if (\defined('WP_CLI') && WP_CLI) {
            return;
        }

        try {
            $coreSettings = app(\BooleanSmtp\Core\Settings\SettingsRepository::class);
            $coreSettings->setTransient('activation_redirect', '1', 120);
        } catch (\Throwable) {
            // Core settings may not be available during very early activation
        }
    }

    /**
     * Hook the admin-side one-time redirect, table repair, and migration checks into `admin_init`.
     *
     * @since 1.0.0
     */
    public function onPluginsLoaded(): void {
        parent::onPluginsLoaded();
        add_action('admin_init', [$this, 'maybeActivationOnboardingRedirect'], 1);
        add_action('admin_init', [$this, 'maybeRepairDatabaseTables'], 5);
        add_action('admin_init', [$this, 'maybeRunMigrations'], 10);
    }

    /**
     * One-time redirect after a single activation from the Plugins screen: admin URL with a query flag
     * so the SPA can open onboarding. A bulk, network-wide or command-line activation does not redirect.
     *
     * @since 1.0.0
     */
    public function maybeActivationOnboardingRedirect(): void {
        if (!$this->isAdminContext() || !$this->currentUserCanManageOptions()) {
            return;
        }

        try {
            $coreSettings = app(\BooleanSmtp\Core\Settings\SettingsRepository::class);
            $redirectFlag = $coreSettings->getTransient('activation_redirect');
        } catch (\Throwable) {
            return;
        }

        if (!$redirectFlag) {
            return;
        }

        $coreSettings->deleteTransient('activation_redirect');

        // A bulk or network-wide activation keeps the owner on the screen they were using.
        if (isset($_GET['activate-multi']) || (\function_exists('is_network_admin') && \is_network_admin())) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads which screen WordPress returned to after activating; nothing changes.
            return;
        }

        // Re-activating a site that is already set up must not send the owner back into the wizard.
        if (!$this->needsOnboarding()) {
            return;
        }

        $url = add_query_arg('booleansmtp_onboard', '1', $this->buildAdminUrl('admin.php?page=boolean-smtp'));
        wp_safe_redirect($url);
        exit;
    }

    /**
     * Whether the site still needs the onboarding wizard: it was never applied or dismissed, and
     * no connection is active (a site set up by hand or by an import does not need it either).
     *
     * @since 1.0.0
     *
     * @return bool
     */
    private function needsOnboarding(): bool {
        try {
            $state = $this->app->make(\BooleanSmtp\Services\Onboarding\OnboardingState::class)->all();
            if (!empty($state['review']) || !empty($state['applied_connection_id']) || !empty($state['dismissed_at'])) {
                return false;
            }

            return $this->app->make(\BooleanSmtp\Repositories\ConnectionRepository::class)->active()->isEmpty();
        } catch (\Throwable) {
            // intentionally silent: when the state cannot be read, the wizard is the safe place to land.
            return true;
        }
    }

    /**
     * Run pending migrations once per plugin version.
     *
     * The stored `db_version` keeps this to one option read per admin request; when the plugin
     * was updated, the runner records whatever is still pending and the version is stamped.
     *
     * @since 1.0.0
     */
    public function maybeRunMigrations(): void {
        if (!$this->isAdminContext() || !Application::hasInstance()) {
            return;
        }

        try {
            $coreSettings = app(\BooleanSmtp\Core\Settings\SettingsRepository::class);
            $installed    = (string) $coreSettings->get('db_version', '0.0.0');
        } catch (\Throwable) {
            return;
        }

        if (version_compare($installed, BOOLEAN_SMTP_VERSION, '<')) {
            $this->app->make(MigrationRunner::class)->run();
            $coreSettings->set('db_version', BOOLEAN_SMTP_VERSION);
        }
    }

    /**
     * Seconds a successful table check is remembered before it runs again.
     *
     * @since 1.0.0
     * @var int
     */
    public const TABLES_VERIFIED_TTL = 12 * 3600;

    /**
     * Recreate any BooleanSMTP table that has gone missing (the migration row exists but the
     * table was dropped). The runner re-runs only `create` migrations and writes no rows.
     *
     * The check itself costs one `SHOW TABLES` per table, so a clean result is remembered in
     * the `tables_verified` transient for {@see TABLES_VERIFIED_TTL} and admin requests in
     * between read one option instead. A repair that recreated something (or found the
     * options table itself missing) leaves no transient behind, so the next request checks again.
     *
     * @since 1.0.0
     */
    public function maybeRepairDatabaseTables(): void {
        if (!$this->isAdminContext() || !$this->currentUserCanManageOptions()) {
            return;
        }
        try {
            $store = $this->app->make(\BooleanSmtp\Core\Settings\SettingsRepository::class);
            if ($store->getTransient('tables_verified') === '1') {
                return;
            }

            $repaired = $this->app->make(MigrationRunner::class)->repair();
            if ($repaired === []) {
                $store->setTransient('tables_verified', '1', self::TABLES_VERIFIED_TTL);
            }
        } catch (\Throwable $e) {
            $this->app->make(LoggerContract::class)->error('Database repair failed: ' . $e->getMessage());
        }
    }

    /**
     * Run on plugin deactivation: clear the plugin's scheduled cron hooks.
     *
     * @since 1.0.0
     */
    public function deactivate(): void {
        $scheduler = $this->app->make(ScheduleAdapterContract::class);

        foreach ([Jobs\ProcessQueueJob::HOOK, Jobs\HealthCheckJob::HOOK, Jobs\OAuthRefreshJob::HOOK] as $hook) {
            $scheduler->clearScheduledHook($hook);
        }
    }

    /**
     * Check whether the current request is in the WordPress admin, via the router adapter when
     * available.
     *
     * @since 1.0.0
     *
     * @return bool True when the current request is an admin request.
     */
    private function isAdminContext(): bool {
        $router = $this->resolveRouterAdapter();
        if ($router instanceof RouterAdapterContract) {
            return $router->isAdmin();
        }

        return function_exists('is_admin') ? (bool) is_admin() : false;
    }

    /**
     * Check whether the current user can manage options, via the auth adapter when available.
     *
     * @since 1.0.0
     *
     * @return bool True when the current user has the `manage_options` capability.
     */
    private function currentUserCanManageOptions(): bool {
        $auth = $this->resolveAuthAdapter();
        if ($auth instanceof AuthAdapterContract) {
            return $auth->can('manage_options');
        }

        return function_exists('current_user_can') ? (bool) current_user_can('manage_options') : false;
    }

    /**
     * Build an admin URL, via the router adapter when available.
     *
     * @since 1.0.0
     *
     * @param string $path Relative admin path (for example `admin.php?page=boolean-smtp`).
     * @return string The resolved admin URL.
     */
    private function buildAdminUrl(string $path): string {
        $router = $this->resolveRouterAdapter();
        if ($router instanceof RouterAdapterContract) {
            return $router->adminUrl($path);
        }

        return function_exists('admin_url') ? (string) admin_url($path) : $path;
    }


    /**
     * Resolve the auth adapter from the container, when the application is booted.
     *
     * @since 1.0.0
     *
     * @return AuthAdapterContract|null The resolved adapter, or null when unavailable.
     */
    private function resolveAuthAdapter(): ?AuthAdapterContract {
        if (!Application::hasInstance()) {
            return null;
        }

        try {
            $adapter = app(AuthAdapterContract::class);
            return $adapter instanceof AuthAdapterContract ? $adapter : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve the router adapter from the container, when the application is booted.
     *
     * @since 1.0.0
     *
     * @return RouterAdapterContract|null The resolved adapter, or null when unavailable.
     */
    private function resolveRouterAdapter(): ?RouterAdapterContract {
        if (!Application::hasInstance()) {
            return null;
        }

        try {
            $adapter = app(RouterAdapterContract::class);
            return $adapter instanceof RouterAdapterContract ? $adapter : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Migrations declared by class: none. The plugin's migrations are the date-named files in
     * `database/Migrations/` (`YYYY_MM_DD_HHMMSS_<description>.php`, each returning a migration
     * instance), discovered by the framework and recorded under their file names, which are
     * therefore frozen once they ship; the framework registers its own options table itself.
     *
     * @since 1.0.0
     *
     * @return array<string, class-string> Empty.
     */
    protected function migrations(): array {
        return [];
    }
}
