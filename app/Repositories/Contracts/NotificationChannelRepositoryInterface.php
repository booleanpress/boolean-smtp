<?php
/**
 * Contract for the repository holding each alert provider's setup.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Repositories\Contracts;

use BooleanSmtp\Models\NotificationChannel;

/**
 * Reads and writes the alert setup of each provider. A provider has at most one setup, so every
 * method is keyed by the provider type (`telegram`, `slack`, `discord`).
 *
 * @since 1.0.0
 */
interface NotificationChannelRepositoryInterface {
    /**
     * Every saved setup, keyed by provider type.
     *
     * @since 1.0.0
     *
     * @return array<string, NotificationChannel>
     */
    public function all(): array;

    /**
     * The setup of one provider.
     *
     * @since 1.0.0
     *
     * @param  string $type Provider type.
     * @return NotificationChannel|null Null when the provider is not set up.
     */
    public function find(string $type): ?NotificationChannel;

    /**
     * The setups alerts are sent through.
     *
     * @since 1.0.0
     *
     * @return list<NotificationChannel>
     */
    public function findEnabled(): array;

    /**
     * Create or update a provider's setup.
     *
     * @since 1.0.0
     *
     * @param  string               $type Provider type.
     * @param  array<string, mixed> $data `settings` and/or `is_active`; keys left out keep their saved value.
     * @return NotificationChannel The saved setup.
     */
    public function save(string $type, array $data): NotificationChannel;

    /**
     * Remove a provider's setup.
     *
     * @since 1.0.0
     *
     * @param  string $type Provider type.
     * @return bool True when a setup was removed.
     */
    public function delete(string $type): bool;
}
