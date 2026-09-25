<?php
/**
 * Reads Post SMTP's transport, its fallback SMTP server and its email log.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration\Sources;

use BooleanSmtp\Services\Migration\Canonical\CanonicalConnection;
use BooleanSmtp\Services\Migration\Canonical\CanonicalEmailLog;
use BooleanSmtp\Services\Migration\Decoders\Base64Value;
use BooleanSmtp\Services\Migration\SourceContext;
use BooleanSmtp\Services\Migration\Support\AddressParser;
use BooleanSmtp\Services\Migration\Support\DriverMap;
use BooleanSmtp\Services\Migration\Support\HeaderLines;

/**
 * Post SMTP keeps one flat `postman_options` array with a single `transport_type`, base64
 * passwords and API keys, plain OAuth client credentials, and an optional fixed fallback SMTP
 * server. Its log table `post_smtp_logs` stores every field as text, `success` as `1`, `0` or a
 * status string, and `time` as a site-local Unix timestamp.
 *
 * @since 1.0.0
 */
final class PostSmtpSource extends AbstractSource
{
    use Concerns\ReadsLogTable;

    /** @since 1.0.0 */
    public const OPTION = 'postman_options';

    /**
     * Stable source id.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function id(): string
    {
        return 'post-smtp';
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
        return 'Post SMTP';
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

        return \is_array($options) && $this->str($options, 'transport_type') !== '';
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
        if (! \is_array($options) || $this->str($options, 'transport_type') === '') {
            return [];
        }
        $out = [$this->primary($context, $options)];
        if ($this->yes($options['fallback_smtp_enabled'] ?? null, false) && $this->str($options, 'fallback_smtp_hostname') !== '') {
            $out[] = $this->fallback($context, $options);
        }

        return $out;
    }

    /**
     * The transport Post SMTP sends through.
     *
     * @since 1.0.0
     *
     * @param  SourceContext        $context Site access.
     * @param  array<string, mixed> $options The option.
     * @return CanonicalConnection
     */
    private function primary(SourceContext $context, array $options): CanonicalConnection
    {
        $transport = strtolower($this->str($options, 'transport_type'));
        $mailer    = str_ends_with($transport, '_api') ? substr($transport, 0, -4) : $transport;
        $sender    = $this->sender(
            $this->str($options, 'sender_email'),
            $this->str($options, 'sender_name'),
            $this->yes($options['prevent_sender_email_override'] ?? null, false),
            $this->yes($options['prevent_sender_name_override'] ?? null, false),
            $this->str($options, 'envelope_sender') === '' || strcasecmp($this->str($options, 'envelope_sender'), $this->str($options, 'sender_email')) === 0,
        );
        $missing = [];
        $apiKey  = function (string $key, string $targetKey) use ($context, $options, &$missing): string {
            if ($context->constantSet('POST_SMTP_API_KEY')) {
                $missing[] = $targetKey;

                return '';
            }
            $stored = $this->str($options, $key);

            return $stored === '' ? '' : (Base64Value::once($stored) ?? $stored);
        };

        switch ($transport) {
            case 'smtp':
                $authType = strtolower($this->str($options, 'auth_type') ?: 'none');
                if ($authType === 'oauth2') {
                    return new CanonicalConnection(
                        $this->id(), 'primary', $this->untitledName('smtp'), null, 'smtp_oauth2', [], [], true,
                        $this->translator->translate('Post SMTP\'s SMTP with OAuth 2.0 cannot be carried over; set up Google Workspace or Microsoft 365 with your app\'s client id and secret.')
                    );
                }
                $auth     = $authType !== 'none';
                $password = '';
                $username = '';
                if ($auth) {
                    $username = $context->constantSet('POST_SMTP_AUTH_USERNAME') ? (string) $context->constant('POST_SMTP_AUTH_USERNAME') : $this->str($options, 'basic_auth_username');
                    if ($context->constantSet('POST_SMTP_AUTH_PASSWORD')) {
                        $missing[] = 'password';
                    } else {
                        $stored   = $this->str($options, 'basic_auth_password');
                        $password = $stored === '' ? '' : (Base64Value::once($stored) ?? $stored);
                    }
                }
                $settings = $sender + $this->smtp(
                    $this->str($options, 'hostname'),
                    $options['port'] ?? 0,
                    $options['enc_type'] ?? 'none',
                    $auth,
                    $username,
                    $password,
                );
                $driver = 'smtp';
                break;
            case 'gmail_api':
                $clientId = $this->str($options, 'oauth_client_id');
                $secret   = $this->str($options, 'oauth_client_secret');
                if ($clientId === '' && $secret === '') {
                    return new CanonicalConnection(
                        $this->id(), 'primary', $this->untitledName('gmail'), null, 'gmail_api', [], [], true,
                        $this->translator->translate('Post SMTP\'s one-click Gmail sends through Post SMTP\'s own service; there is no credential to carry over. Set up Google Workspace with your own app.')
                    );
                }
                $settings = $sender + ['delivery_mode' => 'api', 'client_id' => $clientId, 'client_secret' => $secret, 'key_store' => 'db'];
                $driver   = 'google';
                break;
            case 'sendgrid_api':
                $settings = $sender + ['api_key' => $apiKey('sendgrid_api_key', 'api_key')];
                $driver   = 'sendgrid';
                break;
            case 'postmark_api':
                $settings = $sender + ['server_token' => $apiKey('postmark_api_key', 'server_token'), 'message_stream' => 'outbound'];
                $driver   = 'postmark';
                break;
            default:
                $settings = $sender;
                $driver   = DriverMap::toDriver($mailer);
        }

        return new CanonicalConnection(
            sourcePlugin: $this->id(),
            sourceKey: 'primary',
            name: $this->untitledName($mailer),
            driver: $driver,
            sourceMailer: $mailer,
            settings: $settings,
            missingSecrets: array_values(array_unique($missing)),
            wasDefault: true,
        );
    }

