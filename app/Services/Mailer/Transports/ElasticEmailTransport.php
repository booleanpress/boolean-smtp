<?php

/**
 * Elastic Email mail transport.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer\Transports;

use BooleanSmtp\Contracts\TransportContract;

/**
 * Sends mail through Elastic Email. Supports two delivery modes: `api` (HTTP API) and `smtp`
 * (SMTP with an account email and API key).
 *
 * @since 1.0.0
 */
class ElasticEmailTransport implements TransportContract
{
    /**
     * SMTP presets available for this transport, keyed by preset id, each a [host, port, SMTPSecure] tuple.
     *
     * @since 1.0.0
     * @var array<string, array{0: string, 1: int, 2: string}>
     */
    private const SMTP_PRESETS = [
        'elastic_2525' => ['smtp.elasticemail.com', 2525, 'tls'],
        'elastic_587'  => ['smtp.elasticemail.com', 587, 'tls'],
        'elastic_465'  => ['smtp.elasticemail.com', 465, 'ssl'],
    ];

    /**
     * Get the transport driver identifier.
     *
     * @since 1.0.0
     *
     * @return string Always `elasticemail`.
     */
    public function getDriver(): string
    {
        return 'elasticemail';
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
        return 'Elastic Email';
    }

    /**
     * Configure PHPMailer for Elastic Email's SMTP endpoint.
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

        $preset = $settings['smtp_preset'] ?? 'elastic_2525';
        $triple = self::SMTP_PRESETS[$preset] ?? self::SMTP_PRESETS['elastic_2525'];

        $phpmailer->isSMTP();
        $phpmailer->Host       = $triple[0];
        $phpmailer->Port       = $triple[1];
        $phpmailer->SMTPSecure = $triple[2];
        $phpmailer->SMTPAuth   = true;
        $phpmailer->Username   = $settings['username'] ?? '';
        $phpmailer->Password   = $settings['api_key'] ?? '';

        if (isset($settings['from_email']) && $settings['from_email'] !== '') {
            $phpmailer->From   = (string) $settings['from_email'];
            $phpmailer->Sender = (string) $settings['from_email'];
        }
        if (isset($settings['from_name'])) {
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
        $errors  = [];
        $apiMode = ($settings['delivery_mode'] ?? 'api') === 'api';

        if (empty(trim((string) ($settings['from_email'] ?? '')))) {
            $errors['from_email'] = 'From Email is required.';
        }
        if (trim((string) ($settings['from_name'] ?? '')) === '') {
            $errors['from_name'] = 'From Name is required.';
        }

        if (empty($settings['api_key'])) {
            $errors['api_key'] = 'Elastic Email API Key is required.';
        }

        if (! $apiMode) {
            if (empty($settings['username'])) {
                $errors['username'] = 'Account email (SMTP username) is required.';
            }
            $preset = $settings['smtp_preset'] ?? 'elastic_2525';
            if (! isset(self::SMTP_PRESETS[$preset])) {
                $errors['smtp_preset'] = 'Invalid Elastic Email SMTP endpoint.';
            }
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
                'required' => true,
                'default'  => '',
            ],
            'force_from_name' => [
                'type'     => 'checkbox',
                'label'    => 'Force From name',
                'required' => false,
                'default'  => false,
            ],
            'api_key' => [
                'type'     => 'password',
                'label'    => 'API Key',
                'required' => true,
                'default'  => '',
            ],
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
            'username' => [
                'type'         => 'email',
                'label'        => 'Account Email (SMTP Username)',
                'required'     => false,
                'default'      => '',
                'visible_when' => [
                    'key'   => 'delivery_mode',
                    'value' => 'smtp',
                ],
            ],
            'smtp_preset' => [
                'type'         => 'select',
                'label'        => 'SMTP Server',
                'required'     => false,
                'default'      => 'elastic_2525',
                'visible_when' => [
                    'key'   => 'delivery_mode',
                    'value' => 'smtp',
                ],
                'options'      => [
                    'elastic_2525' => 'smtp.elasticemail.com:2525 (Recommended, STARTTLS)',
                    'elastic_587'  => 'smtp.elasticemail.com:587 (STARTTLS)',
                    'elastic_465'  => 'smtp.elasticemail.com:465 (SSL)',
                ],
            ],
            'email_type' => [
                'type'     => 'select',
                'label'    => 'Email Type',
                'required' => false,
                'default'  => 'transactional',
                'options'  => [
                    'transactional' => 'Transactional',
                    'marketing'     => 'Marketing',
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
        $presets = [];
        foreach (self::SMTP_PRESETS as $key => [$host, $port, $enc]) {
            $presets[$key] = [
                'host'       => $host,
                'port'       => $port,
                'encryption' => $enc,
                'label'      => "{$host}:{$port} (" . strtoupper($enc) . ")",
            ];
        }
        return $presets;
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
            'api_key'       => 'required|string',
            'username'      => 'required_if:delivery_mode,smtp|email',
            'from_email'    => 'required|email',
            'from_name'     => 'required|string|max:255',
        ];
    }
}

