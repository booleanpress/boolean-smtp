<?php

/**
 * Amazon SES mail transport.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer\Transports;

use BooleanSmtp\Contracts\TransportContract;
use BooleanSmtp\Services\Mailer\Api\SesEndpoints;
use BooleanSmtp\Services\Settings\ConstantSettingsResolver;

/**
 * Sends mail through Amazon SES. Supports two delivery modes: `smtp` (SMTP with an access key and
 * secret, or an IAM credential selected through the `boolean_smtp_ses_iam_selector` filter) and
 * `api` (HTTPS API, SendRawEmail), each configurable per AWS region.
 *
 * @since 1.0.0
 */
class SesTransport implements TransportContract {
    /**
     * AWS SES regions with SMTP support; the host of each is {@see SesEndpoints::smtpHost()}.
     *
     * Both eu-north-1 and ap-northeast-3 have full SES API and SMTP support, confirmed against
     * AWS's own endpoint reference, the same as every other entry.
     *
     * @since 1.0.0
     * @var list<string>
     */
    private const SMTP_REGIONS = [
        'us-east-1',
        'us-east-2',
        'us-west-1',
        'us-west-2',
        'eu-west-1',
        'eu-central-1',
        'ap-southeast-1',
        'ap-southeast-2',
        'ap-south-1',
        'ap-northeast-1',
        'ap-northeast-2',
        'ca-central-1',
        'sa-east-1',
        'eu-west-2',
        'eu-west-3',
        'eu-north-1',
        'ap-northeast-3',
    ];

    /**
     * AWS SES regions that offer the SES API but no SMTP endpoint, confirmed against AWS's own
     * endpoint reference.
     *
     * Kept separate from {@see self::SMTP_REGIONS}, which doubles as the SMTP host lookup and the
     * SMTP preset generator: merging these regions in directly would fabricate SMTP endpoints for
     * regions AWS does not offer SMTP in.
     *
     * @since 1.0.0
     * @var list<string>
     */
    private const API_ONLY_REGIONS = [
        'af-south-1', 'ap-south-2', 'ap-southeast-3', 'ap-southeast-5',
        'ca-west-1', 'eu-south-1', 'eu-central-2', 'il-central-1',
        'me-south-1', 'me-central-1',
    ];

    /**
     * Get the transport driver identifier.
     *
     * @since 1.0.0
     *
     * @return string Always `ses`.
     */
    public function getDriver(): string {
        return 'ses';
    }

    /**
     * Get the human-readable transport name shown in the admin UI.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function getName(): string {
        return 'Amazon SES';
    }

    /**
     * Configure PHPMailer for Amazon SES's SMTP endpoint.
     *
     * Does nothing when the connection uses API delivery mode, since API sends bypass PHPMailer's
     * SMTP transport entirely. Resolves the host from the region (or an explicit SMTP preset,
     * custom endpoint, or host/port/encryption override, in that order of precedence) and applies
     * keep-alive when enabled for persistent connections across batch sends.
     *
     * @since 1.0.0
     *
     * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance being prepared for sending.
     * @param array<string, mixed>           $settings  Decrypted connection settings; see {@see self::getSettingsSchema()}.
     */
    public function configure(\PHPMailer\PHPMailer\PHPMailer $phpmailer, array $settings): void {
        if (($settings['delivery_mode'] ?? 'smtp') === 'api') {
            return;
        }

        $resolved   = $this->resolveModeSettings($settings);
        $region     = $resolved['region'];
        $accessKey  = $resolved['access_key'];
        $secret     = $resolved['secret'];
        $smtpPreset = (string) ($settings['smtp_preset'] ?? '');
        $host       = SesEndpoints::smtpHost($region);
        $port       = 587;
        $secure     = 'tls';

        if ($smtpPreset !== '') {
            if (\preg_match('/^ses_([a-z0-9-]+)_(465|587)$/', $smtpPreset, $matches) === 1) {
                $port   = (int) ($matches[2] ?? 587);
                $region = (string) ($matches[1] ?? $region);
                $host   = SesEndpoints::smtpHost($region);
                $secure = $port === 465 ? 'ssl' : 'tls';
            }
        }

        // Custom endpoint override for VPC PrivateLink or enterprise scenarios
        $customEndpoint = trim((string) ($settings['custom_endpoint'] ?? ''));
        if ($customEndpoint !== '') {
            $host = $customEndpoint;
        }

        $hostOverride = trim((string) ($settings['smtp_host'] ?? ''));
        if ($hostOverride !== '') {
            $host = $hostOverride;
        }
        $portOverride = (int) ($settings['smtp_port'] ?? 0);
        if ($portOverride > 0) {
            $port = $portOverride;
        }
        $encryptionOverride = strtolower(trim((string) ($settings['smtp_encryption'] ?? '')));
        if (\in_array($encryptionOverride, ['tls', 'ssl'], true)) {
            $secure = $encryptionOverride;
        }

        $phpmailer->isSMTP();
        $phpmailer->Host       = $host;
        $phpmailer->Port       = $port;
        $phpmailer->SMTPSecure = $secure;
        $phpmailer->SMTPAuth   = true;
        $phpmailer->Username   = $accessKey;
        $phpmailer->Password   = $secret;

        // Keeps the SMTP connection open across batch sends instead of reconnecting per message.
        $keepAlive = (bool) ($settings['keep_alive'] ?? false);
        if ($keepAlive) {
            $phpmailer->SMTPKeepAlive = true;
        }

        if (isset($settings['from_email'])) {
            $phpmailer->From   = $settings['from_email'];
            $phpmailer->Sender = $settings['from_email'];
        }
        if (isset($settings['from_name'])) {
            $phpmailer->FromName = $settings['from_name'];
        }
    }

