<?php
/**
 * WordPress implementation of the framework's logger contract.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Adapters\WordPress;

use BooleanSmtp\Core\Contracts\LoggerContract;

/**
 * Writes plugin log lines to the PHP error log, which WordPress routes to `wp-content/debug.log`
 * when `WP_DEBUG_LOG` is enabled.
 *
 * Every line is prefixed `[boolean-smtp] LEVEL:` so site owners can filter the debug log. `debug`
 * lines are written only while `WP_DEBUG` is on; every other level is always written. This class
 * and the diagnostic {@see \BooleanSmtp\Support\Debug\WordPressDebugLogger} are the only places in the
 * plugin that call `error_log()` directly.
 *
 * @since 1.0.0
 */
final class WordPressLogger implements LoggerContract {
    /**
     * Log line prefix.
     *
     * @since 1.0.0
     * @var string
     */
    private const PREFIX = '[boolean-smtp]';

    /**
     * System is unusable.
     *
     * @since 1.0.0
     *
     * @param string               $message Log message.
     * @param array<string, mixed> $context Structured context appended as JSON.
     */
    public function emergency(string $message, array $context = []): void {
        $this->write('emergency', $message, $context);
    }

    /**
     * Action must be taken immediately.
     *
     * @since 1.0.0
     *
     * @param string               $message Log message.
     * @param array<string, mixed> $context Structured context appended as JSON.
     */
    public function alert(string $message, array $context = []): void {
        $this->write('alert', $message, $context);
    }

    /**
     * Critical conditions.
     *
     * @since 1.0.0
     *
     * @param string               $message Log message.
     * @param array<string, mixed> $context Structured context appended as JSON.
     */
    public function critical(string $message, array $context = []): void {
        $this->write('critical', $message, $context);
    }

    /**
     * Runtime errors that do not require immediate action.
     *
     * @since 1.0.0
     *
     * @param string               $message Log message.
     * @param array<string, mixed> $context Structured context appended as JSON.
     */
    public function error(string $message, array $context = []): void {
        $this->write('error', $message, $context);
    }

    /**
     * Exceptional occurrences that are not errors.
     *
     * @since 1.0.0
     *
     * @param string               $message Log message.
     * @param array<string, mixed> $context Structured context appended as JSON.
     */
    public function warning(string $message, array $context = []): void {
        $this->write('warning', $message, $context);
    }

    /**
     * Normal but significant events.
     *
     * @since 1.0.0
     *
     * @param string               $message Log message.
     * @param array<string, mixed> $context Structured context appended as JSON.
     */
    public function notice(string $message, array $context = []): void {
        $this->write('notice', $message, $context);
    }

    /**
     * Interesting events.
     *
     * @since 1.0.0
     *
     * @param string               $message Log message.
     * @param array<string, mixed> $context Structured context appended as JSON.
     */
    public function info(string $message, array $context = []): void {
        $this->write('info', $message, $context);
    }

    /**
     * Detailed debug information.
     *
     * @since 1.0.0
     *
     * @param string               $message Log message.
     * @param array<string, mixed> $context Structured context appended as JSON.
     */
    public function debug(string $message, array $context = []): void {
        $this->write('debug', $message, $context);
    }

    /**
     * Whether a line of the given level is written in the current environment.
     *
     * @since 1.0.0
     *
     * @param string $level PSR-3 level name.
     * @return bool
     */
    public function isEnabled(string $level): bool {
        if ($level !== 'debug') {
            return true;
        }

        return \defined('WP_DEBUG') && (bool) \constant('WP_DEBUG');
    }

    /**
     * Format and write one line.
     *
     * @since 1.0.0
     *
     * @param string               $level   PSR-3 level name.
     * @param string               $message Log message.
     * @param array<string, mixed> $context Structured context appended as JSON.
     */
    private function write(string $level, string $message, array $context = []): void {
        if (!$this->isEnabled($level)) {
            return;
        }

        $line = self::PREFIX . ' ' . \strtoupper($level) . ': ' . $message;

        if ($context !== []) {
            $encoded = \function_exists('wp_json_encode') ? \wp_json_encode($context) : \json_encode($context);
            $line   .= ' ' . ($encoded !== false ? $encoded : '{}');
        }

        \error_log($line); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the plugin's single logging sink.
    }
}
