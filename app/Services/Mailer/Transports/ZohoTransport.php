<?php

/**
 * Zoho Mail transport.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer\Transports;

use BooleanSmtp\Contracts\TransportContract;

/**
 * Sends mail through Zoho Mail. Supports two delivery modes: `api` (Zoho OAuth) and `smtp` (SMTP
 * with an email address and password or app password), across Zoho's regional data centers.
 *
 * @since 1.0.0
 */
class ZohoTransport implements TransportContract
{
    /**
     * SMTP presets available for this transport, keyed by preset id, each a [host, port, SMTPSecure] tuple.
     *
     * @since 1.0.0
     * @var array<string, array{0: string, 1: int, 2: string}>
     */
    private const SMTP_PRESETS = [
        'zoho_587' => ['smtp.zoho.com', 587, 'tls'],
        'zoho_465' => ['smtp.zoho.com', 465, 'ssl'],
    ];

    /**
     * Zoho regional data center domain suffixes, keyed by region code.
     *
     * @since 1.0.0
     * @var array<string, string>
     */
    private const REGION_MAP = [
        'us' => 'zoho.com',
        'eu' => 'zoho.eu',
        'in' => 'zoho.in',
        'cn' => 'zoho.com.cn',
        'au' => 'zoho.com.au',
        'jp' => 'zoho.jp',
        'ca' => 'zohocloud.ca',
    ];

    /**
     * Get the transport driver identifier.
     *
     * @since 1.0.0
     *
     * @return string Always `zoho`.
     */
    public function getDriver(): string
    {
        return 'zoho';
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
        return 'Zoho Mail';
    }

    /**
     * Resolve the SMTP host for the connection's configured region.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $settings Connection settings; falls back to the US region when absent or unrecognized.
     * @return string
     */
    private static function resolveSmtpHost(array $settings): string
    {
        $region = $settings['region'] ?? 'us';
        $domain = self::REGION_MAP[$region] ?? self::REGION_MAP['us'];

        return 'smtp.' . $domain;
    }

    /**
     * Configure PHPMailer for Zoho Mail's regional SMTP endpoint.
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

        $preset = $settings['smtp_preset'] ?? 'zoho_587';
        $triple = self::SMTP_PRESETS[$preset] ?? self::SMTP_PRESETS['zoho_587'];

        // Override host with region-specific host
        $host = self::resolveSmtpHost($settings);

        $phpmailer->isSMTP();
        $phpmailer->Host       = $host;
        $phpmailer->Port       = $triple[1];
        $phpmailer->SMTPSecure = $triple[2];
        $phpmailer->SMTPAuth   = true;
        $phpmailer->Username   = $settings['username'] ?? ($settings['from_email'] ?? '');
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
        $apiMode = ($settings['delivery_mode'] ?? 'smtp') === 'api';

        if ($apiMode) {
            if (empty($settings['client_id'])) {
                $errors['client_id'] = 'Client ID is required for Zoho OAuth.';
            }
            if (empty($settings['client_secret'])) {
                $errors['client_secret'] = 'Client Secret is required for Zoho OAuth.';
            }
        } else {
            $username = $settings['username'] ?? ($settings['from_email'] ?? '');
            if (empty($username)) {
                $errors['username'] = 'Zoho email (SMTP username) is required.';
            }
            if (empty($settings['password'])) {
                $errors['password'] = 'Password or App Password is required.';
            }
            $preset = $settings['smtp_preset'] ?? 'zoho_587';
            if (! isset(self::SMTP_PRESETS[$preset])) {
                $errors['smtp_preset'] = 'Invalid Zoho SMTP endpoint.';
            }
        }

        $region = $settings['region'] ?? 'us';
        if (! isset(self::REGION_MAP[$region])) {
            $errors['region'] = 'Invalid Zoho region.';
        }

        if ($apiMode && trim((string) ($settings['from_email'] ?? '')) === '') {
            $errors['from_email'] = 'From email is required (Sender Settings).';
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
            'region' => [
                'type'     => 'select',
                'label'    => 'Zoho Region',
                'required' => true,
                'default'  => 'us',
                'options'  => [
                    'us' => 'United States (.com)',
                    'eu' => 'Europe (.eu)',
                    'in' => 'India (.in)',
                    'cn' => 'China (.com.cn)',
                    'au' => 'Australia (.com.au)',
                    'jp' => 'Japan (.jp)',
                    'ca' => 'Canada (.ca)',
                ],
            ],
            'delivery_mode' => [
                'type'     => 'select',
                'label'    => 'Delivery Method',
                'required' => true,
                'default'  => 'api',
                'options'  => [
                    'api'  => 'OAuth (Zoho API)',
                    'smtp' => 'SMTP',
                ],
            ],
            'smtp_preset' => [
                'type'         => 'select',
                'label'        => 'SMTP endpoint',
                'required'     => false,
                'default'      => 'zoho_587',
                'visible_when' => [
                    'key'   => 'delivery_mode',
                    'value' => 'smtp',
                ],
                'options'      => [
                    'zoho_587' => 'smtp.zoho.com:587 (TLS)',
                    'zoho_465' => 'smtp.zoho.com:465 (SSL)',
                ],
            ],
            'username' => [
                'type'         => 'text',
                'label'        => 'Zoho email (SMTP username)',
                'required'     => false,
                'default'      => '',
                'visible_when' => [
                    'key'   => 'delivery_mode',
                    'value' => 'smtp',
                ],
            ],
            'password' => [
                'type'         => 'password',
                'label'        => 'Password or App Password',
                'required'     => false,
                'default'      => '',
                'visible_when' => [
                    'key'   => 'delivery_mode',
                    'value' => 'smtp',
                ],
            ],
            'client_id' => [
                'type'         => 'text',
                'label'        => 'Client ID',
                'required'     => true,
                'default'      => '',
                'visible_when' => [
                    'key'   => 'delivery_mode',
                    'value' => 'api',
                ],
            ],
            'client_secret' => [
                'type'         => 'password',
                'label'        => 'Client Secret',
                'required'     => true,
                'default'      => '',
                'visible_when' => [
                    'key'   => 'delivery_mode',
                    'value' => 'api',
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
            'api'  => 'OAuth (Zoho API)',
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
            'region'        => 'required|in:' . implode(',', array_keys(self::REGION_MAP)),
            'from_email'    => 'required|email',
            'from_name'     => 'nullable|string|max:255',
            'client_id'     => 'required_if:delivery_mode,api|string',
            'client_secret' => 'required_if:delivery_mode,api|string',
            'password'      => 'required_if:delivery_mode,smtp|string',
            'username'      => 'required_if:delivery_mode,smtp|email',
        ];
    }
}

