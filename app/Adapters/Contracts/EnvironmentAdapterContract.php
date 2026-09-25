<?php
/**
 * Contract for reading environment configuration and constants.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Adapters\Contracts;

/**
 * Guarantees a way to read environment constants and configuration values that does not depend
 * on the underlying host application.
 *
 * @since 1.0.0
 */
interface EnvironmentAdapterContract
{
    /**
     * Determine whether a named constant or configuration value is defined.
     *
     * @since 1.0.0
     *
     * @param  string $name Constant or configuration key.
     * @return bool True when the value is defined.
     */
    public function isDefined(string $name): bool;

    /**
     * Return the value of a named constant or configuration setting.
     *
     * @since 1.0.0
     *
     * @param  string $name    Constant or configuration key.
     * @param  mixed  $default Value returned when the key is not defined.
     * @return mixed The resolved value, or $default when not defined.
     */
    public function get(string $name, mixed $default = null): mixed;

    /**
     * Return a named configuration flag as a boolean.
     *
     * @since 1.0.0
     *
     * @param  string $name    Constant or configuration key.
     * @param  bool   $default Value returned when the key is not defined.
     * @return bool The resolved flag, or $default when not defined.
     */
    public function getBoolean(string $name, bool $default = false): bool;

    /**
     * Return a named configuration value as an integer.
     *
     * @since 1.0.0
     *
     * @param  string $name    Constant or configuration key.
     * @param  int    $default Value returned when the key is not defined.
     * @return int The resolved value, or $default when not defined.
     */
    public function getInteger(string $name, int $default = 0): int;

    /**
     * Determine whether the application is running in debug mode.
     *
     * @since 1.0.0
     *
     * @return bool True when debug mode is enabled.
     */
    public function isDebug(): bool;

    /**
     * Return the name of the current runtime environment.
     *
     * @since 1.0.0
     *
     * @return string The environment name, for example "production" or "staging".
     */
    public function getEnvironmentName(): string;

    /**
     * Return the version of the running application.
     *
     * @since 1.0.0
     *
     * @return string The application version string.
     */
    public function getAppVersion(): string;
}
