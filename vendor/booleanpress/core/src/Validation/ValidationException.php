<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Validation;

use BooleanSmtp\Core\Exceptions\ValidationException as BaseValidationException;

/**
 * Validation failure raised with the Validator instance attached.
 *
 * Extends the framework's ValidationException so Error\Handler renders it as a 422 with the
 * field errors, whichever of the two classes a caller catches.
 */
class ValidationException extends BaseValidationException
{
    public Validator $validator;

    public function __construct(Validator $validator)
    {
        parent::__construct($validator->errors());
        $this->validator = $validator;
    }
}
