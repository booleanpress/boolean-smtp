<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Exceptions;

/**
 * Validation Exception
 *
 * Thrown when validation fails.
 */
class ValidationException extends BooleanPressException
{
    /**
     * The validation errors.
     *
     * @var array<string, array<string>>
     */
    protected array $errors = [];

    /**
     * Create a new validation exception.
     *
     * @param array<string, array<string>> $errors
     */
    public function __construct(array $errors = [], string $message = 'The given data was invalid.')
    {
        parent::__construct($message, 422);
        $this->errors = $errors;
    }

    /**
     * Get the validation errors.
     *
     * @return array<string, array<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Convert the exception to a response-friendly array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => false,
            'message' => $this->getMessage(),
            'errors' => $this->errors,
        ];
    }
}
