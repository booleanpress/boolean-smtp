<?php
/**
 * The reader WP Mail SMTP and Easy WP SMTP share: one option in `group.key` form, one mailer
 * at a time, a sodium-sealed SMTP password and `<PREFIX>_*` constants.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration\Sources;

use BooleanSmtp\Services\Migration\Canonical\CanonicalConnection;
use BooleanSmtp\Services\Migration\Decoders\SodiumSecretBox;
use BooleanSmtp\Services\Migration\SourceContext;
use BooleanSmtp\Services\Migration\Support\DriverMap;

/**
 * Both plugins keep `mail{from_email, from_name, mailer, return_path, from_email_force,
 * from_name_force}`, `smtp{host, port, encryption, autotls, auth, user, pass}` and one group
 * per mailer, encrypt only `smtp.pass`, and let a `wp-config.php` constant replace any field
 * once the master constant (`WPMS_ON`, `EASY_WP_SMTP_ON`) is `true` — in which case the
 * option holds an empty value for that field. Non-secret constants are read; a secret one
 * leaves the credential to be entered again.
 *
 * @since 1.0.0
 */
abstract class AbstractWpMailSmtpShapedSource extends AbstractSource
{
    /**
     * The option holding the settings.
     *
     * @since 1.0.0
     *
     * @return string
     */
    abstract protected function optionName(): string;

    /**
     * The option holding the base64 sodium key.
     *
     * @since 1.0.0
     *
     * @return string
     */
    abstract protected function keyOptionName(): string;

    /**
     * Prefix of the plugin's constants, e.g. `WPMS_`.
     *
     * @since 1.0.0
     *
     * @return string
     */
    abstract protected function constantPrefix(): string;

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
        $settings = $context->option($this->optionName());

        return \is_array($settings) && (! empty($settings['mail']) || ! empty($settings['smtp']));
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
        $settings = $context->option($this->optionName());
        if (! \is_array($settings)) {
            return [];
        }

