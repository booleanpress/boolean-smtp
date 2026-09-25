<?php
/**
 * Stores and queries notification channel configurations.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Repositories;

use BooleanSmtp\Models\NotificationChannel;
use BooleanSmtp\Core\Exceptions\ModelNotFoundException;
use BooleanSmtp\Repositories\Contracts\NotificationChannelRepositoryInterface;
use function BooleanSmtp\Core\app;

/**
 * Stores notification channels as one JSON list inside the shared `booleanpress_options` table
 * (`option_name = 'notification_channels'`, `type = 'entity'`, `ref_id = 0`) — the ecosystem's
 * central store for small, bounded datasets, so a handful of alert channels never needs a table.
 * Channel `settings` are encrypted at rest and decrypted on read via {@see EncryptorContract}.
 *
 * @since 1.0.0
 */
class NotificationChannelRepository implements NotificationChannelRepositoryInterface
{
    /**
     * Settings key the channel list is stored under.
     *
     * @since 1.0.0
     * @var string
     */
    private const OPTION_KEY = 'notification_channels';

    /**
     * Resolve the framework settings repository backing storage.
     *
     * @since 1.0.0
     *
     * @return \BooleanSmtp\Core\Settings\SettingsRepository
     */
    private function settings(): \BooleanSmtp\Core\Settings\SettingsRepository
    {
        return app(\BooleanSmtp\Core\Settings\SettingsRepository::class);
    }

    /**
     * Resolve the encryptor used for channel settings at rest.
     *
     * @since 1.0.0
     *
     * @return \BooleanSmtp\Contracts\EncryptorContract
     */
    private function encryptor(): \BooleanSmtp\Contracts\EncryptorContract
    {
        return app(\BooleanSmtp\Contracts\EncryptorContract::class);
    }

    /**
     * Get every notification channel, with settings decrypted.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $filters Unused; present to satisfy {@see RepositoryInterface}.
     * @return NotificationChannel[] Every stored channel.
     */
    public function all(array $filters = []): array
    {
        $raw = $this->settings()->get(self::OPTION_KEY, []);
        $items = is_array($raw) ? $raw : [];

        return array_map(
            function (array $item) {
                if (isset($item['settings']) && is_array($item['settings'])) {
                    $item['settings'] = $this->encryptor()->decryptArray($item['settings']);
                }
                return NotificationChannel::fromArray($item);
            },
            $items
        );
    }

    /**
     * Check whether a channel with the given ID exists.
     *
     * @since 1.0.0
     *
     * @param  string|int $id Channel identifier.
     * @return bool True when the channel exists.
     */
    public function exists(string|int $id): bool
    {
        return $this->find($id) !== null;
    }

    /**
     * Count every stored channel.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $filters Unused; present to satisfy {@see RepositoryInterface}.
     * @return int The number of stored channels.
     */
    public function count(array $filters = []): int
    {
        return count($this->all());
    }

    /**
     * Delete a channel by ID.
     *
     * @since 1.0.0
     *
     * @param  string|int $id Channel identifier.
     * @return bool True when a channel was found and removed.
     */
    public function delete(string|int $id): bool
    {
        return $this->destroy($id);
    }

    /**
     * Find a channel by ID.
     *
     * @since 1.0.0
     *
     * @param  string|int $id Channel identifier.
     * @return NotificationChannel|null The channel, or null when no channel matches the ID.
     */
    public function find(string|int $id): ?NotificationChannel
    {
        foreach ($this->all() as $channel) {
            if ((int) $channel->id === (int) $id) {
                return $channel;
            }
        }
        return null;
    }

    /**
     * Find a channel by ID, or throw when it does not exist.
     *
     * @since 1.0.0
     *
     * @param  string|int $id Channel identifier.
     * @return NotificationChannel
     *
     * @throws ModelNotFoundException When no channel matches the ID.
     */
    public function findOrFail(string|int $id): NotificationChannel
    {
        $channel = $this->find($id);
        if ($channel === null) {
            $exception = new ModelNotFoundException();
            $exception->setModel(NotificationChannel::class, $id);

            throw $exception;
        }
        return $channel;
    }

    /**
     * Find the first channel of the given type.
     *
     * @since 1.0.0
     *
     * @param  string $type Channel type, for example `slack` or `discord`.
     * @return NotificationChannel|null The channel, or null when none exists for the type.
     */
    public function findByType(string $type): ?NotificationChannel
    {
        foreach ($this->all() as $channel) {
            if ($channel->type === $type) {
                return $channel;
            }
        }
        return null;
    }

