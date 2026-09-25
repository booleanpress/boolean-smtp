<?php
/**
 * Reads WP Mail SMTP's connection and, when its Pro edition ever wrote one, its email log.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration\Sources;

use BooleanSmtp\Services\Migration\Canonical\CanonicalEmailLog;
use BooleanSmtp\Services\Migration\SourceContext;
use BooleanSmtp\Services\Migration\Support\AddressParser;
use BooleanSmtp\Services\Migration\Support\DriverMap;
use BooleanSmtp\Services\Migration\Support\HeaderLines;

/**
 * The free edition writes no email log (only diagnostic events); `wpmailsmtp_emails_log` exists
 * on a site only if the Pro edition ran on it, and is read whenever it is there: `people` is a
 * JSON map of recipient lists, `headers` a JSON list of raw lines, `status` one of the Pro
 * edition's five codes, `date_sent` a UTC datetime, `attachments` a count whose file names live
 * in the Pro edition's two attachment tables when it kept the files.
 *
 * @since 1.0.0
 */
final class WpMailSmtpSource extends AbstractWpMailSmtpShapedSource
{
    use Concerns\ReadsLogTable;

    /**
     * The Pro log's `status` codes mapped to the plugin's own: 0 not sent, 1 sent, 2 sent and
     * waiting for the provider's delivery confirmation, 3 delivery confirmed, 4 stopped by the
     * plugin's own "Do Not Send" switch before it left the site.
     *
     * @since 1.0.0
     * @var array<int, string>
     */
    private const STATUS = [0 => 'failed', 1 => 'delivered', 2 => 'delivered', 3 => 'delivered', 4 => 'failed'];

    /**
     * The `status` code of a message the plugin stopped before sending.
     *
     * @since 1.0.0
     * @var int
     */
    private const STATUS_BLOCKED = 4;

    /**
     * The Pro edition's table linking a log row to the attachment files it kept.
     *
     * @since 1.0.0
     * @var string
     */
    private const ATTACHMENTS_TABLE = 'wpmailsmtp_email_attachments';

    /**
     * Names of the attachments of the chunk being mapped, keyed by log row id; empty when the
     * Pro edition did not keep the files.
     *
     * @since 1.0.0
     * @var array<int, list<string>>
     */
    private array $attachmentNames = [];

    /**
     * Stable source id.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function id(): string
    {
        return 'wp-mail-smtp';
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
        return 'WP Mail SMTP';
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
        return 'wp_mail_smtp';
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
        return 'wp_mail_smtp_mail_key';
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
        return 'WPMS_';
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
        return 'wpmailsmtp_emails_log';
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
        return 'date_sent';
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
        return self::DATE_UTC_MYSQL;
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
        return ['id', 'message_id', 'subject', 'people', 'headers', 'error_text', 'error_code', 'error_key', 'content_plain', 'content_html', 'status', 'date_sent', 'mailer', 'attachments', 'initiator_name'];
    }

    /**
     * Read the chunk's attachment file names in one query, when the Pro edition's attachment
     * tables are on this site.
     *
     * @since 1.0.0
     *
     * @param  SourceContext              $context Site access.
     * @param  list<array<string, mixed>> $rows    The chunk's rows.
     * @return void
     */
    protected function beforeChunk(SourceContext $context, array $rows): void
    {
        $this->attachmentNames = [];
        $ids = [];
        foreach ($rows as $row) {
            if ((int) ($row['attachments'] ?? 0) > 0) {
                $ids[] = (int) $row['id'];
            }
        }
        if ($ids === [] || ! $context->tableExists(self::ATTACHMENTS_TABLE)) {
            return;
        }

        $table = '`' . $context->table(self::ATTACHMENTS_TABLE) . '`';
        $list  = implode(', ', $ids);
        foreach ($context->select("SELECT `email_log_id`, `filename` FROM {$table} WHERE `email_log_id` IN ({$list})") as $row) {
            $name = trim((string) ($row['filename'] ?? ''));
            if ($name !== '') {
                $this->attachmentNames[(int) $row['email_log_id']][] = basename($name);
            }
        }
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
        $people  = AddressParser::decode((string) ($row['people'] ?? '')) ?? [];
        $headers = HeaderLines::toMap(HeaderLines::lines($row['headers'] ?? ''));
        // `people.from` holds the bare address; the display name is only on the From header line.
        $fromLine = AddressParser::one((string) ($headers['From'] ?? ''));
        $from     = AddressParser::one((string) ($people['from'] ?? ''));
        $from     = ['email' => $from['email'] !== '' ? $from['email'] : $fromLine['email'], 'name' => $from['name'] !== '' ? $from['name'] : $fromLine['name']];
        unset($headers['To'], $headers['Cc'], $headers['Bcc'], $headers['From'], $headers['Subject'], $headers['Date']);
        $messageId = trim((string) ($row['message_id'] ?? ''));
        if ($messageId !== '') {
            $headers['Message-ID'] = $messageId;
        }

        $status = (int) ($row['status'] ?? 0);
        $count  = (int) ($row['attachments'] ?? 0);
        $names  = $this->attachmentNames[(int) $row['id']] ?? [];
        $attachments = [];
        for ($i = 1; $i <= $count; $i++) {
            $attachments[] = [
                'name' => $names[$i - 1] ?? $this->translator->translate('Attachment {{n}}', ['n' => $i]),
                'type' => null,
                'size' => null,
            ];
        }
        $html  = (string) ($row['content_html'] ?? '');
        $plain = (string) ($row['content_plain'] ?? '');
        $error = trim((string) ($row['error_text'] ?? ''));
        $mailer = (string) ($row['mailer'] ?? '');
        $meta   = [];
        foreach (['error_code' => 'source_error_code', 'error_key' => 'source_error_key'] as $column => $key) {
            $value = trim((string) ($row[$column] ?? ''));
            if ($value !== '') {
                $meta[$key] = $value;
            }
        }
        if ($status === self::STATUS_BLOCKED && $error === '') {
            // The message never left the site: WP Mail SMTP's "Do Not Send" stopped it.
            $error = $this->translator->translate('Blocked before sending by WP Mail SMTP\'s "Do Not Send" setting.');
        }

        return new CanonicalEmailLog(
            sourcePlugin: $this->id(),
            sourceLogId: (int) $row['id'],
            to: AddressParser::fromStored($people['to'] ?? []),
            subject: (string) ($row['subject'] ?? ''),
            status: self::STATUS[$status] ?? 'failed',
            sourceStatus: (string) $status,
            cc: AddressParser::fromStored($people['cc'] ?? []),
            bcc: AddressParser::fromStored($people['bcc'] ?? []),
            fromEmail: $from['email'] !== '' ? $from['email'] : null,
            fromName: $from['name'] !== '' ? $from['name'] : null,
            body: $html !== '' ? $html : ($plain !== '' ? $plain : null),
            headers: $headers,
            attachments: $attachments,
            errorMessage: $error !== '' ? $error : null,
            provider: $mailer !== '' ? (DriverMap::toDriver($mailer) ?? $mailer) : null,
            sourceProvider: $mailer !== '' ? $mailer : null,
            createdAt: $this->rowDate($row['date_sent'] ?? ''),
            initiator: trim((string) ($row['initiator_name'] ?? '')) ?: null,
            meta: $meta,
        );
    }

    /**
     * Why the source has no log to import.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected function noLogReason(): string
    {
        return $this->translator->translate('WP Mail SMTP keeps no email log in its free edition; only its Pro edition writes one, and none was found on this site.');
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
        return [];
    }
}
