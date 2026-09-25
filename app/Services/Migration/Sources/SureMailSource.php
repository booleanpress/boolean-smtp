<?php
/**
 * Reads SureMail's connections and email log.
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
 * SureMail keeps every connection in `suremails_connections` under `connections[id]` with an
 * upper-case `type` and the fields the type's form declared; the ones it marks as encrypted
 * are base64 without padding. Its log table `suremails_email_log` stores serialized header
 * lines, serialized attachment paths, a serialized list of attempts and site-local timestamps.
 *
 * @since 1.0.0
 */
final class SureMailSource extends AbstractSource
{
    use Concerns\ReadsLogTable;

    /** @since 1.0.0 */
    public const OPTION = 'suremails_connections';

    /**
     * `delete_email_logs_after` values in days; `none` keeps logs forever.
     *
     * @since 1.0.0
     * @var array<string, int>
     */
    private const RETENTION = ['1_day' => 1, '7_days' => 7, '30_days' => 30, '365_days' => 365, '730_days' => 730, 'none' => 0];

    /**
     * Stable source id.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function id(): string
    {
        return 'suremails';
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
        return 'SureMail';
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
        $default = (string) ($settings['default_connection']['id'] ?? '');

        $out = [];
        foreach ($settings['connections'] as $id => $connection) {
            if (! \is_array($connection) || strtoupper($this->str($connection, 'type')) === 'SIMULATOR') {
                continue;
            }
            $out[] = $this->connection((string) ($connection['id'] ?? $id), $connection, $default === (string) ($connection['id'] ?? $id));
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
        $key      = (string) ($settings['delete_email_logs_after'] ?? '');

        return isset(self::RETENTION[$key]) ? ['retention_days' => self::RETENTION[$key]] : [];
    }

    /**
     * One SureMail connection.
     *
     * @since 1.0.0
     *
     * @param  string               $id         The connection's id.
     * @param  array<string, mixed> $connection The connection.
     * @param  bool                 $default    Whether it is the default connection.
     * @return CanonicalConnection
     */
    private function connection(string $id, array $connection, bool $default): CanonicalConnection
    {
        $type   = strtoupper($this->str($connection, 'type'));
        $mailer = strtolower($type);
        $name   = $this->str($connection, 'connection_title') ?: $this->untitledName($mailer);
        $sender = $this->sender(
            $this->str($connection, 'from_email'),
            $this->str($connection, 'from_name'),
            $this->yes($connection['force_from_email'] ?? null, true),
            $this->yes($connection['force_from_name'] ?? null, true),
            $this->yes($connection['return_path'] ?? null, true),
        );
        $missing = [];
        $secret  = function (string $key, string $targetKey) use ($connection, &$missing): string {
            $stored = $this->str($connection, $key);
            if ($stored === '') {
                return '';
            }
            $plain = Base64Value::unpadded($stored);
            if ($plain === null) {
                $missing[] = $targetKey;

                return '';
            }

            return $plain;
        };

        switch ($type) {
            case 'PHPMAIL':
                $settings = $sender + ['key_store' => 'db'];
                $driver   = 'php';
                break;
            case 'SMTP':
                $username = $this->str($connection, 'username');
                $settings = $sender + $this->smtp(
                    $this->str($connection, 'host'),
                    $connection['port'] ?? 0,
                    $connection['encryption'] ?? 'tls',
                    $username !== '',
                    $username,
                    $username !== '' ? $secret('password', 'password') : '',
                    $this->yes($connection['auto_tls'] ?? null, true),
                );
                $driver = 'smtp';
                break;
            case 'AWS':
                $region   = $this->str($connection, 'region') ?: 'us-east-1';
                $settings = $sender + [
                    'delivery_mode'  => 'api',
                    'region'         => $region,
                    'api_region'     => $region,
                    'api_access_key' => $secret('username', 'api_access_key'),
                    'api_secret'     => $secret('password', 'api_secret'),
                    'key_store'      => 'db',
                ];
                $driver = 'ses';
                break;
            case 'GMAIL':
                $settings = $sender + ['delivery_mode' => 'api', 'client_id' => $this->str($connection, 'client_id'), 'client_secret' => $secret('client_secret', 'client_secret'), 'key_store' => 'db'];
                $driver   = 'google';
                break;
            case 'OUTLOOK':
                $settings = $sender + ['delivery_mode' => 'api', 'client_id' => $secret('client_id', 'client_id'), 'client_secret' => $secret('client_secret', 'client_secret'), 'tenant_id' => 'common', 'key_store' => 'db'];
                $driver   = 'outlook';
                break;
            case 'SENDGRID':
                $settings = $sender + ['api_key' => $secret('api_key', 'api_key')];
                $driver   = 'sendgrid';
                break;
            case 'POSTMARK':
                $settings = $sender + ['server_token' => $secret('server_token', 'server_token'), 'message_stream' => $this->str($connection, 'message_stream') ?: 'outbound'];
                $driver   = 'postmark';
                break;
            case 'SURECONTACT':
                return new CanonicalConnection(
                    $this->id(), $id, $name, null, $mailer, [], [], $default,
                    $this->translator->translate('SureContact is SureMail\'s own hosted relay and cannot be moved to another plugin.')
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
            missingSecrets: array_values(array_unique($missing)),
            wasDefault: $default,
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
        return 'suremails_email_log';
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
        return ['id', 'email_from', 'email_to', 'subject', 'body', 'headers', 'attachments', 'status', 'response', 'meta', 'connection', 'created_at'];
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
        $from    = AddressParser::one((string) ($row['email_from'] ?? ''));
        $headers = HeaderLines::toMap(HeaderLines::lines($row['headers'] ?? ''));
        $cc      = AddressParser::list($headers['Cc'] ?? '');
        $bcc     = AddressParser::list($headers['Bcc'] ?? '');
        unset($headers['To'], $headers['Cc'], $headers['Bcc'], $headers['From'], $headers['Subject']);

        $attachments = [];
        foreach (AddressParser::decode((string) ($row['attachments'] ?? '')) ?? [] as $path) {
            if (! \is_string($path) || $path === '') {
                continue;
            }
            $size = is_file($path) ? (filesize($path) ?: null) : null;
            $attachments[] = ['name' => basename($path), 'type' => null, 'size' => $size === false ? null : $size];
        }

        $attempts = AddressParser::decode((string) ($row['response'] ?? '')) ?? [];
        $last     = \is_array($attempts) && $attempts !== [] ? end($attempts) : null;
        $message  = \is_array($last) ? trim((string) ($last['Message'] ?? '')) : '';
        $meta     = AddressParser::decode((string) ($row['meta'] ?? '')) ?? [];
        $status   = strtolower((string) ($row['status'] ?? ''));
        $error    = null;
        switch ($status) {
            case 'sent':
                $mapped = \is_array($last) && ! empty($last['simulated']) ? 'simulated' : 'delivered';
                break;
            case 'failed':
                $mapped = 'failed';
                $error  = $message !== '' ? $message : null;
                break;
            case 'blocked':
                $mapped = 'failed';
                $error  = $this->translator->translate('Blocked by SureMail\'s content guard.') . ($message !== '' ? ' ' . $message : '');
                break;
            default:
                $mapped = 'pending';
        }

        $type     = trim((string) ($row['connection'] ?? ''));
        $provider = $type === '' ? null : (strcasecmp($type, 'Default') === 0 ? 'php' : (DriverMap::toDriver($type) ?? strtolower($type)));

        return new CanonicalEmailLog(
            sourcePlugin: $this->id(),
            sourceLogId: (int) $row['id'],
            to: AddressParser::list((string) ($row['email_to'] ?? '')),
            subject: (string) ($row['subject'] ?? ''),
            status: $mapped,
            sourceStatus: $status,
            cc: $cc,
            bcc: $bcc,
            fromEmail: $from['email'] !== '' ? $from['email'] : null,
            fromName: $from['name'] !== '' ? $from['name'] : null,
            body: isset($row['body']) ? (string) $row['body'] : null,
            headers: $headers,
            attachments: $attachments,
            errorMessage: $error,
            provider: $provider,
            sourceProvider: $type !== '' && strcasecmp($type, 'Default') !== 0 ? $type : null,
            retries: (int) ($meta['retry'] ?? 0),
            createdAt: $this->rowDate($row['created_at'] ?? ''),
        );
    }
}
