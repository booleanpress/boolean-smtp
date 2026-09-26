<?php

/**
 * Request to commit a staged OAuth configuration after authorization.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Http\Requests;

use BooleanSmtp\Core\Http\FormRequest;

/**
 * Accepts the final confirmation; the staged server-side payload supplies all fields.
 *
 * @since 1.0.0
 */
final class FinalizeOAuthConnectionRequest extends FormRequest {
    /**
     * The final action takes no browser-supplied connection fields.
     *
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    public function rules(): array {
        return [];
    }
}
