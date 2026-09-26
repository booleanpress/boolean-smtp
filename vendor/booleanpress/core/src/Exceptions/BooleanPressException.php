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
     * Report the exception through the application's error handler, which writes it to the `core`
     * log channel when a site has switched that on. Override in subclasses for custom reporting.
     *
     * @since 0.2.11 Reports through the error handler instead of writing to PHP's error log.
     */
    public function report(): void
    {
        if (!\BooleanSmtp\Core\Foundation\Application::hasInstance()) {
            return;
        }

        $container = \BooleanSmtp\Core\Foundation\Application::getInstance();
        if ($container->has(\BooleanSmtp\Core\Error\Handler::class)) {
            $container->make(\BooleanSmtp\Core\Error\Handler::class)->report($this);
        }
    }
}
