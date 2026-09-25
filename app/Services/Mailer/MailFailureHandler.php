<?php

/**
 * Retries a failed send through the fallback connection and hands what still fails to the retry ladder.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer;

use BooleanSmtp\Contracts\Editions\SenderRouterContract;
use BooleanSmtp\Core\Foundation\Application;
use BooleanSmtp\Models\EmailLog;
use BooleanSmtp\Repositories\EmailLogRepository;
use BooleanSmtp\Services\Queue\RetryLadder;
use BooleanSmtp\Support\Settings;

/**
 * Retries a failed wp_mail at once, in the same send, on the connections the site's sender-routing
 * policy names: by default the fallback connection; with sender groups, the group's other members
 * first. At most {@see MAX_IMMEDIATE_TRIES} tries, never on a connection the message already
 * failed on.
 *
 * When no fallback send will run, or the fallback failed too, the message goes to the
 * {@see RetryLadder}: while "Retry on other connections" is on and an untried active connection
 * and a retry attempt are left, the queue worker retries it later and nothing is announced yet;
 * otherwise {@see EmailDeliveryFailureNotifier::notifyFinal()} fires the final-failure alerts.
 * A send the queue worker itself runs is left to the worker, which owns that decision.
 *
 * @since 1.0.0
 */
class MailFailureHandler
{
    /**
     * True while a fallback retry triggered by this handler is in progress, guarding against
     * re-entering the fallback path if the retry itself fails.
     *
     * @since 1.0.0
     * @var bool
     */
    protected bool $inFallbackRetry = false;

    /**
     * The most connections a failed send is retried on at once, before the rest is left to the
     * retry queue.
     *
     * @since 1.0.0
     * @var int
     */
    public const MAX_IMMEDIATE_TRIES = 3;

    /**
     * The error of the last failed immediate retry, reported while the retry loop runs.
     *
     * @since 1.0.0
     * @var \WP_Error|null
     */
    protected ?\WP_Error $retryError = null;

    /**
     * Create the handler.
     *
     * @since 1.0.0
     *
     * @param Application                      $app      Application container used to resolve settings and the mailer.
     * @param EmailDeliveryFailureNotifier|null $notifier Notifier for final failures; from the container if null.
     */
    public function __construct(
        protected Application $app,
        protected ?EmailDeliveryFailureNotifier $notifier = null,
    ) {
    }

    /**
     * Handle a failed send: retry it once through the fallback connection when one is configured
     * and applicable, then leave what still failed to the retry ladder or the final notification.
     *
     * @since 1.0.0
     *
     * @param  \WP_Error $error WordPress error describing the failed send.
     * @return void
     */
    public function handle(\WP_Error $error): void
    {
        if ($this->inFallbackRetry) {
            // A failed immediate retry: the loop below decides what is tried next.
            $this->retryError = $error;

            return;
        }

        /**
         * Filters whether the fallback retry should be skipped for a failed send.
         *
         * Return true to skip the fallback retry and the retries on other connections, and notify
         * of the final failure immediately.
         *
         * @since 1.0.0
         *
         * @param bool      $skip  Whether to skip the fallback retry; false by default.
         * @param \WP_Error $error WordPress error describing the failed send.
         * @return bool The filtered value.
         */
        if (\apply_filters('boolean_smtp_skip_fallback_retry', false, $error)) {
            $this->failureNotifier()->notifyFinal($error, []);

            return;
        }

        $settings = $this->app->make(Settings::class);

        if ($settings->get('simulation_enabled')) {
            $this->failureNotifier()->notifyFinal($error, []);

            return;
        }

        /** @var MailerManager $mailer */
        $mailer = $this->app->make(MailerManager::class);
        $lastId = $mailer->getLastConfiguredConnectionId();

        // The site's sender-routing policy names the connection to try at once: by default the
        // fallback connection, when fallback is switched on; never one this message failed on.
        $pending    = $this->pendingLog();
        $router     = $this->app->make(SenderRouterContract::class);
        $tried      = $this->previouslyTried($pending);
        $fallbackId = (int) $router->immediateFallback($lastId, $tried);
        if ($fallbackId <= 0) {
            $this->finish($error, [], $pending);

            return;
        }

        if ($lastId !== null && $lastId === $fallbackId) {
            $this->finish($error, ['source' => 'no_fallback_path'], $pending);

            return;
        }

        $data = $error->get_error_data();
        if (! \is_array($data)) {
            $this->finish($error, [], $pending);

            return;
        }

        if (! isset($data['to'], $data['subject'], $data['message'])) {
            $this->finish($error, [], $pending);

            return;
        }

        $to          = $data['to'];
        $subject     = $data['subject'];
        $message     = $data['message'];
        $headers     = $data['headers'] ?? '';
        $attachments = $data['attachments'] ?? [];

        if ($lastId !== null) {
            $tried[] = $lastId;
        }

        $next                  = $fallbackId;
        $this->inFallbackRetry = true;
        try {
            for ($try = 1; $next > 0 && $try <= self::MAX_IMMEDIATE_TRIES; $try++) {
                $tried[] = $next;
                $mailer->forceConnectionForNextSend($next);

                $pendingLogId = $mailer->getPendingLogIdForCurrentSend();
                if ($pendingLogId !== null && $pendingLogId > 0) {
                    $mailer->setForcedLogId($pendingLogId);
                }

                /**
                 * Fires before a failed send is retried through the fallback connection.
                 *
                 * @since 1.0.0
                 *
                 * @param array<string, mixed> $payload Retry payload: to, subject, message, headers, attachments, fallback_id.
                 */
                \do_action('boolean_smtp_mail_fallback', [
                    'to'          => $to,
                    'subject'     => $subject,
                    'message'     => $message,
                    'headers'     => $headers,
                    'attachments' => $attachments,
                    'fallback_id' => $next,
                ]);

                $this->retryError = null;
                if (\function_exists('wp_mail') && \wp_mail($to, $subject, $message, $headers, $attachments)) {
                    $mailer->markFallbackDelivered();

                    return;
                }

                $error = $this->retryError ?? $error;
                $next  = (int) $router->immediateFallback($next, $tried);
                if (\in_array($next, $tried, true)) {
                    $next = 0;
                }
            }
        } finally {
            $this->inFallbackRetry = false;
            $this->retryError      = null;
        }

        $this->finish($error, ['source' => 'fallback_failed']);
    }

