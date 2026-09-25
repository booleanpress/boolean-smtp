<?php
/**
 * Reads Easy WP SMTP's connection, from its 2.x option or its 1.x option.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration\Sources;

use BooleanSmtp\Services\Migration\Canonical\CanonicalConnection;
use BooleanSmtp\Services\Migration\Decoders\LegacyEasyWpSmtpCipher;
use BooleanSmtp\Services\Migration\SourceContext;

/**
 * Easy WP SMTP 2.x stores the WP Mail SMTP shape under `easy_wp_smtp` with `EASY_WP_SMTP_*`
 * constants; a site that never ran 2.x still has the 1.x `swpsmtp_options`, whose password
 * is decided by `swpsmtp_pass_encrypted` and the `swpsmtp_enc_key` option. Neither edition
 * keeps an email log in the free plugin.
 *
 * @since 1.0.0
 */
final class EasyWpSmtpSource extends AbstractWpMailSmtpShapedSource
{
    /** @since 1.0.0 */
    public const LEGACY_OPTION = 'swpsmtp_options';

    /**
     * Stable source id.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function id(): string
    {
        return 'easy-wp-smtp';
    }

    /**
     * The source plugin's display name.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function name(): string
    {
        return 'Easy WP SMTP';
    }

    /**
     * The option holding the settings.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected function optionName(): string
    {
        return 'easy_wp_smtp';
    }

    /**
     * The option holding the base64 sodium key.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected function keyOptionName(): string
    {
        return 'easy_wp_smtp_mail_key';
    }

    /**
     * Prefix of the plugin's constants, e.g. `WPMS_`.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected function constantPrefix(): string
    {
        return 'EASY_WP_SMTP_';
    }

    /**
     * Whether the source's settings are present on this site (installed or left behind).
     *
     * @since 1.0.0
     *
     * @param  SourceContext $context Site access.
     * @return bool
     */
    public function isAvailable(SourceContext $context): bool
    {
        if (parent::isAvailable($context)) {
            return true;
        }
        $legacy = $context->option(self::LEGACY_OPTION);

        return \is_array($legacy) && ! empty($legacy['smtp_settings']) && \is_array($legacy['smtp_settings']);
    }

    /**
     * Every connection the source holds.
     *
     * @since 1.0.0
     *
     * @param  SourceContext $context Site access.
     * @return list<CanonicalConnection>
     */
    public function extractConnections(SourceContext $context): array
    {
        if (parent::isAvailable($context)) {
            return parent::extractConnections($context);
        }
        $legacy = $context->option(self::LEGACY_OPTION);
        if (! \is_array($legacy) || empty($legacy['smtp_settings']) || ! \is_array($legacy['smtp_settings'])) {
            return [];
        }

        return [$this->legacyConnection($context, $legacy)];
    }

    /**
     * The 1.x connection.
     *
     * @since 1.0.0
     *
     * @param  SourceContext        $context Site access.
     * @param  array<string, mixed> $legacy  The `swpsmtp_options` option.
     * @return CanonicalConnection
     */
    private function legacyConnection(SourceContext $context, array $legacy): CanonicalConnection
    {
        $smtp    = $legacy['smtp_settings'];
        $auth    = $this->yes($smtp['autentication'] ?? null, false);
        $missing = [];
        $password = '';
        if ($auth) {
            $cipher   = new LegacyEasyWpSmtpCipher((string) $context->option('swpsmtp_enc_key', ''));
            $password = $cipher->password($this->str($smtp, 'password'), $this->yes($context->option('swpsmtp_pass_encrypted', false), false));
            if ($password === null) {
                $missing[] = 'password';
                $password  = '';
            }
        }
        $host   = $this->str($smtp, 'host');
        $sender = $this->sender(
            $this->str($legacy, 'from_email_field'),
            $this->str($legacy, 'from_name_field'),
            true,
            $this->yes($legacy['force_from_name_replace'] ?? null, false),
            true,
        );
        if ($host === '') {
            return new CanonicalConnection($this->id(), 'primary', $this->untitledName('mail'), 'php', 'mail', $sender + ['key_store' => 'db'], [], true);
        }

        return new CanonicalConnection(
            sourcePlugin: $this->id(),
            sourceKey: 'primary',
            name: $this->untitledName('smtp'),
            driver: 'smtp',
            sourceMailer: 'smtp',
            settings: $sender + $this->smtp(
                $host,
                $smtp['port'] ?? 0,
                $smtp['type_encryption'] ?? 'none',
                $auth,
                $auth ? $this->str($smtp, 'username') : '',
                $password,
                false,
                ! $this->yes($smtp['insecure_ssl'] ?? null, false),
            ),
            missingSecrets: $missing,
            wasDefault: true,
        );
    }
}
