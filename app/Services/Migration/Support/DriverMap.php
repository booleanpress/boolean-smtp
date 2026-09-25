<?php
/**
 * Maps the mailer ids the source plugins use to the plugin's own driver ids.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration\Support;

/**
 * Six plugins, six vocabularies: `amazonses`, `AWS` and `ses` are one provider, `mail`,
 * `default` and `PHPMAIL` are PHP's `mail()`, and so on. One table, case-insensitive.
 *
 * @since 1.0.0
 */
final class DriverMap
{
    /**
     * Source mailer id (lower-cased) → target driver id. A mailer absent here has no driver.
     *
     * @since 1.0.0
     * @var array<string, string>
     */
    private const MAP = [
        'smtp'             => 'smtp',
        'other'            => 'smtp',
        'mail'             => 'php',
        'default'          => 'php',
        'php'              => 'php',
        'phpmail'          => 'php',
        'ses'              => 'ses',
        'amazonses'        => 'ses',
        'aws'              => 'ses',
        'gmail'            => 'google',
        'gmail_api'        => 'google',
        'google'           => 'google',
        'outlook'          => 'outlook',
        'office365'        => 'outlook',
        'microsoft'        => 'outlook',
        'sendgrid'         => 'sendgrid',
        'sendgrid_api'     => 'sendgrid',
        'postmark'         => 'postmark',
        'postmark_api'     => 'postmark',
        'mailgun'          => 'mailgun',
        'mailgun_api'      => 'mailgun',
        'sendinblue'       => 'brevo',
        'sendinblue_api'   => 'brevo',
        'brevo'            => 'brevo',
        'sparkpost'        => 'sparkpost',
        'sparkpost_api'    => 'sparkpost',
        'smtpcom'          => 'smtpcom',
        'smtpcom_api'      => 'smtpcom',
        'sendlayer'        => 'sendlayer',
        'mailersend'       => 'mailersend',
        'mailersend_api'   => 'mailersend',
        'mandrill'         => 'mandrill',
        'mandrill_api'     => 'mandrill',
        'zoho'             => 'zoho',
        'zoho_api'         => 'zoho',
        'elasticemail'     => 'elasticemail',
        'elasticemail_api' => 'elasticemail',
        'elasticmail'      => 'elasticemail',
        'elastic'          => 'elasticemail',
        'smtp2go'          => 'smtp2go',
        'smtp2go_api'      => 'smtp2go',
        'pepipost'         => 'netcore',
        'pepipostapi'      => 'netcore',
        'netcore'          => 'netcore',
    ];

    /**
     * Display names for the source mailers that have no driver, used in the "set up manually"
     * message.
     *
     * @since 1.0.0
     * @var array<string, string>
     */
    private const LABELS = [
        'mailjet'        => 'Mailjet',
        'mailjet_api'    => 'Mailjet',
        'resend'         => 'Resend',
        'resend_api'     => 'Resend',
        'tosend'         => 'ToSend',
        'cloudflare'     => 'Cloudflare Email',
        'cloudflare_api' => 'Cloudflare Email',
        'mailtrap_api'   => 'Mailtrap',
        'emailit'        => 'Emailit',
        'emailit_api'    => 'Emailit',
        'maileroo'       => 'Maileroo',
        'maileroo_api'   => 'Maileroo',
        'sweego_api'     => 'Sweego',
        'sendpulse_api'  => 'SendPulse',
        'surecontact'    => 'SureContact',
        'simulator'      => 'the simulator',
        'mailgun'        => 'Mailgun',
        'brevo'          => 'Brevo',
        'sendinblue'     => 'Brevo',
        'sparkpost'      => 'SparkPost',
        'smtpcom'        => 'SMTP.com',
        'sendlayer'      => 'SendLayer',
        'mailersend'     => 'MailerSend',
        'mandrill'       => 'Mandrill',
        'zoho'           => 'Zoho Mail',
        'elasticemail'   => 'Elastic Email',
        'elastic'        => 'Elastic Email',
        'elasticmail'    => 'Elastic Email',
        'smtp2go'        => 'SMTP2GO',
        'pepipost'       => 'Netcore',
        'pepipostapi'    => 'Netcore',
        'netcore'        => 'Netcore',
    ];

    /**
     * The target driver for a source mailer id.
     *
     * @since 1.0.0
     *
     * @param  string $sourceMailer The source plugin's mailer id, any case.
     * @return string|null Null when the plugin has no transport for it.
     */
    public static function toDriver(string $sourceMailer): ?string
    {
        $key = strtolower(trim($sourceMailer));

        return self::MAP[$key] ?? null;
    }

    /**
     * A display name for a source mailer id.
     *
     * @since 1.0.0
     *
     * @param  string $sourceMailer The source plugin's mailer id, any case.
     * @return string
     */
    public static function label(string $sourceMailer): string
    {
        $key = strtolower(trim($sourceMailer));
        if (isset(self::LABELS[$key])) {
            return self::LABELS[$key];
        }
        $driver = self::MAP[$key] ?? null;

        return match ($driver) {
            'smtp'     => 'Custom SMTP',
            'php'      => 'PHP Mail',
            'ses'      => 'Amazon SES',
            'google'   => 'Google Workspace',
            'outlook'  => 'Microsoft 365',
            'sendgrid' => 'SendGrid',
            'postmark' => 'Postmark',
            default    => ucfirst(str_replace('_api', '', $key)),
        };
    }
}
