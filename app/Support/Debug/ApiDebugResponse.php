<?php
/**
 * Whether admin REST responses carry raw provider diagnostics, and what they carry.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Support\Debug;

/**
 * The developer switch behind the `api_debug` block of the test-email, connection-test and
 * email-log responses. Off by default; when a site turns it on with the
 * `boolean_smtp_api_debug_response_enabled` filter, only users who can manage options receive the
 * block, and other code may add its own diagnostics to it through `boolean_smtp_api_debug_payload`.
 *
 * @since 1.0.0
 */
final class ApiDebugResponse
{
    /**
     * Whether raw diagnostics may be attached to the current admin REST response.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public static function enabled(): bool
    {
        /**
         * Filters whether raw provider diagnostics may be attached to an admin REST response.
         *
         * When true, the test-email, connection-test and email-log responses include an
         * `api_debug` block with the provider's raw request details and error text, for users who
         * can manage options. Off by default; turn it on only while debugging.
         *
         * @since 1.0.0
         *
         * @param bool $enabled Whether the diagnostics are attached. Default false.
         * @return bool Whether the diagnostics are attached.
         */
        if (!(bool) \apply_filters('boolean_smtp_api_debug_response_enabled', false)) {
            return false;
        }

        return !\function_exists('current_user_can') || \current_user_can('manage_options');
    }

    /**
     * Add the diagnostics other code supplies to a response's `api_debug` block.
     *
     * Does nothing unless {@see self::enabled()} is true. A scalar `api_debug` value already in
     * the response is kept under `mailer_payload`.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $data Response data, changed in place.
     * @return void
     */
    public static function extend(array &$data): void
    {
        if (!self::enabled()) {
            return;
        }

        /**
         * Filters the extra diagnostics added to the `api_debug` block of an admin REST response.
         *
         * Runs only while `boolean_smtp_api_debug_response_enabled` is true and the user can
         * manage options. Return an array; its keys are added to the block, replacing keys of the
         * same name.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $extra Extra diagnostics. Default empty.
         * @param array<string, mixed> $data  The response data the block belongs to.
         * @return array<string, mixed> The extra diagnostics.
         */
        $extra = \apply_filters('boolean_smtp_api_debug_payload', [], $data);
        if (!\is_array($extra) || $extra === []) {
            return;
        }

        $block = $data['api_debug'] ?? [];
        if (!\is_array($block)) {
            $block = ['mailer_payload' => $block];
        }

        $data['api_debug'] = array_merge($block, $extra);
    }
}
