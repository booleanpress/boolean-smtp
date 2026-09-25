<?php
/**
 * Plain data object representing a configured notification channel.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Models;

/**
 * Represents one notification channel, such as a Slack webhook or a Telegram chat.
 *
 * This is not an Eloquent-style model: channels are stored as a JSON array in
 * `booleanpress_options`, managed by NotificationChannelRepository.
 *
 * @since 1.0.0
 */
class NotificationChannel
{
    /**
     * Channel ID.
     *
     * @since 1.0.0
     * @var int
     */
    public int $id;

    /**
     * Channel type, for example "slack" or "telegram".
     *
     * @since 1.0.0
     * @var string
     */
    public string $type;

    /**
     * Display name for this channel.
     *
     * @since 1.0.0
     * @var string
     */
    public string $name;

    /**
     * Channel-specific settings.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    public array $settings;

    /**
     * Whether this channel is enabled.
     *
     * @since 1.0.0
     * @var bool
     */
    public bool $is_active;

    /**
     * Creation timestamp, if known.
     *
     * @since 1.0.0
     * @var string|null
     */
    public ?string $created_at;

    /**
     * Last update timestamp, if known.
     *
     * @since 1.0.0
     * @var string|null
     */
    public ?string $updated_at;

    /**
     * Create a notification channel from raw attributes.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $attributes Raw attributes, typically from stored JSON.
     */
    public function __construct(array $attributes = [])
    {
        $this->id         = (int) ($attributes['id'] ?? 0);
        $this->type       = (string) ($attributes['type'] ?? '');
        $this->name       = (string) ($attributes['name'] ?? '');
        $this->settings   = is_array($attributes['settings'] ?? null) ? $attributes['settings'] : [];
        $this->is_active  = (bool) ($attributes['is_active'] ?? true);
        $this->created_at = $attributes['created_at'] ?? null;
        $this->updated_at = $attributes['updated_at'] ?? null;
    }

    /**
     * Create a notification channel from an array of attributes.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $data Raw attributes.
     * @return static The created channel.
     */
    public static function fromArray(array $data): static
    {
        return new static($data);
    }

    /**
     * Convert this channel back to an array for storage.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The channel's attributes.
     */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'type'       => $this->type,
            'name'       => $this->name,
            'settings'   => $this->settings,
            'is_active'  => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