    /**
     * The fixed fallback SMTP server, when enabled.
     *
     * @since 1.0.0
     *
     * @param  SourceContext        $context Site access.
     * @param  array<string, mixed> $options The option.
     * @return CanonicalConnection
     */
    private function fallback(SourceContext $context, array $options): CanonicalConnection
    {
        $auth     = $this->yes($options['fallback_smtp_use_auth'] ?? null, false);
        $missing  = [];
        $password = '';
        $username = '';
        if ($auth) {
            $username = $context->constantSet('POST_SMTP_FALLBACK_AUTH_USERNAME') ? (string) $context->constant('POST_SMTP_FALLBACK_AUTH_USERNAME') : $this->str($options, 'fallback_smtp_username');
            if ($context->constantSet('POST_SMTP_FALLBACK_AUTH_PASSWORD')) {
                $missing[] = 'password';
            } else {
                $password = Base64Value::untilPlain($this->str($options, 'fallback_smtp_password'));
            }
        }
        $sender = $this->sender(
            $this->str($options, 'fallback_from_email') ?: $this->str($options, 'sender_email'),
            $this->str($options, 'sender_name'),
            $this->yes($options['prevent_sender_email_override'] ?? null, false),
            $this->yes($options['prevent_sender_name_override'] ?? null, false),
            true,
        );

        return new CanonicalConnection(
            sourcePlugin: $this->id(),
            sourceKey: 'fallback',
            name: $this->translator->translate('Fallback SMTP (imported from {{plugin}})', ['plugin' => $this->name()]),
            driver: 'smtp',
            sourceMailer: 'smtp',
            settings: $sender + $this->smtp(
                $this->str($options, 'fallback_smtp_hostname'),
                $options['fallback_smtp_port'] ?? 0,
                $options['fallback_smtp_security'] ?? 'none',
                $auth,
                $username,
                $password,
            ),
            missingSecrets: $missing,
        );
    }

