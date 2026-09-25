<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Http;

use BooleanSmtp\Core\Exceptions\AuthorizationException;
use BooleanSmtp\Core\Exceptions\ValidationException;
use BooleanSmtp\Core\Validation\Validator;

/**
 * A request that authorizes and validates itself before the controller action runs.
 *
 * Type-hint a subclass on a controller method; the router builds it from the incoming
 * request and calls validateResolved(). A failed authorize() raises an
 * AuthorizationException (403), failed rules a ValidationException (422) carrying the
 * field errors — both rendered by Error\Handler.
 */
abstract class FormRequest extends Request
{
    protected Validator $validator;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, string|array<string>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Prepare the input before it is validated (normalise, trim, cast). Override as needed.
     */
    protected function prepareForValidation(): void
    {
    }

    /**
     * Check authorization and validate the request.
     *
     * @throws AuthorizationException When authorize() returns false.
     * @throws ValidationException    When a rule fails.
     */
    public function validateResolved(): void
    {
        if (!$this->authorize()) {
            throw new AuthorizationException();
        }

        $this->prepareForValidation();

        $this->validator = Validator::make($this->all(), $this->rules());

        if ($this->validator->fails()) {
            $this->failedValidation($this->validator);
        }
    }

    /**
     * Handle a failed validation attempt.
     *
     * @throws ValidationException
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new ValidationException($validator->errors());
    }

    /**
     * Get the validated data from the request.
     *
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        return $this->validator->validated();
    }
}
