<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Container;

use Exception;

/**
 * Exception thrown when a requested entry is not found in the container.
 */
class EntryNotFoundException extends Exception
{
    public function __construct(string $id, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct("No entry was found for identifier: {$id}", $code, $previous);
    }
}
