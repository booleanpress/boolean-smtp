<?php
/**
 * Decides whether deleting the plugin also deletes its data.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Support;

use BooleanSmtp\Core\Database\Drivers\DriverInterface;

/**
 * Whether `uninstall.php` removes the plugin's data, read without booting the plugin.
 *
 * Data is kept unless the site owner turned on **Settings › Delete data on uninstall**
 * (`delete_data_on_uninstall`). `BOOLEAN_SMTP_PRESERVE_DATA` set to `true` in `wp-config.php`
 * keeps the data even when the setting is on. The setting is read straight from the shared
 * options table, because WordPress runs the uninstall file with the plugin not loaded.
 *
 * @since 1.0.0
 */
final class UninstallPolicy
{
    /**
     * Setting key, without the plugin prefix, that opts in to deleting data on uninstall.
     *
     * @since 1.0.0
     * @var string
     */
    public const SETTING = 'delete_data_on_uninstall';

    /**
     * Whether the plugin's tables, settings and options are to be deleted.
     *
     * @since 1.0.0
     *
     * @param  DriverInterface $driver Database driver for the site.
     * @param  string          $plugin Plugin slug the setting is stored under.
     * @param  string          $prefix Key prefix the setting is stored with.
     * @return bool True only when the owner opted in and no constant preserves the data.
     */
    public static function shouldDeleteData(DriverInterface $driver, string $plugin = 'boolean-smtp', string $prefix = 'boolean-smtp.'): bool
    {
        if (\defined('BOOLEAN_SMTP_PRESERVE_DATA') && \constant('BOOLEAN_SMTP_PRESERVE_DATA')) {
            return false;
        }

        if (!$driver->tableExists('booleanpress_options')) {
            return false;
        }

        $value = $driver->selectVar(
            'SELECT option_value FROM ' . $driver->getTable('booleanpress_options') . ' WHERE plugin = %s AND option_name = %s AND ref_id = 0',
            [$plugin, $prefix . self::SETTING]
        );

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
