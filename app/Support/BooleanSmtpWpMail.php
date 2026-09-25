<?php

/**
 * Static helpers for the wp_mail() takeover.
 *
 * Used by the plugin bootstrap to decide whether BooleanSMTP should replace WordPress core's
 * wp_mail() implementation, and by the takeover shim to hand a captured call to the mail pipeline.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Support;

use BooleanSmtp\Services\Mailer\BooleanSmtpMailPipeline;
use function BooleanSmtp\Core\app;

/**
 * wp_mail() takeover bootstrap helpers.
 *
 * @since 1.0.0
 */
final class BooleanSmtpWpMail
{
    /**
     * Determine whether BooleanSMTP has taken over wp_mail().
     *
     * @since 1.0.0
     *
     * @return bool True when the `BOOLEAN_SMTP_WP_MAIL_TAKEOVER` constant is defined and truthy.
     */
    public static function isTakeoverActive(): bool
    {
        return \defined('BOOLEAN_SMTP_WP_MAIL_TAKEOVER') && (bool) \constant('BOOLEAN_SMTP_WP_MAIL_TAKEOVER');
    }

    /**
     * The name of the plugin that defined `wp_mail()` before BooleanSMTP could.
     *
     * WordPress lets the first plugin that declares the pluggable `wp_mail()` keep it, so a
     * site with two mail plugins sends through whichever one loaded first. Reading the file the
     * live function was declared in names that plugin, which is what the admin warning reports.
     *
     * @since 1.0.0
     *
     * @return string|null The plugin's name, its folder when the header cannot be read, or null
     *                     when BooleanSMTP holds `wp_mail()` or another kind of file declared it.
     */
    public static function takenOverBy(): ?string
    {
        if (self::isTakeoverActive() || !\function_exists('wp_mail')) {
            return null;
        }

        try {
            $file = (new \ReflectionFunction('wp_mail'))->getFileName();
        } catch (\ReflectionException) {
            return null;
        }
        if (!\is_string($file) || $file === '') {
            return null;
        }
        if (!preg_match('#/plugins/([^/]+)/#', \wp_normalize_path($file), $matches)) {
            return null;
        }

        return self::pluginName($matches[1]);
    }

    /**
     * The display name of an installed plugin, from its folder.
     *
     * @since 1.0.0
     *
     * @param  string $folder The plugin's folder inside `wp-content/plugins`.
     * @return string The name from the plugin header, or the folder when it cannot be read.
     */
    private static function pluginName(string $folder): string
    {
        if (!\function_exists('get_plugins')) {
            $include = ABSPATH . 'wp-admin/includes/plugin.php';
            if (!\is_readable($include)) {
                return $folder;
            }
            require_once $include;
        }

        foreach (\get_plugins() as $file => $data) {
            if (\dirname($file) === $folder && !empty($data['Name'])) {
                return (string) $data['Name'];
            }
        }

        return $folder;
    }

    /**
     * Send a message through the mail pipeline using wp_mail()'s argument shape, or hand it to
     * the queue when the `boolean_smtp_queue_should_enqueue` filter asks for it.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $atts Arguments in the same shape wp_mail() receives them.
     * @return bool True when the message was accepted for delivery (or queued).
     */
    public static function send(array $atts): bool
    {
        if (self::shouldEnqueue($atts)) {
            return boolean_smtp_queue($atts) !== null;
        }

        return app(BooleanSmtpMailPipeline::class)->send($atts);
    }

    /**
     * Whether a message is queued for the worker instead of being sent now.
     *
     * A queued message arms the worker, so nothing is ever left in a queue nothing processes.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $atts Arguments in the same shape wp_mail() receives them.
     * @return bool
     */
    private static function shouldEnqueue(array $atts): bool
    {
        if (!\function_exists('boolean_smtp_queue')) {
            return false;
        }

        /**
         * Filters whether a message is queued for background delivery instead of being sent now.
         *
         * Consulted for every `wp_mail()` / `boolean_smtp_mail()` call. Return `true` to store the
         * message and let the queue worker send it — bulk or newsletter mail, for example — while
         * transactional mail keeps going out immediately. A queued call returns `true` to its
         * caller as soon as the message is stored; the worker runs from WP-Cron within a minute
         * (or at once with `wp boolean-smtp queue:work`).
         *
         * @since 1.0.0
         *
         * @param  bool                 $enqueue Whether to queue the message. Default `false`.
         * @param  array<string, mixed> $atts    The `wp_mail()` arguments: `to`, `subject`, `message`, `headers`, `attachments`, and `embeds` when given.
         * @return bool Whether to queue the message.
         */
        return (bool) \apply_filters('boolean_smtp_queue_should_enqueue', false, $atts);
    }
}
