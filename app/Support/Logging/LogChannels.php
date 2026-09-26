<?php

/**
 * The plugin's log channels and the switches that turn them on.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Support\Logging;

/**
 * Which log channels the plugin has, what turns each on, where their files go and at what level
 * each writes.
 *
 * No channel writes a file until the `boolean_smtp_log_file_enabled` filter switches it on. Files
 * go to `wp-content/uploads/boolean-smtp/logs/`, rotated, pruned and capped by the framework's
 * log manager.
 *
 * | Channel | Carries                                      |
 * |---------|----------------------------------------------|
 * | `app`   | the plugin's own log lines (info and above)  |
 * | `core`  | the framework's messages and reported errors |
 *
 * @since 1.0.0
 */
final class LogChannels
{
    /**
     * The plugin's slug, which the framework passes to its log filters.
     *
     * @since 1.0.0
     * @var string
     */
    public const SLUG = 'boolean-smtp';

    /**
     * Every channel the plugin writes.
     *
     * @since 1.0.0
     * @var list<string>
     */
    public const CHANNELS = ['app', 'core'];

    /**
     * Whether a channel writes to disk.
     *
     * @since 1.0.0
     *
     * @param  string $channel Channel name.
     * @return bool
     */
    public static function enabled(string $channel): bool
    {
        /**
         * Filters whether one of BooleanSMTP's log channels writes to disk.
         *
         * Every channel is off by default, so the plugin writes no log file unless a site asks
         * for one. The channels are `app` (the plugin's warnings and errors) and `core` (the
         * framework's messages and reported errors). Files go to
         * `wp-content/uploads/boolean-smtp/logs/` with keyed, unguessable names; each channel
         * rotates at 5 MB, keeps 14 days, and the whole directory is capped at 50 MB.
         *
         * Add this filter from a must-use plugin so it is in place before the plugin boots.
         *
         * @since 1.0.0
         *
         * @param bool   $enabled Whether the channel writes. Default false.
         * @param string $channel The channel name.
         * @return bool Whether the channel writes.
         */
        return (bool) \apply_filters('boolean_smtp_log_file_enabled', false, $channel);
    }

    /**
     * The least severe level a channel writes.
     *
     * @since 1.0.0
     *
     * @param  string $channel Channel name.
     * @param  string $level   The framework's value before this filter.
     * @return string A PSR-3 level name.
     */
    public static function level(string $channel, string $level): string
    {
        $default = $channel === 'app' ? 'info' : $level;

        /**
         * Filters the least severe level a BooleanSMTP log channel writes.
         *
         * @since 1.0.0
         *
         * @param string $level   A PSR-3 level name. Default `info` for `app` (what PHP's error log
         *                        receives while the channel is off); `warning` for `core`.
         * @param string $channel The channel name.
         * @return string A PSR-3 level name.
         */
        return (string) \apply_filters('boolean_smtp_log_file_level', $default, $channel);
    }

    /**
     * Days a channel's files are kept.
     *
     * @since 1.0.0
     *
     * @param  string $channel Channel name.
     * @param  int    $days    The framework's value before this filter.
     * @return int
     */
    public static function retentionDays(string $channel, int $days): int
    {
        /**
         * Filters how many days of a BooleanSMTP log channel's files are kept.
         *
         * Values below 1 are treated as 1: there is no setting that keeps log files forever.
         *
         * @since 1.0.0
         *
         * @param int    $days    Days to keep, today included. Default 14.
         * @param string $channel The channel name.
         * @return int Days to keep.
         */
        return (int) \apply_filters('boolean_smtp_log_file_retention_days', $days, $channel);
    }

    /**
     * The log directory: `<uploads>/boolean-smtp/logs`, named after the plugin's slug.
     *
     * The framework places logs under a shared `<uploads>/booleanpress/<slug>/logs` folder; this
     * plugin keeps its files in its own slug folder instead. A path another `booleanpress_log_path`
     * callback already moved elsewhere is left alone, and so is the framework's fallback when the
     * uploads directory is unavailable.
     *
     * @since 1.0.0
     *
     * @param  string $path The framework's value before this filter.
     * @return string
     */
    public static function path(string $path): string
    {
        $uploads = self::uploadsDirectory();
        if ($uploads === null) {
            return $path;
        }

        $frameworkDefault = $uploads . '/booleanpress/' . self::SLUG . '/logs';

        return rtrim($path, '/\\') === $frameworkDefault ? self::directory($uploads) : $path;
    }

    /**
     * The plugin's log directory inside an uploads directory.
     *
     * @since 1.0.0
     *
     * @param  string $uploads Absolute uploads directory, without a trailing slash.
     * @return string
     */
    public static function directory(string $uploads): string
    {
        return rtrim($uploads, '/\\') . '/' . self::SLUG . '/logs';
    }

    /**
     * The site's uploads directory, without a trailing slash.
     *
     * @since 1.0.0
     *
     * @return string|null Null when WordPress cannot report it.
     */
    private static function uploadsDirectory(): ?string
    {
        if (!\function_exists('wp_upload_dir')) {
            return null;
        }

        $uploads = \wp_upload_dir(null, false);
        $base    = \is_array($uploads) ? (string) ($uploads['basedir'] ?? '') : '';

        return $base === '' ? null : rtrim($base, '/\\');
    }
}
