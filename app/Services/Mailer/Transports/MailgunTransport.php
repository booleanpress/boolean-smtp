<?php

/**
 * Mailgun mail transport.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer\Transports;

use BooleanSmtp\Contracts\TransportContract;

/**
 * Sends mail through Mailgun. Supports two delivery modes: `api` (HTTP API) and `smtp` (SMTP with
 * a domain, username, and API key), each available in the US or EU region.
 *
 * @since 1.0.0
 */
class MailgunTransport implements TransportContract
{
    /**
     * Get the transport driver identifier.
     *
     * @since 1.0.0
     *
     * @return string Always `mailgun`.
     */
    public function getDriver(): string
    {
        return 'mailgun';
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
        return 'Mailgun';
    }

    /**
     * Configure PHPMailer for Mailgun's regional SMTP endpoint.
     *
     * Does nothing when the connection uses API delivery mode, since API sends bypass PHPMailer's
     * SMTP transport entirely.
     *
     * @since 1.0.0
     *
     * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance being prepared for sending.
     * @param array<string, mixed>           $settings  Decrypted connection settings; see {@see self::getSettingsSchema()}.
     */
    public function configure(\PHPMailer\PHPMailer\PHPMailer $phpmailer, array $settings): void
    {
        if (($settings['delivery_mode'] ?? 'api') === 'api') {
            return;
        }

        $region = $settings['region'] ?? 'us';
        $host = $region === 'eu' ? 'smtp.eu.mailgun.org' : 'smtp.mailgun.org';

        $phpmailer->isSMTP();
        $phpmailer->Host       = $host;
        $phpmailer->Port       = 587;
        $phpmailer->SMTPSecure = 'tls';
        $phpmailer->SMTPAuth   = true;
        $phpmailer->Username   = $settings['username'] ?? ('postmaster@' . ($settings['domain'] ?? ''));
        $phpmailer->Password   = $settings['api_key'] ?? '';

        if (isset($settings['from_email'])) {
            $phpmailer->From   = $settings['from_email'];
            $phpmailer->Sender = $settings['from_email'];
        }
        if (isset($settings['from_name'])) {
            $phpmailer->FromName = $settings['from_name'];
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
        $errors  = [];
        $apiMode = ($settings['delivery_mode'] ?? 'api') === 'api';

        if (empty(trim((string) ($settings['from_email'] ?? '')))) {
            $errors['from_email'] = 'From Email is required.';
        }
        if (trim((string) ($settings['from_name'] ?? '')) === '') {
            $errors['from_name'] = 'From Name is required.';
        }
        if (empty($settings['domain'])) {
            $errors['domain'] = 'Mailgun domain is required.';
        }
        if (empty($settings['api_key'])) {
            $errors['api_key'] = 'Mailgun API Key is required.';
        }

        if ($apiMode) {
            return $errors;
        }

        $preset = $settings['smtp_preset'] ?? 'mailgun_us_tls_587';
        $presets = $this->getSmtpPresets();
        if (! isset($presets[$preset])) {
            $errors['smtp_preset'] = 'Invalid Mailgun SMTP endpoint.';
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
            'delivery_mode' => [
                'type'     => 'select',
                'label'    => 'Delivery Method',
                'required' => true,
                'default'  => 'api',
                'options'  => [
                    'api'  => 'HTTP API',
                    'smtp' => 'SMTP',
                ],
            ],
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
                'required' => true,
                'default'  => '',
            ],
            'force_from_name' => [
                'type'     => 'checkbox',
                'label'    => 'Force From name',
                'required' => false,
                'default'  => false,
            ],
            'domain' => [
                'type'     => 'text',
                'label'    => 'Mailgun Domain',
                'required' => true,
                'default'  => '',
            ],
            'api_key' => [
                'type'     => 'password',
                'label'    => 'Private API Key',
                'required' => true,
                'default'  => '',
            ],
            'region' => [
                'type'     => 'select',
                'label'    => 'Region',
                'required' => false,
                'default'  => 'us',
                'options'  => [
                    'us' => 'US (api.mailgun.net)',
                    'eu' => 'EU (api.eu.mailgun.net)',
                ],
            ],
            'smtp_preset' => [
                'type'         => 'select',
                'label'        => 'SMTP Server',
                'required'     => false,
                'default'      => 'mailgun_us_tls_587',
                'visible_when' => [
                    'key'   => 'delivery_mode',
                    'value' => 'smtp',
                ],
                'options'      => [
                    'mailgun_us_tls_587' => 'smtp.mailgun.org:587 (US, TLS)',
                    'mailgun_eu_tls_587' => 'smtp.eu.mailgun.org:587 (EU, TLS)',
                ],
            ],
        ];
    }

    /**
     * Get the supported delivery modes for this transport.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Delivery mode key mapped to its label.
     */
    public function getDeliveryModes(): array
    {
        return [
            'api'  => 'HTTP API',
            'smtp' => 'SMTP',
        ];
    }

    /**
     * Get the SMTP host/port/encryption presets offered in the admin UI.
     *
     * @since 1.0.0
     *
     * @return array<string, array{host: string, port: int, encryption: string, label: string}>
     */
    public function getSmtpPresets(): array
    {
        return [
            'mailgun_us_tls_587' => [
                'host'       => 'smtp.mailgun.org',
                'port'       => 587,
                'encryption' => 'tls',
                'label'      => 'smtp.mailgun.org:587 (US, TLS)',
            ],
            'mailgun_eu_tls_587' => [
                'host'       => 'smtp.eu.mailgun.org',
                'port'       => 587,
                'encryption' => 'tls',
                'label'      => 'smtp.eu.mailgun.org:587 (EU, TLS)',
            ],
        ];
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
            'delivery_mode' => 'required|in:smtp,api',
            'domain'        => 'required|string',
            'api_key'       => 'required|string',
            'region'        => 'required|in:us,eu',
            'from_email'    => 'required|email',
            'from_name'     => 'required|string|max:255',
        ];
    }
}
