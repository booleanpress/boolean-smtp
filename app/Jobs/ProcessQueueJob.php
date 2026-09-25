<?php
/**
 * Processes a batch of the outgoing mail queue on the `boolean_smtp_process_queue` WP-Cron
 * event, with an atomic per-row claim and a global run lock.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Jobs;

use BooleanSmtp\Adapters\Contracts\HookAdapterContract;
use BooleanSmtp\Core\Database\Drivers\DriverInterface;
use BooleanSmtp\Core\Settings\SettingsRepository as CoreSettingsRepository;
use BooleanSmtp\Core\Support\Arr;
use BooleanSmtp\Models\EmailLog;
use BooleanSmtp\Repositories\EmailLogRepository;
use BooleanSmtp\Services\Mailer\EmailDeliveryFailureNotifier;
use BooleanSmtp\Services\Mailer\MailerManager;
use BooleanSmtp\Services\Queue\QueueScheduler;
use BooleanSmtp\Services\Queue\RetryLadder;
use BooleanSmtp\Services\Simulation\SimulationService;
use BooleanSmtp\Support\Settings;

/**
 * Runs on the `boolean_smtp_process_queue` WP-Cron event (armed on demand by
 * {@see \BooleanSmtp\Services\Queue\QueueScheduler}, wired to
 * {@see \BooleanSmtp\Providers\AppServiceProvider::runScheduledQueueProcessing()}) and from
 * `wp boolean-smtp queue:work`, to send queued messages and to retry failed ones on the connections
 * they have not been sent through yet.
 *
 * Concurrency safety has two layers: a global transient lock (`queue_lock`, {@see LOCK_SECONDS})
 * prevents two batches from running at once, and each row is claimed with a conditional
 * `UPDATE ... WHERE reserved_at IS NULL` so that even if the global lock were ever bypassed,
 * two workers could not both send the same queued email. A row whose `sending` reservation
 * is older than {@see LOCK_SECONDS} is treated as abandoned (a batch that crashed mid-send)
 * and is reset to `queued` before claiming begins; the window is long enough for a full
 * batch of slow sends, so a row still in flight is never handed to a second worker.
 *
 * Two kinds of rows are claimed: `queued` messages (`boolean_smtp_queue()`, the
 * `boolean_smtp_queue_should_enqueue` filter) and `failed` messages whose `next_attempt_at` has
 * passed — the ones {@see \BooleanSmtp\Services\Queue\RetryLadder} scheduled. A retry is forced
 * onto the first active connection the message has not been tried on; when it fails again the
 * ladder either schedules the next rung or declares the failure final, and only then do the alert
 * channels fire. Every send opens its own connection.
 *
 * Resolve it from the container; every collaborator is injected.
 *
 * @since 1.0.0
 */
class ProcessQueueJob {
    /**
     * WP-Cron hook the job runs on.
     *
     * @since 1.0.0
     * @var string
     */
    public const HOOK = 'boolean_smtp_process_queue';

    /**
     * Seconds a batch may hold the queue lock, and the age after which a `sending`
     * reservation counts as abandoned: 50 sends with a 10-second timeout each fit inside.
     *
     * @since 1.0.0
     * @var int
     */
    public const LOCK_SECONDS = 900;

    /**
     * The `source` a queued message is stored with; only these rows are retried.
     *
     * @since 1.0.0
     * @var string
     */
    public const QUEUE_SOURCE = 'api_queue';

    /**
     * Headers a previous attempt assembled that must not be replayed on a retry (the
     * transport regenerates them); every other stored header is sent again.
     *
     * @since 1.0.0
     * @var list<string>
     */
    private const TRANSPORT_HEADERS = ['date', 'message-id', 'to', 'subject', 'mime-version', 'content-transfer-encoding', 'x-mailer', 'received', 'return-path', 'dkim-signature'];

