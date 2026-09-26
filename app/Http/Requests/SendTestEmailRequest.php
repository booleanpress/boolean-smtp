<?php
/**
 * Request to send a test email.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Http\Requests;

use BooleanSmtp\Core\Http\FormRequest;

/**
 * Validates `POST /booleansmtp/v1/test-email`. Authorization is the route group's capability; a failing rule
 * is rendered as a 422 response with the field errors.
 *
 * @since 1.0.0
 */
final class SendTestEmailRequest extends FormRequest {
    /**
     * Validation rules.
     *
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    public function rules(): array {
        return [
            'to'               => 'required|email',
            'subject'          => 'nullable|string|max:255',
            'html'             => 'nullable|boolean',
            'multipart'        => 'nullable|boolean',
            'connection_id'    => 'nullable|integer|min:1',
            'onboarding_draft' => 'nullable|boolean',
        ];
    }
}
