<?php
/**
 * Reads FluentSMTP's connections and email log.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration\Sources;

use BooleanSmtp\Contracts\TranslatorContract;
use BooleanSmtp\Services\Migration\Canonical\CanonicalConnection;
use BooleanSmtp\Services\Migration\Canonical\CanonicalEmailLog;
use BooleanSmtp\Services\Migration\Decoders\FluentSmtpCipher;
use BooleanSmtp\Services\Migration\SourceContext;
use BooleanSmtp\Services\Migration\Support\AddressParser;
use BooleanSmtp\Services\Migration\Support\DateNormalizer;
use BooleanSmtp\Services\Migration\Support\DriverMap;

/**
 * FluentSMTP keeps every connection in the `fluentmail-settings` option under
 * `connections[md5(sender email)] = {title, provider_settings}`, with `misc` holding the
 * default connection and the log retention. Credentials are encrypted with the site's
 * authentication salts (see {@see FluentSmtpCipher}); a `key_store` of `wp_config` means the
 * credential lives in a constant and is not copied. Its log table is `fsmpt_email_logs`, with
 * serialized recipients and headers and site-local timestamps.
 *
 * @since 1.0.0
 */
final class FluentSmtpSource extends AbstractSource
{
    use Concerns\ReadsLogTable;

    /** @since 1.0.0 */
    public const OPTION = 'fluentmail-settings';

    /**
     * The `extra.provider` FluentSMTP records for a message its simulation switch swallowed.
     *
     * @since 1.0.0
     * @var string
     */
    private const SIMULATOR = 'Simulator';

    /**
     * Which `provider_settings` keys FluentSMTP encrypts, and from which `encrypt_version` on
     * (a stored `encrypt_version` below the field's means the field is still plain).
     *
     * @since 1.0.0
     * @var array<string, array<string, int>>
     */
    private const ENCRYPTED_FIELDS = [
        'smtp'        => ['password' => 1],
        'ses'         => ['secret_key' => 1, 'access_key' => 2],
        'mailgun'     => ['api_key' => 1],
        'sendgrid'    => ['api_key' => 1],
        'sendinblue'  => ['api_key' => 1],
        'sparkpost'   => ['api_key' => 1],
        'pepipost'    => ['api_key' => 1],
        'postmark'    => ['api_key' => 1],
        'elasticmail' => ['api_key' => 1],
        'tosend'      => ['api_key' => 1],
        'cloudflare'  => ['api_key' => 1],
        'gmail'       => ['client_secret' => 1, 'access_token' => 2, 'refresh_token' => 2],
        'outlook'     => ['client_secret' => 1, 'access_token' => 2, 'refresh_token' => 2],
    ];

    /**
     * @since 1.0.0
     *
     * @param DateNormalizer         $dates      Converts the site-local timestamps to UTC.
     * @param TranslatorContract     $translator Translates names and reasons.
     * @param FluentSmtpCipher|null  $cipher     The cipher; the site's own constants when null.
     */
    public function __construct(
        DateNormalizer $dates,
        TranslatorContract $translator,
        private ?FluentSmtpCipher $cipher = null,
    ) {
        parent::__construct($dates, $translator);
    }

    /**
     * Stable source id.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function id(): string
    {
        return 'fluent-smtp';
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
        return 'FluentSMTP';
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
        $settings = $context->option(self::OPTION);

        return \is_array($settings) && ! empty($settings['connections']) && \is_array($settings['connections']);
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
        $settings = $context->option(self::OPTION);
        if (! \is_array($settings) || empty($settings['connections']) || ! \is_array($settings['connections'])) {
            return [];
        }
        $default  = (string) ($settings['misc']['default_connection'] ?? '');
        $encrypts = ($settings['use_encrypt'] ?? '') === 'yes';
        $version  = (int) ($settings['encrypt_version'] ?? 1);

        $out = [];
        foreach ($settings['connections'] as $hash => $connection) {
            if (! \is_array($connection)) {
                continue;
            }
            $ps = \is_array($connection['provider_settings'] ?? null) ? $connection['provider_settings'] : [];
            $out[] = $this->connection($context, (string) $hash, (string) ($connection['title'] ?? ''), $ps, $default === (string) $hash, $encrypts, $version);
        }

        return $out;
    }

    /**
     * Global values the source holds that become suggestions on the Review step.
     *
     * @since 1.0.0
     *
     * @param  SourceContext $context Site access.
     * @return array{retention_days?: int}
     */
    public function suggestions(SourceContext $context): array
    {
        $settings = $context->option(self::OPTION);
        $days     = (int) ($settings['misc']['log_saved_interval_days'] ?? 0);

        return $days > 0 ? ['retention_days' => $days] : [];
    }

