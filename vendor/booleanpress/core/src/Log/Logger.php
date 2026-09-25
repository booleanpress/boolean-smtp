<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Log;

use BooleanSmtp\Core\Contracts\LoggerContract;
use BooleanSmtp\Core\Foundation\Application;

/**
 * The framework's logger: the `core` channel of the plugin's {@see LogManager} by default.
 *
 * Writes nothing until the channel is switched on with the `booleanpress_log_enabled` filter; then
 * to `<uploads>/booleanpress/<slug>/logs/core-<date>-<hash>.log`, rotated, pruned and capped by the
 * manager. `channel()` returns a logger for any other channel of the same plugin.
 */
class Logger implements LoggerContract
{
    /**
     * The channel entries go to.
     */
    protected string $channel = 'core';

    protected LogManager $manager;

    public function __construct(Application $app)
    {
        $this->manager = $app->bound(LogManager::class)
            ? $app->make(LogManager::class)
            : LogManager::fromApplication($app);
    }

    /**
     * A logger for another channel of the same plugin.
     */
    public function channel(string $channel): static
    {
        $new = clone $this;
        $new->channel = LogManager::normalise($channel);

        return $new;
    }

    /**
     * Whether this logger's channel writes at all.
     */
    public function isEnabled(): bool
    {
        return $this->manager->enabled($this->channel);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function emergency(string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function alert(string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * Log a message at a PSR-3 level.
     *
     * @param array<string, mixed> $context
     */
    public function log(string $level, string|\Stringable $message, array $context = []): void
    {
        $this->manager->write($this->channel, $level, (string) $message, $context);
    }
}
