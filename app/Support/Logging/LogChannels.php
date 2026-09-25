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
 * Which log channels the plugin has, what turns each on, and at what level each writes.
 *
 * No channel writes a file until something switches it on: the `boolean_smtp_log_file_enabled`
 * filter, or — for the developer channels — one of the `BOOLEAN_SMTP_DEBUG_*` constants. Files go
 * to `wp-content/uploads/booleanpress/boolean-smtp/logs/` (or `<BOOLEAN_SMTP_DEBUG_LOG_DIR>/boolean-smtp-logs/`),
 * rotated, pruned and capped by the framework's log manager.
 *
 * | Channel    | Carries                                        | Constant that also turns it on       |
 * |------------|------------------------------------------------|--------------------------------------|
 * | `app`      | the plugin's own log lines (info and above)    | —                                    |
 * | `core`     | the framework's messages and reported errors   | —                                    |
 * | `mail`     | mailer diagnostics                             | `BOOLEAN_SMTP_DEBUG_MAILER`          |
 * | `requests` | outgoing HTTP and incoming REST requests       | `BOOLEAN_SMTP_DEBUG_HTTP`, `_REQUEST` |
 * | `queries`  | database queries                               | `BOOLEAN_SMTP_DEBUG_QUERIES`         |
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
    public const CHANNELS = ['app', 'core', 'mail', 'requests', 'queries'];

    /**
     * The developer channels, which write at `debug` by default.
     *
     * @since 1.0.0
     * @var list<string>
     */
    public const DEVELOPER_CHANNELS = ['mail', 'requests', 'queries'];

    /**
     * The folder the logs go into inside a `BOOLEAN_SMTP_DEBUG_LOG_DIR` parent.
     *
     * @since 1.0.0
     * @var string
     */
    public const CUSTOM_FOLDER = 'boolean-smtp-logs';

    /**
     * The constants that switch a developer channel on.
     *
     * @since 1.0.0
     * @var array<string, list<string>>
     */
    private const CONSTANTS = [
        'mail'     => ['BOOLEAN_SMTP_DEBUG_MAILER'],
        'requests' => ['BOOLEAN_SMTP_DEBUG_HTTP', 'BOOLEAN_SMTP_DEBUG_REQUEST'],
        'queries'  => ['BOOLEAN_SMTP_DEBUG_QUERIES'],
    ];

    /**
     * The per-collector filters of the developer channels, keyed by the constant each defaults to.
     *
     * @since 1.0.0
     * @var array<string, string>
     */
    private const COLLECTOR_FILTERS = [
        'BOOLEAN_SMTP_DEBUG_MAILER'  => 'boolean_smtp_debug_mailer_action_enabled',
        'BOOLEAN_SMTP_DEBUG_HTTP'    => 'boolean_smtp_debug_http_enabled',
        'BOOLEAN_SMTP_DEBUG_REQUEST' => 'boolean_smtp_debug_request_enabled',
        'BOOLEAN_SMTP_DEBUG_QUERIES' => 'boolean_smtp_debug_queries_enabled',
    ];

    /**
     * Whether a channel is on: a constant for it, one of its collector filters, or the
     * `boolean_smtp_log_file_enabled` filter.
     *
     * @since 1.0.0
     *
     * @param  string $channel Channel name.
     * @return bool
     */
    public static function enabled(string $channel): bool
    {
        return self::enabledByHook($channel, self::enabledByConstant($channel) || self::enabledByCollectorFilter($channel));
    }

    /**
     * Whether one of the channel's per-collector filters (`boolean_smtp_debug_http_enabled` and the
     * like) switches its collector on — a collector that captures must also be able to write.
     *
     * @since 1.0.0
     *
     * @param  string $channel Channel name.
     * @return bool
     */
    public static function enabledByCollectorFilter(string $channel): bool
    {
        foreach (self::CONSTANTS[$channel] ?? [] as $constant) {
            $filter = self::COLLECTOR_FILTERS[$constant] ?? null;
            /** This filter is documented in app/Support/Debug/WordPressDebugLogger.php */
            if ($filter !== null && (bool) \apply_filters($filter, \defined($constant) && (bool) \constant($constant))) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- one of the four prefixed boolean_smtp_debug_*_enabled filters listed in COLLECTOR_FILTERS.
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a constant switches the channel on.
     *
     * @since 1.0.0
     *
     * @param  string $channel Channel name.
     * @return bool
     */
    public static function enabledByConstant(string $channel): bool
    {
        foreach (self::CONSTANTS[$channel] ?? [] as $constant) {
            if (\defined($constant) && (bool) \constant($constant)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run the public switch for a channel.
     *
     * @since 1.0.0
     *
     * @param  string $channel Channel name.
     * @param  bool   $default Value before the filter: whether a constant already turned it on.
     * @return bool
     */
    public static function enabledByHook(string $channel, bool $default = false): bool
    {
        /**
         * Filters whether one of BooleanSMTP's log channels writes to disk.
         *
         * Every channel is off by default, so the plugin writes no log file unless a site asks
         * for one. The channels are `app` (the plugin's warnings and errors), `core` (the
         * framework's messages and reported errors), `mail` (mailer diagnostics), `requests`
         * (outgoing HTTP and incoming REST requests) and `queries` (database queries). Files go to
         * `wp-content/uploads/booleanpress/boolean-smtp/logs/` with keyed, unguessable names; each
         * channel rotates at 5 MB, keeps 14 days, and the whole directory is capped at 50 MB.
         * Add this filter from a must-use plugin so it is in place before the plugin boots.
         *
         * @since 1.0.0
         *
         * @param bool   $enabled Whether the channel writes. Default false, or true when a
         *                        `BOOLEAN_SMTP_DEBUG_*` constant switched a developer channel on.
         * @param string $channel The channel name.
         * @return bool Whether the channel writes.
         */
        return (bool) \apply_filters('boolean_smtp_log_file_enabled', $default, $channel);
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
        $default = \in_array($channel, self::DEVELOPER_CHANNELS, true) ? 'debug' : ($channel === 'app' ? 'info' : $level);

        /**
         * Filters the least severe level a BooleanSMTP log channel writes.
         *
         * @since 1.0.0
         *
         * @param string $level   A PSR-3 level name. Default `debug` for `mail`, `requests` and
         *                        `queries`; `info` for `app` (what PHP's error log receives while
         *                        the channel is off); `warning` for `core`.
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
     * The log directory: the framework's default, or `BOOLEAN_SMTP_DEBUG_LOG_DIR` when set.
     *
     * @since 1.0.0
     *
     * @param  string $path The framework's value before this filter.
     * @return string
     */
    public static function path(string $path): string
    {
        $custom = self::customParent();

        return $custom !== null ? $custom . '/' . self::CUSTOM_FOLDER : $path;
    }

    /**
     * The folder `BOOLEAN_SMTP_DEBUG_LOG_DIR` names, when it is set.
     *
     * The constant names a parent folder, not the log folder itself: the logs go into their own
     * `boolean-smtp-logs` subfolder of it, so a constant pointed at a shared folder (`wp-content`)
     * never has log files, protection files or pruning mixed into the site's own files.
     *
     * @since 1.0.0
     *
     * @return string|null Absolute path without a trailing slash, or null when the constant is unset.
     */
    public static function customParent(): ?string
    {
        if (\defined('BOOLEAN_SMTP_DEBUG_LOG_DIR') && \is_string(\constant('BOOLEAN_SMTP_DEBUG_LOG_DIR')) && \constant('BOOLEAN_SMTP_DEBUG_LOG_DIR') !== '') {
            return rtrim((string) \constant('BOOLEAN_SMTP_DEBUG_LOG_DIR'), '/\\');
        }

        return null;
    }
}