    /**
     * The connections this message already failed on in earlier attempts (a queued retry, a
     * resend), read from its log row.
     *
     * @since 1.0.0
     *
     * @param  EmailLog|null $log The message's log row, when the pipeline wrote one.
     * @return list<int>
     */
    private function previouslyTried(?EmailLog $log): array
    {
        $attempts = $log?->attempts;
        if (! \is_array($attempts)) {
            return [];
        }

        $ids = [];
        foreach ($attempts as $attempt) {
            if (\is_array($attempt) && ($attempt['status'] ?? null) === 'failed' && (int) ($attempt['connection_id'] ?? 0) > 0) {
                $ids[] = (int) $attempt['connection_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * End a failed send: schedule the next retry when the ladder has a rung left, otherwise
     * announce the final failure. A send the queue worker runs is left to the worker.
     *
     * @since 1.0.0
     *
     * @param  \WP_Error            $error WordPress error describing the failed send.
     * @param  array<string, mixed> $extra Context for the notifier; recognizes a "source" key.
     * @param  EmailLog|null|false  $log   The message's log row when already read and unchanged
     *                                     since; false to read it now.
     * @return void
     */
    private function finish(\WP_Error $error, array $extra, EmailLog|null|false $log = false): void
    {
        $ladder = $this->app->make(RetryLadder::class);

        if ($ladder->isWorkerAttempt()) {
            return;
        }

        if ($ladder->scheduleRetry($log === false ? $this->pendingLog() : $log)) {
            return;
        }

        $this->failureNotifier()->notifyFinal($error, $extra);
    }

    /**
     * The log row of the send that just failed, when the pipeline wrote one.
     *
     * @since 1.0.0
     *
     * @return EmailLog|null
     */
    private function pendingLog(): ?EmailLog
    {
        $logId = $this->app->make(MailerManager::class)->getPendingLogIdForCurrentSend();
        if ($logId === null || $logId <= 0) {
            return null;
        }

        $log = $this->app->make(EmailLogRepository::class)->find($logId);
        if ($log !== null) {
            $log->refresh();
        }

        return $log;
    }

    /**
     * Resolve the notifier used for final failure alerts.
     *
     * @since 1.0.0
     *
     * @return EmailDeliveryFailureNotifier
     */
    private function failureNotifier(): EmailDeliveryFailureNotifier
    {
        return $this->notifier ?? $this->app->make(EmailDeliveryFailureNotifier::class);
    }
}
