<?php
/**
 * Plugin Name: BooleanSMTP
 * Plugin URI: https://booleansmtp.com
 * Description: Smart email delivery for WordPress — reliable SMTP, API mailers, email logging, failure alerts, and developer tools.
 * Version: 1.0.0
 * Author: BooleanPress
 * Author URI: https://booleansmtp.com/about/
 * Text Domain: boolean-smtp
 * Domain Path: /resources/languages
 * Requires PHP: 8.1
 * Requires at least: 6.0
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

defined('ABSPATH') || exit;

/**
 * Resolve the plugin's canonical wp-content/plugins/ path when loaded through a symlink.
 *
 * PHP 8+ resolves a symlinked `__FILE__` to the real path (for example, under `/Users/...`),
 * which is not under `WP_PLUGIN_DIR`. WordPress then computes the wrong plugin basename, so
 * activation hooks and URLs break. This maps back to the path under `wp-content/plugins/` that
 * resolves to the same file.
 *
 * @since 1.0.0
 *
 * @return string Path suitable for plugin_basename(), register_activation_hook(), plugin_dir_url().
 */
function boolean_smtp_resolved_plugin_file(): string {
    static $resolved = null;

    if ($resolved !== null) {
        return $resolved;
    }

    $file = __FILE__;

    if (!defined('WP_PLUGIN_DIR') || !function_exists('wp_normalize_path')) {
        return $resolved = $file;
    }

    $plugin_dir = wp_normalize_path(WP_PLUGIN_DIR);
    $this_file  = wp_normalize_path($file);

    if (str_starts_with($this_file, $plugin_dir)) {
        return $resolved = $file;
    }

    $main_base = basename($file);
    $real_file = wp_normalize_path((string) (@realpath($file) ?: $file));

    foreach (glob(WP_PLUGIN_DIR . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $candidate      = wp_normalize_path($dir . '/' . $main_base);
        $real_candidate = @realpath($candidate);
        if ($real_candidate) {
            $real_candidate = wp_normalize_path((string) $real_candidate);
            if ($real_candidate === $real_file) {
                return $resolved = $candidate;
            }
        }
    }

    return $resolved = $file;
}

$boolean_smtp_plugin_file = boolean_smtp_resolved_plugin_file();

/*
 * Core plugin constants.
 *
 * BOOLEAN_SMTP_VERSION must move in lockstep with the `Version:` header above; both are read
 * during upgrades to decide whether pending database migrations need to run.
 *
 * @since 1.0.0
 */
define('BOOLEAN_SMTP_VERSION', '1.0.0');
define('BOOLEAN_SMTP_FILE', $boolean_smtp_plugin_file);
define('BOOLEAN_SMTP_PATH', plugin_dir_path($boolean_smtp_plugin_file));
define('BOOLEAN_SMTP_URL', plugin_dir_url($boolean_smtp_plugin_file));

/*
 * Register the `every_minute` cron schedule early so WordPress can reschedule the queue worker
 * even before `plugins_loaded` fires (prevents `invalid_schedule` errors).
 *
 * @since 1.0.0
 */
if (function_exists('add_filter')) {
    add_filter('cron_schedules', function (array $schedules): array {
        if (!isset($schedules['every_minute'])) {
            $schedules['every_minute'] = [
                'interval' => 60,
                'display'  => 'Every minute',
            ];
        }
        return $schedules;
    });
}

$boolean_smtp_autoload = __DIR__ . '/vendor/autoload.php';

if (!function_exists('boolean_smtp_log_bootstrap')) {
    /**
     * Report a bootstrap problem through PHP's error log.
     *
     * Activation and autoload failures happen before the plugin's own logger exists, so they go
     * to `error_log()`: `wp-content/debug.log` when `WP_DEBUG_LOG` is on, otherwise the server's
     * PHP error log. The plugin writes no log file of its own outside `wp-content/uploads`.
     *
     * @since 1.0.0
     *
     * @param string $message Message to log.
     */
    function boolean_smtp_log_bootstrap(string $message): void {
        error_log('[boolean-smtp] BOOTSTRAP: ' . $message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- runs before the plugin's logger exists.
    }
}

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    boolean_smtp_log_bootstrap('PHP ' . PHP_VERSION . ' is too old (requires 8.1+).');
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>';
        echo esc_html(
            sprintf(
                /* translators: %s: PHP version */
                __('BooleanSMTP requires PHP 8.1 or newer. This site is running PHP %s.', 'boolean-smtp'),
                PHP_VERSION
            )
        );
        echo '</p></div>';
    });
    return;
}

if (!is_readable($boolean_smtp_autoload)) {
    boolean_smtp_log_bootstrap(
        'Composer autoload missing or not readable (open_basedir, symlink, or run composer install). Path: ' . $boolean_smtp_autoload
    );
    add_action('admin_notices', static function () use ($boolean_smtp_autoload): void {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__(
            'BooleanSMTP could not load Composer dependencies.',
            'boolean-smtp'
        );
        echo ' ';
        echo esc_html(
            sprintf(
                /* translators: %s: filesystem path */
                __('Expected file: %s', 'boolean-smtp'),
                $boolean_smtp_autoload
            )
        );
        echo ' ';
        echo esc_html__(
            'From the plugin directory, run composer install. If the plugin is symlinked, ensure PHP can read the real path (e.g. open_basedir includes that path).',
            'boolean-smtp'
        );
        echo '</p></div>';
    });
    return;
}

// Composer autoload may include "files" entries from booleanpress/core.
// Ensure the required vendor file exists before loading autoload.
$boolean_smtp_vendor_helpers = __DIR__ . '/vendor/booleanpress/core/src/Foundation/functions.php';

if (!is_readable($boolean_smtp_vendor_helpers)) {
    boolean_smtp_log_bootstrap(
        'Composer autoload will fail because booleanpress/core functions.php is missing at: ' . $boolean_smtp_vendor_helpers
    );
    add_action('admin_notices', static function () use ($boolean_smtp_vendor_helpers): void {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__(
            'BooleanSMTP failed to load its dependencies. The expected file is missing from the plugin vendor directory:',
            'boolean-smtp'
        );
        echo ' ';
        echo esc_html($boolean_smtp_vendor_helpers);
        echo '. Please upload a complete plugin package including `vendor/`, or run `composer install` on the server. If you just uploaded, ensure `vendor/booleanpress/core/src/Foundation/functions.php` is present.';
        echo '</p></div>';
    });
    return;
}

require_once $boolean_smtp_autoload;

$plugin = new BooleanSmtp\Plugin($boolean_smtp_plugin_file);

if (!function_exists('boolean_smtp')) {
    /**
     * Get the BooleanSMTP plugin instance.
     *
     * The single global entry point for other plugins and site code:
     * `boolean_smtp()->app()` is the plugin's service container.
     *
     * @since 1.0.0
     *
     * @return \BooleanSmtp\Plugin
     */
    function boolean_smtp(): \BooleanSmtp\Plugin {
        global $boolean_smtp_plugin;

        return $boolean_smtp_plugin;
    }
}

$GLOBALS['boolean_smtp_plugin'] = $plugin;
$plugin->boot();

require_once __DIR__ . '/includes/boolean-smtp-queue.php';
require_once __DIR__ . '/includes/boolean-smtp-wp-mail.php';
