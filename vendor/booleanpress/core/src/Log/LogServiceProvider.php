<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Log;

use BooleanSmtp\Core\Container\ServiceProvider;

/**
 * Binds the plugin's {@see LogManager} and the framework {@see Logger} (`log`).
 */
class LogServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton(LogManager::class, function ($app) {
            return LogManager::fromApplication($app);
        });

        $this->app->singleton(Logger::class, function ($app) {
            return new Logger($app);
        });

        $this->app->alias(Logger::class, 'log');
    }
}
