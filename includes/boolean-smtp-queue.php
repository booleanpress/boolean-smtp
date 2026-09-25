<?php

/**
 * Defines BooleanSMTP's public API for queueing an email instead of sending it immediately.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Queue an email for later delivery instead of sending it immediately.
 *
 * Creates an email log entry with status `queued` and arms the queue worker, which sends the
 * entry from WP-Cron within a minute (or at once with `wp boolean-smtp queue:work`). Returns null
 * without creating an entry when `to`, `subject`, or `message` is missing.
 *
 * @since 1.0.0
 *
 * @param array<string, mixed> $atts {
 *     @type string|array $to            Recipient email(s).
 *     @type string       $subject       Email subject.
 *     @type string       $message       Email body (HTML or plain text).
 *     @type string|array $headers       Optional email headers.
 *     @type array        $attachments   Optional attachments.
 *     @type int          $connection_id Optional specific connection ID.
 * }
 * @return int|null The created email log's id when queued, or null on failure.
 */
function boolean_smtp_queue(array $atts): ?int
{
    if (!isset($atts['to'], $atts['subject'], $atts['message'])) {
        return null;
    }

    $to = is_array($atts['to']) ? implode(', ', $atts['to']) : $atts['to'];

    // Headers are stored as the list of lines wp_mail() accepts, whichever form the caller used.
    $headers = $atts['headers'] ?? [];
    if (is_string($headers)) {
        $headers = array_values(array_filter(array_map('trim', preg_split('/\r\n|\n/', $headers) ?: []), 'strlen'));
    } elseif (!is_array($headers)) {
        $headers = [];
    }

    $log = \BooleanSmtp\Models\EmailLog::create([
        'to'            => $to,
        'subject'       => $atts['subject'],
        'body'          => $atts['message'],
        'headers'       => $headers,
        'status'        => 'queued',
        'connection_id' => $atts['connection_id'] ?? null,
        'source'        => 'api_queue',
    ]);

    if (!$log) {
        return null;
    }

    /**
     * Fires after an email has been added to the queue.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $payload {
     *     @type int    $id      Id of the queued email log.
     *     @type string $to      Recipient address(es).
     *     @type string $subject Email subject.
     * }
     */
    \do_action('boolean_smtp_email_queued', [
        'id'      => (int) $log->id,
        'to'      => (string) $log->to,
        'subject' => (string) $log->subject,
    ]);

    if (\BooleanSmtp\Core\Foundation\Application::hasInstance()) {
        \BooleanSmtp\Core\Foundation\Application::getInstance()
            ->make(\BooleanSmtp\Services\Queue\QueueScheduler::class)
            ->arm();
    }

    return (int) $log->id;
}
