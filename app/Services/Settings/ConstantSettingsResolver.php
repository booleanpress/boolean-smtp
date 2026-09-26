<?php

/**
 * Resolves connection settings values from wp-config constants or environment variables.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Settings;

/**
 * Resolves settings values from constants or environment variables.
 *
 * Naming convention: `BOOLEANSMTP_{DRIVER}_{KEY}` (for example `BOOLEANSMTP_SMTP_PASSWORD`).
 * A resolved value overrides the corresponding database-stored setting; this lets a connection's
 * credentials be pinned in wp-config.php or the server environment instead of the database.
 *
 * @since 1.0.0
 */
class ConstantSettingsResolver {
    /**
     * Overlay constant/environment values onto a settings array.
     *
     * @since 1.0.0
     *
     * @param  string               $driver       Connection driver slug (e.g. `ses`, `smtp`).
     * @param  array<string, mixed> $settings     Settings values loaded from the database.
     * @param  array<int, string>|null $expectedKeys Additional keys to check even when absent from
     *                                                 `$settings`.
     * @return array<string, mixed> The settings array with resolved values merged in.
     */
    public function resolve(string $driver, array $settings, ?array $expectedKeys = null): array {
        $keys = array_keys($settings);
        if (is_array($expectedKeys)) {
            $keys = array_values(array_unique(array_merge($keys, $expectedKeys)));
        }

        foreach ($keys as $key) {
            $resolved = $this->resolveValue($driver, (string) $key);
            if ($resolved !== null) {
                // Do not overwrite DB values with empty constants/env (defined('X') can be true with '').
                if (\is_string($resolved) && $resolved === '') {
                    continue;
                }
                $settings[(string) $key] = $resolved;
            }
        }

        return $settings;
    }

    /**
     * Determine whether a setting is resolvable from a constant or environment variable.
     *
     * @since 1.0.0
     *
     * @param  string $driver Connection driver slug.
     * @param  string $key    Settings key.
     * @return bool
     */
    public function isDefined(string $driver, string $key): bool {
        return $this->resolveValue($driver, $key) !== null;
    }

    /**
     * Build the canonical constant/environment variable name for a driver and settings key.
     *
     * @since 1.0.0
     *
     * @param  string $driver Connection driver slug.
     * @param  string $key    Settings key.
     * @return string
     */
    public function constantName(string $driver, string $key): string {
        $driverUpper = strtoupper(str_replace([' ', '-'], '_', $driver));

        return "BOOLEANSMTP_{$driverUpper}_" . strtoupper(str_replace([' ', '-'], '_', $key));
    }

    /**
     * Resolve a single settings key from its canonical or alias constant/environment names.
     *
     * @since 1.0.0
     *
     * @param  string $driver Connection driver slug.
     * @param  string $key    Settings key.
     * @return mixed The resolved value, or null when no constant or environment variable is set.
     */
    private function resolveValue(string $driver, string $key): mixed {
        $candidates = array_merge(
            [$this->constantName($driver, $key)],
            $this->aliasConstantNames($driver, $key)
        );

        foreach ($candidates as $constName) {
            if (defined($constName)) {
                return constant($constName);
            }
            $envValue = getenv($constName);
            if ($envValue !== false) {
                return $envValue;
            }
        }

        return null;
    }

    /**
     * Non-canonical constant/environment variable names accepted for backward compatibility.
     *
     * @since 1.0.0
     *
     * @param  string $driver Connection driver slug.
     * @param  string $key    Settings key.
     * @return array<int, string>
     */
    private function aliasConstantNames(string $driver, string $key): array {
        if (strtolower($driver) === 'outlook') {
            return match ($key) {
                'refresh_token', 'token' => [
                    'BOOLEANSMTP_OUTLOOK_TOKEN',
                    'BOOLEANSMTP_MICROSOFT_TOKEN',
                ],
                default => [],
            };
        }

        // Custom SMTP historically read defined('BOOLEAN_SMTP_USERNAME')/defined('BOOLEAN_SMTP_PASSWORD')
        // directly, without the 'S' the canonical BOOLEANSMTP_SMTP_* naming convention uses everywhere
        // else. These aliases keep a site that already has those constants in wp-config.php working.
        if (strtolower($driver) === 'smtp') {
            return match ($key) {
                'username' => ['BOOLEAN_SMTP_USERNAME'],
                'password' => ['BOOLEAN_SMTP_PASSWORD'],
                default    => [],
            };
        }

        if (strtolower($driver) !== 'ses') {
            return [];
        }

        return match ($key) {
            'access_key', 'api_access_key' => [
                'BOOLEANSMTP_AWS_ACCESS_KEY_ID',
            ],
            'secret', 'api_secret' => [
                'BOOLEANSMTP_AWS_SECRET_ACCESS_KEY',
            ],
            'region', 'api_region' => [
                'BOOLEANSMTP_AWS_REGION',
            ],
            'smtp_username' => [
                'BOOLEANSMTP_AWS_SES_SMTP_USERNAME',
                'BOOLEANSMTP_AWS_ACCESS_KEY_ID',
            ],
            'smtp_password' => [
                'BOOLEANSMTP_AWS_SES_SMTP_PASSWORD',
                'BOOLEANSMTP_AWS_SECRET_ACCESS_KEY',
            ],
            'smtp_region' => [
                'BOOLEANSMTP_AWS_SES_SMTP_REGION',
                'BOOLEANSMTP_AWS_REGION',
            ],
            'smtp_host' => [
                'BOOLEANSMTP_AWS_SES_SMTP_HOST',
            ],
            'smtp_port' => [
                'BOOLEANSMTP_AWS_SES_SMTP_PORT',
            ],
            'smtp_encryption' => [
                'BOOLEANSMTP_AWS_SES_SMTP_ENCRYPTION',
            ],
            default => [],
        };
    }
}
