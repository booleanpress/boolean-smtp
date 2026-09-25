<?php
/**
 * Amazon SES endpoint hosts, built from the region.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer\Api;

/**
 * The one place that knows Amazon SES's endpoint domain.
 *
 * SES serves its HTTPS API at `email.<region>.<domain>` and SMTP at
 * `email-smtp.<region>.<domain>`; every sender and the SMTP transport build their host here.
 *
 * @since 1.0.0
 */
final class SesEndpoints
{
    /**
     * Build the HTTPS API host for a region.
     *
     * @since 1.0.0
     *
     * @param  string $region AWS region code, for example `us-east-1`.
     * @return string The API host, for example `email.us-east-1.amazonaws.com`.
     */
    public static function apiHost(string $region): string
    {
        return 'email.' . $region . '.' . self::domain();
    }

    /**
     * Build the SMTP host for a region.
     *
     * @since 1.0.0
     *
     * @param  string $region AWS region code, for example `us-east-1`.
     * @return string The SMTP host, for example `email-smtp.us-east-1.amazonaws.com`.
     */
    public static function smtpHost(string $region): string
    {
        return 'email-smtp.' . $region . '.' . self::domain();
    }

    /**
     * The domain Amazon serves SES from.
     *
     * @since 1.0.0
     *
     * @return string
     */
    private static function domain(): string
    {
        return 'amazonaws.com'; // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Amazon SES's API and SMTP endpoint domain, called to send mail; no content is loaded from it.
    }
}
