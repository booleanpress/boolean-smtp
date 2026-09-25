<?php

/**
 * Connects the plugin's log channels to the framework's log manager.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Providers;

use BooleanSmtp\Core\Container\ServiceProvider;
use BooleanSmtp\Core\Log\LogManager;
use BooleanSmtp\Repositories\DebugLogRepository;
use BooleanSmtp\Support\Logging\LogChannels;

/**
 * Feeds the plugin's public log switches (`boolean_smtp_log_file_enabled`, `boolean_smtp_log_file_level`,
 * `boolean_smtp_log_file_retention_days`, the `BOOLEAN_SMTP_DEBUG_*` constants and
 * `BOOLEAN_SMTP_DEBUG_LOG_DIR`) into the framework's `booleanpress_log_*` filters for this
 * plugin's slug only. Registered first, so every later provider's log calls see them.
 *
 * @since 1.0.0
 */
class LoggingServiceProvider extends ServiceProvider
{
    /**
     * Register the filters.
     *
     * @since 1.0.0
     */
    public function register(): void
    {
        $this->addFilter('booleanpress_log_enabled', [$this, 'filterEnabled'], 10, 3);
        $this->addFilter('booleanpress_log_level', [$this, 'filterLevel'], 10, 3);
        $this->addFilter('booleanpress_log_retention_days', [$this, 'filterRetentionDays'], 10, 3);
        $this->addFilter('booleanpress_log_path', [$this, 'filterPath'], 10, 2);

        // The SMTP debug sessions live in the log root too; registering their folder lets the
        // manager count them toward the size ceiling and prune them with the channel files.
        $this->app->singleton(LogManager::class, static function ($app): LogManager {
            return LogManager::fromApplication($app)->manage('debug-sessions', DebugLogRepository::FILE_PATTERN);
        });
    }

    /**
     * Whether one of this plugin's channels writes.
     *
     * @since 1.0.0
     *
     * @param  mixed  $enabled The framework's value.
     * @param  mixed  $channel The channel name.
     * @param  mixed  $slug    The plugin slug the framework asks for.
     * @return bool
     */
    public function filterEnabled(mixed $enabled, mixed $channel = '', mixed $slug = ''): bool
    {
        if ($slug !== LogChannels::SLUG) {
            return (bool) $enabled;
        }

        return (bool) $enabled || LogChannels::enabled((string) $channel);
    }

    /**
     * The least severe level one of this plugin's channels writes.
     *
     * @since 1.0.0
     *
     * @param  mixed $level   The framework's value.
     * @param  mixed $channel The channel name.
     * @param  mixed $slug    The plugin slug the framework asks for.
     * @return string
     */
    public function filterLevel(mixed $level, mixed $channel = '', mixed $slug = ''): string
    {
        return $slug === LogChannels::SLUG ? LogChannels::level((string) $channel, (string) $level) : (string) $level;
    }

    /**
     * Days one of this plugin's channels keeps.
     *
     * @since 1.0.0
     *
     * @param  mixed $days    The framework's value.
     * @param  mixed $channel The channel name.
     * @param  mixed $slug    The plugin slug the framework asks for.
     * @return int
     */
    public function filterRetentionDays(mixed $days, mixed $channel = '', mixed $slug = ''): int
    {
        return $slug === LogChannels::SLUG ? LogChannels::retentionDays((string) $channel, (int) $days) : (int) $days;
    }

    /**
     * This plugin's log directory.
     *
     * @since 1.0.0
     *
     * @param  mixed $path The framework's value.
     * @param  mixed $slug The plugin slug the framework asks for.
     * @return string
     */
    public function filterPath(mixed $path, mixed $slug = ''): string
    {
        return $slug === LogChannels::SLUG ? LogChannels::path((string) $path) : (string) $path;
    }
}
