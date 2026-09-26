<?php
/**
 * Stores the alert setup of each provider.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Repositories;

use BooleanSmtp\Contracts\EncryptorContract;
use BooleanSmtp\Core\Settings\SettingsRepository;
use BooleanSmtp\Models\NotificationChannel;
use BooleanSmtp\Repositories\Contracts\NotificationChannelRepositoryInterface;

/**
 * Stores the alert setups as one JSON object keyed by provider type inside the shared
 * `booleanpress_options` table (`option_name = 'alert_providers'`, `type = 'entity'`), the
 * ecosystem's central store for small, bounded datasets. Each provider has at most one setup.
 * Settings are encrypted at rest and decrypted on read via {@see EncryptorContract}.
 *
 * @since 1.0.0
 */
class NotificationChannelRepository implements NotificationChannelRepositoryInterface
{
    /**
     * Settings key the setups are stored under.
     *
     * @since 1.0.0
     * @var string
     */
    public const OPTION_KEY = 'alert_providers';

    /**
     * @since 1.0.0
     *
     * @param SettingsRepository $settings  The framework's shared settings store.
     * @param EncryptorContract  $encryptor Encrypts settings at rest.
     */
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly EncryptorContract $encryptor,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     *
     * @return array<string, NotificationChannel>
     */
    public function all(): array
    {
        $setups = [];
        foreach ($this->loadRaw() as $type => $item) {
            $setups[$type] = $this->hydrate($type, $item);
        }

        return $setups;
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     *
     * @param  string $type Provider type.
     * @return NotificationChannel|null
     */
    public function find(string $type): ?NotificationChannel
    {
        $raw = $this->loadRaw();

        return isset($raw[$type]) ? $this->hydrate($type, $raw[$type]) : null;
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     *
     * @return list<NotificationChannel>
     */
    public function findEnabled(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (NotificationChannel $setup): bool => $setup->is_active
        ));
    }

    /**
     * {@inheritDoc}
     *
     * Settings supplied in `$data` replace the saved ones and are encrypted before they are stored;
     * a save without settings keeps the stored (already encrypted) settings untouched.
     *
     * @since 1.0.0
     *
     * @param  string               $type Provider type.
     * @param  array<string, mixed> $data `settings` and/or `is_active`.
     * @return NotificationChannel
     */
    public function save(string $type, array $data): NotificationChannel
    {
        $raw  = $this->loadRaw();
        $now  = gmdate('Y-m-d H:i:s');
        $item = $raw[$type] ?? ['settings' => [], 'is_active' => true, 'created_at' => $now];

        if (array_key_exists('settings', $data) && is_array($data['settings'])) {
            $item['settings'] = $this->encryptor->encryptArray($data['settings']);
        }
        if (array_key_exists('is_active', $data)) {
            $item['is_active'] = (bool) $data['is_active'];
        }
        $item['updated_at'] = $now;

        $raw[$type] = $item;
        $this->settings->setEntity(self::OPTION_KEY, $raw);

        return $this->hydrate($type, $item);
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     *
     * @param  string $type Provider type.
     * @return bool
     */
    public function delete(string $type): bool
    {
        $raw = $this->loadRaw();
        if (!isset($raw[$type])) {
            return false;
        }

        unset($raw[$type]);
        $this->settings->setEntity(self::OPTION_KEY, $raw);

        return true;
    }

    /**
     * The stored setups, still encrypted, keyed by provider type.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadRaw(): array
    {
        $raw = $this->settings->get(self::OPTION_KEY, []);
        if (!is_array($raw)) {
            return [];
        }

        return array_filter($raw, static fn (mixed $item, mixed $type): bool => is_string($type) && is_array($item), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Build a setup from its stored form, decrypting its settings.
     *
     * @since 1.0.0
     *
     * @param  string               $type Provider type.
     * @param  array<string, mixed> $item Stored attributes.
     * @return NotificationChannel
     */
    private function hydrate(string $type, array $item): NotificationChannel
    {
        $settings = is_array($item['settings'] ?? null) ? $this->encryptor->decryptArray($item['settings']) : [];

        return NotificationChannel::fromArray(['type' => $type, 'settings' => $settings] + $item);
    }
}
