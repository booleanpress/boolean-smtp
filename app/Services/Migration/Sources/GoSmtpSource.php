<?php
/**
 * Reads GoSMTP's connections; its free edition keeps no email log.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration\Sources;

use BooleanSmtp\Services\Migration\Canonical\CanonicalConnection;
use BooleanSmtp\Services\Migration\SourceContext;
use BooleanSmtp\Services\Migration\Support\DriverMap;

/**
 * GoSMTP keeps `gosmtp_options['mailer'][id]` entries — id `0` is the primary connection, whose
 * sender fields sit at the option's top level, while an additional connection carries its own
 * — with the SMTP password in plain text and the mailer under `mail_type`.
 *
 * @since 1.0.0
 */
final class GoSmtpSource extends AbstractSource
{
    /** @since 1.0.0 */
    public const OPTION = 'gosmtp_options';

    /**
     * Stable source id.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function id(): string
    {
        return 'gosmtp';
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
        return 'GoSMTP';
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
        $options = $context->option(self::OPTION);

        return \is_array($options) && ! empty($options['mailer']) && \is_array($options['mailer']);
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
        $options = $context->option(self::OPTION);
        if (! \is_array($options) || empty($options['mailer']) || ! \is_array($options['mailer'])) {
            return [];
        }

        $out = [];
        foreach ($options['mailer'] as $id => $entry) {
            if (! \is_array($entry)) {
                continue;
            }
            $primary = (string) $id === '0';
            $senderSource = $primary ? $options : $entry;
            $out[] = $this->connection((string) $id, $entry, $senderSource, $primary);
        }

        return $out;
    }

    /**
     * One GoSMTP connection.
     *
     * @since 1.0.0
     *
     * @param  string               $id           The entry's key.
     * @param  array<string, mixed> $entry        The mailer entry.
     * @param  array<string, mixed> $senderSource Where the sender fields live for this entry.
     * @param  bool                 $primary      Whether it is the primary connection.
     * @return CanonicalConnection
     */
    private function connection(string $id, array $entry, array $senderSource, bool $primary): CanonicalConnection
    {
        $mailer = strtolower($this->str($entry, 'mail_type') ?: 'mail');
        $sender = $this->sender(
            $this->str($senderSource, 'from_email'),
            $this->str($senderSource, 'from_name'),
            $this->yes($senderSource['force_from_email'] ?? null, false),
            $this->yes($senderSource['force_from_name'] ?? null, false),
            $this->yes($senderSource['return_path'] ?? null, false),
        );
        $name = $this->str($entry, 'nickname') ?: $this->untitledName($mailer);

        switch ($mailer) {
            case 'smtp':
                $auth     = $this->yes($entry['smtp_auth'] ?? null, false);
                $settings = $sender + $this->smtp(
                    $this->str($entry, 'smtp_host'),
                    $entry['smtp_port'] ?? 0,
                    $entry['encryption'] ?? 'none',
                    $auth,
                    $auth ? $this->str($entry, 'smtp_username') : '',
                    $auth ? $this->str($entry, 'smtp_password') : '',
                    true,
                    ! $this->yes($entry['disable_ssl_verification'] ?? null, false),
                );
                $driver = 'smtp';
                break;
            case 'mail':
                $settings = $sender + ['key_store' => 'db'];
                $driver   = 'php';
                break;
            case 'gmail':
                $settings = $sender + ['delivery_mode' => 'api', 'client_id' => $this->str($entry, 'client_id'), 'client_secret' => $this->str($entry, 'client_secret'), 'key_store' => 'db'];
                $driver   = 'google';
                break;
            case 'outlook':
                $settings = $sender + ['delivery_mode' => 'api', 'client_id' => $this->str($entry, 'client_id'), 'client_secret' => $this->str($entry, 'client_secret'), 'tenant_id' => 'common', 'key_store' => 'db'];
                $driver   = 'outlook';
                break;
            case 'sendgrid':
                $settings = $sender + ['api_key' => $this->str($entry, 'api_key')];
                $driver   = 'sendgrid';
                break;
            case 'postmark':
                $settings = $sender + ['server_token' => $this->str($entry, 'server_api_token'), 'message_stream' => $this->str($entry, 'message_stream_id') ?: 'outbound'];
                $driver   = 'postmark';
                break;
            case 'amazonses':
                return new CanonicalConnection(
                    $this->id(), $id, $name, null, $mailer, [], [], $primary,
                    $this->translator->translate('GoSMTP\'s Amazon SES connection belongs to its Pro edition and cannot be read; set up Amazon SES with your access key.')
                );
            default:
                $settings = $sender;
                $driver   = DriverMap::toDriver($mailer);
        }

        return new CanonicalConnection(
            sourcePlugin: $this->id(),
            sourceKey: $id,
            name: $name,
            driver: $driver,
            sourceMailer: $mailer,
            settings: $settings,
            wasDefault: $primary,
        );
    }
}
