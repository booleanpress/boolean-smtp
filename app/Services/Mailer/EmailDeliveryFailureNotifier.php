<?php

/**
 * Builds and dispatches the final delivery-failure notification for a mail send.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer;

use BooleanSmtp\Core\Foundation\Application;
use BooleanSmtp\Models\EmailLog;
use BooleanSmtp\Repositories\EmailLogRepository;

/**
 * Fires {@see notifyFinal()} after fallback semantics are resolved (see {@see MailFailureHandler}).
 *
 * @since 1.0.0
 */
class EmailDeliveryFailureNotifier
{
    /**
     * Create the notifier.
     *
     * @since 1.0.0
     *
     * @param Application $app Application container used to resolve the mailer and log repository.
     */
    public function __construct(protected Application $app)
    {
    }

    /**
     * Notify that a send has failed with no further fallback to try, building the event data from
     * a WP_Error raised through the standard wp_mail_failed chain.
     *
     * @since 1.0.0
     *
     * @param  \WP_Error             $error WordPress error describing the failed send.
     * @param  array<string, mixed>  $extra Additional context; recognizes a "source" key identifying the caller.
     * @return void
     */
    public function notifyFinal(\WP_Error $error, array $extra = []): void
    {
        [$data, $log] = $this->buildDataFromWpError($error, $extra);
        $context      = [
            'log'      => $log,
            'wp_error' => $error,
            'source'   => (string) ($extra['source'] ?? 'wp_mail'),
        ];

        /** This filter is documented in app/Services/Mailer/EmailLogSendLifecycle.php */
        $data = \apply_filters('boolean_smtp_email_event_data', $data, 'failed_final', $context);

        $notifyContext = array_merge($context, ['data' => $data]);
        /**
         * Filters whether a final delivery failure is announced and notified.
         *
         * Return `false` to suppress both the `boolean_smtp_email_delivery_failed_final` action and
         * the alert channels for this event.
         *
         * @since 1.0.0
         *
         * @param  bool                 $should_notify Whether to announce the failure. Default `true`.
         * @param  array<string, mixed> $context       The `failed_final` context of `boolean_smtp_email_event_data` (`log`, `wp_error`, `source`) plus the filtered event data under `data`.
         * @return bool Whether to announce the failure.
         */
        if (! \apply_filters('boolean_smtp_should_notify_delivery_failure', true, $notifyContext)) {
            return;
        }

        /**
         * Fires when a mail delivery attempt has failed with no further fallback to try.
         *
         * The hook to build failure alerts on: unlike `boolean_smtp_email_failed`, which fires for
         * every failed attempt, this fires once per message, after the fallback connection has
         * also failed or been skipped, and not at all when `boolean_smtp_should_notify_delivery_failure`
         * returned `false`.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $data {
         *     @type string   $to            Recipient address(es).
         *     @type string   $subject       Subject line.
         *     @type string   $error         Error message.
         *     @type int      $code          Numeric error code, `0` when the error carried none.
         *     @type int|null $log_id        Id of the email log entry, `null` when no row was written.
         *     @type int|null $connection_id Id of the connection used, `null` when unknown.
         *     @type string   $provider      Provider of the connection used.
         *     @type string   $source        Code path that raised the failure: `wp_mail`, `api_queue`, `queue_exception`, ….
         * }
         */
        \do_action('boolean_smtp_email_delivery_failed_final', $data);
    }

    /**
     * Notify that a send has failed with no further fallback to try, building the event data from
     * a stored email log when wp_mail() raised outside the normal wp_mail_failed chain.
     *
     * @since 1.0.0
     *
     * @param  EmailLog $log            Log entry recorded for the failed send.
     * @param  string   $errorMessage   Failure reason to record on the event.
     * @param  string   $failureSource  Identifier for the code path that raised the failure.
     * @return void
     */
    public function notifyFinalFromQueueLog(EmailLog $log, string $errorMessage, string $failureSource = 'queue_exception'): void
    {
        $data = [
            'to'            => (string) $log->to,
            'subject'       => (string) $log->subject,
            'error'         => $errorMessage,
            'code'          => 0,
            'log_id'        => (int) $log->id,
            'connection_id' => $log->connection_id !== null ? (int) $log->connection_id : null,
            'provider'      => (string) ($log->provider ?? ''),
            'source'        => $failureSource,
        ];

        $context = [
            'log'      => $log,
            'wp_error' => null,
            'source'   => $failureSource,
        ];

        /** This filter is documented in app/Services/Mailer/EmailLogSendLifecycle.php */
        $data = \apply_filters('boolean_smtp_email_event_data', $data, 'failed_final', $context);

        $notifyContext = array_merge($context, ['data' => $data]);
        /** This filter is documented above. */
        if (! \apply_filters('boolean_smtp_should_notify_delivery_failure', true, $notifyContext)) {
            return;
        }

        /** This action is documented above. */
        \do_action('boolean_smtp_email_delivery_failed_final', $data);
    }

    /**
     * Build the notification event data and, when available, the related email log from a WP_Error.
     *
     * @since 1.0.0
     *
     * @param  \WP_Error            $error WordPress error describing the failed send.
     * @param  array<string, mixed> $extra Additional context; recognizes a "source" key identifying the caller.
     * @return array{0: array<string, mixed>, 1: ?EmailLog}
     */
    private function buildDataFromWpError(\WP_Error $error, array $extra = []): array
    {
        $msg  = $error->get_error_message();
        $code = \is_numeric($error->get_error_code()) ? (int) $error->get_error_code() : 0;
        $errData = $error->get_error_data();
        $to      = '';
        $subj    = '';
        if (\is_array($errData)) {
            $rawTo = $errData['to'] ?? '';
            $to    = \is_array($rawTo) ? implode(', ', $rawTo) : (string) $rawTo;
            $subj  = (string) ($errData['subject'] ?? '');
        }

        $mailer = $this->app->make(MailerManager::class);
        $logId  = $mailer->getPendingLogIdForCurrentSend();
        $log    = null;
        if ($logId !== null && $logId > 0) {
            $log = $this->app->make(EmailLogRepository::class)->find($logId);
        }

        $data = [
            'to'            => $to,
            'subject'       => $subj,
            'error'         => $msg,
            'code'          => $code,
            'log_id'        => $log ? (int) $log->id : null,
            'connection_id' => $log && $log->connection_id !== null ? (int) $log->connection_id : null,
            'provider'      => $log ? (string) ($log->provider ?? '') : '',
            'source'        => (string) ($extra['source'] ?? 'wp_mail'),
        ];

        return [$data, $log];
    }
}
