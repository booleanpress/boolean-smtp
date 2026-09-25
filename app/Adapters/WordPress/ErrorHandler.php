<?php
/**
 * WordPress implementation of the error handler adapter contract.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Adapters\WordPress;

use BooleanSmtp\Adapters\Contracts\ErrorHandlerAdapterContract;
use BooleanSmtp\Core\Contracts\LoggerContract;

/**
 * Represents and logs errors using WordPress's WP_Error class where available.
 *
 * Falls back to a plain object representation when WP_Error is unavailable, so the adapter also
 * works under a test harness, and writes error and debug messages through the plugin logger.
 *
 * @since 1.0.0
 */
final class ErrorHandler implements ErrorHandlerAdapterContract {
    /**
     * @since 1.0.0
     *
     * @param LoggerContract $logger Plugin logger the error and debug messages are written to.
     */
    public function __construct(
        private readonly LoggerContract $logger,
    ) {}

    /**
     * Create an error object.
     *
     * @since 1.0.0
     *
     * @param string $code Error code/identifier
     * @param string $message Human-readable error message
     * @param mixed $data Additional error data
     * @return object A WP_Error when the class is available, otherwise a plain object with
     *                equivalent fields.
     */
    public function createError(string $code, string $message, mixed $data = null): object {
        if (\class_exists('WP_Error', false)) {
            return new \WP_Error($code, $message, $data);
        }

        // Fallback object for non-WordPress contexts.
        return (object) [
            'error'        => $code,
            'message'      => $message,
            'data'         => $data,
            '__is_error__' => true
        ];
    }

    /**
     * Determine whether a value is an error.
     *
     * @since 1.0.0
     *
     * @param mixed $value Value to check
     * @return bool True when the value is a WP_Error, or an equivalent plain-object error.
     */
    public function isError(mixed $value): bool {
        if (\function_exists('is_wp_error')) {
            return \is_wp_error($value);
        }

        // Fallback check for array-based errors
        return \is_array($value) && isset($value['__is_error__']) && $value['__is_error__'] === true;
    }

    /**
     * Return the code of an error object.
     *
     * @since 1.0.0
     *
     * @param mixed $error Error object
     * @return string The error code, or an empty string when none is available.
     */
    public function getErrorCode(object $error): string {
        if ($error instanceof \WP_Error) {
            return $error->get_error_code();
        }

        if (isset($error->error)) {
            return (string) $error->error;
        }

        return '';
    }

    /**
     * Return the message of an error object.
     *
     * @since 1.0.0
     *
     * @param mixed $error Error object
     * @return string The error message, or an empty string when none is available.
     */
    public function getErrorMessage(object $error): string {
        if ($error instanceof \WP_Error) {
            return $error->get_error_message();
        }

        if (isset($error->message)) {
            return (string) $error->message;
        }

        return '';
    }

    /**
     * Return the additional data attached to an error object.
     *
     * @since 1.0.0
     *
     * @param mixed $error Error object
     * @return mixed The error's additional data, or null when none is attached.
     */
    public function getErrorData(object $error): mixed {
        if ($error instanceof \WP_Error) {
            return $error->get_error_data();
        }

        if (isset($error->data)) {
            return $error->data;
        }

        return null;
    }

    /**
     * Log an error message through the plugin logger.
     *
     * @since 1.0.0
     *
     * @param string $message Error message
     * @param int $level Severity level (0=debug, 1=warning, 2=error)
     */
    public function logError(string $message, int $level = 2): void {
        match (true) {
            $level >= 2   => $this->logger->error($message),
            $level === 1  => $this->logger->warning($message),
            default       => $this->logger->debug($message),
        };
    }

    /**
     * Log a debug message through the plugin logger (written only when `WP_DEBUG` is on).
     *
     * @since 1.0.0
     *
     * @param string $message Debug message
     */
    public function logDebug(string $message): void {
        $this->logger->debug($message);
    }
}