    /**
     * @since 1.0.0
     *
     * @param Settings                     $settings     Plugin settings (simulation mode).
     * @param CoreSettingsRepository       $coreSettings Transient store holding the queue lock.
     * @param DriverInterface              $driver       Database driver for the atomic row claims.
     * @param EmailDeliveryFailureNotifier $notifier     Sends the final-failure notification.
     * @param HookAdapterContract          $hooks        Captures `wp_mail_failed` and `email_sent` around each send.
     * @param MailerManager                $mailer       Told which row a send belongs to and which connection to use.
     * @param SimulationService            $simulation   Marks queued rows as simulated when simulation mode is on.
     * @param RetryLadder                  $ladder       Picks the next connection and schedules or ends the retries.
     * @param QueueScheduler               $scheduler    Re-arms the worker while rows remain.
     * @param EmailLogRepository           $logs         Answers whether rows are left for a later run.
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly CoreSettingsRepository $coreSettings,
        private readonly DriverInterface $driver,
        private readonly EmailDeliveryFailureNotifier $notifier,
        private readonly HookAdapterContract $hooks,
        private readonly MailerManager $mailer,
        private readonly SimulationService $simulation,
        private readonly RetryLadder $ladder,
        private readonly QueueScheduler $scheduler,
        private readonly EmailLogRepository $logs,
    ) {}

    /**
     * Claim and send up to `$limit` queued (and, when asked, due-for-retry failed) emails.
     *
     * Acquires the global `queue_lock` transient before doing any work and releases it in
     * a `finally` block; returns 0 immediately without processing anything if another run
     * already holds the lock. A message the fallback connection delivered after the
     * primary failed counts as delivered. A rate-limit error (detected from the exception
     * message) requeues the row as `queued` for a later run instead of marking it `failed`
     * and does not trigger a final-failure notification. Every other failure increments
     * `retries` and asks the retry ladder for the next rung: a scheduled retry, or the final
     * failure with its notification. Rows left eligible when the run ends arm another run.
     *
     * @since 1.0.0
     *
     * @param  int  $limit          Maximum number of emails to process in this run.
     * @param  bool $includeRetries When true, also claims `failed` messages whose scheduled
     *                               retry time has passed, not only `queued` rows.
     * @return int The number of emails processed (sent, simulated, or failed) in this run.
     */
    public function handle(int $limit = 50, bool $includeRetries = false): int {
        $simulationEnabled = (bool) $this->settings->get('simulation_enabled');

        // Global lock: only one queue batch may run at a time.
        $lockTimeout   = self::LOCK_SECONDS;
        $coreSettings  = $this->coreSettings;
        $currentLock   = (int) $coreSettings->getTransient('queue_lock', 0);

        if ($currentLock > (time() - $lockTimeout)) {
            return 0;
        }

        $coreSettings->setTransient('queue_lock', (string) time(), $lockTimeout);

        try {
            $claimToken     = bin2hex(random_bytes(16));
            $staleThreshold = time() - self::LOCK_SECONDS;

            $table = $this->driver->getTable('boolean_smtp_email_logs');

            // Rows a crashed worker left in `sending` go back to the queue once the lock window has passed.
            $this->driver->statement(
                "UPDATE {$table}
                 SET reserved_by = NULL, reserved_at = NULL, status = 'queued'
                 WHERE status = 'sending' AND reserved_at < %d",
                [$staleThreshold]
            );

            $candidates = $this->candidates($limit, $includeRetries);
            $processed  = 0;

            foreach ($candidates as $log) {
                // Atomic claim: only one worker can win this row, since the WHERE clause
                // requires reserved_at to still be NULL at the moment of the UPDATE.
                $affected = $this->driver->statement(
                    "UPDATE {$table}
                     SET reserved_by = %s, reserved_at = %d, status = 'sending'
                     WHERE id = %d AND reserved_at IS NULL",
                    [$claimToken, time(), (int) $log->id]
                );

                if (!$affected) {
                    continue;
                }

                // Row successfully claimed by this worker.
                $log->refresh();

                if ($simulationEnabled) {
                    $this->simulation->markCaptured($log);

                    $processed++;
                    continue;
                }

                // A retry goes to the first active connection the message has not been tried on;
                // a first send resolves its connection the ordinary way.
                if ($this->isRetry($log)) {
                    $next = $this->ladder->nextConnectionFor($log);
                    if ($next === null) {
                        $this->endLadder($log, (string) ($log->error_message ?? ''), 'retry_exhausted');
                        $processed++;
                        continue;
                    }
                    $this->mailer->forceConnectionForNextSend((int) $next->id);
                }

                // Primary failure-detail source: $GLOBALS['phpmailer'] is only ever populated
                // for an SMTP-mode send (see BooleanSmtpMailPipeline::send()) — an API-mode
                // connection (Outlook/SES/Gmail) never touches it, so on a batch that mixes
                // connection types it would otherwise still hold a PREVIOUS queue item's stale
                // PHPMailer and misattribute that item's error to this one. wp_mail_failed fires
                // with a real message on every failure path in both modes, so capture that instead
                // and only fall back to the global if it somehow comes up empty.
                $lastWpMailFailedMessage = '';
                $captureWpMailFailed     = function (\WP_Error $err) use (&$lastWpMailFailedMessage): void {
                    $lastWpMailFailedMessage = $err->get_error_message();
                };
                $this->hooks->listen('wp_mail_failed', $captureWpMailFailed, 1, 1);

                // The send re-enters the normal mail pipeline, which logs every message. Forcing
                // this row's id makes the pipeline update the queued row instead of creating a
                // second one, and the send lifecycle then announces `email_sent` for it — so the
                // job only announces the delivery itself when the lifecycle stayed silent (the
                // source is excluded from logging, or WordPress predates `wp_mail_succeeded`).
                $sentAnnounced = false;
                $noteSentEvent = function () use (&$sentAnnounced): void {
                    $sentAnnounced = true;
                };
                $this->hooks->listen('boolean_smtp_email_sent', $noteSentEvent, 1, 1);
                $this->mailer->clearPendingLogIdForCurrentSend();
                $this->mailer->setForcedLogId((int) $log->id);
                $this->ladder->beginWorkerAttempt();

                try {
                    try {
                        $sent = wp_mail(
                            $log->to,
                            $log->subject,
                            $log->body ?? '',
                            self::retryHeaders($log->headers, (string) ($log->body ?? ''))
                        );
                    } finally {
                        $this->ladder->endWorkerAttempt();
                        $this->mailer->clearForcedLogId();
                        $this->mailer->clearForcedConnectionForSend();
                        $this->hooks->removeListener('wp_mail_failed', $captureWpMailFailed, 1);
                        $this->hooks->removeListener('boolean_smtp_email_sent', $noteSentEvent, 1);
                    }

                    // The fallback connection may have delivered the message from inside the
                    // failed primary send; the lifecycle announced it, so it counts as sent.
                    $sent = $sent || $sentAnnounced;

                    // Another worker only reaches this row after the lock window; leave its
                    // result alone if that happened while this send was in flight.
                    $log->refresh();
                    if ((string) $log->reserved_by !== $claimToken) {
                        continue;
                    }

                    if ($sent) {
                        $log->update([
                            'status'            => 'delivered',
                            'retries'           => $log->retries + 1,
                            'reserved_at'       => null,
                            'reserved_by'       => null,
                            'next_attempt_at'   => null,
                            'last_attempted_at' => gmdate('Y-m-d H:i:s')
                        ]);

                        $log->refresh();

                        if ($sentAnnounced) {
                            $processed++;
                            continue;
                        }

                        $sentPayload = [
                            'id'            => (int) $log->id,
                            'to'            => (string) $log->to,
                            'subject'       => (string) $log->subject,
                            'status'        => 'delivered',
                            'provider'      => (string) ($log->provider ?? ''),
                            'timestamp'     => time(),
                            'log_id'        => (int) $log->id,
                            'connection_id' => $log->connection_id !== null ? (int) $log->connection_id : null
                        ];
                        /** This filter is documented in app/Services/Mailer/EmailLogSendLifecycle.php */
                        $sentPayload = \apply_filters('boolean_smtp_email_event_data', $sentPayload, 'sent', [
                            'log'       => $log,
                            'mail_data' => []
                        ]);

                        /** This action is documented in app/Services/Mailer/EmailLogSendLifecycle.php */
                        \do_action('boolean_smtp_email_sent', $sentPayload);
                    } else {
                        $detail = trim($lastWpMailFailedMessage);
                        if ($detail === '' && isset($GLOBALS['phpmailer']) && \is_object($GLOBALS['phpmailer']) && isset($GLOBALS['phpmailer']->ErrorInfo)) {
                            $detail = trim((string) $GLOBALS['phpmailer']->ErrorInfo);
                        }

                        $this->recordFailedAttempt($log, $detail);
                    }

                    $processed++;
                } catch (\Throwable $e) {
                    $errorMessage = $e->getMessage();
                    $isRateLimit  = str_contains($errorMessage, 'Rate limit exceeded');

                    if ($isRateLimit) {
                        $log->update([
                            'status'            => 'queued',
                            'error_message'     => $errorMessage,
                            'retries'           => (int) $log->retries + 1,
                            'reserved_at'       => null,
                            'reserved_by'       => null,
                            'last_attempted_at' => gmdate('Y-m-d H:i:s')
                        ]);
                        continue;
                    }

                    $this->recordFailedAttempt($log, $errorMessage, 'queue_exception');
                }
            }
        } finally {
            $coreSettings->deleteTransient('queue_lock');
        }

        if ($this->logs->hasQueueWork()) {
            $this->scheduler->arm();
        }

        return $processed;
    }

