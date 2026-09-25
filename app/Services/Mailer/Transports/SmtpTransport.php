<?php

/**
 * Custom SMTP mail transport.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer\Transports;

use BooleanSmtp\Contracts\TransportContract;
use BooleanSmtp\Services\Settings\ConstantSettingsResolver;

/**
 * Sends mail through a user-supplied SMTP server. Supports a single delivery mode, `smtp`, with
 * host, port, encryption, authentication, timeout, and TLS verification all configurable per connection.
 *
 * @since 1.0.0
 */
class SmtpTransport implements TransportContract {
    /**
     * Get the transport driver identifier.
     *
     * @since 1.0.0
     *
     * @return string Always `smtp`.
     */
    public function getDriver(): string {
        return 'smtp';
    }

    /**
     * Get the human-readable transport name shown in the admin UI.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function getName(): string {
        return 'Custom SMTP';
    }

    /**
     * Resolve host and credential fields from wp-config.php constants or environment variables.
     *
     * Applies only when `key_store` is `wp_config` or `env`, using the same `BOOLEANSMTP_SMTP_*`
     * naming convention the admin UI's copy-paste snippet and {@see ConstantSettingsResolver}
     * already use.
     *
     * Callers already route every settings array through {@see ConstantSettingsResolver} before it
     * reaches a transport, so this resolution is normally redundant on that path. It is kept here
     * so this class still resolves credentials correctly if it is ever invoked without going
     * through that caller first, mirroring {@see SesTransport::prepareSettings()}. Keep both layers
     * in sync: a settings key added to {@see self::getSettingsSchema()} but not to the credential
     * key list below will silently fail to resolve from wp-config or the environment.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $settings Connection settings, possibly with unresolved wp-config/env placeholders.
     * @return array<string, mixed> Settings with credential fields resolved, when applicable.
     */
    public static function prepareSettings(array $settings): array {
        $keyStore = $settings['key_store'] ?? 'db';
        if ($keyStore !== 'wp_config' && $keyStore !== 'env') {
            return $settings;
        }

        return (new ConstantSettingsResolver())->resolve('smtp', $settings, [
            'host', 'port', 'encryption', 'username', 'password',
            'timeout', 'debug_level', 'client_hostname',
        ]);
    }

    /**
     * Configure PHPMailer for the connection's SMTP server.
     *
     * Applies host, port, encryption, auto-TLS, timeout, debug level, keep-alive, client hostname
     * (EHLO/HELO), peer certificate verification, and authentication from the connection settings.
     * Timeout is clamped to 1-300 seconds and debug level to 0-4, falling back to their defaults
     * when out of range.
     *
     * @since 1.0.0
     *
     * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance being prepared for sending.
     * @param array<string, mixed>           $settings  Decrypted connection settings; see {@see self::getSettingsSchema()}.
     */
    public function configure(\PHPMailer\PHPMailer\PHPMailer $phpmailer, array $settings): void {
        $settings = self::prepareSettings($settings);

        $phpmailer->isSMTP();
        $phpmailer->Host       = $settings['host'] ?? 'localhost';
        $phpmailer->Port       = (int) ($settings['port'] ?? 587);
        $encryption            = $settings['encryption'] ?? 'tls';
        $phpmailer->SMTPSecure = ($encryption === 'none') ? '' : $encryption;

        $phpmailer->SMTPAutoTLS = self::isTruthy($settings['use_auto_tls'] ?? true);

        $timeout = (int) ($settings['timeout'] ?? 10);
        if ($timeout < 1 || $timeout > 300) {
            $timeout = 10;
        }
        $phpmailer->Timeout = $timeout;

        $debugLevel = (int) ($settings['debug_level'] ?? 0);
        if ($debugLevel < 0 || $debugLevel > 4) {
            $debugLevel = 0;
        }
        $phpmailer->SMTPDebug = $debugLevel;

        $phpmailer->SMTPKeepAlive = self::isTruthy($settings['keep_alive'] ?? false);

        if (!empty($settings['client_hostname'])) {
            $clientHostname      = trim((string) $settings['client_hostname']);
            $phpmailer->Hostname = $clientHostname;
            if (\property_exists($phpmailer, 'Helo')) {
                $phpmailer->Helo = $clientHostname;
            }
        }

        $verifyPeerEnabled = !\array_key_exists('ssl_verify_peer', $settings)
        || self::isTruthy($settings['ssl_verify_peer']);

        if (!$verifyPeerEnabled) {
            $smtpOptions = \is_array($phpmailer->SMTPOptions ?? null) ? $phpmailer->SMTPOptions : [];
            $sslOptions  = \is_array($smtpOptions['ssl'] ?? null) ? $smtpOptions['ssl'] : [];

            $smtpOptions['ssl'] = array_merge($sslOptions, [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true
            ]);

            $phpmailer->SMTPOptions = $smtpOptions;
        }

        $authEnabled         = self::isAuthenticationEnabled($settings);
        $phpmailer->SMTPAuth = $authEnabled;

        if ($authEnabled) {
            $phpmailer->Username = (string) ($settings['username'] ?? '');
            $phpmailer->Password = (string) ($settings['password'] ?? '');
        } else {
            $phpmailer->Username = '';
            $phpmailer->Password = '';
        }

        if (isset($settings['from_email']) && $settings['from_email'] !== '') {
            $phpmailer->From = (string) $settings['from_email'];
        }

        if (isset($settings['from_name']) && $settings['from_name'] !== '') {
            $phpmailer->FromName = (string) $settings['from_name'];
        }

    }

