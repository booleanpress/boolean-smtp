<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Console;

use BooleanSmtp\Core\Container\ServiceProvider;
use BooleanSmtp\Core\Console\Commands\HooksListCommand;
use BooleanSmtp\Core\Console\Commands\MigrateCommand;

/**
 * Console component: WP-CLI commands under the plugin's slug.
 *
 * Registered through `Plugin::$components` (`Console`). Binds the console application
 * (namespace = the plugin slug, so two BooleanPress plugins never collide) and, when the
 * request runs under WP-CLI, adds the framework's `migrate` and `hooks:list` commands.
 * Plugins add their own with `ServiceProvider::commands()`.
 */
class ConsoleServiceProvider extends ServiceProvider
{
    /**
     * Register the console application.
     */
    public function register(): void
    {
        $this->app->singleton(Application::class, function ($app) {
            return new Application($app, $app->pluginSlug());
        });

        $this->app->alias(Application::class, 'console');
    }

    /**
     * Add the framework commands when running under WP-CLI.
     */
    public function boot(): void
    {
        $this->commands([
            MigrateCommand::class,
            HooksListCommand::class,
        ]);
    }
}
