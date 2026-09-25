<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Database;

use BooleanSmtp\Core\Database\Drivers\DriverInterface;
use BooleanSmtp\Core\Database\Drivers\MySQLDriver;
use BooleanSmtp\Core\Database\Drivers\SQLiteDriver;

/**
 * Database Connection Manager
 *
 * Manages database driver instances and allows switching between
 * MySQL (production) and SQLite (testing) drivers.
 */
class ConnectionManager
{
    /**
     * The default connection name.
     */
    protected string $default = 'mysql';

    /**
     * The registered connection configurations.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $connections = [];

    /**
     * The resolved driver instances.
     *
     * @var array<string, DriverInterface>
     */
    protected array $drivers = [];

    /**
     * Create a new connection manager.
     *
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        if (isset($config['default'])) {
            $this->default = $config['default'];
        }

        if (isset($config['connections'])) {
            $this->connections = $config['connections'];
        }
    }

    /**
     * Get a database driver instance.
     */
    public function connection(?string $name = null): DriverInterface
    {
        $name = $name ?? $this->default;

        if (!isset($this->drivers[$name])) {
            $this->drivers[$name] = $this->createDriver($name);
        }

        return $this->drivers[$name];
    }

    /**
     * Get the default connection driver.
     */
    public function getDefault(): DriverInterface
    {
        return $this->connection();
    }

    /**
     * Set the default connection name.
     */
    public function setDefaultConnection(string $name): void
    {
        $this->default = $name;
    }

    /**
     * Get the default connection name.
     */
    public function getDefaultConnectionName(): string
    {
        return $this->default;
    }

    /**
     * Add a connection configuration.
     *
     * @param array<string, mixed> $config
     */
    public function addConnection(string $name, array $config): void
    {
        $this->connections[$name] = $config;
    }

    /**
     * Set a pre-built driver instance.
     */
    public function setDriver(string $name, DriverInterface $driver): void
    {
        $this->drivers[$name] = $driver;
    }

    /**
     * Check if a connection exists.
     */
    public function hasConnection(string $name): bool
    {
        return isset($this->connections[$name]) || isset($this->drivers[$name]);
    }

    /**
     * Create a driver instance for the given connection.
     */
    protected function createDriver(string $name): DriverInterface
    {
        $config = $this->connections[$name] ?? [];
        $driverType = $config['driver'] ?? $name;

        return match ($driverType) {
            'mysql', 'wpdb' => $this->createMySQLDriver($config),
            'sqlite'        => $this->createSQLiteDriver($config),
            default         => throw new \InvalidArgumentException("Unsupported database driver: {$driverType}"),
        };
    }

    /**
     * Create a MySQL driver.
     *
     * @param array<string, mixed> $config
     */
    protected function createMySQLDriver(array $config): MySQLDriver
    {
        return new MySQLDriver();
    }

    /**
     * Create a SQLite driver.
     *
     * @param array<string, mixed> $config
     */
    protected function createSQLiteDriver(array $config): SQLiteDriver
    {
        $database = $config['database'] ?? ':memory:';
        $prefix = $config['prefix'] ?? 'wp_test_';

        return new SQLiteDriver($database, $prefix);
    }

    /**
     * Disconnect a driver.
     */
    public function disconnect(?string $name = null): void
    {
        $name = $name ?? $this->default;
        unset($this->drivers[$name]);
    }

    /**
     * Reconnect a driver.
     */
    public function reconnect(?string $name = null): DriverInterface
    {
        $this->disconnect($name);
        return $this->connection($name);
    }
}