    /**
     * One FluentSMTP connection.
     *
     * @since 1.0.0
     *
     * @param  SourceContext        $context  Site access.
     * @param  string               $hash     The connection's key.
     * @param  string               $title    Its title.
     * @param  array<string, mixed> $ps       Its `provider_settings`.
     * @param  bool                 $default  Whether it is the default connection.
     * @param  bool                 $encrypts Whether the option's credentials are encrypted.
     * @param  int                  $version  The option's `encrypt_version`.
     * @return CanonicalConnection
     */
    private function connection(SourceContext $context, string $hash, string $title, array $ps, bool $default, bool $encrypts, int $version): CanonicalConnection
    {
        $provider = strtolower($this->str($ps, 'provider') ?: 'smtp');
        $wpConfig = $this->str($ps, 'key_store') === 'wp_config';
        $missing  = [];

        $secret = function (string $field, string $targetKey) use ($ps, $provider, $wpConfig, $encrypts, $version, &$missing): string {
            if ($wpConfig) {
                $missing[] = $targetKey;

                return '';
            }
            $stored = $this->str($ps, $field);
            if ($stored === '') {
                return '';
            }
            $fieldVersion = self::ENCRYPTED_FIELDS[$provider][$field] ?? null;
            if (! $encrypts || $fieldVersion === null || $fieldVersion > $version) {
                return $stored;
            }
            $plain = $this->cipher()->decrypt($stored);
            if ($plain === null) {
                $missing[] = $targetKey;

                return '';
            }

            return $plain;
        };

        $sender = $this->sender(
            $this->str($ps, 'sender_email'),
            html_entity_decode($this->str($ps, 'sender_name'), \ENT_QUOTES),
            $this->yes($ps['force_from_email'] ?? null, true),
            $this->yes($ps['force_from_name'] ?? null, false),
            $this->yes($ps['return_path'] ?? null, true),
        );
        $name = $title !== '' ? $title : $this->untitledName($provider);

        switch ($provider) {
            case 'smtp':
                $settings = $sender + $this->smtp(
                    $this->str($ps, 'host'),
                    $ps['port'] ?? 0,
                    $ps['encryption'] ?? 'none',
                    $this->yes($ps['auth'] ?? null, false),
                    $wpConfig ? (string) ($context->constant('FLUENTMAIL_SMTP_USERNAME') ?? '') : $this->str($ps, 'username'),
                    $this->yes($ps['auth'] ?? null, false) ? $secret('password', 'password') : '',
                    $this->yes($ps['auto_tls'] ?? null, true),
                );
                $driver = 'smtp';
                break;
            case 'ses':
                $region   = $this->str($ps, 'region') ?: 'us-east-1';
                $settings = $sender + [
                    'delivery_mode'  => 'api',
                    'region'         => $region,
                    'api_region'     => $region,
                    'api_access_key' => $secret('access_key', 'api_access_key'),
                    'api_secret'     => $secret('secret_key', 'api_secret'),
                    'key_store'      => 'db',
                ];
                $driver = 'ses';
                break;
            case 'gmail':
                $settings = $sender + [
                    'delivery_mode' => 'api',
                    'client_id'     => $wpConfig ? (string) ($context->constant('FLUENTMAIL_GMAIL_CLIENT_ID') ?? '') : $this->str($ps, 'client_id'),
                    'client_secret' => $secret('client_secret', 'client_secret'),
                    'key_store'     => 'db',
                ];
                $driver = 'google';
                break;
            case 'outlook':
                $settings = $sender + [
                    'delivery_mode' => 'api',
                    'client_id'     => $wpConfig ? (string) ($context->constant('FLUENTMAIL_OUTLOOK_CLIENT_ID') ?? '') : $this->str($ps, 'client_id'),
                    'client_secret' => $secret('client_secret', 'client_secret'),
                    'tenant_id'     => $this->str($ps, 'tenant_id') ?: 'common',
                    'key_store'     => 'db',
                ];
                $driver = 'outlook';
                break;
            case 'postmark':
                $settings = $sender + ['server_token' => $secret('api_key', 'server_token'), 'message_stream' => $this->str($ps, 'message_stream') ?: 'outbound'];
                $driver   = 'postmark';
                break;
            case 'sendgrid':
                $settings = $sender + ['api_key' => $secret('api_key', 'api_key')];
                $driver   = 'sendgrid';
                break;
            case 'default':
                $settings = $sender + ['key_store' => 'db'];
                $driver   = 'php';
                break;
            default:
                $settings = $sender;
                $driver   = DriverMap::toDriver($provider);
        }

        return new CanonicalConnection(
            sourcePlugin: $this->id(),
            sourceKey: $hash,
            name: $name,
            driver: $driver,
            sourceMailer: $provider,
            settings: $settings,
            missingSecrets: array_values(array_unique($missing)),
            wasDefault: $default,
        );
    }

