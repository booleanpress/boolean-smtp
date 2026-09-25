<?php

/**
 * Postmark mail transport.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer\Transports;

use BooleanSmtp\Contracts\TransportContract;

/**
 * Sends mail through Postmark. Supports two delivery modes: `api` (HTTP API) and `smtp` (SMTP
 * with the server API token used as both username and password).
 *
 * @since 1.0.0
 */
class PostmarkTransport implements TransportContract {
    /**
     * Get the transport driver identifier.
     *
     * @since 1.0.0
     *
     * @return string Always `postmark`.
     */
    public function getDriver(): string {
        return 'postmark';
    }

    /**
     * Get the human-readable transport name shown in the admin UI.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function getName(): string {
        return 'Postmark';
    }

    /**
     * Configure PHPMailer for Postmark's SMTP endpoint.
     *
     * Does nothing when the connection uses API delivery mode, since API sends bypass PHPMailer's
     * SMTP transport entirely. When a message stream is configured, adds the `X-PM-Message-Stream`
     * header so Postmark routes the message to that stream.
     *
     * @since 1.0.0
     *
     * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance being prepared for sending.
     * @param array<string, mixed>           $settings  Decrypted connection settings; see {@see self::getSettingsSchema()}.
     */
    public function configure(\PHPMailer\PHPMailer\PHPMailer $phpmailer, array $settings): void {
        if (($settings['delivery_mode'] ?? 'api') === 'api') {
            return;
        }

        $phpmailer->isSMTP();
        $phpmailer->Host       = 'smtp.postmarkapp.com';
        $phpmailer->Port       = 587;
        $phpmailer->SMTPSecure = 'tls';
        $phpmailer->SMTPAuth   = true;
        $phpmailer->Username   = $settings['api_key'] ?? '';
        $phpmailer->Password   = $settings['api_key'] ?? '';

        if (isset($settings['from_email'])) {
            $phpmailer->From   = $settings['from_email'];
            $phpmailer->Sender = $settings['from_email'];
        }
        if (isset($settings['from_name'])) {
            $phpmailer->FromName = $settings['from_name'];
        }

        if (!empty($settings['message_stream'])) {
            $phpmailer->addCustomHeader('X-PM-Message-Stream', $settings['message_stream']);
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
    public function validateSettings(array $settings): array {
        $errors  = [];
        $apiMode = ($settings['delivery_mode'] ?? 'api') === 'api';

        if (empty(trim((string) ($settings['from_email'] ?? '')))) {
            $errors['from_email'] = 'From Email is required.';
        }
        if (trim((string) ($settings['from_name'] ?? '')) === '') {
            $errors['from_name'] = 'From Name is required.';
        }
        if (empty($settings['api_key'])) {
            $errors['api_key'] = 'Postmark Server API Token is required.';
        }

        if (! $apiMode) {
            $preset = $settings['smtp_preset'] ?? 'postmark_tls_587';
            $presets = $this->getSmtpPresets();
            if (! isset($presets[$preset])) {
                $errors['smtp_preset'] = 'Invalid Postmark SMTP endpoint.';
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
    public function getSettingsSchema(): array {
        return [
            'delivery_mode'         => [
                'type'     => 'select',
                'label'    => 'Delivery Method',
                'required' => true,
                'default'  => 'api',
                'options'  => [
                    'api'  => 'HTTP API',
                    'smtp' => 'SMTP',
                ],
            ],
            'from_email'            => [
                'type'     => 'email',
                'label'    => 'From Email',
                'required' => true,
                'default'  => ''
            ],
            'force_from_email'      => [
                'type'     => 'checkbox',
                'label'    => 'Force From email',
                'required' => false,
                'default'  => false
            ],
            'return_path'           => [
                'type'     => 'checkbox',
                'label'    => 'Set envelope sender (Return-Path) from From address',
                'required' => false,
                'default'  => true
            ],
            'from_name'             => [
                'type'     => 'text',
                'label'    => 'From Name',
                'required' => true,
                'default'  => ''
            ],
            'force_from_name'       => [
                'type'     => 'checkbox',
                'label'    => 'Force From name',
                'required' => false,
                'default'  => false
            ],
            'api_key'               => [
                'type'     => 'password',
                'label'    => 'Server API Token',
                'required' => true,
                'default'  => ''
            ],
            'message_stream'        => [
                'type'     => 'text',
                'label'    => 'Message Stream',
                'required' => false,
                'default'  => '',
                'help'     => 'Postmark message stream ID. Leave empty to send through the default transactional stream.'
            ],
            'smtp_preset'           => [
                'type'         => 'select',
                'label'        => 'SMTP Server',
                'required'     => false,
                'default'      => 'postmark_tls_587',
                'visible_when' => [
                    'key'   => 'delivery_mode',
                    'value' => 'smtp',
                ],
                'options'      => [
                    'postmark_tls_587' => 'smtp.postmarkapp.com:587 (TLS)'
                ]
            ]
        ];
    }

    /**
     * Get the supported delivery modes for this transport.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Delivery mode key mapped to its label.
     */
    public function getDeliveryModes(): array {
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
    public function getSmtpPresets(): array {
        return [
            'postmark_tls_587' => [
                'host'       => 'smtp.postmarkapp.com',
                'port'       => 587,
                'encryption' => 'tls',
                'label'      => 'smtp.postmarkapp.com:587 (TLS)'
            ]
        ];
    }

    /**
     * Get validation rules for connection settings.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Field name mapped to its validation rule string.
     */
    public function getValidationRules(): array {
        return [
            'delivery_mode' => 'required|in:smtp,api',
            'api_key'       => 'required|string',
            'from_email'    => 'required|email',
            'from_name'     => 'required|string|max:255',
        ];
    }
}
