<?php

/**
 * Defines BooleanSMTP's public mail API and its `wp_mail()` takeover.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use BooleanSmtp\Support\BooleanSmtpWpMail;
use BooleanSmtp\Core\Contracts\LoggerContract;
use BooleanSmtp\Core\Foundation\Application;
use function BooleanSmtp\Core\app;

if (! function_exists('boolean_smtp_mail')) {
    /**
     * Send an email through the BooleanSMTP mail pipeline directly.
     *
     * This is the same pipeline `wp_mail()` uses (connection resolution, logging, fallback
     * retry, and hooks), exposed as a public function for code that wants to call it without
     * depending on WordPress's pluggable `wp_mail()` being defined by this plugin. Returns
     * `false` without sending when the plugin's application container is not booted.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $atts {
     *     @type string|array $to          Recipient address(es).
     *     @type string       $subject     Email subject.
     *     @type string       $message     Email body.
     *     @type string|array $headers     Optional additional headers.
     *     @type array        $attachments Optional file paths to attach.
     *     @type array        $embeds      Optional inline attachments, keyed by content id.
     * }
     * @return bool True when the message was accepted for delivery.
     */
    function boolean_smtp_mail(array $atts): bool
    {
        if (! Application::hasInstance()) {
            return false;
        }

        try {
            return BooleanSmtpWpMail::send($atts);
        } catch (\Throwable $e) {
            app(LoggerContract::class)->error('boolean_smtp_mail(): ' . $e->getMessage());

            return false;
        }
    }
}

if (! function_exists('wp_mail')) {
    /**
     * Define WordPress's pluggable `wp_mail()`, delegating to the BooleanSMTP mail pipeline.
     *
     * This file loads before WordPress's own `pluggable.php`, so this definition wins and no
     * other plugin can redefine `wp_mail()` afterward. Delegates to {@see boolean_smtp_mail()}
     * with the same arguments, plus `embeds` when a sixth argument was actually passed.
     *
     * @since 1.0.0
     *
     * @param string|string[] $to          Recipient address(es).
     * @param string          $subject     Email subject.
     * @param string          $message     Email body.
     * @param string|string[] $headers     Optional additional headers.
     * @param string[]        $attachments Optional file paths to attach.
     * @param array           $embeds      Optional inline attachments, keyed by content id.
     * @return bool True when the message was accepted for delivery.
     */
    function wp_mail($to, $subject, $message, $headers = '', $attachments = [], $embeds = []): bool
    {
        if (! Application::hasInstance()) {
            return false;
        }

        try {
            $atts = compact('to', 'subject', 'message', 'headers', 'attachments');
            if (\func_num_args() >= 6 && $embeds !== [] && $embeds !== '') {
                $atts['embeds'] = $embeds;
            }

            return boolean_smtp_mail($atts);
        } catch (\Throwable $e) {
            app(LoggerContract::class)->error('wp_mail(): ' . $e->getMessage());

            return false;
        }
    }

    if (! \defined('BOOLEAN_SMTP_WP_MAIL_TAKEOVER')) {
        \define('BOOLEAN_SMTP_WP_MAIL_TAKEOVER', true);
    }
}
