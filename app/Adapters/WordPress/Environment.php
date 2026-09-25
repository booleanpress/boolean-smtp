<?php
/**
 * WordPress implementation of the environment adapter contract.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Adapters\WordPress;

use BooleanSmtp\Adapters\Contracts\EnvironmentAdapterContract;

/**
 * Reads environment configuration from PHP and WordPress constants.
 *
 * Centralizes every defined()/constant() call behind the contract so the rest of the plugin does
 * not depend on WordPress constants directly.
 *
 * @since 1.0.0
 */
final class Environment implements EnvironmentAdapterContract
{
    /**
     * Determine whether a constant is defined.
     *
     * @since 1.0.0
     *
     * @param string $name Constant name
     * @return bool True when the constant is defined.
     */
    public function isDefined(string $name): bool
    {
        return \defined($name);
    }

    /**
     * Return the value of a defined constant.
     *
     * @since 1.0.0
     *
     * @param string $name Constant name
     * @param mixed $default Default if not defined
     * @return mixed The constant's value, or $default when not defined.
     */
    public function get(string $name, mixed $default = null): mixed
    {
        if (\defined($name)) {
            return \constant($name);
        }
        return $default;
    }

    /**
     * Return a defined constant as a boolean.
     *
     * @since 1.0.0
     *
     * @param string $name Constant name
     * @param bool $default Default value
     * @return bool The constant's value cast to boolean, or $default when not defined.
     */
    public function getBoolean(string $name, bool $default = false): bool
    {
        if (\defined($name)) {
            return (bool)\constant($name);
        }
        return $default;
    }

    /**
     * Return a defined constant as an integer.
     *
     * @since 1.0.0
     *
     * @param string $name Constant name
     * @param int $default Default value
     * @return int The constant's value cast to integer, or $default when not defined.
     */
    public function getInteger(string $name, int $default = 0): int
    {
        if (\defined($name)) {
            return (int)\constant($name);
        }
        return $default;
    }

    /**
     * Determine whether debug mode is enabled.
     *
     * Reads the WP_DEBUG constant, falling back to the current PHP error reporting level when
     * WP_DEBUG is not defined.
     *
     * @since 1.0.0
     *
     * @return bool True when debugging is enabled.
     */
    public function isDebug(): bool
    {
        if (\defined('WP_DEBUG')) {
            return (bool)\constant('WP_DEBUG');
        }

        return false;
    }

    /**
     * Return the name of the current runtime environment.
     *
     * Checks, in order, the WordPress 5.5+ WP_ENVIRONMENT_TYPE constant, a custom ENVIRONMENT
     * constant, and the PHP_ENV environment variable, defaulting to "production".
     *
     * @since 1.0.0
     *
     * @return string Environment name ('production', 'development', 'staging', etc.)
     */
    public function getEnvironmentName(): string
    {
        if (\defined('WP_ENVIRONMENT_TYPE')) {
            return (string)\constant('WP_ENVIRONMENT_TYPE');
        }

        if (\defined('ENVIRONMENT')) {
            return (string)\constant('ENVIRONMENT');
        }

        $phpEnv = '';
        if (isset($_ENV['PHP_ENV']) && \is_scalar($_ENV['PHP_ENV'])) {
            $phpEnv = \sanitize_key(\wp_unslash((string) $_ENV['PHP_ENV']));
        } elseif (isset($_SERVER['PHP_ENV']) && \is_scalar($_SERVER['PHP_ENV'])) {
            $phpEnv = \sanitize_key(\wp_unslash((string) $_SERVER['PHP_ENV']));
        }
        if ($phpEnv !== '') {
            return $phpEnv;
        }

        return 'production';
    }

    /**
     * Return the plugin version.
     *
     * Reads the BOOLEAN_SMTP_VERSION constant first, then falls back to the Version header of the
     * main plugin file.
     *
     * @since 1.0.0
     *
     * @return string The version string, for example "1.2.0".
     */
    public function getAppVersion(): string
    {
        if (\defined('BOOLEAN_SMTP_VERSION')) {
            return (string)\constant('BOOLEAN_SMTP_VERSION');
        }

        $pluginFile = \defined('BOOLEAN_SMTP_PLUGIN_FILE')
            ? \constant('BOOLEAN_SMTP_PLUGIN_FILE')
            : \realpath(__DIR__ . '/../../boolean-smtp.php');

        if ($pluginFile && \is_readable($pluginFile)) {
            $fileData = \get_file_data($pluginFile, ['Version' => 'Version']);
            if (!empty($fileData['Version'])) {
                return $fileData['Version'];
            }
        }

        return '0.0.0';
    }
}
