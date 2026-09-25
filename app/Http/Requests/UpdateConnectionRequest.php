<?php
/**
 * Request to update a mail connection.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Http\Requests;

use BooleanSmtp\Core\Http\FormRequest;

/**
 * Validates `PUT /booleansmtp/v1/connections/{id}`. Authorization is the route group's capability; a failing rule
 * is rendered as a 422 response with the field errors.
 *
 * @since 1.0.0
 */
final class UpdateConnectionRequest extends FormRequest {
    /**
     * Validation rules.
     *
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    public function rules(): array {
        return [
            'name'     => 'nullable|string|max:255',
            'driver'   => 'nullable|string',
            'settings' => 'nullable|array',
            'priority' => 'nullable|integer|min:0',
            'is_active'=> 'nullable|boolean',
        ];
    }
}