    /**
     * Determine whether SMTP authentication should be used for a connection.
     *
     * Uses the explicit `authentication` flag when present; otherwise infers authentication is
     * wanted whenever a username has been set.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $settings Connection settings.
     * @return bool
     */
    public static function isAuthenticationEnabled(array $settings): bool {
        if (\array_key_exists('authentication', $settings)) {
            return self::isTruthy($settings['authentication']);
        }

        return !empty($settings['username']);
    }

    /**
     * Coerce a loosely-typed settings value to a boolean.
     *
     * Accepts native booleans, numbers, and the case-insensitive strings `1`, `yes`, `true`, and
     * `on` as truthy; null and an empty string are always falsy.
     *
     * @since 1.0.0
     *
     * @param mixed $value Value to coerce.
     * @return bool
     */
    public static function isTruthy(mixed $value): bool {
        if (\is_bool($value)) {
            return $value;
        }
        if ($value === null || $value === '') {
            return false;
        }
        if (\is_int($value) || \is_float($value)) {
            return (bool) $value;
        }
        $v = strtolower(trim((string) $value));

        return \in_array($v, ['1', 'yes', 'true', 'on'], true);
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
        $settings = self::prepareSettings($settings);
        $errors   = [];

        if (empty($settings['host'])) {
            $errors['host'] = 'SMTP host is required.';
        }

        $port = $settings['port'] ?? '';

        if ($port === '' || $port === null) {
            $errors['port'] = 'SMTP port is required.';
        } else {
            $portValue = (int) $port;
            if ($portValue < 1 || $portValue > 65535) {
                $errors['port'] = 'SMTP port must be between 1 and 65535.';
            }
        }

        if (isset($settings['timeout']) && $settings['timeout'] !== '' && $settings['timeout'] !== null) {
            $timeoutValue = (int) $settings['timeout'];
            if ($timeoutValue < 1 || $timeoutValue > 300) {
                $errors['timeout'] = 'SMTP timeout must be between 1 and 300 seconds.';
            }
        }

        if (isset($settings['debug_level']) && $settings['debug_level'] !== '' && $settings['debug_level'] !== null) {
            $debugLevelValue = (int) $settings['debug_level'];
            if ($debugLevelValue < 0 || $debugLevelValue > 4) {
                $errors['debug_level'] = 'SMTP debug level must be between 0 and 4.';
            }
        }

        if (!empty($settings['encryption']) && !\in_array($settings['encryption'], ['none', 'tls', 'ssl'], true)) {
            $errors['encryption'] = 'Encryption must be none, tls, or ssl.';
        }

        if (!empty($settings['client_hostname']) && \preg_match('/\s/', (string) $settings['client_hostname'])) {
            $errors['client_hostname'] = 'Client hostname cannot contain spaces.';
        }

        $keyStore = $settings['key_store'] ?? 'db';
        $authOn   = self::isAuthenticationEnabled($settings);

        // $settings here already went through prepareSettings() above, so for wp_config/env
        // these were resolved from BOOLEANSMTP_SMTP_USERNAME / BOOLEANSMTP_SMTP_PASSWORD
        // (constant or environment variable) if defined; only the error copy differs by store.
        if ($authOn) {
            if (empty($settings['username'])) {
                $errors['username'] = $keyStore === 'db'
                ? 'SMTP username is required when authentication is enabled.'
                : 'Define BOOLEANSMTP_SMTP_USERNAME in wp-config.php or the environment when using this credential store.';
            }
            if (($settings['password'] ?? '') === '') {
                $errors['password'] = $keyStore === 'db'
                ? 'SMTP password is required when authentication is enabled.'
                : 'Define BOOLEANSMTP_SMTP_PASSWORD in wp-config.php or the environment when using this credential store.';
            }
        }

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
    public function getSettingsSchema(): array {
        return [
            'from_email'       => [
                'type'     => 'email',
                'label'    => 'From Email',
                'required' => false,
                'default'  => ''
            ],
            'force_from_email' => [
                'type'     => 'checkbox',
                'label'    => 'Force From email',
                'required' => false,
                'default'  => false
            ],
            'return_path'      => [
                'type'     => 'checkbox',
                'label'    => 'Set envelope sender (Return-Path) from From address',
                'required' => false,
                'default'  => true
            ],
            'from_name'        => [
                'type'     => 'text',
                'label'    => 'From Name',
                'required' => false,
                'default'  => ''
            ],
            'force_from_name'  => [
                'type'     => 'checkbox',
                'label'    => 'Force From name',
                'required' => false,
                'default'  => false
            ],
            'host'             => [
                'type'     => 'text',
                'label'    => 'SMTP Host',
                'required' => true,
                'default'  => ''
            ],
            'port'             => [
                'type'     => 'number',
                'label'    => 'Port',
                'required' => true,
                'default'  => 587
            ],
            'encryption'       => [
                'type'     => 'select',
                'label'    => 'Encryption Method',
                'required' => false,
                'default'  => 'tls',
                'options'  => [
                    'none' => 'None',
                    'ssl'  => 'SSL',
                    'tls'  => 'TLS'
                ]
            ],
            'authentication'   => [
                'type'     => 'checkbox',
                'label'    => 'SMTP Authentication',
                'required' => false,
                'default'  => false
            ],
            'username'         => [
                'type'         => 'text',
                'label'        => 'SMTP Username',
                'required'     => false,
                'default'      => '',
                'visible_when' => [
                    'key'   => 'authentication',
                    'value' => true
                ]
            ],
            'password'         => [
                'type'         => 'password',
                'label'        => 'Password',
                'required'     => false,
                'default'      => '',
                'visible_when' => [
                    'key'   => 'authentication',
                    'value' => true
                ]
            ],
            'key_store'        => [
                'type'     => 'select',
                'label'    => 'Credential store',
                'required' => false,
                'default'  => 'db',
                'options'  => [
                    'db'        => 'Database (encrypted)',
                    'wp_config' => 'wp-config (BOOLEANSMTP_SMTP_USERNAME / BOOLEANSMTP_SMTP_PASSWORD)',
                    'env'       => 'Environment variable (BOOLEANSMTP_SMTP_USERNAME / BOOLEANSMTP_SMTP_PASSWORD)'
                ]
            ],
            'use_auto_tls'     => [
                'type'     => 'checkbox',
                'label'    => 'Auto TLS (STARTTLS)',
                'required' => false,
                'default'  => true
            ],
            'timeout'          => [
                'type'     => 'number',
                'label'    => 'Connection Timeout (seconds)',
                'required' => false,
                'default'  => 10
            ],
            'debug_level'      => [
                'type'     => 'select',
                'label'    => 'SMTP Debug Level',
                'required' => false,
                'default'  => 0,
                'options'  => [
                    0 => 'Off',
                    1 => 'Errors Only',
                    2 => 'Commands',
                    3 => 'Connection + Commands',
                    4 => 'Low-level + Full Trace'
                ]
            ],
            'client_hostname'  => [
                'type'     => 'text',
                'label'    => 'Client Hostname (EHLO/HELO)',
                'required' => false,
                'default'  => ''
            ],
            'ssl_verify_peer'  => [
                'type'     => 'checkbox',
                'label'    => 'Verify TLS Peer Certificate',
                'required' => false,
                'default'  => true
            ],
            'keep_alive'       => [
                'type'     => 'checkbox',
                'label'    => 'Enable SMTP Keep-Alive',
                'required' => false,
                'default'  => false
            ]
        ];
    }

    /**
     * Get the supported delivery modes for this transport.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Delivery mode key mapped to its label; only `smtp` is supported.
     */
    public function getDeliveryModes(): array {
        return ['smtp' => 'Custom SMTP'];
    }

    /**
     * Get the SMTP host/port/encryption presets offered in the admin UI.
     *
     * @since 1.0.0
     *
     * @return array<string, array{host: string, port: int, encryption: string, label: string}> Always empty; this transport has no built-in presets.
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
        return [
            'host'            => 'required|string',
            'port'            => 'required|integer|between:1,65535',
            'encryption'      => 'required|in:none,ssl,tls',
            'authentication'  => 'boolean',
            'username'        => 'required_if:authentication,true|string',
            'password'        => 'required_if:authentication,true|string',
            'key_store'       => 'required|in:db,wp_config,env',
            'from_email'      => 'nullable|email',
            'use_auto_tls'    => 'boolean',
            'timeout'         => 'nullable|integer|between:1,300',
            'debug_level'     => 'nullable|integer|between:0,4',
            'client_hostname' => 'nullable|string|max:255',
            'ssl_verify_peer' => 'boolean',
            'keep_alive'      => 'boolean'
        ];
    }
}
