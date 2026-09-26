<?php
/**
 * Plain data object representing the alert setup of one provider.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Models;

/**
 * The alert setup of one provider (Telegram, Slack or Discord). Each provider has at most one
 * setup, identified by its type.
 *
 * This is not an Eloquent-style model: setups are stored as JSON in `booleanpress_options`,
 * managed by NotificationChannelRepository.
 *
 * @since 1.0.0
 */
class NotificationChannel
{
    /**
     * Provider type, for example "slack" or "telegram".
     *
     * @since 1.0.0
     * @var string
     */
    public string $type;

    /**
     * Provider-specific settings (webhook URL, bot token, chat ID, …).
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    public array $settings;

    /**
     * Whether alerts are sent through this setup.
     *
     * @since 1.0.0
     * @var bool
     */
    public bool $is_active;

    /**
     * When the setup was first saved (UTC, `Y-m-d H:i:s`).
     *
     * @since 1.0.0
     * @var string|null
     */
    public ?string $created_at;

    /**
     * When the setup was last saved (UTC, `Y-m-d H:i:s`).
     *
     * @since 1.0.0
     * @var string|null
     */
    public ?string $updated_at;

    /**
     * Create a setup from raw attributes.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $attributes Raw attributes, typically from stored JSON.
     */
    public function __construct(array $attributes = [])
    {
        $this->type       = (string) ($attributes['type'] ?? '');
        $this->settings   = is_array($attributes['settings'] ?? null) ? $attributes['settings'] : [];
        $this->is_active  = (bool) ($attributes['is_active'] ?? true);
        $this->created_at = $attributes['created_at'] ?? null;
        $this->updated_at = $attributes['updated_at'] ?? null;
    }

    /**
     * Create a setup from an array of attributes.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $data Raw attributes.
     * @return static The created setup.
     */
    public static function fromArray(array $data): static
    {
        return new static($data);
    }

    /**
     * Convert this setup back to an array.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The setup's attributes.
     */
    public function toArray(): array
    {
        return [
            'type'       => $this->type,
            'settings'   => $this->settings,
            'is_active'  => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
