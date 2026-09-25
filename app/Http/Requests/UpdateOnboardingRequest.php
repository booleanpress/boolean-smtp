<?php
/**
 * Request to update the guided-onboarding progress record.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Http\Requests;

use BooleanSmtp\Core\Http\FormRequest;

/**
 * Validates `POST /booleansmtp/v1/dashboard/onboarding`. The `onboarding` object carries the wizard's
 * step flags and references; keys it does not know are dropped by the state service, so this request
 * only types the ones it does.
 *
 * @since 1.0.0
 */
final class UpdateOnboardingRequest extends FormRequest {
    /**
     * Validation rules.
     *
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    public function rules(): array {
        return [
            'onboarding'                       => 'required|array',
            'onboarding.start'                 => 'nullable|boolean',
            'onboarding.provider'              => 'nullable|boolean',
            'onboarding.connect'               => 'nullable|boolean',
            'onboarding.verify'                => 'nullable|boolean',
            'onboarding.review'                => 'nullable|boolean',
            'onboarding.send_test'             => 'nullable|boolean',
            'onboarding.verify_skipped'        => 'nullable|boolean',
            'onboarding.draft_connection_id'   => 'nullable|integer|min:0',
            'onboarding.applied_connection_id' => 'nullable|integer|min:0',
            'onboarding.migration_source'      => 'nullable|string|max:50',
            'onboarding.dismissed_at'          => 'nullable|string|max:40',
        ];
    }
}
