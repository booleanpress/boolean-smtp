<?php
/**
 * Contract for a mail transport driver.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Contracts;

/**
 * Guarantees a way to identify a transport, configure PHPMailer to send through it, validate its
 * settings, and describe its settings schema, delivery modes and SMTP presets for the UI.
 *
 * @since 1.0.0
 */
interface TransportContract
{
    /**
     * Return the transport driver identifier.
     *
     * @since 1.0.0
     *
     * @return string The unique driver identifier.
     */
    public function getDriver(): string;

    /**
     * Return a human-readable name for this transport.
     *
     * @since 1.0.0
     *
     * @return string The display name.
     */
    public function getName(): string;

    /**
     * Configure the PHPMailer instance for this transport.
     *
     * @since 1.0.0
     *
     * @param  \PHPMailer\PHPMailer\PHPMailer $phpmailer Instance to configure.
     * @param  array<string, mixed>           $settings  Decrypted connection settings.
     */
    public function configure(\PHPMailer\PHPMailer\PHPMailer $phpmailer, array $settings): void;

    /**
     * Validate connection settings.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $settings Settings to validate.
     * @return array<string, string> Validation errors keyed by field name; empty when valid.
     */
    public function validateSettings(array $settings): array;

    /**
     * Return the settings schema used to render this transport's configuration UI.
     *
     * @since 1.0.0
     *
     * @return array<string, array{type: string, label: string, required: bool, default?: mixed}>
     *         Field definitions keyed by setting name.
     */
    public function getSettingsSchema(): array;

    /**
     * Return the delivery modes this transport supports.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Display label keyed by delivery mode, for example
     *                                ['smtp' => 'SMTP', 'api' => 'HTTP API'].
     */
    public function getDeliveryModes(): array;

    /**
     * Return the SMTP host/port/encryption presets offered in the settings dropdown.
     *
     * @since 1.0.0
     *
     * @return array<string, array{host: string, port: int, encryption: string, label: string}>
     *         Presets keyed by preset identifier.
     */
    public function getSmtpPresets(): array;

    /**
     * Return validation rules for connection settings.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Pipe-delimited validation rule strings keyed by setting name,
     *                                for example ['api_key' => 'required|string'].
     */
    public function getValidationRules(): array;
}
