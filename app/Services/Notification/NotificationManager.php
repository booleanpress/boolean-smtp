<?php

/**
 * Resolves notification channel drivers and dispatches alerts through them.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Notification;

use BooleanSmtp\Contracts\NotificationChannelContract;
use BooleanSmtp\Contracts\Editions\NotificationLimitContract;
use BooleanSmtp\Models\NotificationChannel;
use BooleanSmtp\Repositories\NotificationChannelRepository;
use BooleanSmtp\Core\Contracts\LoggerContract;
use BooleanSmtp\Core\Foundation\Application;
use function BooleanSmtp\Core\app;
use function BooleanSmtp\Core\config;

/**
 * Central entry point for sending alerts to configured notification channels.
 *
 * @since 1.0.0
 */
class NotificationManager {
    /**
     * Channel type to driver class map, loaded from the `notifications.channels` config.
     *
     * @since 1.0.0
     * @var array<string, class-string<NotificationChannelContract>>
     */
    private array $channelDrivers = [];

    /**
     * Loads the configured notification channel drivers.
     *
     * @since 1.0.0
     *
     * @param NotificationChannelRepository $channels Lists the enabled notification channels.
     * @param LoggerContract                $logger   Records channel delivery failures.
     */
    public function __construct(
        private readonly NotificationChannelRepository $channels,
        private readonly LoggerContract $logger,
    ) {
        $channels = config('notifications.channels', []);
        foreach ($channels as $type => $class) {
            if (class_exists($class)) {
                $this->channelDrivers[$type] = $class;
            }
        }
    }

    /**
     * Sends an alert to every enabled notification channel, subject to the alert's cooldown.
     *
     * Does nothing when the alert type is disabled in configuration or is still within its
     * cooldown window.
     *
     * @since 1.0.0
     *
     * @param  string                $alertType Alert type key, matching a `notifications.alerts.*` config entry.
     * @param  string                $message   Human-readable alert message.
     * @param  array<string, mixed>  $context   Additional context passed through to each channel.
     * @return void
     */
    public function notify(string $alertType, string $message, array $context = []): void {
        $alertConfig = config("notifications.alerts.{$alertType}", []);
        if (!($alertConfig['enabled'] ?? false)) {
            return;
        }

        if ($this->isInCooldown($alertType, $alertConfig)) {
            return;
        }

        foreach ($this->channels->findEnabled() as $channel) {
            $this->sendViaChannel($channel, $message, $context);
        }
    }

    /**
     * Sends a message through a single notification channel.
     *
     * Delivery failures are caught and logged rather than propagated, so one failing channel
     * does not stop the others.
     *
     * @since 1.0.0
     *
     * @param  NotificationChannel   $channel The channel record to send through.
     * @param  string                $message Human-readable alert message.
     * @param  array<string, mixed>  $context Additional context passed to the channel driver.
     * @return bool True when the channel driver reported success.
     */
    public function sendViaChannel(NotificationChannel $channel, string $message, array $context = []): bool {
        $driver = $this->resolveDriver($channel->type);
        if (!$driver) {
            return false;
        }

        try {
            $settings = $channel->settings ?? [];
            return $driver->send($message, is_array($settings) ? $settings : [], $context);
        } catch (\Throwable $e) {
            $this->logger->error("Notification channel {$channel->type} failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Validates settings and sends a test message through a channel type.
     *
     * @since 1.0.0
     *
     * @param  string                $type     Channel type identifier (for example `slack`, `telegram`).
     * @param  array<string, mixed>  $settings Channel settings to validate and send with.
     * @return array<string, mixed> Result with a `success` flag and, on failure, `error` or `errors`.
     */
    public function testChannel(string $type, array $settings): array {
        $driver = $this->resolveDriver($type);
        if (!$driver) {
            return ['success' => false, 'error' => "Unknown channel type: {$type}"];
        }

        $errors = $driver->validateSettings($settings);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        try {
            $sent = $driver->send('Test notification from BooleanSMTP', $settings, [
                'operational' => [
                    'kind'     => 'notification_test',
                    'severity' => 'info',
                    'facts'    => ['Channel type' => $type],
                ],
            ]);
            return ['success' => $sent];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Returns the maximum number of channels allowed for a channel type, as the site's
     * notification-limit policy sets it.
     *
     * @since 1.0.0
     *
     * @param  string $type Channel type identifier.
     * @return int
     */
    public function maxChannelsForType(string $type): int {
        return $this->limitPolicy()->maxPerType($type);
    }

    /**
     * The message returned when a channel type is at its limit.
     *
     * @since 1.0.0
     *
     * @param  string $type Channel type identifier.
     * @param  int    $max  The limit that was reached.
     * @return string
     */
    public function channelLimitMessage(string $type, int $max): string {
        return $this->limitPolicy()->limitMessage($type, $max);
    }

    /**
     * The notification-limit policy bound for this site, resolved on each use.
     *
     * @since 1.0.0
     *
     * @return NotificationLimitContract
     */
    private function limitPolicy(): NotificationLimitContract {
        return app(NotificationLimitContract::class);
    }

    /**
     * Returns the notification channel types available on this site, with display name and settings schema.
     *
     * @since 1.0.0
     *
     * @return array<string, array{name: string, schema: array<string, mixed>}>
     */
    public function getAvailableChannels(): array {
        $result = [];
        foreach ($this->channelDrivers as $type => $class) {
            $driver = $this->resolveDriver($type);
            if (!$driver) {
                continue;
            }

            $result[$type] = [
                'name'   => $driver->getName(),
                'schema' => $driver->getSettingsSchema()
            ];
        }
        return $result;
    }

    /**
     * Resolves a channel type to its driver instance, preferring the container when available.
     *
     * @since 1.0.0
     *
     * @param  string $type Channel type identifier.
     * @return NotificationChannelContract|null The resolved driver, or null when the type is unknown.
     */
    private function resolveDriver(string $type): ?NotificationChannelContract {
        $class = $this->channelDrivers[$type] ?? null;
        if (!$class || !class_exists($class)) {
            return null;
        }

        if (Application::hasInstance()) {
            try {
                $resolved = app()->make($class);
                if ($resolved instanceof NotificationChannelContract) {
                    return $resolved;
                }
            } catch (\Throwable) {
                // Fallback to direct instantiation below.
            }
        }

        $driver = new $class();

        return $driver instanceof NotificationChannelContract ? $driver : null;
    }

    /**
     * Rate-limits repeated alerts (for example, many failed sends in a burst) per alert type.
     *
     * @since 1.0.0
     *
     * @param  string                $alertType   Alert type key.
     * @param  array<string, mixed>  $alertConfig Alert configuration; `cooldown` is the window in seconds.
     * @return bool True when the alert type is still within its cooldown window.
     */
    private function isInCooldown(string $alertType, array $alertConfig): bool {
        $seconds = (int) ($alertConfig['cooldown'] ?? 0);
        if ($seconds <= 0) {
            return false;
        }

        if (!Application::hasInstance()) {
            return false;
        }

        try {
            $settings = app(\BooleanSmtp\Core\Settings\SettingsRepository::class);
        } catch (\Throwable) {
            return false;
        }

        $key = 'ntfy_cd_' . \preg_replace('/[^a-z0-9_]/i', '_', $alertType);
        if ($settings->getTransient($key)) {
            return true;
        }

        $settings->setTransient($key, '1', $seconds);

        return false;
    }
}
