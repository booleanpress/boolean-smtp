<?php

/**
 * Resolves the OAuth2 redirect URI used for each supported provider's connection flow.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer\OAuth;

/**
 * OAuth2 redirect URIs for Google and Microsoft.
 *
 * Priority:
 * 1. Per-provider constants when defined (BOOLEANSMTP_GOOGLE_OAUTH_REDIRECT_URI, etc.)
 * 2. Central OAuth server at https://oauth.booleansmtp.com/{provider} (default)
 * 3. This site's REST callback URLs (fallback when BOOLEANSMTP_USE_LOCAL_OAUTH_REDIRECTS is true)
 * 4. WordPress filters for final override
 *
 * @since 1.0.0
 */
final class OAuthRedirectUri
{
    /**
     * Base URL of the central OAuth relay used when no local override applies.
     *
     * @since 1.0.0
     * @var string
     */
    private const OAUTH_SERVER = 'https://oauth.booleansmtp.com';

    /**
     * Resolve the redirect URI for the Google connection flow.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public static function google(): string
    {
        $default = self::resolveDefault('google', 'BOOLEANSMTP_GOOGLE_OAUTH_REDIRECT_URI');

        /**
         * Filters the redirect URI used for the Google OAuth2 connection flow.
         *
         * Return a different URI to override the resolved default.
         *
         * @since 1.0.0
         *
         * @param string $default Redirect URI resolved from a constant, a local REST callback, or the OAuth server.
         * @return string The value to short-circuit with, or the default to run the built-in behaviour.
         */
        return (string) \apply_filters('boolean_smtp_google_oauth_redirect_uri', $default);
    }

    /**
     * Resolve the redirect URI for the Microsoft connection flow.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public static function microsoft(): string
    {
        $default = self::resolveDefault('microsoft', 'BOOLEANSMTP_MICROSOFT_OAUTH_REDIRECT_URI');

        /**
         * Filters the redirect URI used for the Microsoft OAuth2 connection flow.
         *
         * Return a different URI to override the resolved default.
         *
         * @since 1.0.0
         *
         * @param string $default Redirect URI resolved from a constant, a local REST callback, or the OAuth server.
         * @return string The value to short-circuit with, or the default to run the built-in behaviour.
         */
        return (string) \apply_filters('boolean_smtp_microsoft_oauth_redirect_uri', $default);
    }

    /**
     * Resolve the default redirect URI for a provider.
     *
     * @since 1.0.0
     *
     * @param  string $provider     Provider slug (google, microsoft).
     * @param  string $constantName Name of the constant that, when defined, overrides the resolved default.
     * @return string
     */
    private static function resolveDefault(string $provider, string $constantName): string
    {
        // 1. Explicit constant override
        if (\defined($constantName)) {
            $c = \constant($constantName);
            if (\is_string($c) && trim($c) !== '') {
                return trim($c);
            }
        }

        // 2. Local REST callbacks (opt-in for dev/debug)
        if (self::localRedirectsEnabled()) {
            return (string) \get_rest_url(null, "booleansmtp/v1/oauth/{$provider}/callback");
        }

        // 3. Central OAuth server (default — avoids site-specific callback issues)
        return self::OAUTH_SERVER . '/' . $provider;
    }

    /**
     * Determine whether the site is configured to use its own REST callbacks instead of the central OAuth server.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    private static function localRedirectsEnabled(): bool
    {
        return \defined('BOOLEANSMTP_USE_LOCAL_OAUTH_REDIRECTS')
            && \constant('BOOLEANSMTP_USE_LOCAL_OAUTH_REDIRECTS');
    }
}
