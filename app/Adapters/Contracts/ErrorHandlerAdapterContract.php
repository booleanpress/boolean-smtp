<?php
/**
 * Contract for creating, inspecting and logging errors.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Adapters\Contracts;

/**
 * Guarantees a way to represent and log errors that does not depend on the underlying host
 * application's native error type.
 *
 * @since 1.0.0
 */
interface ErrorHandlerAdapterContract
{
    /**
     * Create an error object.
     *
     * @since 1.0.0
     *
     * @param  string $code    Error code or key.
     * @param  string $message Human-readable error message.
     * @param  mixed  $data    Additional structured data describing the error, if any.
     * @return object The created error object.
     */
    public function createError(string $code, string $message, mixed $data = null): object;

    /**
     * Determine whether a value is an error object created by this contract's implementation.
     *
     * @since 1.0.0
     *
     * @param  mixed $value Value to check.
     * @return bool True when the value represents an error.
     */
    public function isError(mixed $value): bool;

    /**
     * Return the code of an error object.
     *
     * @since 1.0.0
     *
     * @param  object $error Error object.
     * @return string The error code.
     */
    public function getErrorCode(object $error): string;

    /**
     * Return the message of an error object.
     *
     * @since 1.0.0
     *
     * @param  object $error Error object.
     * @return string The error message.
     */
    public function getErrorMessage(object $error): string;

    /**
     * Return the additional data attached to an error object.
     *
     * @since 1.0.0
     *
     * @param  object $error Error object.
     * @return mixed The error's additional data, or null when none was attached.
     */
    public function getErrorData(object $error): mixed;

    /**
     * Log an error for later diagnosis.
     *
     * @since 1.0.0
     *
     * @param  string $message Error message to log.
     * @param  int    $level   Severity of the message: 0 for debug, 1 for warning, 2 for error.
     */
    public function logError(string $message, int $level = 2): void;

    /**
     * Log a debug message.
     *
     * Implementations may choose to log only when debug mode is enabled.
     *
     * @since 1.0.0
     *
     * @param  string $message Debug message to log.
     */
    public function logDebug(string $message): void;
}
