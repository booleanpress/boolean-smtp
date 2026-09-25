<?php

/**
 * SMTP2GO mail transport.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer\Transports;

use BooleanSmtp\Contracts\TransportContract;

/**
 * Sends mail through SMTP2GO. Supports two delivery modes: `api` (HTTP API) and `smtp` (SMTP
 * with a username and password).
 *
 * @since 1.0.0
 */
class Smtp2GoTransport implements TransportContract
{
    /**
     * SMTP presets available for this transport, keyed by preset id, each a [host, port, SMTPSecure] tuple.
     *
     * @since 1.0.0
     * @var array<string, array{0: string, 1: int, 2: string}>
     */
    private const SMTP_PRESETS = [
        'smtp2go_587'  => ['mail.smtp2go.com', 587, 'tls'],
        'smtp2go_2525' => ['mail.smtp2go.com', 2525, 'tls'],
        'smtp2go_465'  => ['mail.smtp2go.com', 465, 'ssl'],
    ];

    /**
     * Get the transport driver identifier.
     *
     * @since 1.0.0
     *
     * @return string Always `smtp2go`.
     */
    public function getDriver(): string
    {
        return 'smtp2go';
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
        return 'SMTP2GO';
    }

    /**
     * Configure PHPMailer for SMTP2GO's SMTP endpoint.
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

        $preset = $settings['smtp_preset'] ?? 'smtp2go_587';
        $triple = self::SMTP_PRESETS[$preset] ?? self::SMTP_PRESETS['smtp2go_587'];

        $phpmailer->isSMTP();
        $phpmailer->Host       = $triple[0];
        $phpmailer->Port       = $triple[1];
        $phpmailer->SMTPSecure = $triple[2];
        $phpmailer->SMTPAuth   = true;
        $phpmailer->Username   = $settings['username'] ?? '';
        $phpmailer->Password   = $settings['password'] ?? '';

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

        if ($apiMode) {
            if (empty($settings['api_key'])) {
                $errors['api_key'] = 'SMTP2GO API Key is required.';
            }
        } else {
            if (empty($settings['username'])) {
                $errors['username'] = 'SMTP Username is required.';
            }
            if (empty($settings['password'])) {
                $errors['password'] = 'SMTP Password is required.';
            }
            $preset = $settings['smtp_preset'] ?? 'smtp2go_587';
            if (! isset(self::SMTP_PRESETS[$preset])) {
                $errors['smtp_preset'] = 'Invalid SMTP2GO endpoint.';
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
            'smtp_preset' => [
                'type'         => 'select',
                'label'        => 'SMTP Server',
                'required'     => false,
                'default'      => 'smtp2go_587',
                'visible_when' => [
                    'key'   => 'delivery_mode',
                    'value' => 'smtp',
                ],
                'options'      => [
                    'smtp2go_587'  => 'mail.smtp2go.com:587 (STARTTLS)',
                    'smtp2go_2525' => 'mail.smtp2go.com:2525 (STARTTLS)',
                    'smtp2go_465'  => 'mail.smtp2go.com:465 (SSL)',
                ],
            ],
            'api_key' => [
                'type'     => 'password',
                'label'    => 'API Key',
                'required' => false,
                'default'  => '',
            ],
            'username' => [
                'type'         => 'text',
                'label'        => 'SMTP Username',
                'required'     => false,
                'default'      => '',
                'visible_when' => [
                    'key'   => 'delivery_mode',
                    'value' => 'smtp',
                ],
            ],
            'password' => [
                'type'         => 'password',
                'label'        => 'SMTP Password',
                'required'     => false,
                'default'      => '',
                'visible_when' => [
                    'key'   => 'delivery_mode',
                    'value' => 'smtp',
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
            'api_key'       => 'required_if:delivery_mode,api|string',
            'username'      => 'required_if:delivery_mode,smtp|string',
            'password'      => 'required_if:delivery_mode,smtp|string',
            'from_email'    => 'required|email',
            'from_name'     => 'required|string|max:255',
        ];
    }
}

