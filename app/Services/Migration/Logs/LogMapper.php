<?php
/**
 * Turns a canonical log row into the attributes of the plugin's own email-log table.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration\Logs;

use BooleanSmtp\Contracts\TranslatorContract;
use BooleanSmtp\Services\Migration\Canonical\CanonicalEmailLog;

/**
 * The plugin's own rows carry the first recipient in `to`, a header map, attachment metadata,
 * one attempt per delivery try and an initiator label in `source`. An imported row follows
 * the same shape with `source` naming the plugin it came from, one attempt marked as imported,
 * and a `message_id` that doubles as the dedupe key.
 *
 * @since 1.0.0
 */
final class LogMapper
{
    /**
     * Prefix of every imported row's `message_id`: `migrator:<source>:<source row id>`.
     *
     * @since 1.0.0
     * @var string
     */
    public const MESSAGE_ID_PREFIX = 'migrator:';

    /**
     * @since 1.0.0
     *
     * @param TranslatorContract $translator Translates the `source` label.
     */
    public function __construct(private readonly TranslatorContract $translator) {}

    /**
     * The dedupe key of a source row.
     *
     * @since 1.0.0
     *
     * @param  string $source      Source id.
     * @param  int    $sourceLogId The row's id in the source table.
     * @return string
     */
    public static function messageId(string $source, int $sourceLogId): string
    {
        return self::MESSAGE_ID_PREFIX . $source . ':' . $sourceLogId;
    }

    /**
     * Map one row.
     *
     * @since 1.0.0
     *
     * @param  CanonicalEmailLog  $log                The row as the source read it.
     * @param  string             $sourceName         The source plugin's display name.
     * @param  array<string, int> $connectionByDriver Driver → id of the one draft imported from the same source with that driver.
     * @param  array<string, int> $connectionBySource The source plugin's own mailer key → id of the one draft imported from it; tried first, because two of its connections can share our driver (a relay conversion, for instance).
     * @return array<string, mixed> Attributes for the email-log table.
     */
    public function map(CanonicalEmailLog $log, string $sourceName, array $connectionByDriver = [], array $connectionBySource = []): array
    {
        $now       = \gmdate('Y-m-d H:i:s');
        $createdAt = $log->createdAt ?? $now;
        $source    = $this->translator->translate('Imported: {{plugin}}', ['plugin' => $sourceName]);
        $provider  = $log->provider;
        $sourceKey = $log->sourceProvider !== null ? strtolower(trim($log->sourceProvider)) : '';
        $connId    = $connectionBySource[$sourceKey] ?? ($provider !== null ? ($connectionByDriver[$provider] ?? null) : null);

        $attempt = [
            'timestamp'     => strtotime($createdAt . ' UTC') ?: time(),
            'date'          => $createdAt,
            'status'        => $log->status,
            'connection_id' => $connId,
            'provider'      => $provider,
            'source'        => $source,
            'error'         => $log->errorMessage,
            'imported'      => [
                'plugin'        => $log->sourcePlugin,
                'source_id'     => $log->sourceLogId,
                'source_status' => $log->sourceStatus,
                'initiator'     => $log->initiator,
            ] + $log->meta,
        ];

        return [
            'to'                => $log->to === [] ? '(unknown)' : \mb_substr(implode(', ', $log->to), 0, 255),
            'cc'                => $log->cc === [] ? null : implode(', ', $log->cc),
            'bcc'               => $log->bcc === [] ? null : implode(', ', $log->bcc),
            'from_email'        => $log->fromEmail,
            'from_name'         => $log->fromName,
            'subject'           => $log->subject !== '' ? \mb_substr($log->subject, 0, 255) : '(no subject)',
            'body'              => $log->body,
            'headers'           => $log->headers,
            'attachments'       => $log->attachments,
            'status'            => $log->status,
            'provider'          => $provider,
            'connection_id'     => $connId,
            'error_message'     => $log->errorMessage,
            'message_id'        => self::messageId($log->sourcePlugin, $log->sourceLogId),
            'retries'           => $log->retries,
            'delivery_time_ms'  => $log->deliveryTimeMs,
            'attempts'          => [$attempt],
            'source'            => $source,
            'last_attempted_at' => $createdAt,
            'created_at'        => $createdAt,
            'updated_at'        => $now,
        ];
    }
}
