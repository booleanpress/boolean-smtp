<?php
/**
 * Turns a SendGrid or Postmark API connection into the same provider's SMTP relay.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration\Support;

use BooleanSmtp\Services\Migration\Canonical\CanonicalConnection;

/**
 * Both providers accept their API credential as an SMTP login on a fixed host, so the
 * conversion is deterministic and the connection keeps working through Custom SMTP.
 *
 * @since 1.0.0
 */
final class SmtpRelayConverter
{
    /**
     * Relay endpoints and how the credential is used as the SMTP login.
     *
     * @since 1.0.0
     * @var array<string, array{host: string, port: int, username: string|null, secret_key: string, conversion: string}>
     */
    private const RELAYS = [
        'sendgrid' => ['host' => 'smtp.sendgrid.net', 'port' => 587, 'username' => 'apikey', 'secret_key' => 'api_key', 'conversion' => 'sendgrid_relay'],
        'postmark' => ['host' => 'smtp.postmarkapp.com', 'port' => 587, 'username' => null, 'secret_key' => 'server_token', 'conversion' => 'postmark_relay'],
    ];

    /**
     * Whether a driver has a relay conversion.
     *
     * @since 1.0.0
     *
     * @param  string|null $driver Target driver id.
     * @return bool
     */
    public static function converts(?string $driver): bool
    {
        return $driver !== null && isset(self::RELAYS[$driver]);
    }

    /**
     * The conversion tag for a driver, for the assessment.
     *
     * @since 1.0.0
     *
     * @param  string $driver `sendgrid` or `postmark`.
     * @return string
     */
    public static function conversion(string $driver): string
    {
        return self::RELAYS[$driver]['conversion'];
    }

    /**
     * The connection as a Custom SMTP relay. The API credential (`api_key` for SendGrid,
     * `server_token` for Postmark) becomes the SMTP password; Postmark also uses it as the
     * username. A missing credential stays missing.
     *
     * @since 1.0.0
     *
     * @param  CanonicalConnection $connection A `sendgrid` or `postmark` connection.
     * @return CanonicalConnection The same connection on the `smtp` driver.
     */
    public static function convert(CanonicalConnection $connection): CanonicalConnection
    {
        $relay  = self::RELAYS[(string) $connection->driver];
        $secret = (string) ($connection->settings[$relay['secret_key']] ?? '');

        $settings = $connection->settings;
        unset($settings[$relay['secret_key']], $settings['message_stream'], $settings['domain']);
        $settings['host']           = $relay['host'];
        $settings['port']           = $relay['port'];
        $settings['encryption']     = 'tls';
        $settings['use_auto_tls']   = true;
        $settings['authentication'] = true;
        $settings['username']       = $relay['username'] ?? $secret;
        $settings['password']       = $secret;
        $settings['key_store']      = 'db';

        $missing = array_values(array_filter(
            $connection->missingSecrets,
            static fn (string $key): bool => $key !== $relay['secret_key']
        ));
        if ($secret === '' || \in_array($relay['secret_key'], $connection->missingSecrets, true)) {
            $missing[] = 'password';
        }

        return new CanonicalConnection(
            sourcePlugin: $connection->sourcePlugin,
            sourceKey: $connection->sourceKey,
            name: $connection->name . ' (as SMTP relay)',
            driver: 'smtp',
            sourceMailer: $connection->sourceMailer,
            settings: $settings,
            missingSecrets: array_values(array_unique($missing)),
            wasDefault: $connection->wasDefault,
        );
    }
}
