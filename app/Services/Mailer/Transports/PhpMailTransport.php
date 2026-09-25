<?php

/**
 * WordPress-default mail transport, sending through PHPMailer without a dedicated SMTP or API configuration.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer\Transports;

use BooleanSmtp\Contracts\TransportContract;

/**
 * Leaves WordPress's default mail delivery in place, applying only the connection's From address
 * and From name overrides. Supports a single delivery mode, `wp_mail`, PHP's built-in mail().
 *
 * @since 1.0.0
 */
class PhpMailTransport implements TransportContract
{
    /**
     * Get the transport driver identifier.
     *
     * @since 1.0.0
     *
     * @return string Always `php`.
     */
    public function getDriver(): string
    {
        return 'php';
    }

    /**
     * Get the human-readable transport name shown in the admin UI.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function getName(): string
    {
        return 'WordPress mail';
    }

    /**
     * Apply the connection's From address and name to PHPMailer without changing its transport.
     *
     * Deliberately does not call PHPMailer's `isMail()`: that method forces PHP's `mail()` and runs
     * after most `phpmailer_init` hooks, which would overwrite SMTP or API transports configured by
     * WordPress core or by other plugins. WordPress already routes outbound mail through `wp_mail()`
     * into PHPMailer, so only this connection's From overrides are applied here. The envelope sender
     * (Return-Path) is applied separately by `MailerManager::applyConnectionIdentityAndEnvelope()`.
     *
     * @since 1.0.0
     *
     * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance being prepared for sending.
     * @param array<string, mixed>           $settings  Decrypted connection settings; see {@see self::getSettingsSchema()}.
     */
    public function configure(\PHPMailer\PHPMailer\PHPMailer $phpmailer, array $settings): void
    {
        // Do not call isMail(): that forces PHP's mail() and runs after most phpmailer_init hooks,
        // overwriting SMTP/API transports from WordPress core or other plugins. WordPress routes
        // outbound mail through wp_mail() into PHPMailer; only this connection's From overrides are
        // applied here. Envelope Sender (Return-Path) is applied in
        // MailerManager::applyConnectionIdentityAndEnvelope().
        if (!empty($settings['from_email'])) {
            $phpmailer->From = (string) $settings['from_email'];
        }

        if (!empty($settings['from_name'])) {
            $phpmailer->FromName = (string) $settings['from_name'];
        }
    }

    /**
     * Validate connection settings.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $settings Connection settings to validate.
     * @return array<string, string> Validation errors keyed by field name; empty when valid.
     */
    public function validateSettings(array $settings): array
    {
        $errors = [];

        if (!empty($settings['from_email']) && \function_exists('is_email') && !\is_email((string) $settings['from_email'])) {
            $errors['from_email'] = 'From email must be a valid address.';
        }

        return $errors;
    }

    /**
     * Get the settings schema used to render the connection form in the admin UI.
     *
     * @since 1.0.0
     *
     * @return array<string, array{type: string, label: string, required: bool, default?: mixed}>
     */
    public function getSettingsSchema(): array
    {
        return [
            'from_email' => [
                'type'     => 'email',
                'label'    => 'From Email',
                'required' => true,
                'default'  => '',
            ],
            'force_from_email' => [
                'type'     => 'checkbox',
                'label'    => 'Force From email',
                'required' => false,
                'default'  => false,
            ],
            'return_path' => [
                'type'     => 'checkbox',
                'label'    => 'Set envelope sender (Return-Path) from From address',
                'required' => false,
                'default'  => true,
            ],
            'from_name' => [
                'type'     => 'text',
                'label'    => 'From Name',
                'required' => false,
                'default'  => '',
            ],
            'force_from_name' => [
                'type'     => 'checkbox',
                'label'    => 'Force From name',
                'required' => false,
                'default'  => false,
            ],
        ];
    }

    /**
     * Get the supported delivery modes for this transport.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Delivery mode key mapped to its label; only `wp_mail` is supported.
     */
    public function getDeliveryModes(): array
    {
        return ['wp_mail' => 'WordPress Default (mail)'];
    }

    /**
     * Get the SMTP host/port/encryption presets offered in the admin UI.
     *
     * @since 1.0.0
     *
     * @return array<string, array{host: string, port: int, encryption: string, label: string}> Always empty; this transport has no SMTP mode.
     */
    public function getSmtpPresets(): array
    {
        return [];
    }

    /**
     * Get validation rules for connection settings.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Field name mapped to its validation rule string.
     */
    public function getValidationRules(): array
    {
        return [
            'from_email' => 'nullable|email',
        ];
    }
}

