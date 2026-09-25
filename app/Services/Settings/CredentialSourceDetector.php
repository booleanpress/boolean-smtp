<?php

/**
 * Detects where a connection's SES credentials are actually loaded from.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Settings;

use BooleanSmtp\Services\AWS\IMDSv2Client;

/**
 * Credential source detection and precedence resolver.
 *
 * Determines where SES credentials are loaded from, in order of precedence:
 * 1. Database (encrypted connection settings)
 * 2. wp-config.php constants (`BOOLEANSMTP_AWS_ACCESS_KEY_ID`)
 * 3. Environment variables (`BOOLEANSMTP_AWS_ACCESS_KEY_ID`)
 * 4. EC2 IAM role via IMDSv2 (opt-in)
 *
 * Backs the credential-source transparency badge shown in the connection UI.
 *
 * @since 1.0.0
 */
final class CredentialSourceDetector {
    /**
     * Credentials stored in the BooleanSMTP database, encrypted at rest.
     *
     * @since 1.0.0
     */
    public const SOURCE_DB        = 'database';

    /**
     * Credentials read from a wp-config.php constant.
     *
     * @since 1.0.0
     */
    public const SOURCE_WP_CONFIG = 'wp_config';

    /**
     * Credentials read from a server environment variable.
     *
     * @since 1.0.0
     */
    public const SOURCE_ENV       = 'env';

    /**
     * Credentials obtained from an EC2 instance's IAM role via IMDSv2.
     *
     * @since 1.0.0
     */
    public const SOURCE_IAM_ROLE  = 'iam_role';

    /**
     * No credential source could be determined.
     *
     * @since 1.0.0
     */
    public const SOURCE_UNKNOWN   = 'unknown';

    /**
     * Detect credential source for a connection.
     *
     * Returns the highest-priority source actually providing credentials.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $settings       Decrypted connection settings.
     * @param  string               $credentialType One of `access_key`, `secret_key`, or `all`.
     * @return array{source: string, label: string, icon: string|null} Source metadata.
     */
    public static function detect(array $settings, string $credentialType = 'all'): array {
        $accessKeySource = self::detectAccessKeySource($settings);
        $secretKeySource = self::detectSecretKeySource($settings);

        if ($credentialType === 'access_key') {
            return self::sourceMetadata($accessKeySource);
        } elseif ($credentialType === 'secret_key') {
            return self::sourceMetadata($secretKeySource);
        }

        // For 'all', return the highest priority source
        $precedence = [self::SOURCE_DB, self::SOURCE_WP_CONFIG, self::SOURCE_ENV, self::SOURCE_IAM_ROLE];
        foreach ($precedence as $source) {
            if ($accessKeySource === $source && $secretKeySource === $source) {
                return self::sourceMetadata($source);
            }
        }

        // Mixed sources (should be rare)
        return self::sourceMetadata(self::SOURCE_UNKNOWN);
    }

    /**
     * Get all credential sources for UI display (transparency).
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $settings Decrypted connection settings.
     * @return array<string, array{source: string, label: string, icon: string|null}>
     */
    public static function detectAll(array $settings): array {
        return [
            'access_key' => self::detect($settings, 'access_key'),
            'secret_key' => self::detect($settings, 'secret_key')
        ];
    }

    /**
     * Detect where the access key is loaded from.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $settings Decrypted connection settings.
     * @return string One of the `SOURCE_*` constants.
     */
    private static function detectAccessKeySource(array $settings): string {
        // 1. Check database
        if (
            self::hasNonEmptySetting($settings, 'access_key')
            || self::hasNonEmptySetting($settings, 'api_access_key')
            || self::hasNonEmptySetting($settings, 'smtp_username')
        ) {
            return self::SOURCE_DB;
        }

        // 2. Check wp-config constant
        if (self::hasDefinedConstant([
            'BOOLEANSMTP_AWS_ACCESS_KEY_ID',
            'BOOLEANSMTP_AWS_SES_ACCESS_KEY',
            'BOOLEANSMTP_AWS_SES_ACCESS_KEY_ID'
        ])) {
            return self::SOURCE_WP_CONFIG;
        }

        // 3. Check environment variable
        if (self::hasEnvironmentVariable([
            'BOOLEANSMTP_AWS_ACCESS_KEY_ID',
            'BOOLEANSMTP_AWS_SES_ACCESS_KEY',
            'BOOLEANSMTP_AWS_SES_ACCESS_KEY_ID'
        ])) {
            return self::SOURCE_ENV;
        }

        // 4. Check IAM role
        if (self::isIamRoleAvailable()) {
            return self::SOURCE_IAM_ROLE;
        }

        return self::SOURCE_UNKNOWN;
    }

    /**
     * Detect where the secret key is loaded from.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $settings Decrypted connection settings.
     * @return string One of the `SOURCE_*` constants.
     */
    private static function detectSecretKeySource(array $settings): string {
        // 1. Check database
        if (
            self::hasNonEmptySetting($settings, 'secret')
            || self::hasNonEmptySetting($settings, 'secret_key')
            || self::hasNonEmptySetting($settings, 'api_secret')
            || self::hasNonEmptySetting($settings, 'smtp_password')
        ) {
            return self::SOURCE_DB;
        }

        // 2. Check wp-config constant
        if (self::hasDefinedConstant([
            'BOOLEANSMTP_AWS_SECRET_ACCESS_KEY',
            'BOOLEANSMTP_AWS_SES_SECRET',
            'BOOLEANSMTP_AWS_SES_SECRET_KEY'
        ])) {
            return self::SOURCE_WP_CONFIG;
        }

        // 3. Check environment variable
        if (self::hasEnvironmentVariable([
            'BOOLEANSMTP_AWS_SECRET_ACCESS_KEY',
            'BOOLEANSMTP_AWS_SES_SECRET',
            'BOOLEANSMTP_AWS_SES_SECRET_KEY'
        ])) {
            return self::SOURCE_ENV;
        }

        // 4. Check IAM role
        if (self::isIamRoleAvailable()) {
            return self::SOURCE_IAM_ROLE;
        }

        return self::SOURCE_UNKNOWN;
    }

