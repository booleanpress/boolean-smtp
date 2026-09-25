<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Database;

use BooleanSmtp\Core\Container\ServiceProvider;
use BooleanSmtp\Core\Database\Drivers\DriverInterface;
use BooleanSmtp\Core\Database\Drivers\MySQLDriver;

class DatabaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register the Connection Manager
        $this->app->singleton(ConnectionManager::class, function ($app) {
            $config = [];

            // Try to load database config from the plugin's config directory
            if (method_exists($app, 'configPath')) {
                $configPath = $app->configPath('database.php');
                if (file_exists($configPath)) {
                    $config = require $configPath;
                }
            }

            // Default to MySQL if no config
            if (empty($config)) {
                $config = [
                    'default' => 'mysql',
                    'connections' => [
                        'mysql' => ['driver' => 'mysql'],
                    ],
                ];
            }

            return new ConnectionManager($config);
        });

        // Register the default driver as a singleton
        $this->app->singleton(DriverInterface::class, function ($app) {
            return $app->make(ConnectionManager::class)->getDefault();
        });

        // Alias for convenience
        $this->app->alias(ConnectionManager::class, 'db');
        $this->app->alias(DriverInterface::class, 'db.driver');
    }
}
