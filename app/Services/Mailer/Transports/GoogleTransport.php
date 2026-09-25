<?php

/**
 * Google / Gmail mail transport.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer\Transports;

use BooleanSmtp\Contracts\TransportContract;

/**
 * Sends mail through Google/Gmail. Supports two delivery modes in the free plugin: `api` (Gmail
 * API over HTTPS, OAuth) and `smtp` (SMTP with an app password); add-ons may register further
 * delivery modes through the `boolean_smtp_google_*` filters below.
 *
 * @since 1.0.0
 */
class GoogleTransport implements TransportContract {
    /**
     * SMTP presets available for this transport, keyed by preset id, each a [host, port, SMTPSecure] tuple.
     *
     * The `gmail_relay_*` presets point at Google Workspace's SMTP Relay service
     * (smtp-relay.gmail.com) rather than the standard smtp.gmail.com endpoint. They are
     * Workspace-only, require the sending server's IP to be allow-listed in the Workspace Admin
     * console (Apps > Google Workspace > Gmail > Routing > SMTP relay), and raise the daily send
     * ceiling above the standard 2,000/day API/SMTP limit. They use the same {@see self::configure()}
     * PHPMailer SMTP path as the standard presets; only the host/port/encryption triple differs.
     *
     * @since 1.0.0
     * @var array<string, array{0: string, 1: int, 2: string}>
     */
    private const SMTP_PRESETS = [
        'gmail_tls_587'   => ['smtp.gmail.com', 587, 'tls'],
        'gmail_ssl_465'   => ['smtp.gmail.com', 465, 'ssl'],
        'gmail_relay_587' => ['smtp-relay.gmail.com', 587, 'tls'],
        'gmail_relay_465' => ['smtp-relay.gmail.com', 465, 'ssl']
    ];

    /**
     * Get the transport driver identifier.
     *
     * @since 1.0.0
     *
     * @return string Always `google`.
     */
    public function getDriver(): string {
        return 'google';
    }

    /**
     * Get the human-readable transport name shown in the admin UI.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function getName(): string {
        return 'Google / Gmail';
    }

    /**
     * Resolve the mailbox address used as the SMTP username and for validation.
     *
     * Reads the Sender Settings `from_email` field, falling back to the legacy `email` field for
     * connections saved before Sender Settings existed.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $settings Connection settings.
     * @return string
     */
    private static function mailboxAddress(array $settings): string {
        $from = trim((string) ($settings['from_email'] ?? ''));
        if ($from !== '') {
            return $from;
        }

        return trim((string) ($settings['email'] ?? ''));
    }

