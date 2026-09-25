<?php
/**
 * Request to update the plugin settings.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Http\Requests;

use BooleanSmtp\Core\Http\FormRequest;

/**
 * Validates `PUT /booleansmtp/v1/settings`. Authorization is the route group's capability; a failing rule
 * is rendered as a 422 response with the field errors.
 *
 * @since 1.0.0
 */
final class UpdateSettingsRequest extends FormRequest {
    /**
     * Validation rules.
     *
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    public function rules(): array {
        return [
            'from_name'                 => 'nullable|string|max:255',
            'from_email'                => 'nullable|email',
            'force_from'                => 'nullable|boolean',
            'simulation_enabled'        => 'nullable|boolean',
            'show_test_email_console'   => 'nullable|boolean',
            'auto_plain_text'           => 'nullable|boolean',
            'log_emails'                => 'nullable|boolean',
            'log_mailer_diagnostics'    => 'nullable|boolean',
            'log_body'                  => 'nullable|boolean',
            'log_retention_days'        => 'nullable|integer|min:1|max:3650',
            'fallback_enabled'          => 'nullable|boolean',
            'default_connection_id'     => 'nullable|integer|min:0',
            'fallback_connection_id'    => 'nullable|integer|min:0',
            'auto_retry'                => 'nullable|boolean',
            'health_check_enabled'      => 'nullable|boolean',
            'health_check_interval'     => 'nullable|integer|min:1|max:1440',
            'oauth_refresh_enabled'     => 'nullable|boolean',
            'oauth_refresh_interval'    => 'nullable|integer|min:1|max:1440',
            'oauth_refresh_max_retries' => 'nullable|integer|min:1|max:10',
            'delete_data_on_uninstall'  => 'nullable|boolean',
        ];    }

    /**
     * The settings keys this request accepts, in the order the settings screen saves them.
     *
     * @since 1.0.0
     *
     * @return list<string>
     */
    public static function keys(): array {
        return [
            'from_name',
            'from_email',
            'force_from',
            'simulation_enabled',
            'show_test_email_console',
            'auto_plain_text',
            'log_emails',
            'log_mailer_diagnostics',
            'log_body',
            'log_retention_days',
            'fallback_enabled',
            'default_connection_id',
            'fallback_connection_id',
            'auto_retry',
            'health_check_enabled',
            'health_check_interval',
            'oauth_refresh_enabled',
            'oauth_refresh_interval',
            'oauth_refresh_max_retries',
            'delete_data_on_uninstall',
        ];    }
}
