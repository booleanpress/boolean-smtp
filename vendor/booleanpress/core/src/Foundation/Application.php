<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Foundation;

use BooleanSmtp\Core\Container\Container;
use BooleanSmtp\Core\Container\ServiceProvider;
use BooleanSmtp\Core\Contracts\ServiceProviderInterface;

/**
 * BooleanPress Application
 *
 * The central application class that bootstraps and manages the framework.
 * Extends the Container to provide application-level services.
 */
class Application extends Container
{
    /**
     * The framework version.
     */
    public const VERSION = '0.2.4';

    /**
     * The base path for the plugin.
     *
     * Defaults to '' (not left uninitialized) so an Application built with no
     * $basePath — e.g. a lazily auto-created Application::getInstance() in a
     * test process — can still be read safely before setBasePath() runs.
     */
    protected string $basePath = '';

    /**
     * Indicates if the application has been bootstrapped.
     */
    protected bool $hasBeenBootstrapped = false;

    /**
     * Indicates if the application has "booted".
     */
    protected bool $booted = false;

    /**
     * All of the registered service providers.
     *
     * @var array<string, ServiceProviderInterface>
     */
    protected array $serviceProviders = [];

    /**
     * The names of the loaded service providers.
     *
     * @var array<string>
     */
    protected array $loadedProviders = [];

    /**
     * The deferred services and their providers.
     *
     * @var array<string, string>
     */
    protected array $deferredServices = [];

    /**
     * The custom bootstrap path defined by the developer.
     */
    protected ?string $bootstrapPath = null;

    /**
     * The custom config path defined by the developer.
     */
    protected ?string $configPath = null;

    /**
     * The custom database path defined by the developer.
     */
    protected ?string $databasePath = null;

    /**
     * The custom storage path defined by the developer.
     */
    protected ?string $storagePath = null;

    /**
     * The application namespace.
     */
    protected ?string $namespace = null;

    /**
     * The array of all application instances, keyed by their base paths.
     *
     * @var array<string, static>
     */
    protected static array $appInstances = [];

    /**
     * Optional framework components, keyed by name.
     *
     * The kernel (Container, Config, Events, Database, Validation, Settings, Hooks, Http,
     * Log, Error, Pagination, Support, Exceptions, WP) is registered by the constructor for
     * every plugin. Everything listed here is registered only when a plugin names it in
     * `Plugin::$components`, and a plugin build may leave the source directory of an
     * unregistered component out of its package (the directory is `src/<Name>`).
     *
     * @var array<string, class-string<ServiceProviderInterface>>
     */
    public const COMPONENTS = [
        'Admin'      => \BooleanSmtp\Core\Admin\AdminServiceProvider::class,
        'Assets'     => \BooleanSmtp\Core\Assets\AssetsServiceProvider::class,
        'Auth'       => \BooleanSmtp\Core\Auth\AuthServiceProvider::class,
        'Cache'      => \BooleanSmtp\Core\Cache\CacheServiceProvider::class,
        'Console'    => \BooleanSmtp\Core\Console\ConsoleServiceProvider::class,
        'Queue'      => \BooleanSmtp\Core\Queue\QueueServiceProvider::class,
        'Scheduling' => \BooleanSmtp\Core\Scheduling\ScheduleServiceProvider::class,
        'View'       => \BooleanSmtp\Core\View\ViewServiceProvider::class,
    ];

    /**
     * The components registered on this application, in registration order.
     *
     * @var list<string>
     */
    protected array $components = [];

    /**
     * Create a new Application instance.
     *
     * Registers the kernel providers only; components are opted into with
     * {@see registerComponent()} (normally through `Plugin::$components`).
     *
     * @param string|null $basePath The base path of the plugin
     */
    public function __construct(?string $basePath = null)
    {
        if ($basePath) {
            $this->setBasePath($basePath);
        }

        $this->registerBaseBindings();

        if ($this->basePath) {
            static::$appInstances[realpath($this->basePath)] = $this;
        }

        $this->register(\BooleanSmtp\Core\Events\EventServiceProvider::class);
        $this->register(\BooleanSmtp\Core\Database\DatabaseServiceProvider::class);
        $this->register(\BooleanSmtp\Core\Config\ConfigServiceProvider::class);
        $this->register(\BooleanSmtp\Core\Validation\ValidationServiceProvider::class);
        $this->register(\BooleanSmtp\Core\Log\LogServiceProvider::class);
        $this->register(\BooleanSmtp\Core\Settings\SettingsServiceProvider::class);

        // Hook Registry (singleton, always available -- documents extension points)
        $this->singleton(\BooleanSmtp\Core\Hooks\HookRegistry::class);

        // Exception Handler
        $this->singleton(\BooleanSmtp\Core\Error\Handler::class, function ($app) {
            return new \BooleanSmtp\Core\Error\Handler($app);
        });

        $this->registerCoreContainerAliases();
    }

