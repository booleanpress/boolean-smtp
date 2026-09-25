<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Admin;

use BooleanSmtp\Core\Container\ServiceProvider;

class AdminServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(Admin::class, function ($app) {
            return new Admin();
        });

        $this->app->alias(Admin::class, 'admin');
    }
}