    /**
     * The rows this run may claim: `queued` messages and, when asked, `failed` messages whose
     * scheduled retry time has passed — oldest first, at most `$limit` in total.
     *
     * @since 1.0.0
     *
     * @param  int  $limit          Maximum number of rows.
     * @param  bool $includeRetries Whether due retries are claimed too.
     * @return list<EmailLog>
     */
    private function candidates(int $limit, bool $includeRetries): array {
        $rows = EmailLog::query()
            ->whereNull('reserved_at')
            ->where('status', 'queued')
            ->orderBy('created_at', 'ASC')
            ->limit($limit)
            ->get()
            ->all();

        if ($includeRetries) {
            $due = EmailLog::query()
                ->whereNull('reserved_at')
                ->where('status', 'failed')
                ->whereNotNull('next_attempt_at')
                ->where('next_attempt_at', '<=', gmdate('Y-m-d H:i:s'))
                ->orderBy('next_attempt_at', 'ASC')
                ->limit($limit)
                ->get()
                ->all();

            $rows = array_merge($rows, $due);
        }

        return array_slice($rows, 0, $limit);
    }

    /**
     * Whether a claimed row is a retry the ladder scheduled (as opposed to a queued first send,
     * or a rate-limited send put back in the queue for the same connection).
     *
     * @since 1.0.0
     *
     * @param  EmailLog $log The claimed row.
     * @return bool
     */
    private function isRetry(EmailLog $log): bool {
        return $log->next_attempt_at !== null;
    }