    /**
     * Register an optional framework component by name.
     *
     * @param string $name A key of {@see COMPONENTS}, e.g. `Admin`.
     * @return ServiceProviderInterface The component's provider.
     *
     * @throws \InvalidArgumentException When the name is not a known component.
     */
    public function registerComponent(string $name): ServiceProviderInterface
    {
        if (!isset(static::COMPONENTS[$name])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown framework component "%s"; known components: %s.',
                $name,
                implode(', ', array_keys(static::COMPONENTS))
            ));
        }

        if (!in_array($name, $this->components, true)) {
            $this->components[] = $name;
        }

        return $this->register(static::COMPONENTS[$name]);
    }

    /**
     * Whether a component has been registered on this application.
     *
     * @param string $name A key of {@see COMPONENTS}.
     */
    public function hasComponent(string $name): bool
    {
        return in_array($name, $this->components, true);
    }

    /**
     * The names of the registered components, in registration order.
     *
     * @return list<string>
     */
    public function components(): array
    {
        return $this->components;
    }

    /**
     * Get the application instance by path or the last registered one.
     *
     * @param string|null $path
     * @return static
     */
    public static function getInstance(?string $path = null): static
    {
        if ($path) {
            $path = realpath($path);
            foreach (static::$appInstances as $basePath => $instance) {
                if (str_starts_with($path, $basePath)) {
                    return $instance;
                }
            }
        }

        return parent::getInstance();
    }

    /**
     * Get the version number of the framework.
     *
     * @return string
     */
    public function version(): string
    {
        return static::VERSION;
    }

    /**
     * Register the basic bindings into the container.
     */
    protected function registerBaseBindings(): void
    {
        // Only set the global singleton if none exists yet.
        // When multiple plugins each create their own Application instance,
        // the first one becomes the global accessor for Container::getInstance().
        // Each plugin still has its own Application via $plugin->app().
        if (static::$instance === null) {
            static::setInstance($this);
        }

        $this->instance('app', $this);
        $this->instance(Container::class, $this);
        $this->instance(Application::class, $this);
    }

    /**
     * Register all of the base service providers.
     *
     * @return void
     */
    protected function registerBaseServiceProviders(): void
    {
        // Override in plugin-specific Application to register core providers
    }

    /**
     * Set the base path for the plugin.
     */
    public function setBasePath(string $basePath): static
    {
        $this->basePath = rtrim($basePath, '\/');

        $this->bindPathsInContainer();

        return $this;
    }

    /**
     * Bind all of the application paths in the container.
     */
    protected function bindPathsInContainer(): void
    {
        $this->instance('path', $this->path());
        $this->instance('path.base', $this->basePath());
        $this->instance('path.config', $this->configPath());
        $this->instance('path.database', $this->databasePath());
        $this->instance('path.storage', $this->storagePath());
        $this->instance('path.bootstrap', $this->bootstrapPath());
    }

    /**
     * Get the path to the application "app" directory.
     *
     * @param string $path
     * @return string
     */
    public function path(string $path = ''): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . 'app' . ($path ? DIRECTORY_SEPARATOR . $path : $path);
    }

    /**
     * Get the base path of the plugin.
     *
     * @param string $path
     * @return string
     */
    public function basePath(string $path = ''): string
    {
        return $this->basePath . ($path ? DIRECTORY_SEPARATOR . $path : $path);
    }

    /**
     * Get the path to the bootstrap directory.
     *
     * @param string $path
     * @return string
     */
    public function bootstrapPath(string $path = ''): string
    {
        return ($this->bootstrapPath ?: $this->basePath . DIRECTORY_SEPARATOR . 'bootstrap')
            . ($path ? DIRECTORY_SEPARATOR . $path : $path);
    }

    /**
     * Set the bootstrap file directory.
     */
    public function useBootstrapPath(string $path): static
    {
        $this->bootstrapPath = $path;
        $this->instance('path.bootstrap', $path);

        return $this;
    }

    /**
     * Get the path to the configuration directory.
     *
     * @param string $path
     * @return string
     */
    public function configPath(string $path = ''): string
    {
        return ($this->configPath ?: $this->basePath . DIRECTORY_SEPARATOR . 'config')
            . ($path ? DIRECTORY_SEPARATOR . $path : $path);
    }

    /**
     * Set the config directory.
     */
    public function useConfigPath(string $path): static
    {
        $this->configPath = $path;
        $this->instance('path.config', $path);

        return $this;
    }

    /**
     * Get the path to the database directory.
     *
     * @param string $path
     * @return string
     */
    public function databasePath(string $path = ''): string
    {
        return ($this->databasePath ?: $this->basePath . DIRECTORY_SEPARATOR . 'database')
            . ($path ? DIRECTORY_SEPARATOR . $path : $path);
    }

    /**
     * Set the database directory.
     */
    public function useDatabasePath(string $path): static
    {
        $this->databasePath = $path;
        $this->instance('path.database', $path);

        return $this;
    }

    /**
     * Get the path to the storage directory.
     *
     * @param string $path
     * @return string
     */
    public function storagePath(string $path = ''): string
    {
        return ($this->storagePath ?: $this->basePath . DIRECTORY_SEPARATOR . 'storage')
            . ($path ? DIRECTORY_SEPARATOR . $path : $path);
    }

    /**
     * The directory the plugin's log files are written to: `<uploads>/booleanpress/<slug>/logs` on
     * WordPress, `storage/logs` elsewhere ({@see \BooleanSmtp\Core\Log\LogRoot}).
     */
    public function logPath(string $path = ''): string
    {
        return $this->make(\BooleanSmtp\Core\Log\LogManager::class)->root()->path($path);
    }

    /**
     * Set the storage directory.
     */
    public function useStoragePath(string $path): static
    {
        $this->storagePath = $path;
        $this->instance('path.storage', $path);

        return $this;
    }

    /**
     * Register a service provider with the application.
     *
     * @param ServiceProviderInterface|string $provider
     * @param bool $force Force re-registration
     * @return ServiceProviderInterface
     */
    public function register(ServiceProviderInterface|string $provider, bool $force = false): ServiceProviderInterface
    {
        if (($registered = $this->getProvider($provider)) && !$force) {
            return $registered;
        }

        if (is_string($provider)) {
            $provider = $this->resolveProvider($provider);
        }

        $provider->register();

        $this->markAsRegistered($provider);

        // If the application has already booted, boot this provider
        if ($this->isBooted()) {
            $this->bootProvider($provider);
        }

        return $provider;
    }

    /**
     * Get the registered service provider instance if it exists.
     *
     * @param ServiceProviderInterface|string $provider
     * @return ServiceProviderInterface|null
     */
    public function getProvider(ServiceProviderInterface|string $provider): ?ServiceProviderInterface
    {
        $name = is_string($provider) ? $provider : get_class($provider);

        return $this->serviceProviders[$name] ?? null;
    }

    /**
     * Get all service providers.
     *
     * @return array<string, ServiceProviderInterface>
     */
    public function getProviders(): array
    {
        return $this->serviceProviders;
    }

    /**
     * Resolve a service provider instance from the class name.
     *
     * @param string $provider
     * @return ServiceProviderInterface
     */
    public function resolveProvider(string $provider): ServiceProviderInterface
    {
        return new $provider($this);
    }

    /**
     * Mark the given provider as registered.
     */
    protected function markAsRegistered(ServiceProviderInterface $provider): void
    {
        $class = get_class($provider);

        $this->serviceProviders[$class] = $provider;
        $this->loadedProviders[$class] = true;
    }

    /**
     * Boot the given service provider.
     *
     * @param ServiceProviderInterface $provider
     * @return void
     */
    protected function bootProvider(ServiceProviderInterface $provider): void
    {
        $provider->callBootingCallbacks();
    }

    /**
     * Boot the application's service providers.
     *
     * @return void
     */
    public function boot(): void
    {
        if ($this->isBooted()) {
            return;
        }

        foreach ($this->serviceProviders as $provider) {
            $this->bootProvider($provider);
        }

        $this->booted = true;
    }

    /**
     * Determine if the application has been booted.
     *
     * @return bool
     */
    public function isBooted(): bool
    {
        return $this->booted;
    }

    /**
     * Add an array of services to the application's deferred services.
     *
     * @param array<string, string> $services
     */
    public function addDeferredServices(array $services): void
    {
        $this->deferredServices = array_merge($this->deferredServices, $services);
    }

    /**
     * Determine if the given service is a deferred service.
     */
    public function isDeferredService(string $service): bool
    {
        return isset($this->deferredServices[$service]);
    }

    /**
     * Load and boot the deferred provider for a service.
     */
    public function loadDeferredProvider(string $service): void
    {
        if (!$this->isDeferredService($service)) {
            return;
        }

        $provider = $this->deferredServices[$service];

        if (!isset($this->loadedProviders[$provider])) {
            $this->registerDeferredProvider($provider, $service);
        }
    }

    /**
     * Register a deferred provider and service.
     */
    public function registerDeferredProvider(string $provider, ?string $service = null): void
    {
        if ($service) {
            unset($this->deferredServices[$service]);
        }

        $instance = $this->register($provider);

        if (!$this->isBooted()) {
            return;
        }

        $this->bootProvider($instance);
    }

    /**
     * Resolve the given type from the container.
     *
     * @param string $abstract
     * @param array<string, mixed> $parameters
     * @return mixed
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        $abstract = $this->getAlias($abstract);

        // Load deferred provider if needed
        if ($this->isDeferredService($abstract)) {
            $this->loadDeferredProvider($abstract);
        }

        return parent::make($abstract, $parameters);
    }

    /**
     * Register the core class aliases in the container.
     */
    public function registerCoreContainerAliases(): void
    {
        $aliases = [
            'app' => [
                Application::class,
                Container::class,
                \BooleanSmtp\Core\Contracts\ContainerInterface::class,
            ],
        ];

        foreach ($aliases as $key => $aliasArray) {
            foreach ($aliasArray as $alias) {
                $this->alias($key, $alias);
            }
        }
    }

    /**
     * Run the given array of bootstrap classes.
     *
     * @param array<string> $bootstrappers
     */
    public function bootstrapWith(array $bootstrappers): void
    {
        $this->hasBeenBootstrapped = true;

        foreach ($bootstrappers as $bootstrapper) {
            $this->make($bootstrapper)->bootstrap($this);
        }
    }

    /**
     * Determine if the application has been bootstrapped.
     */
    public function hasBeenBootstrapped(): bool
    {
        return $this->hasBeenBootstrapped;
    }

    /**
     * Get the application namespace.
     */
    public function getNamespace(): string
    {
        if ($this->namespace !== null) {
            return $this->namespace;
        }

        // Try to get namespace from composer.json
        $composerPath = $this->basePath('composer.json');
        if (file_exists($composerPath)) {
            $composer = json_decode(file_get_contents($composerPath), true);
            if (isset($composer['autoload']['psr-4'])) {
                $namespaces = array_keys($composer['autoload']['psr-4']);
                $this->namespace = $namespaces[0] ?? 'App\\';
            }
        }

        return $this->namespace ??= 'App\\';
    }

    /**
     * Set the application namespace.
     */
    public function setNamespace(string $namespace): static
    {
        $this->namespace = rtrim($namespace, '\\') . '\\';

        return $this;
    }

    /**
     * Determine if we are running in the console (WP-CLI).
     */
    public function runningInConsole(): bool
    {
        return \defined('WP_CLI') && WP_CLI;
    }

    /**
     * Get the URL for the plugin.
     */
    public function pluginUrl(string $path = ''): string
    {
        if (\function_exists('plugin_dir_url')) {
            return plugin_dir_url($this->basePath . '/plugin.php') . ltrim($path, '/');
        }

        return $this->basePath($path);
    }

    /**
     * Get the current WordPress environment.
     */
    public function environment(): string
    {
        if (\defined('WP_ENVIRONMENT_TYPE')) {
            return WP_ENVIRONMENT_TYPE;
        }

        if (\function_exists('wp_get_environment_type')) {
            return wp_get_environment_type();
        }

        return 'production';
    }

    /**
     * Check if the application is in debug mode.
     */
    public function isDebug(): bool
    {
        return \defined('WP_DEBUG') && WP_DEBUG;
    }

    /**
     * Get the path to the cached packages manifest.
     */
    public function getCachedPackagesPath(): string
    {
        return $this->bootstrapPath('cache/packages.php');
    }

    /**
     * Get the path to the cached services manifest.
     */
    public function getCachedServicesPath(): string
    {
        return $this->bootstrapPath('cache/services.php');
    }

    /**
     * Terminate the application.
     */
    public function terminate(): void
    {
        // Override for custom termination logic
    }

    /**
     * The cached plugin data from the main plugin file.
     *
     * @var array<string, string>|null
     */
    protected ?array $pluginData = null;

    /**
     * The main plugin file path.
     */
    protected ?string $pluginFile = null;

    /**
     * Set the main plugin file path.
     */
    public function setPluginFile(string $pluginFile): static
    {
        $this->pluginFile = $pluginFile;
        $this->pluginData = null; // Reset cached data
        return $this;
    }

    /**
     * Get the main plugin file path.
     * Attempts to auto-detect if not explicitly set.
     */
    public function getPluginFile(): ?string
    {
        if ($this->pluginFile) {
            return $this->pluginFile;
        }

        // Try common plugin file names
        $candidates = [
            $this->basePath . '/plugin.php',
            $this->basePath . '/' . basename($this->basePath) . '.php',
        ];

        // Look for any PHP file with "Plugin Name:" header
        foreach ($candidates as $file) {
            if (file_exists($file)) {
                $this->pluginFile = $file;
                return $file;
            }
        }

        // Search for main plugin file in base directory
        $files = glob($this->basePath . '/*.php');
        foreach ($files as $file) {
            $content = file_get_contents($file, false, null, 0, 8192);
            if (stripos($content, 'Plugin Name:') !== false) {
                return $this->pluginFile = $file;
            }
        }

        return null;
    }

    /**
     * Get all plugin header data.
     *
     * @return array<string, string>
     */
    public function getPluginData(): array
    {
        if ($this->pluginData !== null) {
            return $this->pluginData;
        }

        $pluginFile = $this->getPluginFile();
        if (!$pluginFile || !file_exists($pluginFile)) {
            return $this->pluginData = [];
        }

        // Use WordPress's get_plugin_data() if available
        if (function_exists('get_plugin_data')) {
            $this->pluginData = get_plugin_data($pluginFile, false, false);
            return $this->pluginData;
        }

        // Fallback: Parse headers manually
        $content = file_get_contents($pluginFile, false, null, 0, 8192);

        $headers = [
            'Name' => 'Plugin Name',
            'PluginURI' => 'Plugin URI',
            'Version' => 'Version',
            'Description' => 'Description',
            'Author' => 'Author',
            'AuthorURI' => 'Author URI',
            'TextDomain' => 'Text Domain',
            'DomainPath' => 'Domain Path',
            'Network' => 'Network',
            'RequiresWP' => 'Requires at least',
            'RequiresPHP' => 'Requires PHP',
        ];

        $this->pluginData = [];
        foreach ($headers as $key => $header) {
            if (preg_match('/^[\s\*#@]*' . preg_quote($header, '/') . ':\s*(.+)$/mi', $content, $match)) {
                $this->pluginData[$key] = trim($match[1]);
            } else {
                $this->pluginData[$key] = '';
            }
        }

        return $this->pluginData;
    }

    /**
     * Get the plugin name from the header.
     */
    public function pluginName(): string
    {
        $data = $this->getPluginData();
        return $data['Name'] ?? '';
    }

    /**
     * Get the plugin version from the header.
     */
    public function pluginVersion(): string
    {
        $data = $this->getPluginData();
        return $data['Version'] ?? '1.0.0';
    }

    /**
     * Get a URL-friendly slug from the plugin name.
     * Example: "BooleanPress To-Do Example" -> "todo"
     */
    public function pluginSlug(): string
    {
        $data = $this->getPluginData();

        if (!empty($data['TextDomain'])) {
            return $data['TextDomain'];
        }

        $name = $data['Name'] ?? '';

        if (empty($name)) {
            // Fallback to directory name
            return strtolower(basename($this->basePath));
        }

        // Remove common prefixes
        $name = preg_replace('/^(BooleanPress|BP)\s+/i', '', $name);

        // Remove common suffixes
        $name = preg_replace('/\s+(Plugin|Example|App)$/i', '', $name);

        // Convert to slug
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        return $slug ?: strtolower(basename($this->basePath));
    }

    /**
     * Get the plugin text domain from the header.
     */
    public function textDomain(): string
    {
        $data = $this->getPluginData();
        return $data['TextDomain'] ?? $this->pluginSlug();
    }
}
