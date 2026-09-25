<?php
/**
 * Data access contract for notification channel configurations.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

namespace BooleanSmtp\Repositories\Contracts;

use BooleanSmtp\Models\NotificationChannel;

/**
 * Stores and retrieves the notification channels (Slack, Discord, and so on) used to
 * alert on delivery failures.
 *
 * @since 1.0.0
 */
interface NotificationChannelRepositoryInterface extends RepositoryInterface {
    /**
     * Find the first channel of the given type.
     *
     * @since 1.0.0
     *
     * @param  string $type Channel type, for example `slack` or `discord`.
     * @return NotificationChannel|null The channel, or null when none exists for the type.
     */
    public function findByType(string $type): ?NotificationChannel;

    /**
     * Get every enabled channel.
     *
     * @since 1.0.0
     *
     * @return array<int, NotificationChannel> Enabled channels.
     */
    public function findEnabled(): array;

    /**
     * Find a channel by ID.
     *
     * @since 1.0.0
     *
     * @param  string|int $id Channel identifier.
     * @return NotificationChannel|null The channel, or null when no channel matches the ID.
     */
    public function find(string | int $id): ?NotificationChannel;

    /**
     * Replace a channel's settings.
     *
     * @since 1.0.0
     *
     * @param  int|string            $id       Channel identifier.
     * @param  array<string, mixed>  $settings New settings for the channel.
     * @return bool True when the channel was found and updated.
     */
    public function updateSettings(int | string $id, array $settings): bool;

    /**
     * Enable or disable a channel.
     *
     * @since 1.0.0
     *
     * @param  int|string $id      Channel identifier.
     * @param  bool       $enabled True to enable the channel, false to disable it.
     * @return bool True when the channel was found and updated.
     */
    public function setEnabled(int | string $id, bool $enabled): bool;
}
