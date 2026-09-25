<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Config;

use BooleanSmtp\Core\Container\ServiceProvider;

class ConfigServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton('config', function ($app) {
            $loader = new FileLoader($app->configPath());
            return new Repository($loader->load());
        });

        $this->app->instance(Repository::class, $this->app['config']);
    }

    /**
     * Boot the service provider.
     *
     * @return void
     */
    public function boot(): void
    {
        //
    }
}
