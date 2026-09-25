<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Settings;

use BooleanSmtp\Core\Container\ServiceProvider;

class SettingsServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->bind(Schema::class, function ($app) {
            return new Schema();
        });

        $this->app->singleton(SettingsRepository::class, function ($app) {
            $plugin = $app->pluginSlug();
            $repo = new SettingsRepository($plugin);
            $repo->loadAutoloaded();
            return $repo;
        });

        $this->app->alias(SettingsRepository::class, 'settings');
    }
}
