<?php

/**
 * Updates the email log row for a send once WordPress reports its outcome.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer;

use BooleanSmtp\Repositories\EmailLogRepository;
use BooleanSmtp\Support\Settings;
use BooleanSmtp\Core\Foundation\Application;

/**
 * Finalizes email log rows after transport success/failure (pairs with {@see MailerManager::logEmail}).
 *
 * @since 1.0.0
 */
final class EmailLogSendLifecycle
{
    /**
     * Create the lifecycle handler.
     *
     * @since 1.0.0
     *
     * @param Application $app Application container used to resolve settings, the mailer, and the log repository.
     */
    public function __construct(
        protected Application $app,
    ) {
    }

    /**
     * Mark the pending log entry as delivered after wp_mail() reports success.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $mailData Arguments passed to the wp_mail_succeeded action.
     * @return void
     */
    public function onWpMailSucceeded(array $mailData): void
    {
        $settings = $this->app->make(Settings::class);
        if ($settings->get('simulation_enabled')) {
            return;
        }

        if (! $settings->get('log_emails')) {
            return;
        }

        $mailer = $this->app->make(MailerManager::class);
        $logId  = $mailer->getPendingLogIdForCurrentSend();
        if ($logId === null || $logId <= 0) {
            return;
        }

        $repo = $this->app->make(EmailLogRepository::class);
        $log  = $repo->find($logId);
        if (! $log) {
            $mailer->clearPendingLogIdForCurrentSend();

            return;
        }

        $log->refresh();
        $attempts = $log->attempts ?: [];
        $last     = count($attempts) - 1;
        if ($last >= 0) {
            $attempts[$last]['status'] = 'delivered';
            $attempts[$last]['date']   = gmdate('Y-m-d H:i:s');
        }

        $update = [
            'status'           => 'delivered',
            'error_message'    => null,
            'attempts'         => $attempts,
            'last_attempted_at' => gmdate('Y-m-d H:i:s'),
            'delivery_time_ms' => $mailer->takeLastSendDurationMs(),
            ...$this->fullHeadersUpdate($mailer),
        ];

        // A queued message keeps its body until it is delivered so retries can send it; once
        // delivered, the body-logging setting decides whether the log keeps it.
        if ($log->body !== null && !$mailer->isBodyLoggingAllowed()) {
            $update['body'] = null;
        }

        $log->update($update);

        $mailer->clearPendingLogIdForCurrentSend();

        $toRaw = $mailData['to'] ?? $log->to ?? '';
        $toStr = \is_array($toRaw) ? implode(', ', $toRaw) : (string) $toRaw;

        $payload = [
            'id'            => (int) $log->id,
            'to'            => $toStr,
            'subject'       => (string) ($mailData['subject'] ?? $log->subject ?? ''),
            'status'        => 'delivered',
            'provider'      => (string) ($log->provider ?? ''),
            'timestamp'     => time(),
            'log_id'        => (int) $log->id,
            'connection_id' => $log->connection_id !== null ? (int) $log->connection_id : null,
        ];
        $context = [
            'log'       => $log,
            'mail_data' => $mailData,
        ];
        /**
         * Filters the data describing a mail delivery event before it is announced and used to
         * build a notification.
         *
         * Fired immediately before `boolean_smtp_email_sent`, `boolean_smtp_email_failed` and
         * `boolean_smtp_email_delivery_failed_final`; the array returned here is what those
         * actions receive. `$context` carries the same keys for a given event wherever it is
         * fired (a key is `null` when its value is unknown):
         *
         * - `sent`: `log` (the `EmailLog`), `mail_data` (the `wp_mail()` arguments, empty for a queued send).
         * - `failed`: `log` (the `EmailLog`), `wp_error` (the `WP_Error` of the attempt).
         * - `failed_final`: `log` (the `EmailLog`, `null` when no row was written), `wp_error` (`null` when the failure was raised outside `wp_mail_failed`), `source` (the code path: `wp_mail`, `api_queue`, `queue_exception`, …).
         *
         * @since 1.0.0
         *
         * @param  array<string, mixed> $data    Event data. `sent`: `id`, `to`, `subject`, `status`, `provider`, `timestamp`, `log_id`, `connection_id`. `failed` and `failed_final`: `to`, `subject`, `error`, `code`, `log_id`, `connection_id`, `provider` (and `source` for `failed_final`).
         * @param  string               $event   Event type: `sent`, `failed` or `failed_final`.
         * @param  array<string, mixed> $context Context for the event, keyed as listed above.
         * @return array<string, mixed> The filtered event data.
         */
        $payload = \apply_filters('boolean_smtp_email_event_data', $payload, 'sent', $context);

        /**
         * Fires after a message has been delivered successfully.
         *
         * Fired for direct sends and for queued sends alike, after the email log entry has been
         * marked delivered; the payload has passed through `boolean_smtp_email_event_data`.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $payload {
         *     @type int      $id            Id of the email log entry.
         *     @type string   $to            Recipient address(es), comma separated.
         *     @type string   $subject       Subject line.
         *     @type string   $status        Always `delivered`.
         *     @type string   $provider      Provider of the connection that delivered the message.
         *     @type int      $timestamp     Unix timestamp of the delivery.
         *     @type int      $log_id        Id of the email log entry (same as `id`).
         *     @type int|null $connection_id Id of the connection used, `null` when unknown.
         * }
         */
        \do_action('boolean_smtp_email_sent', $payload);
    }

