<?php
/**
 * Sends through, or checks, a delivery mode another plugin provides.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer\Api;

/**
 * The Google and Microsoft API senders handle their own `api` mode. A connection can also use a
 * mode another plugin registers through `boolean_smtp_google_delivery_modes` or
 * `boolean_smtp_outlook_delivery_modes`; its sends and checks go through the
 * `boolean_smtp_delivery_mode_result` filter, and fail with a plain message when nothing on the
 * site handles the mode.
 *
 * @since 1.0.0
 */
final class ExtensionDeliveryModes
{
    /**
     * Send a message through, or check, a delivery mode another plugin provides.
     *
     * @since 1.0.0
     *
     * @param  string               $operation `send` or `probe`.
     * @param  string               $mode      The connection's delivery mode.
     * @param  string               $driver    `google` or `outlook`.
     * @param  array<string, mixed> $settings  Decrypted connection settings.
     * @param  string               $rawMime   Raw MIME message for a send; empty for a check.
     * @return array{0: bool|\WP_Error, 1: array<string, mixed>} The result, and the settings the
     *                                                           handling plugin asked to store on the connection.
     */
    public static function dispatch(string $operation, string $mode, string $driver, array $settings, string $rawMime = ''): array
    {
        /**
         * Filters the result of sending through, or checking, a delivery mode another plugin provides.
         *
         * A plugin that registers a delivery mode through `boolean_smtp_{$driver}_delivery_modes`
         * performs the send or the connection check for that mode here. Leave the result `null`
         * for a mode that is not yours.
         *
         * @since 1.0.0
         *
         * @param mixed                $result    Null by default. Return `array{success: bool, error?: \WP_Error,
         *                                        settings?: array<string, mixed>}`; `settings` are stored on the connection.
         * @param string               $operation `send` or `probe`.
         * @param string               $mode      The connection's delivery mode.
         * @param string               $driver    `google` or `outlook`.
         * @param array<string, mixed> $settings  Decrypted connection settings.
         * @param string               $rawMime   Raw MIME message for a send; empty for a check.
         * @return mixed The result.
         */
        $outcome = \apply_filters('boolean_smtp_delivery_mode_result', null, $operation, $mode, $driver, $settings, $rawMime);

        if (!\is_array($outcome) || !\array_key_exists('success', $outcome)) {
            return [
                new \WP_Error('booleansmtp_delivery_mode_unavailable', 'This connection uses a delivery mode that is not available on this site.'),
                [],
            ];
        }

        $stored = \is_array($outcome['settings'] ?? null) ? $outcome['settings'] : [];
        if ($outcome['success']) {
            return [true, $stored];
        }

        $error = ($outcome['error'] ?? null) instanceof \WP_Error
            ? $outcome['error']
            : new \WP_Error('booleansmtp_delivery_mode_failed', 'The request through this delivery mode failed.');

        return [$error, $stored];
    }
}