    /**
     * Configure PHPMailer for Gmail's SMTP endpoint.
     *
     * Does nothing unless the delivery mode is `smtp`: the `api` mode and any add-on-provided mode
     * (such as a hosted OAuth proxy) send over HTTPS instead, bypassing PHPMailer's SMTP transport.
     *
     * @since 1.0.0
     *
     * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance being prepared for sending.
     * @param array<string, mixed>           $settings  Decrypted connection settings; see {@see self::getSettingsSchema()}.
     */
    public function configure(\PHPMailer\PHPMailer\PHPMailer $phpmailer, array $settings): void {
        $mode = (string) ($settings['delivery_mode'] ?? 'api');
        // Only 'smtp' needs a real PHPMailer SMTP/DSN configuration -- 'api' and any add-on-provided
        // mode (e.g. Pro's One Click) send over HTTPS instead, so both skip this unconditionally.
        if ($mode !== 'smtp') {
            return;
        }

        $preset  = $settings['smtp_preset'] ?? 'gmail_tls_587';
        $triple  = self::SMTP_PRESETS[$preset] ?? self::SMTP_PRESETS['gmail_tls_587'];
        $mailbox = self::mailboxAddress($settings);

        $phpmailer->isSMTP();
        $phpmailer->Host       = $triple[0];
        $phpmailer->Port       = $triple[1];
        $phpmailer->SMTPSecure = $triple[2];
        $phpmailer->SMTPAuth   = true;
        $phpmailer->Username   = $mailbox;
        $phpmailer->Password   = $settings['password'] ?? '';

        if ($mailbox !== '') {
            $phpmailer->From   = $mailbox;
            $phpmailer->Sender = $mailbox;
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
    public function validateSettings(array $settings): array {
        $errors  = [];
        $mode    = (string) ($settings['delivery_mode'] ?? 'api');
        $mailbox = self::mailboxAddress($settings);

        if ($mode === 'api') {
            if (empty($settings['client_id'])) {
                $errors['client_id'] = 'Client ID is required for Gmail API.';
            }
            if (empty($settings['client_secret'])) {
                $errors['client_secret'] = 'Client Secret is required for Gmail API.';
            }
            if ($mailbox === '') {
                $errors['from_email'] = 'From email is required (Sender Settings).';
            }
            return $errors;
        }

        if ($mode === 'smtp') {
            // SMTP: app password only (no Google Cloud OAuth credentials).
            if ($mailbox === '') {
                $errors['from_email'] = 'From email is required (Sender Settings).';
            }
            if (empty($settings['password'])) {
                $errors['password'] = 'App password is required.';
            }
            $preset = $settings['smtp_preset'] ?? 'gmail_tls_587';
            if (!isset(self::SMTP_PRESETS[$preset])) {
                $errors['smtp_preset'] = 'Invalid SMTP endpoint preset.';
            }
            return $errors;
        }

        // Any other delivery mode (e.g. Pro's One Click) is entirely validated by whichever
        // add-on registered it via the boolean_smtp_google_delivery_modes filter -- see
        // getSettingsSchema()/getValidationRules() for the matching schema/rule filters.
        /**
         * Filters validation errors for a Google/Gmail connection using a delivery mode not
         * implemented by the free transport.
         *
         * Fires only when the delivery mode is neither `api` nor `smtp` (a mode registered by an
         * add-on through the `boolean_smtp_google_delivery_modes` filter). Return the validation
         * errors for that mode, keyed by field name.
         *
         * @since 1.0.0
         *
         * @param array<string, string> $errors   Validation errors keyed by field name; empty until a listener adds to it.
         * @param string                $mode     The connection's delivery mode.
         * @param array<string, mixed>  $settings Full connection settings being validated.
         * @return array<string, string> The filtered errors.
         */
        return \apply_filters('boolean_smtp_google_validate_settings', $errors, $mode, $settings);
    }

    /**
     * Get the settings schema used to render the connection form in the admin UI.
     *
     * @since 1.0.0
     *
     * @return array<string, array{type: string, label: string, required: bool, default?: mixed}>
     */
    public function getSettingsSchema(): array {
        $schema = [
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
                'required' => false,
                'default'  => ''
            ],
            'force_from_name'       => [
                'type'     => 'checkbox',
                'label'    => 'Force From name',
                'required' => false,
                'default'  => false
            ],
            'delivery_mode'         => [
                'type'     => 'select',
                'label'    => 'Delivery',
                'required' => true,
                'default'  => 'api',
                'options'  => [
                    'api'  => 'Gmail API (HTTPS, OAuth)',
                    'smtp' => 'SMTP (app password)',
                ]
            ],
            'smtp_preset'           => [
                'type'         => 'select',
                'label'        => 'SMTP endpoint',
                'required'     => false,
                'default'      => 'gmail_tls_587',
                'visible_when' => [
                    'key'   => 'delivery_mode',
                    'value' => 'smtp'
                ],
                'options'      => [
                    'gmail_tls_587'   => 'smtp.gmail.com:587 (TLS)',
                    'gmail_ssl_465'   => 'smtp.gmail.com:465 (SSL)',
                    'gmail_relay_587' => 'smtp-relay.gmail.com:587 (Workspace Relay, TLS)',
                    'gmail_relay_465' => 'smtp-relay.gmail.com:465 (Workspace Relay, SSL)'
                ],
                'help'         => 'Workspace Relay presets require the sending server\'s IP to be allow-listed in your Workspace Admin console (Apps > Google Workspace > Gmail > Routing > SMTP relay). They raise the daily send ceiling well above the standard 2,000/day API and SMTP limit -- see the setup guide.'
            ],
            'password'              => [
                'type'         => 'password',
                'label'        => 'App Password',
                'required'     => false,
                'default'      => '',
                'visible_when' => [
                    'key'   => 'delivery_mode',
                    'value' => 'smtp'
                ]
            ],
            'client_id'             => [
                'type'     => 'text',
                'label'    => 'Client ID',
                'required' => true,
                'default'  => '',
                'visible_when' => [
                    'key'   => 'delivery_mode',
                    'value' => 'api'
                ]
            ],
            'client_secret'         => [
                'type'     => 'password',
                'label'    => 'Client Secret',
                'required' => true,
                'default'  => '',
                'visible_when' => [
                    'key'   => 'delivery_mode',
                    'value' => 'api'
                ]
            ]
        ];

        // Add-on extension point: Pro's One Click adds a 'one_click' delivery_mode option plus its
        // own one_click_bearer_token/one_click_status fields here -- see GoogleSchemaExtender.
        /**
         * Filters the Google/Gmail connection settings schema before it is returned to the admin UI.
         *
         * Allows an add-on to extend the schema with fields for an additional delivery mode (for
         * example, a hosted OAuth proxy). Return the schema, keyed by field name.
         *
         * @since 1.0.0
         *
         * @param array<string, array{type: string, label: string, required: bool, default?: mixed}> $schema Settings schema keyed by field name.
         * @return array<string, array{type: string, label: string, required: bool, default?: mixed}> The filtered schema.
         */
        return \apply_filters('boolean_smtp_google_settings_schema', $schema);
    }

    /**
     * Get the supported delivery modes for this transport.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Delivery mode key mapped to its label.
     */
    public function getDeliveryModes(): array {
        /**
         * Filters the delivery modes available for the Google/Gmail transport.
         *
         * Return the delivery modes, adding an entry for any mode an add-on registers.
         *
         * @since 1.0.0
         *
         * @param array<string, string> $modes Delivery mode key mapped to its label.
         * @return array<string, string> The filtered modes.
         */
        return \apply_filters('boolean_smtp_google_delivery_modes', [
            'api'  => 'Gmail API (HTTPS, OAuth)',
            'smtp' => 'SMTP (app password)',
        ]);
    }

    /**
     * Get the SMTP host/port/encryption presets offered in the admin UI.
     *
     * @since 1.0.0
     *
     * @return array<string, array{host: string, port: int, encryption: string, label: string}>
     */
    public function getSmtpPresets(): array {
        $presets = [];
        foreach (self::SMTP_PRESETS as $key => [$host, $port, $enc]) {
            $presets[$key] = [
                'host'       => $host,
                'port'       => $port,
                'encryption' => $enc,
                'label'      => "{$host}:{$port} (" . strtoupper($enc) . ")"
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
    public function getValidationRules(): array {
        $rules = [
            'delivery_mode' => 'required|in:' . implode(',', array_keys(self::getDeliveryModes())),
            'from_email'    => 'required|email',
            'from_name'     => 'nullable|string|max:255',
            'client_id'     => 'required_if:delivery_mode,api|string',
            'client_secret' => 'required_if:delivery_mode,api|string',
            'password'      => 'required_if:delivery_mode,smtp|string'
        ];

        // Add-on extension point: Pro's One Click adds a one_click_bearer_token rule here.
        /**
         * Filters the validation rules for Google/Gmail connection settings.
         *
         * Return the rules, adding entries for any fields an add-on introduces.
         *
         * @since 1.0.0
         *
         * @param array<string, string> $rules Field name mapped to its validation rule string.
         * @return array<string, string> The filtered rules.
         */
        return \apply_filters('boolean_smtp_google_validation_rules', $rules);
    }
}
