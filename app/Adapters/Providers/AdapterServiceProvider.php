<?php
/**
 * Registers the WordPress adapter implementations in the dependency injection container.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Adapters\Providers;

use BooleanSmtp\Core\Container\ServiceProvider;
use BooleanSmtp\Core\Contracts\LoggerContract;
use BooleanSmtp\Core\Log\LogManager;
use BooleanSmtp\Support\Logging\PluginLogger;
use BooleanSmtp\Adapters\Contracts\AuthAdapterContract;
use BooleanSmtp\Adapters\Contracts\ContextAdapterContract;
use BooleanSmtp\Adapters\Contracts\EnvironmentAdapterContract;
use BooleanSmtp\Adapters\Contracts\ErrorHandlerAdapterContract;
use BooleanSmtp\Adapters\Contracts\HookAdapterContract;
use BooleanSmtp\Adapters\Contracts\HttpAdapterContract;
use BooleanSmtp\Adapters\Contracts\RouterAdapterContract;
use BooleanSmtp\Adapters\Contracts\ScheduleAdapterContract;
use BooleanSmtp\Adapters\WordPress\AuthAdapter;
use BooleanSmtp\Adapters\WordPress\ContextAdapter;
use BooleanSmtp\Adapters\WordPress\Environment;
use BooleanSmtp\Adapters\WordPress\ErrorHandler;
use BooleanSmtp\Adapters\WordPress\WordPressLogger;
use BooleanSmtp\Adapters\WordPress\HookAdapter;
use BooleanSmtp\Adapters\WordPress\HttpAdapter;
use BooleanSmtp\Adapters\WordPress\RouterAdapter;
use BooleanSmtp\Adapters\WordPress\ScheduleAdapter;
use BooleanSmtp\Adapters\WordPress\TranslatorAdapter;
use BooleanSmtp\Contracts\TranslatorContract;

/**
 * Binds every adapter contract to its WordPress implementation as a container singleton, plus the
 * framework's logger contract to the WordPress debug-log logger.
 *
 * This lets the rest of the plugin depend on the adapter contracts and receive a WordPress-backed
 * implementation, instead of depending on WordPress functions directly.
 *
 * @since 1.0.0
 */
final class AdapterServiceProvider extends ServiceProvider {
    /**
     * Register the adapter contract bindings.
     *
     * Runs early in the bootstrap sequence, before any service that depends on an adapter contract
     * is resolved.
     *
     * @since 1.0.0
     */
    public function register(): void {
        $this->app->singleton(
            HookAdapterContract::class,
            HookAdapter::class
        );

        // PHP's error log until a site switches the `app` log channel on; then its rotated file.
        $this->app->singleton(
            LoggerContract::class,
            static fn ($app): LoggerContract => new PluginLogger(
                new WordPressLogger(),
                $app->make(LogManager::class)
            )
        );

        $this->app->singleton(
            ErrorHandlerAdapterContract::class,
            ErrorHandler::class
        );

        $this->app->singleton(
            EnvironmentAdapterContract::class,
            Environment::class
        );

        // Bound as a singleton because context is scoped per process in CLI and per request in web.
        $this->app->singleton(
            ContextAdapterContract::class,
            ContextAdapter::class
        );

        $this->app->singleton(
            HttpAdapterContract::class,
            HttpAdapter::class
        );

        $this->app->singleton(
            ScheduleAdapterContract::class,
            ScheduleAdapter::class
        );

        $this->app->singleton(
            AuthAdapterContract::class,
            AuthAdapter::class
        );

        $this->app->singleton(
            RouterAdapterContract::class,
            RouterAdapter::class
        );

        // The text domain is bound here so every translation is consistently scoped to the plugin
        // and cannot be misspelled at the call site.
        $this->app->singleton(
            TranslatorContract::class,
            fn($app) => new TranslatorAdapter('boolean-smtp')
        );
    }

    /**
     * Run provider initialization that depends on every provider having registered its bindings.
     *
     * This provider currently has no boot-time work.
     *
     * @since 1.0.0
     */
    public function boot(): void {
    }
}
