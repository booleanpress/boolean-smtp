<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Validation;

use BooleanSmtp\Core\Container\ServiceProvider;

class ValidationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('validator', function ($app) {
            return new Factory($app);
        });
    }
}
