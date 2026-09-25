<?php
/**
 * Request to create a mail connection.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Http\Requests;

use BooleanSmtp\Core\Http\FormRequest;

/**
 * Validates `POST /booleansmtp/v1/connections`. Authorization is the route group's capability; a failing rule
 * is rendered as a 422 response with the field errors.
 *
 * @since 1.0.0
 */
final class StoreConnectionRequest extends FormRequest {
    /**
     * Validation rules.
     *
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    public function rules(): array {
        return [
            'name'     => 'required|string|max:255',
            'driver'   => 'required|string',
            'settings' => 'required|array',
            'priority' => 'nullable|integer|min:0',
            'is_active'=> 'nullable|boolean',
        ];
    }
}
