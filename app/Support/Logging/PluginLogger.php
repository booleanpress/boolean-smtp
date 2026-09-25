<?php

/**
 * The plugin's logger: PHP's error log until the `app` log channel is switched on.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Support\Logging;

use BooleanSmtp\Adapters\WordPress\WordPressLogger;
use BooleanSmtp\Core\Contracts\LoggerContract;
use BooleanSmtp\Core\Log\LogManager;

/**
 * What `LoggerContract` resolves to. While the `app` channel is off — the default — every line goes
 * to {@see WordPressLogger} (PHP's error log, which WordPress routes to `debug.log` only when
 * `WP_DEBUG_LOG` is on), exactly as before, so the plugin writes no file of its own. Once a site
 * switches the channel on (`boolean_smtp_log_file_enabled`), lines go to the channel's rotated file
 * instead.
 *
 * @since 1.0.0
 */
final class PluginLogger implements LoggerContract
{
    /**
     * @since 1.0.0
     *
     * @param WordPressLogger $fallback Writes to PHP's error log.
     * @param LogManager      $logs     The plugin's log channels.
     */
    public function __construct(
        private readonly WordPressLogger $fallback,
        private readonly LogManager $logs,
    ) {
    }

    /**
     * System is unusable.
     *
     * @since 1.0.0
     *
     * @param string               $message Log message.
     * @param array<string, mixed> $context Structured context.
     */
    public function emergency(string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    /**
     * Action must be taken immediately.
     *
     * @since 1.0.0
     *
     * @param string               $message Log message.
     * @param array<string, mixed> $context Structured context.
     */
    public function alert(string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    /**
     * Critical conditions.
     *
     * @since 1.0.0
     *
     * @param string               $message Log message.
     * @param array<string, mixed> $context Structured context.
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    /**
     * Runtime errors that do not require immediate action.
     *
     * @since 1.0.0
     *
     * @param string               $message Log message.
     * @param array<string, mixed> $context Structured context.
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * Exceptional occurrences that are not errors.
     *
     * @since 1.0.0
     *
     * @param string               $message Log message.
     * @param array<string, mixed> $context Structured context.
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * Normal but significant events.
     *
     * @since 1.0.0
     *
     * @param string               $message Log message.
     * @param array<string, mixed> $context Structured context.
     */
    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    /**
     * Interesting events.
     *
     * @since 1.0.0
     *
     * @param string               $message Log message.
     * @param array<string, mixed> $context Structured context.
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * Detailed debug information.
     *
     * @since 1.0.0
     *
     * @param string               $message Log message.
     * @param array<string, mixed> $context Structured context.
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * Write to the `app` channel when it is on, to PHP's error log otherwise.
     *
     * @since 1.0.0
     *
     * @param string               $level   PSR-3 level name.
     * @param string               $message Log message.
     * @param array<string, mixed> $context Structured context.
     */
    private function log(string $level, string $message, array $context): void
    {
        if ($this->logs->enabled('app')) {
            $this->logs->channel('app')->log($level, $message, $context);
            return;
        }

        $this->fallback->{$level}($message, $context);
    }
}