        return [$this->connectionFromOption($context, $settings)];
    }

    /**
     * The single connection the option describes.
     *
     * @since 1.0.0
     *
     * @param  SourceContext        $context  Site access.
     * @param  array<string, mixed> $settings The option.
     * @return CanonicalConnection
     */
    protected function connectionFromOption(SourceContext $context, array $settings): CanonicalConnection
    {
        $constants = $context->constantSet($this->constantPrefix() . 'ON') && $context->constant($this->constantPrefix() . 'ON') === true;
        $mail      = \is_array($settings['mail'] ?? null) ? $settings['mail'] : [];
        $value     = function (array $group, string $key, string $constant) use ($context, $constants): string {
            if ($constants && $context->constantSet($this->constantPrefix() . $constant)) {
                return trim((string) $context->constant($this->constantPrefix() . $constant));
            }

            return $this->str($group, $key);
        };
        $flag = function (array $group, string $key, string $constant, bool $absent) use ($context, $constants): bool {
            if ($constants && $context->constantSet($this->constantPrefix() . $constant)) {
                return $this->yes($context->constant($this->constantPrefix() . $constant), $absent);
            }

            return $this->yes($group[$key] ?? null, $absent);
        };

        $mailer  = strtolower($value($mail, 'mailer', 'MAILER') ?: 'mail');
        $missing = [];
        $sender  = $this->sender(
            $value($mail, 'from_email', 'MAIL_FROM'),
            $value($mail, 'from_name', 'MAIL_FROM_NAME'),
            $flag($mail, 'from_email_force', 'MAIL_FROM_FORCE', false),
            $flag($mail, 'from_name_force', 'MAIL_FROM_NAME_FORCE', false),
            $flag($mail, 'return_path', 'SET_RETURN_PATH', false),
        );
        $group = \is_array($settings[$mailer] ?? null) ? $settings[$mailer] : [];

        // A secret kept in a constant is not copied; an API key kept in the option is plain text.
        $secret = function (array $group, string $key, string $constant, string $targetKey) use ($context, $constants, &$missing): string {
            if ($constants && $context->constantSet($this->constantPrefix() . $constant)) {
                $missing[] = $targetKey;

                return '';
            }

            return $this->str($group, $key);
        };

        switch ($mailer) {
            case 'smtp':
            case 'pepipost':
                $auth     = $flag($group, 'auth', 'SMTP_AUTH', false);
                $password = '';
                if ($auth) {
                    if ($constants && $context->constantSet($this->constantPrefix() . 'SMTP_PASS')) {
                        $missing[] = 'password';
                    } else {
                        $password = $this->smtpPassword($context, $this->str($group, 'pass'), $mailer === 'smtp');
                        if ($password === null) {
                            $missing[] = 'password';
                            $password  = '';
                        }
                    }
                }
                $settingsOut = $sender + $this->smtp(
                    $value($group, 'host', 'SMTP_HOST'),
                    $value($group, 'port', 'SMTP_PORT') ?: 0,
                    $constants && $context->constantSet($this->constantPrefix() . 'SSL') ? $context->constant($this->constantPrefix() . 'SSL') : ($group['encryption'] ?? 'none'),
                    $auth,
                    $auth ? $value($group, 'user', 'SMTP_USER') : '',
                    $password,
                    $flag($group, 'autotls', 'SMTP_AUTOTLS', true),
                );
                $driver = 'smtp';
                break;
            case 'mail':
                $settingsOut = $sender + ['key_store' => 'db'];
                $driver      = 'php';
                break;
            case 'gmail':
                $settingsOut = $sender + [
                    'delivery_mode' => 'api',
                    'client_id'     => $value($group, 'client_id', 'GMAIL_CLIENT_ID'),
                    'client_secret' => $secret($group, 'client_secret', 'GMAIL_CLIENT_SECRET', 'client_secret'),
                    'key_store'     => 'db',
                ];
                $driver = 'google';
                break;
            case 'outlook':
                $settingsOut = $sender + [
                    'delivery_mode' => 'api',
                    'client_id'     => $value($group, 'client_id', 'OUTLOOK_CLIENT_ID'),
                    'client_secret' => $secret($group, 'client_secret', 'OUTLOOK_CLIENT_SECRET', 'client_secret'),
                    'tenant_id'     => 'common',
                    'key_store'     => 'db',
                ];
                $driver = 'outlook';
                break;
            case 'amazonses':
                $region      = $value($group, 'region', 'AMAZONSES_REGION') ?: 'us-east-1';
                $settingsOut = $sender + [
                    'delivery_mode'  => 'api',
                    'region'         => $region,
                    'api_region'     => $region,
                    'api_access_key' => $value($group, 'client_id', 'AMAZONSES_CLIENT_ID'),
                    'api_secret'     => $secret($group, 'client_secret', 'AMAZONSES_CLIENT_SECRET', 'api_secret'),
                    'key_store'      => 'db',
                ];
                $driver = 'ses';
                break;
            case 'sendgrid':
                $settingsOut = $sender + ['api_key' => $secret($group, 'api_key', 'SENDGRID_API_KEY', 'api_key')];
                $driver      = 'sendgrid';
                break;
            case 'postmark':
                $settingsOut = $sender + [
                    'server_token'   => $secret($group, 'server_api_token', 'POSTMARK_SERVER_API_TOKEN', 'server_token'),
                    'message_stream' => $value($group, 'message_stream', 'POSTMARK_MESSAGE_STREAM') ?: 'outbound',
                ];
                $driver = 'postmark';
                break;
            default:
                $settingsOut = $sender;
                $driver      = DriverMap::toDriver($mailer);
        }

        return new CanonicalConnection(
            sourcePlugin: $this->id(),
            sourceKey: 'primary',
            name: $this->untitledName($mailer),
            driver: $driver,
            sourceMailer: $mailer,
            settings: $settingsOut,
            missingSecrets: array_values(array_unique($missing)),
            wasDefault: true,
        );
    }

    /**
     * The SMTP password from its stored form: opened with the site's sodium key, accepted as
     * plain text when it was never sealed, refused when it still looks sealed after the attempt.
     *
     * @since 1.0.0
     *
     * @param  SourceContext $context Site access.
     * @param  string        $stored  The stored `smtp.pass`.
     * @param  bool          $sealed  Whether this mailer's password is sealed at all (`pepipost` is not).
     * @return string|null The password, or null when it cannot be recovered.
     */
    protected function smtpPassword(SourceContext $context, string $stored, bool $sealed): ?string
    {
        if ($stored === '') {
            return '';
        }
        if (! $sealed) {
            return $stored;
        }
        $box   = SodiumSecretBox::fromSite($context, $this->keyOptionName(), $this->constantPrefix() . 'CRYPTO_KEY');
        $plain = $box->decrypt($stored);
        if ($plain !== null) {
            return $plain;
        }

        return SodiumSecretBox::looksSealed($stored) ? null : $stored;
    }
}
