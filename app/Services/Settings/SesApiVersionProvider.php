<?php

/**
 * Resolves which AWS SES API version a connection should use.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Settings;

/**
 * SES API version provider.
 *
 * Determines whether to use SES v1 (Query API) or v2 (JSON API) for a given connection.
 * Defaults to v2; v1 can be forced via constant or per-connection setting.
 *
 * @since 1.0.0
 *
 * @see https://docs.aws.amazon.com/ses/latest/APIReference-V2/
 * @see https://docs.aws.amazon.com/ses/latest/APIReferenceV1/
 */
final class SesApiVersionProvider {
    /**
     * Resolve the SES API version for a connection.
     *
     * Checks in order:
     * 1. `BOOLEANSMTP_AWS_SES_ENABLE_V2_API` constant (explicit global override)
     * 2. Connection settings `enable_ses_v2_api` field (per-connection override when present)
     * 3. Default: v2
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $settings Decrypted connection settings.
     * @return 1|2 API version (1 or 2).
     */
    public static function resolveVersion(array $settings): int {
        // Global override via wp-config/env constant.
        if (\defined('BOOLEANSMTP_AWS_SES_ENABLE_V2_API')) {
            $constantValue = self::normalizeVersionFlag(\constant('BOOLEANSMTP_AWS_SES_ENABLE_V2_API'));
            if ($constantValue !== null) {
                return $constantValue;
            }
        }

        // Per-connection override only applies when key exists.
        if (\array_key_exists('enable_ses_v2_api', $settings)) {
            $perConnectionFlag = self::normalizeVersionFlag($settings['enable_ses_v2_api']);
            if ($perConnectionFlag !== null) {
                return $perConnectionFlag;
            }
        }

        return 2;
    }

    /**
     * Determine if the v2 API is enabled globally.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public static function isV2Enabled(): bool {
        if (\defined('BOOLEANSMTP_AWS_SES_ENABLE_V2_API')) {
            $value = self::normalizeVersionFlag(\constant('BOOLEANSMTP_AWS_SES_ENABLE_V2_API'));
            if ($value !== null) {
                return $value === 2;
            }
        }

        return true;
    }

    /**
     * Normalize a constant, environment, or settings value into an API version.
     *
     * @since 1.0.0
     *
     * @param  mixed $value Raw value from a constant, environment variable, or setting.
     * @return 1|2|null Null when the value does not map to a known version.
     */
    private static function normalizeVersionFlag(mixed $value): ?int {
        if ($value === true || $value === 1 || $value === '1' || $value === 2 || $value === '2') {
            return 2;
        }

        if ($value === false || $value === 0 || $value === '0') {
            return 1;
        }

        if (\is_string($value)) {
            $normalized = \strtolower(\trim($value));
            if ($normalized === 'true' || $normalized === 'yes' || $normalized === 'on' || $normalized === 'v2') {
                return 2;
            }
            if ($normalized === 'false' || $normalized === 'no' || $normalized === 'off' || $normalized === 'v1') {
                return 1;
            }
        }

        return null;
    }

    /**
     * Get a human-readable API version string.
     *
     * @since 1.0.0
     *
     * @param  int $version API version (1 or 2).
     * @return string
     */
    public static function versionString(int $version): string {
        return match ($version) {
            2       => 'SES v2 (JSON API)',
            default => 'SES v1 (Query API)'
        };
    }

    /**
     * Get the AWS documentation URL for the version's send endpoint.
     *
     * @since 1.0.0
     *
     * @param  int $version API version (1 or 2).
     * @return string
     */
    public static function documentationUrl(int $version): string {
        return match ($version) {
            2       => 'https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_SendEmail.html',
            default => 'https://docs.aws.amazon.com/ses/latest/APIReferenceV1/API_SendRawEmail.html'
        };
    }
}