    /**
     * Resolve wp-config/environment credentials and advanced SES settings from constants.
     *
     * Applies only when `key_store` is `wp_config` or `env`, after the settings array has already
     * been decrypted from the database. Also fills in the configuration set, custom API endpoint,
     * SMTP keep-alive flag, and static message tags from `BOOLEANSMTP_AWS_SES_*` constants or
     * environment variables when those settings are not already present.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $settings Connection settings, possibly with unresolved wp-config/env placeholders.
     * @return array<string, mixed> Settings with credentials and advanced fields resolved, when applicable.
     */
    public static function prepareSettings(array $settings): array {
        $keyStore = $settings['key_store'] ?? 'db';
        if ($keyStore !== 'wp_config' && $keyStore !== 'env') {
            return $settings;
        }

        $deliveryMode = (string) ($settings['delivery_mode'] ?? 'api');

        // Routed through the shared resolver (defined()/constant() for wp_config, getenv() for
        // env) instead of hand-rolled defined()-only checks: the old version silently no-op'd
        // for key_store=env, so an SES SMTP connection using "Environment variable" credential
        // storage could never pass save-time validation even though MailerManager's send-time
        // resolution (expectedKeysForDriver('ses')) already supported env vars correctly.
        $credentialKeys = $deliveryMode === 'smtp'
            ? ['smtp_username', 'smtp_password', 'smtp_region', 'smtp_host', 'smtp_port', 'smtp_encryption']
            : ['api_access_key', 'api_secret', 'api_region'];
        $settings = (new ConstantSettingsResolver())->resolve('ses', $settings, $credentialKeys);

        // Configuration Set support (API and SMTP)
        if (!isset($settings['configuration_set']) || $settings['configuration_set'] === '') {
            $settings['configuration_set'] = \defined('BOOLEANSMTP_AWS_SES_CONFIGURATION_SET')
            ? (string) \constant('BOOLEANSMTP_AWS_SES_CONFIGURATION_SET')
            : '';
        }

        // Custom SES API endpoint override (for VPC PrivateLink)
        if (!isset($settings['custom_endpoint']) || $settings['custom_endpoint'] === '') {
            $settings['custom_endpoint'] = \defined('BOOLEANSMTP_AWS_SES_CUSTOM_ENDPOINT')
            ? (string) \constant('BOOLEANSMTP_AWS_SES_CUSTOM_ENDPOINT')
            : '';
        }

        // SES SMTP keep-alive support
        if (!isset($settings['keep_alive'])) {
            $settings['keep_alive'] = \defined('BOOLEANSMTP_AWS_SES_SMTP_KEEP_ALIVE')
            ? (bool) \constant('BOOLEANSMTP_AWS_SES_SMTP_KEEP_ALIVE')
            : false;
        }

        // Static message tags
        if (!isset($settings['static_tags']) || (is_array($settings['static_tags']) && empty($settings['static_tags']))) {
            $staticTagsJson = \defined('BOOLEANSMTP_AWS_SES_STATIC_TAGS')
            ? (string) \constant('BOOLEANSMTP_AWS_SES_STATIC_TAGS')
            : '';
            if ($staticTagsJson !== '') {
                $decoded = @json_decode($staticTagsJson, true);
                if (is_array($decoded)) {
                    $settings['static_tags'] = $decoded;
                }
            }
        }

        return $settings;
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
        $settings     = self::prepareSettings($settings);
        $errors       = [];
        $deliveryMode = (string) ($settings['delivery_mode'] ?? 'api');
        $keyStore     = (string) ($settings['key_store'] ?? 'db');
        $iamSelector  = trim((string) ($settings['iam_selector'] ?? ''));

        $resolved  = $this->resolveModeSettings($settings);
        $accessKey = $resolved['access_key'];
        $secret    = $resolved['secret'];
        $region    = $resolved['region'];

        if (!in_array($deliveryMode, ['smtp', 'api'], true)) {
            $errors['delivery_mode'] = 'Invalid delivery mode. Use SMTP or API.';
        }

        if ($keyStore === 'wp_config' && $iamSelector === '') {
            if ($accessKey === '') {
                $errors[$deliveryMode === 'smtp' ? 'smtp_username' : 'api_access_key'] = $deliveryMode === 'smtp'
                ? 'Define BOOLEANSMTP_AWS_SES_SMTP_USERNAME or BOOLEANSMTP_AWS_ACCESS_KEY_ID in wp-config.php when using wp-config credential store.'
                : 'Define BOOLEANSMTP_AWS_ACCESS_KEY_ID in wp-config.php when using wp-config credential store.';
            }
            if ($secret === '' || $secret === null) {
                $errors[$deliveryMode === 'smtp' ? 'smtp_password' : 'api_secret'] = $deliveryMode === 'smtp'
                ? 'Define BOOLEANSMTP_AWS_SES_SMTP_PASSWORD or BOOLEANSMTP_AWS_SECRET_ACCESS_KEY in wp-config.php when using wp-config credential store.'
                : 'Define BOOLEANSMTP_AWS_SECRET_ACCESS_KEY in wp-config.php when using wp-config credential store.';
            }
        } elseif ($iamSelector === '') {
            if ($accessKey === '') {
                $errors[$deliveryMode === 'smtp' ? 'smtp_username' : 'api_access_key'] = 'Access Key is required.';
            }
            if ($secret === '' || $secret === null) {
                $errors[$deliveryMode === 'smtp' ? 'smtp_password' : 'api_secret'] = 'Secret Key is required.';
            }
        }
        if ($region === '') {
            $errors[$deliveryMode === 'smtp' ? 'smtp_region' : 'api_region'] = 'AWS Region is required.';
        } else {
            // SMTP mode is restricted to SMTP_REGIONS (AWS doesn't offer an SMTP endpoint in every
            // region); API mode additionally allows the API-only regions.
            $validRegions = $deliveryMode === 'smtp' ? self::SMTP_REGIONS : $this->allApiRegions();
            if (!in_array($region, $validRegions, true)) {
                $errors[$deliveryMode === 'smtp' ? 'smtp_region' : 'api_region'] = 'Unsupported AWS Region selected.';
            }
        }
        if ($deliveryMode === 'smtp' && $keyStore === 'db') {
            $preset = trim((string) ($settings['smtp_preset'] ?? ''));
            if ($preset === '') {
                $errors['smtp_preset'] = 'SMTP host:port is required.';
            } elseif (\preg_match('/^ses_[a-z0-9-]+_(465|587)$/', $preset) !== 1) {
                $errors['smtp_preset'] = 'Invalid SMTP host:port selection.';
            }
        }

        if (empty($settings['from_email'])) {
            $errors['from_email'] = 'From Email is required.';
        } elseif (!filter_var((string) $settings['from_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['from_email'] = 'From Email must be a valid email address.';
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
        /**
         * Filters the list of predefined IAM credentials offered in the SES connection form.
         *
         * When non-empty, the schema adds an "IAM Credential Selection" field so a site can pick a
         * predefined credential (for example, one provisioned by a hosting platform) instead of
         * entering an access key and secret directly; see `boolean_smtp_ses_iam_credentials` for
         * where the corresponding secrets are supplied. Return the available identities, keyed by
         * selector id and mapped to their display label.
         *
         * @since 1.0.0
         *
         * @param array<string, string> $identities IAM credential selector id mapped to its display label; empty by default.
         * @return array<string, string> The filtered identities.
         */
        $iamIdentities = \apply_filters('boolean_smtp_ses_iam_selector', []);
        $schema        = [
            'from_email'            => [
                'type'     => 'email',
                'label'    => 'From Email (must be verified in SES)',
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
                    'smtp' => 'SMTP (STARTTLS)',
                    'api'  => 'HTTPS API (SendRawEmail)'
                ],
                'help'     => 'API mode is recommended. Use SMTP only when HTTPS API delivery is not available.'
            ],
            'region'                => [
                'type'     => 'select',
                'label'    => 'AWS Region',
                'required' => true,
                'default'  => 'us-east-1',
                'options'  => array_combine($this->allApiRegions(), $this->allApiRegions()),
                'help'     => 'Use the same region where your SES identities are verified.'
            ],
            'smtp_region'           => [
                'type'     => 'select',
                'label'    => 'SMTP Region',
                'required' => true,
                'default'  => 'us-east-1',
                'options'  => array_combine(self::SMTP_REGIONS, self::SMTP_REGIONS)
            ],
            'api_region'            => [
                'type'     => 'select',
                'label'    => 'API Region',
                'required' => true,
                'default'  => 'us-east-1',
                'options'  => array_combine($this->allApiRegions(), $this->allApiRegions())
            ],
            'smtp_preset'           => [
                'type'     => 'select',
                'label'    => 'SMTP Host:Port',
                'required' => true,
                'default'  => 'ses_us-east-1_587',
                'options'  => $this->smtpPresetOptions()
            ],
            'access_key'            => [
                'type'     => 'text',
                'label'    => 'Access Key ID',
                'required' => true,
                'default'  => '',
                'help'     => 'For SMTP mode, this value is used as the SMTP username.'
            ],
            'secret'                => [
                'type'     => 'password',
                'label'    => 'Secret Access Key',
                'required' => true,
                'default'  => '',
                'help'     => 'For SMTP mode, this value is used as the SMTP password.'
            ],
            'smtp_username'         => [
                'type'     => 'text',
                'label'    => 'SMTP Username',
                'required' => true,
                'default'  => ''
            ],
            'smtp_password'         => [
                'type'     => 'password',
                'label'    => 'SMTP Password',
                'required' => true,
                'default'  => ''
            ],
            'api_access_key'        => [
                'type'     => 'text',
                'label'    => 'API Access Key ID',
                'required' => true,
                'default'  => ''
            ],
            'api_secret'            => [
                'type'     => 'password',
                'label'    => 'API Secret Access Key',
                'required' => true,
                'default'  => ''
            ]
        ];

        if (!empty($iamIdentities) && is_array($iamIdentities)) {
            $schema['iam_selector'] = [
                'type'     => 'select',
                'label'    => 'IAM Credential Selection',
                'required' => false,
                'default'  => '',
                'options'  => $iamIdentities,
                'help'     => 'Select a predefined IAM credential to use. If selected, overrides manually entered keys.'
            ];
        }

        // Configuration Set, custom API endpoint, SMTP keep-alive, and static message tags are
        // Pro-only fields; Pro re-adds these four fields here for licensed users through this same
        // filter. Without Pro installed and licensed, the schema is returned unchanged.
        /**
         * Filters the Amazon SES connection settings schema before it is returned to the admin UI.
         *
         * Allows an add-on to extend the schema with additional fields (for example, Configuration
         * Set, a custom API endpoint, SMTP keep-alive, or static message tags). Return the schema,
         * keyed by field name.
         *
         * @since 1.0.0
         *
         * @param array<string, array{type: string, label: string, required: bool, default?: mixed}> $schema Settings schema keyed by field name.
         * @return array<string, array{type: string, label: string, required: bool, default?: mixed}> The filtered schema.
         */
        return \apply_filters('boolean_smtp_ses_settings_schema', $schema);
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
            'smtp' => 'SMTP (STARTTLS)',
            'api'  => 'HTTPS API (SendRawEmail)'
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
        $presets = [];
        foreach (self::SMTP_REGIONS as $region) {
            $host = SesEndpoints::smtpHost($region);
            $presets["ses_{$region}_587"] = [
                'host'       => $host,
                'port'       => 587,
                'encryption' => 'tls',
                'label'      => "{$host}:587 (STARTTLS)"
            ];
            $presets["ses_{$region}_465"] = [
                'host'       => $host,
                'port'       => 465,
                'encryption' => 'ssl',
                'label'      => "{$host}:465 (SSL/TLS)"
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
        return [
            'access_key' => 'required|string',
            'secret'     => 'required|string|min:20',
            'region'     => 'required|in:' . implode(',', $this->allApiRegions()),
            'from_email' => 'required|email',
            'from_name'  => 'string|max:255'
        ];
    }

    /**
     * Resolve the access key, secret, and region for the connection's delivery mode.
     *
     * Prefers a predefined IAM credential selected through `iam_selector` (see
     * `boolean_smtp_ses_iam_credentials`) over manually entered keys; falls back to the API or SMTP
     * field names depending on delivery mode, with the shared `access_key`/`secret`/`region` fields
     * as a final fallback for connections saved before mode-specific fields existed.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $settings Connection settings.
     * @return array{access_key: string, secret: string, region: string}
     */
    private function resolveModeSettings(array $settings): array {
        $deliveryMode = (string) ($settings['delivery_mode'] ?? 'smtp');
        $iamSelector  = trim((string) ($settings['iam_selector'] ?? ''));

        $accessKey = '';
        $secret    = '';

        if ($iamSelector !== '') {
            /**
             * Filters the predefined IAM credentials available for SES connections to select from.
             *
             * Used to resolve the access key and secret for a connection that selected a predefined
             * credential through the `iam_selector` setting instead of entering keys directly; see
             * `boolean_smtp_ses_iam_selector` for where the selectable list is populated. Return the
             * credentials, keyed by the same selector id, each with `access_key` and `secret` values.
             *
             * @since 1.0.0
             *
             * @param array<string, array{access_key: string, secret: string}> $credentials IAM selector id mapped to its access key and secret; empty by default.
             * @return array<string, array{access_key: string, secret: string}> The filtered credentials.
             */
            $iamCredentials = \apply_filters('boolean_smtp_ses_iam_credentials', []);
            if (isset($iamCredentials[$iamSelector])) {
                $accessKey = $iamCredentials[$iamSelector]['access_key'] ?? '';
                $secret    = $iamCredentials[$iamSelector]['secret'] ?? '';
            }
        }

        if ($deliveryMode === 'api') {
            return [
                'access_key' => $accessKey !== '' ? $accessKey : trim((string) ($settings['api_access_key'] ?? $settings['access_key'] ?? '')),
                'secret'     => $secret !== '' ? $secret : trim((string) ($settings['api_secret'] ?? $settings['secret'] ?? $settings['secret_key'] ?? '')),
                'region'     => trim((string) ($settings['api_region'] ?? $settings['region'] ?? 'us-east-1'))
            ];
        }

        return [
            'access_key' => $accessKey !== '' ? $accessKey : trim((string) ($settings['smtp_username'] ?? $settings['access_key'] ?? '')),
            'secret'     => $secret !== '' ? $secret : trim((string) ($settings['smtp_password'] ?? $settings['secret'] ?? $settings['secret_key'] ?? '')),
            'region'     => trim((string) ($settings['smtp_region'] ?? $settings['region'] ?? 'us-east-1'))
        ];
    }

    /**
     * Build the SMTP host:port dropdown options for every SMTP-capable region.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Preset id mapped to its `host:port (encryption)` label.
     */
    private function smtpPresetOptions(): array {
        $options = [];
        foreach (self::SMTP_REGIONS as $region) {
            $host = SesEndpoints::smtpHost($region);
            $options["ses_{$region}_587"] = "{$host}:587 (STARTTLS)";
            $options["ses_{$region}_465"] = "{$host}:465 (SSL/TLS)";
        }

        return $options;
    }

    /**
     * Get every region valid for API-mode sending: the SMTP-capable regions plus the API-only ones.
     *
     * Not used for SMTP mode: SMTP stays scoped to {@see self::SMTP_REGIONS}, since AWS does not
     * offer an SMTP endpoint in any of the API-only regions.
     *
     * @since 1.0.0
     *
     * @return list<string>
     */
    private function allApiRegions(): array {
        return array_merge(self::SMTP_REGIONS, self::API_ONLY_REGIONS);
    }
}