    /**
     * The log table, without the site prefix.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected function logTable(): string
    {
        return 'post_smtp_logs';
    }

    /**
     * The column holding the row's timestamp.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected function logDateColumn(): string
    {
        return 'time';
    }

    /**
     * Which shape the date column has (one of the `DATE_*` constants).
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected function logDateKind(): string
    {
        return self::DATE_LOCAL_UNIX;
    }

    /**
     * The columns the mapper reads.
     *
     * @since 1.0.0
     *
     * @return list<string>
     */
    protected function logColumns(): array
    {
        return ['id', 'solution', 'success', 'from_header', 'to_header', 'cc_header', 'bcc_header', 'reply_to_header', 'transport_uri', 'original_subject', 'original_message', 'original_headers', 'time'];
    }

    /**
     * Map one row of the table.
     *
     * @since 1.0.0
     *
     * @param  array $row The row.
     * @return CanonicalEmailLog
     */
    protected function mapLogRow(array $row): CanonicalEmailLog
    {
        $success  = trim((string) ($row['success'] ?? ''));
        $solution = trim((string) ($row['solution'] ?? ''));
        $from     = AddressParser::one((string) ($row['from_header'] ?? ''));
        $headers  = HeaderLines::toMap(HeaderLines::lines((string) ($row['original_headers'] ?? '')));
        unset($headers['To'], $headers['Cc'], $headers['Bcc'], $headers['From'], $headers['Subject']);
        $replyTo = AddressParser::list((string) ($row['reply_to_header'] ?? ''));
        if ($replyTo !== []) {
            $headers['Reply-To'] = implode(', ', $replyTo);
        }

        // `success` is `1` on success, `0` or the failure text on failure, and a status string
        // for a queued send or a send that went through the fallback server.
        $error = null;
        if ($success === '1') {
            $status = 'delivered';
        } elseif (strcasecmp($success, 'In Queue') === 0) {
            $status = 'pending';
        } elseif ($success === '0' || $success === '') {
            $status = 'failed';
            $error  = $solution !== '' && ! str_starts_with($solution, 'Not found') ? $solution : null;
        } elseif (str_starts_with($success, 'Sent')) {
            $status = 'delivered';
        } else {
            $status = 'failed';
            $error  = $success;
            if ($solution !== '' && ! str_starts_with($solution, 'Not found') && ! str_starts_with($solution, 'All good')) {
                $error .= ' — ' . $solution;
            }
        }

        $uri      = (string) ($row['transport_uri'] ?? '');
        $provider = null;
        $scheme   = '';
        if ($uri !== '') {
            $scheme = strtolower((string) strstr($uri, ':', true));
            $host   = strtolower($uri);
            $provider = match (true) {
                $scheme === 'smtp'                        => 'smtp',
                str_contains($host, 'googleapis')         => 'google',
                str_contains($host, 'sendgrid')           => 'sendgrid',
                str_contains($host, 'postmarkapp')        => 'postmark',
                str_contains($host, 'mailgun')            => 'mailgun',
                str_contains($host, 'sendinblue') || str_contains($host, 'brevo') => 'brevo',
                str_contains($host, 'sparkpost')          => 'sparkpost',
                str_contains($host, 'mandrill')           => 'mandrill',
                default                                   => DriverMap::toDriver($scheme) ?? ($scheme !== '' ? $scheme : null),
            };
        }

        return new CanonicalEmailLog(
            sourcePlugin: $this->id(),
            sourceLogId: (int) $row['id'],
            to: AddressParser::list((string) ($row['to_header'] ?? '')),
            subject: (string) ($row['original_subject'] ?? ''),
            status: $status,
            sourceStatus: $success,
            cc: AddressParser::list((string) ($row['cc_header'] ?? '')),
            bcc: AddressParser::list((string) ($row['bcc_header'] ?? '')),
            fromEmail: $from['email'] !== '' ? $from['email'] : null,
            fromName: $from['name'] !== '' ? $from['name'] : null,
            body: isset($row['original_message']) ? (string) $row['original_message'] : null,
            headers: $headers,
            errorMessage: $error,
            provider: $provider,
            // Post SMTP keeps one transport at a time, so its scheme names the connection.
            sourceProvider: $scheme !== '' ? $scheme : null,
            createdAt: $this->rowDate($row['time'] ?? 0),
        );
    }
}
