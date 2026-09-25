<?php

/**
 * Microsoft Outlook / Office 365 mail transport.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer\Transports;

use BooleanSmtp\Contracts\TransportContract;

/**
 * Sends mail through Microsoft Outlook / Office 365 using delegated OAuth via the Microsoft Graph
 * API. The free plugin supports a single delivery mode, `api`; add-ons may register further
 * delivery modes (such as a hosted OAuth proxy or an application-permission mode) through the
 * `boolean_smtp_outlook_*` filters below, the same pattern `GoogleTransport` uses for its
 * `boolean_smtp_google_*` filters.
 *
 * SMTP delivery (mailbox password / Basic Authentication against smtp.office365.com) is not
 * offered: Microsoft has disabled SMTP AUTH Basic Authentication by default for new tenants and is
 * phasing it out for existing tenants, with no password-based replacement comparable to Google's
 * App Password. Sending through the Microsoft Graph API achieves the same outcome without that
 * expiration risk.
 *
 * @since 1.0.0
 */
class OutlookTransport implements TransportContract {
    /**
     * Get the transport driver identifier.
     *
     * @since 1.0.0
     *
     * @return string Always `outlook`.
     */
    public function getDriver(): string {
        return 'outlook';
    }

    /**
     * Get the human-readable transport name shown in the admin UI.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function getName(): string {
        return 'Microsoft Outlook / Office 365';
    }

    /**
     * Resolve the mailbox address used as the Graph `sendMail`/`Mail.Send.Shared` target and for validation.
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
     * Configure PHPMailer for this transport.
     *
     * Every Outlook delivery mode (API, and any add-on-provided mode) sends over the Microsoft
     * Graph HTTPS API through the dedicated Graph mail sender, never PHPMailer's own SMTP
     * transport, so there is nothing to configure on `$phpmailer`.
     *
     * @since 1.0.0
     *
     * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance being prepared for sending.
     * @param array<string, mixed>           $settings  Decrypted connection settings; see {@see self::getSettingsSchema()}.
     */
    public function configure(\PHPMailer\PHPMailer\PHPMailer $phpmailer, array $settings): void {
        // Every Outlook delivery mode (API, One Click, Application Permission) sends over
        // Microsoft Graph HTTPS via MicrosoftGraphMailSender, never PHPMailer's own SMTP
        // transport -- nothing to configure on $phpmailer.
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
        $mode = (string) ($settings['delivery_mode'] ?? 'api');

        if ($mode !== 'api') {
            // Any add-on-provided delivery mode is validated entirely by whichever add-on
            // registered it via the boolean_smtp_outlook_delivery_modes filter, reached through
            // this same filter dispatch. An unrecognized mode string (no add-on installed)
            // validates as "no errors" -- the same fail-open-on-schema/fail-closed-on-send
            // pattern GoogleTransport uses; the Graph mail sender is what actually refuses to send.
            /**
             * Filters validation errors for an Outlook connection using a delivery mode not
             * implemented by the free transport.
             *
             * Fires only when the delivery mode is not `api` (a mode registered by an add-on
             * through the `boolean_smtp_outlook_delivery_modes` filter). Return the validation
             * errors for that mode, keyed by field name.
             *
             * @since 1.0.0
             *
             * @param array<string, string> $errors   Validation errors keyed by field name; empty until a listener adds to it.
             * @param string                $mode     The connection's delivery mode.
             * @param array<string, mixed>  $settings Full connection settings being validated.
             * @return array<string, string> The filtered errors.
             */
            return \apply_filters('boolean_smtp_outlook_validate_settings', [], $mode, $settings);
        }

        $errors  = [];
        $mailbox = self::mailboxAddress($settings);

        if (empty($settings['client_id'])) {
            $errors['client_id'] = 'Application (client) ID is required for Microsoft Graph.';
        }
        if (empty($settings['client_secret'])) {
            $errors['client_secret'] = 'Client secret is required for Microsoft Graph.';
        }
        if (empty($settings['tenant_id'])) {
            $errors['tenant_id'] = 'Directory (tenant) ID is required for Microsoft Graph.';
        }
        if ($mailbox === '') {
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
    public function getSettingsSchema(): array {
        $schema = [
            'from_email'             => [
                'type'     => 'email',
                'label'    => 'From Email',
                'required' => true,
                'default'  => ''
            ],
            'force_from_email'       => [
                'type'     => 'checkbox',
                'label'    => 'Force From email',
                'required' => false,
                'default'  => false
            ],
            'return_path'            => [
                'type'     => 'checkbox',
                'label'    => 'Set envelope sender (Return-Path) from From address',
                'required' => false,
                'default'  => true
            ],
            'from_name'              => [
                'type'     => 'text',
                'label'    => 'From Name',
                'required' => false,
                'default'  => ''
            ],
            'force_from_name'        => [
                'type'     => 'checkbox',
                'label'    => 'Force From name',
                'required' => false,
                'default'  => false
            ],
            'delivery_mode'          => [
                'type'     => 'select',
                'label'    => 'Delivery',
                'required' => true,
                'default'  => 'api',
                'options'  => $this->getDeliveryModes()
            ],
            'client_id'              => [
                'type'         => 'text',
                'label'        => 'Client ID',
                'required'     => true,
                'default'      => '',
                'visible_when' => [
                    'key'   => 'delivery_mode',
                    'value' => 'api'
                ]
            ],
            'client_secret'          => [
                'type'         => 'password',
                'label'        => 'Client Secret',
                'required'     => true,
                'default'      => '',
                'visible_when' => [
                    'key'   => 'delivery_mode',
                    'value' => 'api'
                ]
            ],
            'tenant_id'              => [
                'type'         => 'text',
                'label'        => 'Tenant ID',
                'required'     => false,
                'default'      => 'common',
                'placeholder'  => 'common',
                'visible_when' => [
                    'key'   => 'delivery_mode',
                    'value' => 'api'
                ],
                'helpDisplay' => 'tooltip',
                'help'        => 'Leave as "common" for any Microsoft account (most setups). Use your organization\'s Tenant ID (Entra admin center -> Overview) only to restrict sign-in to your organization.'
            ],
            'send_as_shared_mailbox' => [
                'type'         => 'checkbox',
                'label'        => 'Send as a shared mailbox',
                'required'     => false,
                'default'      => false,
                'visible_when' => [
                    'key'   => 'delivery_mode',
                    'value' => 'api'
                ],
                'standalone'  => true,
                'helpDisplay' => 'tooltip',
                'help'        => 'Send as the shared mailbox in From Email instead of your own. Requires Mail.Send.Shared and Exchange "Send As" rights on that mailbox.'
            ]
        ];

        /**
         * Filters the Outlook connection settings schema before it is returned to the admin UI.
         *
         * Allows an add-on to extend the schema with fields for an additional delivery mode (for
         * example, a hosted OAuth proxy or an application-permission mode). Return the schema,
         * keyed by field name.
         *
         * @since 1.0.0
         *
         * @param array<string, array{type: string, label: string, required: bool, default?: mixed}> $schema Settings schema keyed by field name.
         * @return array<string, array{type: string, label: string, required: bool, default?: mixed}> The filtered schema.
         */
        return \apply_filters('boolean_smtp_outlook_settings_schema', $schema);
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
         * Filters the delivery modes available for the Outlook transport.
         *
         * Return the delivery modes, adding an entry for any mode an add-on registers.
         *
         * @since 1.0.0
         *
         * @param array<string, string> $modes Delivery mode key mapped to its label.
         * @return array<string, string> The filtered modes.
         */
        return \apply_filters('boolean_smtp_outlook_delivery_modes', [
            'api' => 'API (Manual App)'
        ]);
    }

    /**
     * Get the SMTP host/port/encryption presets offered in the admin UI.
     *
     * @since 1.0.0
     *
     * @return array<string, array{host: string, port: int, encryption: string, label: string}> Always empty; this transport has no SMTP mode.
     */
    public function getSmtpPresets(): array {
        return [];
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
            'delivery_mode'          => 'required|in:' . implode(',', array_keys($this->getDeliveryModes())),
            'from_email'             => 'required|email',
            'from_name'              => 'nullable|string|max:255',
            'tenant_id'              => 'required_if:delivery_mode,api|string',
            'client_id'              => 'required_if:delivery_mode,api|string',
            'client_secret'          => 'required_if:delivery_mode,api|string',
            'send_as_shared_mailbox' => 'nullable|boolean'
        ];

        /**
         * Filters the validation rules for Outlook connection settings.
         *
         * Return the rules, adding entries for any fields an add-on introduces.
         *
         * @since 1.0.0
         *
         * @param array<string, string> $rules Field name mapped to its validation rule string.
         * @return array<string, string> The filtered rules.
         */
        return \apply_filters('boolean_smtp_outlook_validation_rules', $rules);
    }
}
