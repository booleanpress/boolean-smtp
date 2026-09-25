<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Foundation;

use BooleanSmtp\Core\Contracts\ServiceProviderInterface;
use BooleanSmtp\Core\Database\Migration\MigrationRunner;

/**
 * BooleanPress Plugin Base Class
 *
 * Base class for WordPress plugins using the BooleanPress framework.
 * Extend this class to create your plugin's main entry point.
 */
abstract class Plugin
{
    /**
     * The application instance.
     */
    protected Application $app;

    /**
     * The plugin file path.
     */
    protected string $pluginFile;

    /**
     * Indicates if the plugin has been booted.
     */
    protected bool $booted = false;

    /**
     * The optional framework components this plugin uses, by name.
     *
     * Keys of {@see Application::COMPONENTS} (`Admin`, `Assets`, `Auth`, `Cache`, `Console`,
     * `Queue`, `Scheduling`, `View`). They are registered before {@see $providers}; a
     * component that is not listed is never loaded and may be left out of the plugin build.
     *
     * @var list<string>
     */
    protected array $components = [];

    /**
     * The service providers to register.
     *
     * @var array<class-string<ServiceProviderInterface>>
     */
    protected array $providers = [];

    /**
     * Create a new plugin instance.
     *
     * @param string $pluginFile The main plugin file path
     */
    public function __construct(string $pluginFile)
    {
        $this->pluginFile = $pluginFile;
        $this->app = new Application(dirname($pluginFile));
    }

    /**
     * Get the application instance.
     */
    public function app(): Application
    {
        return $this->app;
    }

    /**
     * Get the plugin file path.
     */
    public function pluginFile(): string
    {
        return $this->pluginFile;
    }

    /**
     * Get the plugin directory path.
     */
    public function basePath(string $path = ''): string
    {
        return $this->app->basePath($path);
    }

    /**
     * Get the plugin URL.
     */
    public function url(string $path = ''): string
    {
        return $this->app->pluginUrl($path);
    }

    /**
     * Boot the plugin.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->registerBaseBindings();
        $this->registerComponents();
        $this->registerServiceProviders();
        $this->registerHooks();
        $this->app->boot();

        $this->booted = true;
    }

    /**
     * Register base bindings.
     */
    protected function registerBaseBindings(): void
    {
        $this->app->instance('plugin', $this);
        $this->app->instance('plugin.file', $this->pluginFile);
        $this->app->singleton(MigrationRunner::class, fn () => $this->migrationRunner());
    }

    /**
     * Register the framework components listed in {@see $components}.
     */
    protected function registerComponents(): void
    {
        foreach ($this->components as $component) {
            $this->app->registerComponent($component);
        }
    }

    /**
     * The names of the framework components this plugin registers.
     *
     * @return list<string>
     */
    public function components(): array
    {
        return $this->components;
    }

    /**
     * Migrations declared by class, keyed by migration name.
     *
     * Normally empty: the plugin's migrations are the date-named files in
     * `database/Migrations/` (`YYYY_MM_DD_HHMMSS_<description>.php`, each returning a
     * migration instance), which {@see migrationRunner()} discovers and keys by file name,
     * and the framework's own tables come from {@see frameworkMigrations()}. A key is
     * recorded in `booleanpress_migrations` and must never change once shipped.
     *
     * @return array<string, class-string<\BooleanSmtp\Core\Database\Migration\Migration>>
     */
    protected function migrations(): array
    {
        return [];
    }

    /**
     * The framework migrations this plugin runs, by file name: the shared options table
     * always, the jobs table when the `Queue` component is registered. Override to add the
     * revisions table (`2026_09_20_000003_create_booleanpress_revisions_table`) when models use
     * revisions.
     *
     * @since 0.2.1
     *
     * @return list<string> File names (without `.php`) under the framework's `Database/Migrations/`.
     */
    protected function frameworkMigrations(): array
    {
        $files = ['2026_09_20_000001_create_booleanpress_options_table'];

        if ($this->app->hasComponent('Queue')) {
            $files[] = '2026_09_20_000002_create_booleanpress_jobs_table';
        }

        return $files;
    }

    /**
     * A migration runner for this plugin: the framework's own tables ({@see frameworkMigrations()},
     * recorded under `core`), the declared {@see migrations()}, and every date-named file in the
     * plugin's `database/Migrations/` directory, recorded under the plugin's slug and version.
     *
     * Bound as a singleton (`MigrationRunner::class`) so activation, upgrade checks,
     * repair and the `migrate` console command all share one definition.
     */
    public function migrationRunner(): MigrationRunner
    {
        $runner = new MigrationRunner([], $this->app);

        $frameworkDirectory = dirname(__DIR__) . '/Database/Migrations';
        foreach ($this->frameworkMigrations() as $name) {
            $runner->load($frameworkDirectory . '/' . $name . '.php');
        }

        foreach ($this->migrations() as $name => $class) {
            $runner->register($name, $class);
        }

        $directory = $this->app->databasePath('Migrations');
        if (is_dir($directory)) {
            $runner->discover($directory);
        }

        return $runner;
    }