    /**
     * Mark the pending log entry as failed after wp_mail() reports a failure.
     *
     * @since 1.0.0
     *
     * @param  \WP_Error $error WordPress error describing the failed send.
     * @return void
     */
    public function onWpMailFailed(\WP_Error $error): void
    {
        $settings = $this->app->make(Settings::class);
        if ($settings->get('simulation_enabled')) {
            return;
        }

        if (! $settings->get('log_emails')) {
            return;
        }

        $mailer = $this->app->make(MailerManager::class);
        $logId  = $mailer->getPendingLogIdForCurrentSend();
        if ($logId === null || $logId <= 0) {
            return;
        }

        $repo = $this->app->make(EmailLogRepository::class);
        $log  = $repo->find($logId);
        if (! $log) {
            return;
        }

        $msg = $error->get_error_message();
        $log->refresh();
        $attempts = $log->attempts ?: [];
        $last     = count($attempts) - 1;
        if ($last >= 0) {
            $attempts[$last]['status'] = 'failed';
            $attempts[$last]['error']  = $msg;
            $attempts[$last]['date']   = gmdate('Y-m-d H:i:s');
        }

        $log->update([
            'status'           => 'failed',
            'error_message' => $msg,
            'attempts'      => $attempts,
            'last_attempted_at' => gmdate('Y-m-d H:i:s'),
            ...$this->fullHeadersUpdate($mailer),
        ]);

        $data = $error->get_error_data();
        $to   = '';
        $subj = '';
        if (\is_array($data)) {
            $rawTo = $data['to'] ?? '';
            $to    = \is_array($rawTo) ? implode(', ', $rawTo) : (string) $rawTo;
            $subj  = (string) ($data['subject'] ?? '');
        }

        $payload = [
            'to'            => $to,
            'subject'       => $subj,
            'error'         => $msg,
            'code'          => is_numeric($error->get_error_code()) ? (int) $error->get_error_code() : 0,
            'log_id'        => (int) $log->id,
            'connection_id' => $log->connection_id !== null ? (int) $log->connection_id : null,
            'provider'      => (string) ($log->provider ?? ''),
        ];
        /** This filter is documented above. */
        $payload = \apply_filters('boolean_smtp_email_event_data', $payload, 'failed', [
            'log'      => $log,
            'wp_error' => $error,
        ]);

        /**
         * Fires after a send attempt has failed, before the fallback connection is tried.
         *
         * Fires for every failed attempt, so it can fire and the message still be delivered by
         * the fallback connection; build failure alerts on
         * `boolean_smtp_email_delivery_failed_final` instead. The payload has passed through
         * `boolean_smtp_email_event_data`.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $payload {
         *     @type string   $to            Recipient address(es), comma separated.
         *     @type string   $subject       Subject line.
         *     @type string   $error         Error message.
         *     @type int      $code          Numeric error code, `0` when the error carried none.
         *     @type int      $log_id        Id of the email log entry.
         *     @type int|null $connection_id Id of the connection used, `null` when unknown.
         *     @type string   $provider      Provider of the connection used.
         * }
         */
        \do_action('boolean_smtp_email_failed', $payload);
    }

    /**
     * Backfills the log's `headers` field with the full assembled MIME headers (Date, To,
     * From, Subject, Message-ID, MIME-Version, Content-Type, custom headers, ...) once the
     * real send has completed — {@see MailerManager::logEmail()} only has custom headers
     * available at log-creation time, since it runs on `phpmailer_init`, before PHPMailer
     * has assembled the rest.
     *
     * Only for SMTP-mode sends: `$GLOBALS['phpmailer']` is WordPress core's own PHPMailer
     * instance, correct for the send that's completing right now — but ONLY when that send
     * went through core wp_mail()'s normal SMTP path. An API-mode send (Outlook/SES/Gmail)
     * short-circuits via `pre_wp_mail` before core ever touches that global, so it would
     * hold a stale, unrelated instance (or none) — {@see MailerManager::persistLogAttempt()}
     * already recorded that log's real headers itself, tracked via
     * {@see MailerManager::pendingLogHeadersAlreadyCaptured()}.
     *
     * @since 1.0.0
     *
     * @param  MailerManager $mailer Mailer instance used for the send that just completed.
     * @return array{headers?: array<string, string>}
     */
    private function fullHeadersUpdate(MailerManager $mailer): array
    {
        if ($mailer->pendingLogHeadersAlreadyCaptured()) {
            return [];
        }

        if (! isset($GLOBALS['phpmailer']) || ! ($GLOBALS['phpmailer'] instanceof \PHPMailer\PHPMailer\PHPMailer)) {
            return [];
        }

        $headers = MailerManager::parseRawHeaders($GLOBALS['phpmailer']->getSentMIMEMessage());

        return $headers !== [] ? ['headers' => $headers] : [];
    }
}
