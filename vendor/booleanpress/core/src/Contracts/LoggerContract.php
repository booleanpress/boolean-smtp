<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Contracts;

/**
 * Logger Contract
 *
 * Provides a standardized PSR-3 inspired logging interface,
 * allowing different logging implementations to be swapped.
 */
interface LoggerContract
{
    /**
     * System is unusable.
     *
     * @param string $message
     * @param array<string, mixed> $context
     */
    public function emergency(string $message, array $context = []): void;

    /**
     * Action must be taken immediately.
     *
     * @param string $message
     * @param array<string, mixed> $context
     */
    public function alert(string $message, array $context = []): void;

    /**
     * Critical conditions.
     *
     * @param string $message
     * @param array<string, mixed> $context
     */
    public function critical(string $message, array $context = []): void;

    /**
     * Runtime errors that do not require immediate action.
     *
     * @param string $message
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void;

    /**
     * Exceptional occurrences that are not errors.
     *
     * @param string $message
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void;

    /**
     * Normal but significant events.
     *
     * @param string $message
     * @param array<string, mixed> $context
     */
    public function notice(string $message, array $context = []): void;

    /**
     * Interesting events.
     *
     * @param string $message
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void;

    /**
     * Detailed debug information.
     *
     * @param string $message
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void;
}