    /**
     * Count channels of the given type.
     *
     * @since 1.0.0
     *
     * @param  string $type Channel type, for example `slack` or `discord`.
     * @return int The number of channels of that type.
     */
    public function countByType(string $type): int
    {
        $count = 0;
        foreach ($this->all() as $channel) {
            if ($channel->type === $type) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Get every enabled channel.
     *
     * @since 1.0.0
     *
     * @return NotificationChannel[] Enabled channels.
     */
    public function findEnabled(): array
    {
        return array_values(array_filter(
            $this->all(),
            fn(NotificationChannel $ch) => $ch->is_active
        ));
    }

    /**
     * Create a new notification channel.
     *
     * Assigns the next sequential ID, stamps `created_at`/`updated_at`, defaults
     * `is_active` to true when not supplied, and encrypts `settings` before persisting.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $data Channel attributes.
     * @return NotificationChannel The created channel, with settings decrypted.
     */
    public function create(array $data): NotificationChannel
    {
        $items = $this->loadRaw();
        $nextId = $this->nextId($items);

        $now = gmdate('Y-m-d H:i:s');
        $entry = array_merge($data, [
            'id'         => $nextId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (!isset($entry['is_active'])) {
            $entry['is_active'] = true;
        }

        $persistEntry = $entry;
        if (isset($persistEntry['settings']) && is_array($persistEntry['settings'])) {
            $persistEntry['settings'] = $this->encryptor()->encryptArray($persistEntry['settings']);
        }

        $items[] = $persistEntry;
        $this->persist($items);

        return NotificationChannel::fromArray($entry);
    }

    /**
     * Update a channel's attributes.
     *
     * Re-encrypts `settings` only when this call supplied plaintext settings in `$data`;
     * settings left untouched by this call stay in their already-encrypted stored form.
     *
     * @since 1.0.0
     *
     * @param  int|string            $id   Channel identifier.
     * @param  array<string, mixed>  $data Attributes to merge into the stored channel.
     * @return NotificationChannel|null The updated channel, or null when no channel matches the ID.
     */
    public function update(int|string $id, array $data): ?NotificationChannel
    {
        $items = $this->loadRaw();
        $found = false;
        $updated = null;

        foreach ($items as &$item) {
            if ((int) ($item['id'] ?? 0) === (int) $id) {
                $item = array_merge($item, $data, ['updated_at' => gmdate('Y-m-d H:i:s')]);
                unset($item['id']);
                $item['id'] = (int) $id;
                $found = true;
                $updated = $item;
                // Only re-encrypt when this call actually supplied plaintext settings ($data) --
                // $item['settings'] alone is ambiguous, since it's already ciphertext for every
                // field an update like setEnabled() didn't touch (loaded raw via loadRaw()).
                if (array_key_exists('settings', $data) && is_array($item['settings'] ?? null)) {
                    $item['settings'] = $this->encryptor()->encryptArray($item['settings']);
                }
                break;
            }
        }
        unset($item);

        if (!$found) {
            return null;
        }

        $this->persist($items);
        return NotificationChannel::fromArray($updated);
    }

    /**
     * Replace a channel's settings.
     *
     * @since 1.0.0
     *
     * @param  int|string            $id       Channel identifier.
     * @param  array<string, mixed>  $settings New settings for the channel.
     * @return bool True when the channel was found and updated.
     */
    public function updateSettings(int|string $id, array $settings): bool
    {
        return $this->update($id, ['settings' => $settings]) !== null;
    }

    /**
     * Enable or disable a channel.
     *
     * @since 1.0.0
     *
     * @param  int|string $id      Channel identifier.
     * @param  bool       $enabled True to enable the channel, false to disable it.
     * @return bool True when the channel was found and updated.
     */
    public function setEnabled(int|string $id, bool $enabled): bool
    {
        return $this->update($id, ['is_active' => $enabled]) !== null;
    }

    /**
     * Remove a channel from storage.
     *
     * @since 1.0.0
     *
     * @param  int|string $id Channel identifier.
     * @return bool True when a channel was found and removed.
     */
    public function destroy(int|string $id): bool
    {
        $items = $this->loadRaw();
        $before = count($items);
        $items = array_values(array_filter(
            $items,
            fn(array $item) => (int) ($item['id'] ?? 0) !== (int) $id
        ));

        if (count($items) === $before) {
            return false;
        }

        $this->persist($items);
        return true;
    }

    /**
     * Load the raw, still-encrypted channel list from storage.
     *
     * @since 1.0.0
     *
     * @return array<array<string, mixed>> Raw channel entries.
     */
    private function loadRaw(): array
    {
        $raw = $this->settings()->get(self::OPTION_KEY, []);
        return is_array($raw) ? $raw : [];
    }

    /**
     * Persist the raw channel list to storage.
     *
     * @since 1.0.0
     *
     * @param array<array<string, mixed>> $items Raw channel entries to store.
     */
    private function persist(array $items): void
    {
        $this->settings()->setEntity(self::OPTION_KEY, $items);
    }

    /**
     * Compute the next sequential channel ID.
     *
     * @since 1.0.0
     *
     * @param  array<array<string, mixed>> $items Existing raw channel entries.
     * @return int One greater than the highest existing ID; 1 when there are none.
     */
    private function nextId(array $items): int
    {
        $max = 0;
        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);
            if ($id > $max) {
                $max = $id;
            }
        }
        return $max + 1;
    }
}