    /**
     * Register the plugin's service providers.
     */
    protected function registerServiceProviders(): void
    {
        foreach ($this->providers as $provider) {
            $this->app->register($provider);
        }
    }

    /**
     * Register WordPress hooks.
     */
    protected function registerHooks(): void
    {
        if (!function_exists('register_activation_hook')) {
            return;
        }

        register_activation_hook($this->pluginFile, [$this, 'activate']);
        register_deactivation_hook($this->pluginFile, [$this, 'deactivate']);

        // Register uninstall hook (uses static method requirement)
        $pluginFile = $this->pluginFile;
        $uninstallerClass = $this->getUninstallerClass();
        if ($uninstallerClass && method_exists($uninstallerClass, 'uninstall')) {
            register_uninstall_hook($pluginFile, [$uninstallerClass, 'uninstall']);
        }

        add_action('plugins_loaded', [$this, 'onPluginsLoaded']);
        add_action('init', [$this, 'onInit']);

        // Enqueue deactivation popup script on plugins page
        add_action('admin_enqueue_scripts', [$this, 'maybeEnqueueDeactivationScript']);
    }

    /**
     * Get the uninstaller class for this plugin.
     * Override in child class to provide a custom uninstaller.
     *
     * @return class-string|null
     */
    protected function getUninstallerClass(): ?string
    {
        return null;
    }

    /**
     * Run plugin activation tasks.
     */
    public function activate(): void
    {
        // Override in child class
    }

    /**
     * Run plugin deactivation tasks.
     */
    public function deactivate(): void
    {
        // Override in child class
    }

    /**
     * Called on 'plugins_loaded' hook.
     */
    public function onPluginsLoaded(): void
    {
        // Override in child class
    }

    /**
     * Called on 'init' hook.
     */
    public function onInit(): void
    {
        // Override in child class
    }

    /**
     * Enqueue deactivation popup script on the plugins page.
     */
    public function maybeEnqueueDeactivationScript(string $hook): void
    {
        if ($hook !== 'plugins.php') {
            return;
        }

        if (!$this->shouldShowDeactivationPopup()) {
            return;
        }

        $pluginBasename = plugin_basename($this->pluginFile);
        $slug = $this->slug();

        // Inline script for deactivation popup
        $script = <<<JS
(function() {
    'use strict';
    document.addEventListener('DOMContentLoaded', function() {
        var deactivateLink = document.querySelector('tr[data-plugin="{$pluginBasename}"] .deactivate a, tr[data-slug="{$slug}"] .deactivate a');
        if (!deactivateLink) return;

        deactivateLink.addEventListener('click', function(e) {
            e.preventDefault();
            var href = this.href;

            var confirmed = confirm(
                'Do you want to delete all plugin data (tables, settings, jobs) when uninstalling?\\n\\n' +
                'Click OK to mark data for deletion on uninstall.\\n' +
                'Click Cancel to keep data (deactivate only).'
            );

            // Store preference
            fetch(ajaxurl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=booleanpress_deactivation_preference&plugin={$slug}&delete_data=' + (confirmed ? '1' : '0') + '&_wpnonce=' + '{$this->getDeactivationNonce()}'
            }).finally(function() {
                window.location.href = href;
            });
        });
    });
})();
JS;

        wp_add_inline_script('jquery', $script);
    }

    /**
     * Whether to show the deactivation popup.
     * Override to return true in plugins that want this behavior.
     */
    protected function shouldShowDeactivationPopup(): bool
    {
        return false;
    }

    /**
     * Get a nonce for the deactivation preference AJAX call.
     */
    protected function getDeactivationNonce(): string
    {
        if (function_exists('wp_create_nonce')) {
            return wp_create_nonce('booleanpress_deactivation_' . $this->slug());
        }
        return '';
    }

    /**
     * Check if the plugin is booted.
     */
    public function isBooted(): bool
    {
        return $this->booted;
    }

    /**
     * Get the plugin slug.
     */
    public function slug(): string
    {
        return $this->app->pluginSlug();
    }

    /**
     * Get the plugin text domain.
     */
    public function textDomain(): string
    {
        return $this->slug();
    }

    /**
     * Load plugin text domain.
     */
    protected function loadTextDomain(): void
    {
        if (function_exists('load_plugin_textdomain')) {
            load_plugin_textdomain(
                $this->textDomain(),
                false,
                $this->slug() . '/languages'
            );
        }
    }
}