    /**
     * The cipher, from the site's constants unless one was injected.
     *
     * @since 1.0.0
     *
     * @return FluentSmtpCipher
     */
    private function cipher(): FluentSmtpCipher
    {
        return $this->cipher ??= FluentSmtpCipher::fromSiteConstants();
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
        return 'fsmpt_email_logs';
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
        return 'created_at';
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
        return self::DATE_LOCAL_MYSQL;
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
        return ['id', 'to', 'from', 'subject', 'body', 'headers', 'attachments', 'status', 'response', 'extra', 'retries', 'created_at'];
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
        $headers  = AddressParser::decode((string) ($row['headers'] ?? '')) ?? [];
        $response = AddressParser::decode((string) ($row['response'] ?? '')) ?? [];
        $extra    = AddressParser::decode((string) ($row['extra'] ?? '')) ?? [];
        $from     = AddressParser::one((string) ($row['from'] ?? ''));
        $status   = strtolower((string) ($row['status'] ?? ''));

        $headerMap = [];
        $replyTo   = AddressParser::fromStored($headers['reply-to'] ?? []);
        if ($replyTo !== []) {
            $headerMap['Reply-To'] = implode(', ', $replyTo);
        }
        if (! empty($headers['content-type'])) {
            $headerMap['Content-Type'] = (string) $headers['content-type'];
        }

        $attachments = [];
        $stored = AddressParser::decode((string) ($row['attachments'] ?? '')) ?? [];
        foreach ($stored as $tuple) {
            if (! \is_array($tuple)) {
                continue;
            }
            $name = (string) ($tuple[2] ?? $tuple[1] ?? basename((string) ($tuple[0] ?? '')));
            $attachments[] = ['name' => $name !== '' ? $name : 'attachment', 'type' => isset($tuple[4]) ? (string) $tuple[4] : null, 'size' => null];
        }

        $provider  = (string) ($extra['provider'] ?? '');
        // FluentSMTP records the message as sent through "Simulator" when its simulation switch
        // is on: nothing left the site, so the row is history of a simulated send, not a delivery.
        $simulated = strcasecmp($provider, self::SIMULATOR) === 0;
        $error     = null;
        if ($status === 'failed') {
            $error = \is_array($response) ? (string) ($response['message'] ?? ($response['fallback_response']['message'] ?? '')) : '';
            if ($error === '' && \is_array($response['errors'] ?? null)) {
                $error = implode('; ', array_map('strval', $response['errors']));
            }
            $error = $error === '' ? null : $error;
        }

        return new CanonicalEmailLog(
            sourcePlugin: $this->id(),
            sourceLogId: (int) $row['id'],
            to: AddressParser::fromStored($row['to'] ?? ''),
            subject: (string) ($row['subject'] ?? ''),
            status: match (true) {
                $status === 'sent' && $simulated => 'simulated',
                $status === 'sent'               => 'delivered',
                $status === 'failed'             => 'failed',
                default                          => 'pending',
            },
            sourceStatus: $status,
            cc: AddressParser::fromStored($headers['cc'] ?? []),
            bcc: AddressParser::fromStored($headers['bcc'] ?? []),
            fromEmail: $from['email'] !== '' ? $from['email'] : null,
            fromName: $from['name'] !== '' ? $from['name'] : null,
            body: isset($row['body']) ? (string) $row['body'] : null,
            headers: $headerMap,
            attachments: $attachments,
            errorMessage: $error,
            provider: $simulated || $provider === '' ? null : (DriverMap::toDriver($provider) ?? $provider),
            sourceProvider: $provider !== '' ? $provider : null,
            retries: (int) ($row['retries'] ?? 0),
            deliveryTimeMs: isset($extra['send_time_ms']) ? (int) round((float) $extra['send_time_ms']) : null,
            createdAt: $this->rowDate($row['created_at'] ?? ''),
        );
    }
}
