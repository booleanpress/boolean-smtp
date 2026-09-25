<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Container;

use BooleanSmtp\Core\Contracts\ServiceProviderInterface;

/**
 * Base Service Provider
 *
 * Service providers are the central place of all BooleanPress application bootstrapping.
 * Your own application, as well as all core services, are bootstrapped via service providers.
 *
 * @example
 * class MyServiceProvider extends ServiceProvider
 * {
 *     public function register(): void
 *     {
 *         $this->app->singleton(MyService::class, function ($app) {
 *             return new MyService($app->make(Dependency::class));
 *         });
 *     }
 *
 *     public function boot(): void
 *     {
 *         // Bootstrap after all providers registered
 *     }
 * }
 */
abstract class ServiceProvider implements ServiceProviderInterface
{
    /**
     * The application instance.
     */
    protected Container $app;

    /**
     * Indicates if the provider has been booted.
     */
    protected bool $booted = false;

    /**
     * The services provided by the provider.
     *
     * @var array<string>
     */
    protected array $provides = [];

    /**
     * Create a new service provider instance.
     */
    public function __construct(Container $app)
    {
        $this->app = $app;
    }

    /**
     * {@inheritDoc}
     */
    public function register(): void
    {
        // Override in child classes
    }

    /**
     * {@inheritDoc}
     */
    public function boot(): void
    {
        // Override in child classes
    }

    /**
     * {@inheritDoc}
     */
    public function provides(): array
    {
        return $this->provides;
    }

    /**
     * {@inheritDoc}
     */
    public function isDeferred(): bool
    {
        return !empty($this->provides);
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<string>
     */
    public function when(): array
    {
        return [];
    }

    /**
     * Register a binding if it hasn't already been registered.
     */
    protected function bindIf(string $abstract, \Closure|string|null $concrete = null, bool $shared = false): void
    {
        if (!$this->app->bound($abstract)) {
            $this->app->bind($abstract, $concrete, $shared);
        }
    }

    /**
     * Register a singleton binding if it hasn't already been registered.
     */
    protected function singletonIf(string $abstract, \Closure|string|null $concrete = null): void
    {
        if (!$this->app->bound($abstract)) {
            $this->app->singleton($abstract, $concrete);
        }
    }

    /**
     * Merge the given configuration with the existing configuration.
     *
     * @param string $path Path to config file
     * @param string $key Configuration key
     */
    protected function mergeConfigFrom(string $path, string $key): void
    {
        if ($this->app->bound('config')) {
            $config = $this->app->make('config');
            $config->set($key, array_merge(
                require $path,
                $config->get($key, [])
            ));
        }
    }

    /**
     * Load the given routes file.
     */
    protected function loadRoutesFrom(string $path): void
    {
        require $path;
    }

    /**
     * Register console commands with the Console component.
     *
     * Does nothing unless the plugin registers the `Console` component and the request
     * runs under WP-CLI, so providers may call this unconditionally from `boot()`.
     *
     * @param array<class-string<\BooleanSmtp\Core\Console\Command>>|class-string<\BooleanSmtp\Core\Console\Command> $commands
     */
    protected function commands(array|string $commands): void
    {
        if (!$this->app->bound('console') || !\BooleanSmtp\Core\Console\Application::isRunning()) {
            return;
        }

        $console = $this->app->make('console');
        foreach ((array) $commands as $command) {
            $console->add($command);
        }
    }

    /**
     * Register console commands with the Console component.
     *
     * @deprecated 0.2.0 Use {@see commands()}.
     *
     * @param array<string>|string $paths
     */
    protected function loadCommands(array|string $paths): void
    {
        $this->commands($paths);
    }

    /**
     * Register WordPress hooks.
     */
    protected function addAction(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        if (function_exists('add_action')) {
            add_action($hook, $callback, $priority, $acceptedArgs);
        }
    }

    /**
     * Register WordPress filters.
     */
    protected function addFilter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        if (function_exists('add_filter')) {
            add_filter($hook, $callback, $priority, $acceptedArgs);
        }
    }

    /**
     * Call the boot method on the service provider.
     */
    public function callBootingCallbacks(): void
    {
        if ($this->booted) {
            return;
        }

        $this->boot();
        $this->booted = true;
    }
}
