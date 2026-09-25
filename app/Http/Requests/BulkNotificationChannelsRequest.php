<?php
/**
 * Request to apply an action to several notification channels.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Http\Requests;

use BooleanSmtp\Core\Http\FormRequest;

/**
 * Validates `POST /booleansmtp/v1/notifications/bulk`. Authorization is the route group's capability; a failing rule
 * is rendered as a 422 response with the field errors.
 *
 * @since 1.0.0
 */
final class BulkNotificationChannelsRequest extends FormRequest {
    /**
     * Validation rules.
     *
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    public function rules(): array {
        return [
            'ids'   => 'required|array',
            'action'=> 'required|string|in:delete,enable,disable',
        ];
    }
}
