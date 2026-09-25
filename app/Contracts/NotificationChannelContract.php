<?php
/**
 * Contract for a notification delivery channel.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Contracts;

/**
 * Guarantees a way to identify a notification channel, validate its settings, describe its
 * settings schema for the UI, and send a message through it.
 *
 * @since 1.0.0
 */
interface NotificationChannelContract
{
    /**
     * Return the channel identifier.
     *
     * @since 1.0.0
     *
     * @return string The unique channel identifier.
     */
    public function getIdentifier(): string;

    /**
     * Return a human-readable name for this channel.
     *
     * @since 1.0.0
     *
     * @return string The display name.
     */
    public function getName(): string;

    /**
     * Send a notification through this channel.
     *
     * @since 1.0.0
     *
     * @param  string               $message  The notification message.
     * @param  array<string, mixed> $settings Channel-specific settings.
     * @param  array<string, mixed> $context  Additional context data.
     * @return bool True when the notification was accepted for delivery.
     */
    public function send(string $message, array $settings, array $context = []): bool;

    /**
     * Validate channel settings.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $settings Settings to validate.
     * @return array<string, string> Validation errors keyed by field name; empty when valid.
     */
    public function validateSettings(array $settings): array;

    /**
     * Return the settings schema used to render the channel's configuration UI.
     *
     * @since 1.0.0
     *
     * @return array<string, array{type: string, label: string, required: bool}> Field
     *         definitions keyed by setting name.
     */
    public function getSettingsSchema(): array;
}
