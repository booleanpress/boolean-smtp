<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Exceptions;

/**
 * Authorization Exception
 *
 * Thrown when a user is not authorized to perform an action.
 */
class AuthorizationException extends BooleanPressException
{
    /**
     * Create a new authorization exception.
     */
    public function __construct(string $message = 'This action is unauthorized.', int $code = 403)
    {
        parent::__construct($message, $code);
    }
}
