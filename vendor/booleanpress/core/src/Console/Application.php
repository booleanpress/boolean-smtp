<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Console;

use BooleanSmtp\Core\Container\Container;

/**
 * Console Application
 *
 * Manages WP-CLI command registration and execution.
 */
class Application
{
    /**
     * The container instance.
     */
    protected Container $container;

    /**
     * The parent command namespace.
     */
    protected string $namespace;

    /**
     * Registered commands.
     *
     * @var array<string, class-string<Command>>
     */
    protected array $commands = [];

    /**
     * Create a new console application.
     */
    public function __construct(?Container $container = null, string $namespace = 'booleanpress')
    {
        $this->container = $container ?? Container::getInstance();
        $this->namespace = $namespace;
    }

    /**
     * Set the command namespace.
     */
    public function setNamespace(string $namespace): static
    {
        $this->namespace = $namespace;
        return $this;
    }

    /**
     * Get the command namespace.
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * Register a command.
     *
     * @param class-string<Command>|Command $command
     */
    public function add(string|Command $command): static
    {
        if (is_string($command)) {
            $instance = $this->container->make($command);
        } else {
            $instance = $command;
        }

        if (method_exists($instance, 'setApplication') && $this->container instanceof \BooleanSmtp\Core\Foundation\Application) {
            $instance->setApplication($this->container);
        }

        $this->commands[$instance->getName()] = $instance::class;
        $instance->register($this->namespace);

        return $this;
    }

    /**
     * Register multiple commands.
     *
     * @param array<class-string<Command>> $commands
     */
    public function addCommands(array $commands): static
    {
        foreach ($commands as $command) {
            $this->add($command);
        }

        return $this;
    }

    /**
     * Check if running in WP-CLI context.
     */
    public static function isRunning(): bool
    {
        return defined('WP_CLI') && WP_CLI;
    }

    /**
     * Register commands only when running in WP-CLI.
     */
    public function registerWhenRunning(callable $callback): void
    {
        if (self::isRunning()) {
            $callback($this);
        }
    }

    /**
     * Get all registered commands.
     *
     * @return array<string, class-string<Command>>
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * Check if a command is registered.
     */
    public function hasCommand(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    /**
     * Create a command from a closure.
     */
    public function command(string $name, string $description, callable $handler): static
    {
        $command = new class ($name, $description, $handler) extends Command {
            private \Closure $handler;

            public function __construct(string $name, string $description, callable $handler)
            {
                $this->name = $name;
                $this->description = $description;
                $this->handler = \Closure::fromCallable($handler);
            }

            public function handle(array $args, array $assocArgs): void
            {
                ($this->handler)($args, $assocArgs, $this);
            }
        };

        return $this->add($command);
    }

    /**
     * Write a message to the console.
     */
    public static function info(string $message): void
    {
        if (self::isRunning()) {
            \WP_CLI::log($message);
        }
    }

    /**
     * Write a success message.
     */
    public static function success(string $message): void
    {
        if (self::isRunning()) {
            \WP_CLI::success($message);
        }
    }

    /**
     * Write a warning message.
     */
    public static function warning(string $message): void
    {
        if (self::isRunning()) {
            \WP_CLI::warning($message);
        }
    }

    /**
     * Write an error message.
     */
    public static function error(string $message): void
    {
        if (self::isRunning()) {
            \WP_CLI::error($message);
        }
    }
}
