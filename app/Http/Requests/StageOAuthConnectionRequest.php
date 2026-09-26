<?php

/**
 * Request to stage edits to an active OAuth mailer without changing its live settings.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Http\Requests;

use BooleanSmtp\Core\Http\FormRequest;

/**
 * Validates the replacement configuration for an active Google or Microsoft mailer.
 *
 * @since 1.0.0
 */
final class StageOAuthConnectionRequest extends FormRequest {
    /**
     * Validation rules for a staged configuration.
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
        ];
    }
}
