<?php
/**
 * Cleans up after BooleanSMTP when the plugin is deleted.
 *
 * WordPress runs this file when the plugin is deleted from the Plugins screen (or with
 * `wp plugin uninstall`). The plugin itself is not booted: only the Composer autoloader and
 * the framework's uninstaller are loaded. Deactivating the plugin never runs this file.
 *
 * Always removed: the plugin's scheduled events, its log files and SMTP debug sessions under
 * `wp-content/uploads/boolean-smtp/logs/`, and each user's dismissal of its admin notice. These are
 * short-lived diagnostics and screen state that nothing would clean up once the plugin is gone.
 *
 * Kept by default: connections, email logs, settings and options, so a reinstall picks up where
 * the site left off. They are removed only when the site owner turned on **Settings › Delete data on
 * uninstall**: then the plugin's two tables go, with this plugin's rows in the shared settings and
 * migrations tables (and those tables themselves when no other BooleanPress plugin is left), and its
 * WordPress options and transients. `define('BOOLEAN_SMTP_PRESERVE_DATA', true);` in `wp-config.php`
 * keeps the data even when that setting is on.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

$boolean_smtp_autoload = __DIR__ . '/vendor/autoload.php';

if (!is_readable($boolean_smtp_autoload)) {
    return;
}

require_once $boolean_smtp_autoload;

// Scheduled events (every occurrence, whatever arguments they were scheduled with): the plugin's
// four jobs, their pre-release names and the framework scheduler's per-minute tick.
foreach ([
    'boolean_smtp_health_check', 'boolean_smtp_oauth_refresh', 'boolean_smtp_process_queue', 'boolean_smtp_prune_logs',
    'booleansmtp_health_check', 'booleansmtp_oauth_refresh', 'booleansmtp_process_queue', 'booleansmtp_prune_logs',
    'boolean-smtp_schedule_run',
] as $boolean_smtp_hook) {
    wp_unschedule_hook($boolean_smtp_hook);
}

/**
 * Delete the files BooleanSMTP wrote inside a directory — channel logs, debug sessions and the
 * protection files — then the directory when nothing else is left in it.
 *
 * Only names the plugin writes are removed, and a protection file only when its content is
 * exactly what the framework wrote, so anything a person put there stays.
 *
 * @since 1.0.0
 *
 * @param string $boolean_smtp_dir Absolute directory path.
 */
function boolean_smtp_uninstall_remove_logs(string $boolean_smtp_dir): void {
    if (!is_dir($boolean_smtp_dir) || is_link($boolean_smtp_dir)) {
        return;
    }

    $boolean_smtp_sessions = $boolean_smtp_dir . '/debug-sessions';
    if (is_dir($boolean_smtp_sessions) && !is_link($boolean_smtp_sessions)) {
        foreach (scandir($boolean_smtp_sessions) ?: [] as $boolean_smtp_entry) {
            if (preg_match(\BooleanSmtp\Repositories\DebugLogRepository::FILE_PATTERN, $boolean_smtp_entry) || in_array($boolean_smtp_entry, ['.htaccess', 'index.php'], true)) {
                wp_delete_file($boolean_smtp_sessions . '/' . $boolean_smtp_entry);
            }
        }
        @rmdir($boolean_smtp_sessions); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP_Filesystem is not loaded during uninstall; an empty local directory is removed directly. left in place when something else is in it.
    }

    $boolean_smtp_protection = \BooleanSmtp\Core\Log\LogRoot::protectionFiles();
    foreach (scandir($boolean_smtp_dir) ?: [] as $boolean_smtp_entry) {
        $boolean_smtp_file    = $boolean_smtp_dir . '/' . $boolean_smtp_entry;
        $boolean_smtp_is_ours = preg_match(\BooleanSmtp\Core\Log\LogRoot::FILE_PATTERN, $boolean_smtp_entry)
            || (isset($boolean_smtp_protection[$boolean_smtp_entry]) && is_file($boolean_smtp_file) && (string) file_get_contents($boolean_smtp_file) === $boolean_smtp_protection[$boolean_smtp_entry]); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local content check.
        if ($boolean_smtp_is_ours && is_file($boolean_smtp_file) && !is_link($boolean_smtp_file)) {
            wp_delete_file($boolean_smtp_file);
        }
    }

    @rmdir($boolean_smtp_dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP_Filesystem is not loaded during uninstall; an empty local directory is removed directly. left in place when something else is in it.
}

// Log files and SMTP debug sessions under `<uploads>/boolean-smtp/logs`; the `boolean-smtp/` folder
// goes too when nothing else is left in it.
$boolean_smtp_uploads = function_exists('wp_upload_dir') ? wp_upload_dir(null, false) : [];
if (is_array($boolean_smtp_uploads) && !empty($boolean_smtp_uploads['basedir'])) {
    $boolean_smtp_logs = \BooleanSmtp\Support\Logging\LogChannels::directory((string) $boolean_smtp_uploads['basedir']);
    boolean_smtp_uninstall_remove_logs($boolean_smtp_logs);
    @rmdir(dirname($boolean_smtp_logs)); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP_Filesystem is not loaded during uninstall; an empty local directory is removed directly. non-empty is fine to leave.
}

// Each user's dismissal of the "another plugin claimed WordPress mail" notice.
delete_metadata('user', 0, \BooleanSmtp\Providers\AppServiceProvider::TAKEOVER_DISMISSED_META, '', true);

// Database footprint, only when the site owner opted in, through the framework's driver so no
// plugin code runs against $wpdb directly.
$boolean_smtp_driver = new \BooleanSmtp\Core\Database\Drivers\MySQLDriver();

if (\BooleanSmtp\Support\UninstallPolicy::shouldDeleteData($boolean_smtp_driver)) {
    $boolean_smtp_uninstaller = new \BooleanSmtp\Core\Foundation\Uninstaller($boolean_smtp_driver);

    $boolean_smtp_uninstaller->dropTables(['boolean_smtp_connections', 'boolean_smtp_email_logs']);
    $boolean_smtp_uninstaller->removeMigrations('boolean-smtp');
    $boolean_smtp_uninstaller->clearOptions('boolean-smtp');
    $boolean_smtp_uninstaller->deleteWordPressOptions('boolean_smtp_');
    $boolean_smtp_uninstaller->deleteWordPressOptions('booleansmtp_');
    $boolean_smtp_uninstaller->dropSharedTablesIfUnused();
}
