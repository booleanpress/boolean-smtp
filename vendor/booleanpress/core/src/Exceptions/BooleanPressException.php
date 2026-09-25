<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Exceptions;

use Exception;

/**
 * Base Exception for BooleanCore Framework
 *
 * All framework exceptions extend from this base class,
 * allowing for granular or broad exception handling.
 */
class BooleanPressException extends Exception
{
    /**
     * Create a new exception instance.
     */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Report the exception.
     * Override in subclasses for custom logging behavior.
     */
    public function report(): void
    {
        if (function_exists('error_log')) {
            error_log('[BooleanCore] ' . static::class . ': ' . $this->getMessage());
        }
    }
}
