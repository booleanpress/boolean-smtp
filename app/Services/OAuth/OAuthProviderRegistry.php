<?php

/**
 * Registry of OAuth-enabled connection providers.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\OAuth;

/**
 * Central registry of OAuth-enabled providers and their characteristics.
 *
 * Lets the OAuth refresh system and connection health probes work generically across drivers
 * instead of branching on the driver name. Third-party code can add further providers through
 * the `boolean_smtp_oauth_providers_register` action.
 *
 * @since 1.0.0
 */
final class OAuthProviderRegistry {
    /**
     * Registered providers, keyed by driver name.
     *
     * @since 1.0.0
     * @var array<string, array{
     *     name: string,
     *     delivery_modes: list<string>,
     *     token_field: string,
     *     refresh_token_field: string,
     *     expiration_field: string,
     *     api_class: class-string,
     *     supports_oauth_refresh: bool,
     *     refresh_endpoint: string
     * }>
     */
    private static array $providers = [];

    /**
     * Populate the registry with the built-in OAuth providers, once per request.
     *
     * Fires `boolean_smtp_oauth_providers_register` after registering the built-in providers so
     * other code can add its own before the registry is first read.
     *
     * @since 1.0.0
     */
    public static function initialize(): void {
        if (!empty(self::$providers)) {
            return;
        }

        self::registerProvider('google', [
            'name'                   => 'Google / Gmail',
            'delivery_modes'         => ['api'],
            'token_field'            => 'access_token',
            'refresh_token_field'    => 'refresh_token',
            'expiration_field'       => 'token_expires_at',
            'api_class'              => 'BooleanSmtp\Services\Mailer\Api\GmailApiSender',
            'supports_oauth_refresh' => true,
            'refresh_endpoint'       => 'https://oauth2.googleapis.com/token'
        ]);

        self::registerProvider('outlook', [
            'name'                   => 'Microsoft Outlook / Office 365',
            'delivery_modes'         => ['api'],
            'token_field'            => 'access_token',
            'refresh_token_field'    => 'refresh_token',
            'expiration_field'       => 'token_expires_at',
            'api_class'              => 'BooleanSmtp\Services\Mailer\Api\MicrosoftGraphMailSender',
            'supports_oauth_refresh' => true,
            'refresh_endpoint'       => 'https://login.microsoftonline.com/{tenant_id}/oauth2/v2.0/token'
        ]);

        /**
         * Fires after the built-in OAuth providers have been registered.
         *
         * Use {@see OAuthProviderRegistry::registerProvider()} from a callback on this action to
         * add a custom OAuth-enabled provider before the registry is first read.
         *
         * @since 1.0.0
         *
         * @param class-string $registryClass Fully qualified name of {@see OAuthProviderRegistry}.
         */
        \do_action('boolean_smtp_oauth_providers_register', self::class);
    }

    /**
     * Register or replace a provider's configuration in the registry.
     *
     * @since 1.0.0
     *
     * @param  string  $driver Provider driver key, for example `google` or `outlook`.
     * @param  array{
     *     name: string,
     *     delivery_modes: list<string>,
     *     token_field: string,
     *     refresh_token_field: string,
     *     expiration_field: string,
     *     api_class: class-string,
     *     supports_oauth_refresh: bool,
     *     refresh_endpoint: string
     * }  $config Provider configuration.
     */
    public static function registerProvider(string $driver, array $config): void {
        self::$providers[$driver] = $config;
    }

    /**
     * Get the configuration of every registered provider.
     *
     * @since 1.0.0
     *
     * @return array<string, array{
     *     name: string,
     *     delivery_modes: list<string>,
     *     token_field: string,
     *     refresh_token_field: string,
     *     expiration_field: string,
     *     api_class: class-string,
     *     supports_oauth_refresh: bool,
     *     refresh_endpoint: string
     * }> Provider configuration, keyed by driver name.
     */
    public static function all(): array {
        self::initialize();
        return self::$providers;
    }

    /**
     * Get the configuration for a single provider.
     *
     * @since 1.0.0
     *
     * @param  string  $driver Provider driver key.
     * @return array{
     *     name: string,
     *     delivery_modes: list<string>,
     *     token_field: string,
     *     refresh_token_field: string,
     *     expiration_field: string,
     *     api_class: class-string,
     *     supports_oauth_refresh: bool,
     *     refresh_endpoint: string
     * }|null Provider configuration, or null when the driver is not registered.
     */
    public static function get(string $driver): ?array {
        self::initialize();
        return self::$providers[$driver] ?? null;
    }

    /**
     * Check whether a driver supports refreshing its OAuth token.
     *
     * @since 1.0.0
     *
     * @param  string  $driver Provider driver key.
     * @return bool True when the driver is registered and supports OAuth refresh.
     */
    public static function supportsOAuthRefresh(string $driver): bool {
        $provider = self::get($driver);
        return $provider && ($provider['supports_oauth_refresh'] ?? false);
    }

    /**
     * Get the driver keys of every provider that supports OAuth refresh.
     *
     * @since 1.0.0
     *
     * @return list<string> Driver keys.
     */
    public static function getOAuthRefreshDrivers(): array {
        self::initialize();
        return \array_keys(
            \array_filter(
                self::$providers,
                fn(array $config) => $config['supports_oauth_refresh'] ?? false
            )
        );
    }

    /**
     * Get the settings field name that stores a driver's access token.
     *
     * @since 1.0.0
     *
     * @param  string  $driver Provider driver key.
     * @return string Field name; defaults to `access_token` when the driver is not registered.
     */
    public static function getTokenField(string $driver): string {
        return self::get($driver)['token_field'] ?? 'access_token';
    }

    /**
     * Get the settings field name that stores a driver's refresh token.
     *
     * @since 1.0.0
     *
     * @param  string  $driver Provider driver key.
     * @return string Field name; defaults to `refresh_token` when the driver is not registered.
     */
    public static function getRefreshTokenField(string $driver): string {
        return self::get($driver)['refresh_token_field'] ?? 'refresh_token';
    }

    /**
     * Get the settings field name that stores a driver's token expiration.
     *
     * @since 1.0.0
     *
     * @param  string  $driver Provider driver key.
     * @return string Field name; defaults to `token_expires_at` when the driver is not registered.
     */
    public static function getExpirationField(string $driver): string {
        return self::get($driver)['expiration_field'] ?? 'token_expires_at';
    }

    /**
     * Check whether a driver supports the API delivery mode that OAuth requires.
     *
     * @since 1.0.0
     *
     * @param  string  $driver Provider driver key.
     * @return bool True when the driver is registered and lists `api` among its delivery modes.
     */
    public static function supportsApiMode(string $driver): bool {
        $provider = self::get($driver);
        if (!$provider) {
            return false;
        }

        return \in_array('api', $provider['delivery_modes'] ?? [], true);
    }
}