    /**
     * Cached result of the last IAM role availability probe, to avoid repeated IMDSv2 calls.
     *
     * @since 1.0.0
     * @var bool|null
     */
    private static ?bool $iamRoleAvailable = null;

    /**
     * Determine whether an EC2 IAM role is available via IMDSv2.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    private static function isIamRoleAvailable(): bool {
        if (!self::shouldProbeIamRole()) {
            return false;
        }

        if (self::$iamRoleAvailable === null) {
            try {
                $client                 = new IMDSv2Client();
                self::$iamRoleAvailable = $client->isAvailable();
            } catch (\Throwable) {
                self::$iamRoleAvailable = false;
            }
        }

        return self::$iamRoleAvailable;
    }

    /**
     * Determine whether a settings array has a non-empty value for a key.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $settings
     * @param  string               $key
     * @return bool
     */
    private static function hasNonEmptySetting(array $settings, string $key): bool {
        return isset($settings[$key]) && \trim((string) $settings[$key]) !== '';
    }

    /**
     * Determine whether any of the given constant names is defined with a non-empty value.
     *
     * @since 1.0.0
     *
     * @param  array<int, string> $names
     * @return bool
     */
    private static function hasDefinedConstant(array $names): bool {
        foreach ($names as $name) {
            if (\defined($name) && \trim((string) \constant($name)) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether any of the given environment variable names has a non-empty value.
     *
     * @since 1.0.0
     *
     * @param  array<int, string> $names
     * @return bool
     */
    private static function hasEnvironmentVariable(array $names): bool {
        foreach ($names as $name) {
            $serverValue = isset($_SERVER[$name]) && \is_string($_SERVER[$name]) ? \sanitize_text_field(\wp_unslash($_SERVER[$name])) : null;
            if (\is_string($serverValue) && \trim($serverValue) !== '') {
                return true;
            }

            $envValue = \getenv($name);
            if ($envValue !== false && \trim((string) $envValue) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether probing the EC2 IMDSv2 endpoint for an IAM role is enabled.
     *
     * Opt-in: disabled unless the `BOOLEANSMTP_AWS_ENABLE_IMDS_ROLE_SOURCE` constant or
     * environment variable is truthy, since a non-EC2 host may otherwise wait on a connection
     * timeout trying to reach the metadata endpoint.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    private static function shouldProbeIamRole(): bool {
        $enabled = false;

        if (\defined('BOOLEANSMTP_AWS_ENABLE_IMDS_ROLE_SOURCE')) {
            $enabled = \filter_var((string) \constant('BOOLEANSMTP_AWS_ENABLE_IMDS_ROLE_SOURCE'), FILTER_VALIDATE_BOOLEAN);
        }

        if (!$enabled) {
            $env = \getenv('BOOLEANSMTP_AWS_ENABLE_IMDS_ROLE_SOURCE');
            if ($env !== false) {
                $enabled = \filter_var((string) $env, FILTER_VALIDATE_BOOLEAN);
            }
        }

        if (\function_exists('apply_filters')) {
            /**
             * Filters whether BooleanSMTP probes the EC2 IMDSv2 endpoint for an IAM role.
             *
             * @since 1.0.0
             *
             * @param bool $enabled Whether the probe is currently enabled by constant or
             *                      environment variable. Return true to enable it.
             * @return bool The filtered value.
             */
            $enabled = (bool) \apply_filters('boolean_smtp_aws_enable_imds_role_source', $enabled);
        }

        return $enabled;
    }

    /**
     * Get UI metadata for a credential source.
     *
     * @since 1.0.0
     *
     * @param  string $source One of the `SOURCE_*` constants.
     * @return array{source: string, label: string, icon: string|null}
     */
    private static function sourceMetadata(string $source): array {
        return match ($source) {
            self::SOURCE_DB        => [
                'source' => self::SOURCE_DB,
                'label'  => 'Credentials from BooleanSMTP database (encrypted)',
                'icon'   => 'database'
            ],
            self::SOURCE_WP_CONFIG => [
                'source' => self::SOURCE_WP_CONFIG,
                'label'  => 'Credentials from wp-config.php constants',
                'icon'   => 'settings'
            ],
            self::SOURCE_ENV       => [
                'source' => self::SOURCE_ENV,
                'label'  => 'Credentials from environment variables',
                'icon'   => 'shield'
            ],
            self::SOURCE_IAM_ROLE  => [
                'source' => self::SOURCE_IAM_ROLE,
                'label'  => 'Credentials from EC2 IAM role (IMDSv2)',
                'icon'   => 'lock'
            ],
            default                => [
                'source' => self::SOURCE_UNKNOWN,
                'label'  => 'Credential source unknown',
                'icon'   => null
            ]
        };
    }
}