    /**
     * Record a failed worker attempt and let the retry ladder decide what follows: the next
     * rung (scheduled, no notification yet) or the final failure with its notification.
     *
     * @since 1.0.0
     *
     * @param  EmailLog $log    The claimed row.
     * @param  string   $detail Failure message to record; kept as stored when empty.
     * @param  string   $source Code path of a final failure: `retry_exhausted`, `queue_exception`.
     * @return void
     */
    private function recordFailedAttempt(EmailLog $log, string $detail, string $source = 'retry_exhausted'): void {
        $update = [
            'status'            => 'failed',
            'retries'           => (int) $log->retries + 1,
            'reserved_at'       => null,
            'reserved_by'       => null,
            'next_attempt_at'   => null,
            'last_attempted_at' => gmdate('Y-m-d H:i:s')
        ];

        if ($detail !== '') {
            $update['error_message'] = $detail;
        }

        $log->update($update);
        $log->refresh();

        if ($this->ladder->scheduleRetry($log)) {
            return;
        }

        $this->endLadder($log, $detail !== '' ? $detail : (string) ($log->error_message ?? ''), $source);
    }

    /**
     * Declare a message's failure final: clear its retry schedule and notify.
     *
     * @since 1.0.0
     *
     * @param  EmailLog $log     The row.
     * @param  string   $message Failure message for the notification.
     * @param  string   $source  Code path that ended the retries.
     * @return void
     */
    private function endLadder(EmailLog $log, string $message, string $source): void {
        $log->update([
            'status'          => 'failed',
            'reserved_at'     => null,
            'reserved_by'     => null,
            'next_attempt_at' => null,
        ]);
        $log->refresh();

        try {
            $this->notifier->notifyFinalFromQueueLog($log, $message !== '' ? $message : 'Delivery failed on every available connection.', $source);
        } catch (\Throwable) {
            // intentionally silent: a notification failure must not surface from the worker.
        }
    }

    /**
     * The `wp_mail()` headers for sending a stored message again.
     *
     * A queued message stores the headers it was requested with (a list of lines). After an
     * attempt the log holds the headers the transport actually assembled, as a name → value
     * map; those are replayed minus the ones the transport regenerates, and a multipart
     * `Content-Type` (auto plain-text, attachments) becomes the type of the stored body.
     *
     * @since 1.0.0
     *
     * @param  mixed  $stored The log's `headers` value.
     * @param  string $body   The stored body, used to pick the content type of a multipart message.
     * @return list<string> Header lines.
     */
    public static function retryHeaders(mixed $stored, string $body = ''): array {
        if (is_string($stored)) {
            return array_values(array_filter(array_map('trim', preg_split('/\r\n|\n/', $stored) ?: []), 'strlen'));
        }
        if (!is_array($stored)) {
            return [];
        }
        if (Arr::isList($stored)) {
            return array_values(array_filter($stored, static fn ($line): bool => is_string($line) && trim($line) !== ''));
        }

        $lines = [];
        foreach ($stored as $name => $value) {
            $name  = trim((string) $name);
            $lower = strtolower($name);
            if ($name === '' || !is_scalar($value) || in_array($lower, self::TRANSPORT_HEADERS, true) || str_starts_with($lower, 'x-ses-')) {
                continue;
            }
            $value = trim((string) $value);
            if ($lower === 'content-type' && stripos($value, 'multipart/') === 0) {
                $value = (preg_match('/<[a-z][^>]*>/i', $body) === 1 ? 'text/html' : 'text/plain') . '; charset=UTF-8';
            }
            $lines[] = $name . ': ' . $value;
        }

        return $lines;
    }
}
