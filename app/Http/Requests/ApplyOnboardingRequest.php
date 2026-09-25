<?php
/**
 * Request to apply a reviewed onboarding draft.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Http\Requests;

use BooleanSmtp\Core\Http\FormRequest;

/**
 * Validates `POST /booleansmtp/v1/dashboard/onboarding/apply`: the draft to activate and the
 * preferences the review step collected with it.
 *
 * @since 1.0.0
 */
final class ApplyOnboardingRequest extends FormRequest {
    /**
     * Validation rules.
     *
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    public function rules(): array {
        return [
            'connection_id'      => 'required|integer|min:1',
            'make_primary'       => 'nullable|boolean',
            'log_retention_days' => 'nullable|integer|in:7,30,90',
            'import_logs'        => 'nullable|boolean',
            'migration_source'   => 'nullable|string|max:50',
        ];
    }
}
