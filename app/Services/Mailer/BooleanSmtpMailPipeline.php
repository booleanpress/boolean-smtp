<?php

/**
 * Full wp_mail-compatible send pipeline used when BooleanSMTP overrides wp_mail().
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer;

use BooleanSmtp\Core\Foundation\Application;
use BooleanSmtp\Support\Settings;
use BooleanSmtp\Services\Mailer\Headers\MailHeaders;
use BooleanSmtp\Services\Mailer\Headers\MailHeadersNormalizer;
use BooleanSmtp\Services\Simulation\SimulationService;

/**
 * Single entry for BooleanSMTP mail: wp_mail compatibility, headers, PHPMailer, transports.
 *
 * @since 1.0.0
 */
final class BooleanSmtpMailPipeline {
    /**
     * Create the pipeline.
     *
     * @since 1.0.0
     *
     * @param Application           $app              Application container used to resolve adapters and services.
     * @param MailHeadersNormalizer $headerNormalizer Normalizer applied to headers before a message is sent.
     */
    public function __construct(
        protected Application $app,
        protected MailHeadersNormalizer $headerNormalizer,
    ) {
    }

    /**
     * Full wp_mail-compatible send (after {@see apply_filters('wp_mail')} if caller did not).
     * Uses adapter contracts for hooks, errors, and context management.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $atts wp_mail-style arguments: to, subject, message, headers, attachments.
     * @return bool True when the message was accepted by the transport (the primary one, or the
     *              fallback connection after the primary failed); false when validation,
     *              PHPMailer construction, or the send itself failed.
     */
    public function send(array $atts): bool {
        $hookAdapter  = $this->app->make(\BooleanSmtp\Adapters\Contracts\HookAdapterContract::class);
        $errorAdapter = $this->app->make(\BooleanSmtp\Adapters\Contracts\ErrorHandlerAdapterContract::class);

        // A log id left over from an earlier send in this request must never receive this
        // send's failure (a validation or From error happens before any row is logged).
        $this->app->make(MailerManager::class)->clearPendingLogIdForCurrentSend();

        /**
         * Filters the wp_mail() arguments.
         *
         * Return a modified array to change what is sent.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $atts wp_mail-style arguments: to, subject, message, headers, attachments.
         */
        $atts = $hookAdapter->filter('wp_mail', $atts);

        /**
         * Fires when a message enters the BooleanSMTP mail pipeline, before validation runs.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $atts wp_mail-style arguments as received by the pipeline.
         */
        $hookAdapter->dispatch('boolean_smtp_mail_intake', $atts);

        /**
         * Filters whether a message should be rejected before it is processed further.
         *
         * Return an error object (see {@see \BooleanSmtp\Adapters\Contracts\ErrorHandlerAdapterContract})
         * to reject the message and fail the send; return null to let it continue.
         *
         * @since 1.0.0
         *
         * @param mixed                $validation Null to continue, or an error object naming the rejection reason.
         * @param array<string, mixed> $atts       wp_mail-style arguments being validated.
         * @return mixed A `WP_Error` to reject the message, `true` to accept it, or `null` to run the built-in validation.
         */
        $validation = $hookAdapter->filter('boolean_smtp_mail_validate', null, $atts);
        if ($errorAdapter->isError($validation)) {
            $this->failWithError($atts, $errorAdapter->getErrorMessage($validation));

            return $this->app->make(MailerManager::class)->takeFallbackDelivered();
        }

        $message = MailMessage::fromWpMailAtts($atts);
        /**
         * Fires after a message has passed validation, before headers are normalized.
         *
         * @since 1.0.0
         *
         * @param MailMessage           $message Normalized message about to be processed.
         * @param array<string, mixed>  $atts    Original wp_mail-style arguments.
         */
        $hookAdapter->dispatch('boolean_smtp_mail_after_validate', $message, $atts);

        $this->applyHeaderPipeline($message, $atts, $hookAdapter);

        /**
         * Filters whether to preempt sending an email through the pipeline.
         *
         * Return a non-null value to short-circuit the send; the pipeline returns that value,
         * cast to bool, without building PHPMailer or contacting a transport.
         *
         * @since 1.0.0
         *
         * @param null|bool             $pre  Short-circuit return value; null by default.
         * @param array<string, mixed>  $atts wp_mail-style arguments for the message being sent.
         */
        $pre = $hookAdapter->filter('pre_wp_mail', null, $atts);
        if (null !== $pre) {
            return (bool) $pre;
        }

        $settings = $this->app->make(Settings::class);
        if ($settings->get('simulation_enabled')) {
            $to         = $atts['to'] ?? '';
            $simulation = $this->app->make(SimulationService::class);
            $simulation->capture([
                'to'         => \is_array($to) ? implode(', ', $to) : (string) $to,
                'from_email' => (string) $settings->get('from_email'),
                'from_name'  => (string) $settings->get('from_name'),
                'subject'    => (string) ($atts['subject'] ?? ''),
                'body'       => (string) ($atts['message'] ?? ''),
                'headers'    => []
            ]);

            return true;
        }

        $atts = $message->toWpMailAttsArray();

        try {
            $phpmailer = WpMailMimeBuilder::createPhpmailerFromAtts($atts);
        } catch (\Throwable $e) {
            $this->failWithError($atts, $e->getMessage());

            return $this->app->make(MailerManager::class)->takeFallbackDelivered();
        }

        // Populate WordPress's documented `global $phpmailer` the same way core's own wp_mail()
        // does — this plugin fully overrides wp_mail() (see includes/boolean-smtp-wp-mail.php),
        // so nothing else ever does this otherwise. Several fallback reads already assume it
        // (TestEmailController, ConnectionController, ProcessQueueJob, EmailLogController,
        // EmailLogSendLifecycle::fullHeadersUpdate()), and third-party code hooking
        // wp_mail_failed/wp_mail_succeeded commonly expects it too, matching core behavior.
        $GLOBALS['phpmailer'] = $phpmailer;

        /**
         * Fires after PHPMailer has been built from the message, before phpmailer_init runs.
         *
         * @since 1.0.0
         *
         * @param MailMessage                     $message   Message being sent.
         * @param \PHPMailer\PHPMailer\PHPMailer  $phpmailer Populated mailer instance.
         */
        $hookAdapter->dispatch('boolean_smtp_mail_before_phpmailer', $message, $phpmailer);

        /**
         * Fires after PHPMailer is initialized.
         *
         * @since 1.0.0
         *
         * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer The PHPMailer instance, passed by reference.
         */
        \do_action_ref_array('phpmailer_init', [ &$phpmailer]); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own mail hook, fired on purpose so API sends keep wp_mail() behaviour for other plugins.

        $mailer = $this->app->make(MailerManager::class);
        $mailer->markSendStart();

        /**
         * Fires immediately before the message is handed to PHPMailer's transport.
         *
         * @since 1.0.0
         *
         * @param MailMessage                     $message   Message being sent.
         * @param \PHPMailer\PHPMailer\PHPMailer  $phpmailer Mailer instance about to send.
         */
        $hookAdapter->dispatch('boolean_smtp_mail_transport_send', $message, $phpmailer);

        try {
            $sent = $phpmailer->send();
        } catch (\Throwable $e) {
            $this->failWithError($atts, $e->getMessage(), $phpmailer);
            $result = MailResult::fail($e->getMessage());
            /**
             * Fires after a send attempt has completed, whether it succeeded or failed.
             *
             * @since 1.0.0
             *
             * @param MailResult  $result  Outcome of the send attempt.
             * @param MailMessage $message Message that was sent.
             */
            $hookAdapter->dispatch('boolean_smtp_mail_send_result', $result, $message);

            return $mailer->takeFallbackDelivered();
        }

        $result = $sent ? MailResult::ok() : MailResult::fail((string) $phpmailer->ErrorInfo);

        /**
         * Fires after a send attempt has completed, whether it succeeded or failed.
         *
         * @since 1.0.0
         *
         * @param MailResult  $result  Outcome of the send attempt.
         * @param MailMessage $message Message that was sent.
         */
        $hookAdapter->dispatch('boolean_smtp_mail_send_result', $result, $message);

        if ($sent) {
            $mailData = [
                'to'          => $atts['to'] ?? [],
                'subject'     => $atts['subject'] ?? '',
                'message'     => $atts['message'] ?? '',
                'headers'     => $atts['headers'] ?? '',
                'attachments' => $atts['attachments'] ?? []
            ];
            /**
             * Fires after PHPMailer has successfully sent an email.
             *
             * @since 1.0.0
             *
             * @param array<string, mixed> $mailData wp_mail arguments for the message that was sent.
             */
            $hookAdapter->dispatch('wp_mail_succeeded', $mailData);

            return true;
        }

        $this->failWithError($atts, (string) $phpmailer->ErrorInfo, $phpmailer);

        // The fallback handler runs inside `wp_mail_failed`; when it delivered the message the
        // caller gets the true outcome instead of a failure it might retry itself.
        return $mailer->takeFallbackDelivered();
    }

    /**
     * Build a wp_mail_failed error and fire it, mirroring WordPress core's own failure signal.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>                    $atts      wp_mail-style arguments for the message that failed.
     * @param  string                                   $message   Failure reason.
     * @param  \PHPMailer\PHPMailer\PHPMailer|null       $phpmailer Mailer instance involved in the failure, if any.
     * @return void
     */
    private function failWithError(array $atts, string $message,  ? \PHPMailer\PHPMailer\PHPMailer $phpmailer = null) : void {
        $errorAdapter = $this->app->make(\BooleanSmtp\Adapters\Contracts\ErrorHandlerAdapterContract::class);
        $hookAdapter  = $this->app->make(\BooleanSmtp\Adapters\Contracts\HookAdapterContract::class);

        $mailData = [
            'to'          => $atts['to'] ?? [],
            'subject'     => $atts['subject'] ?? '',
            'message'     => $atts['message'] ?? '',
            'headers'     => $atts['headers'] ?? '',
            'attachments' => $atts['attachments'] ?? []
        ];

        $error = $errorAdapter->createError('wp_mail_failed', $message, $mailData);
        /**
         * Fires after a message has failed to send.
         *
         * @since 1.0.0
         *
         * @param object $error Error object carrying the failure message and the mail arguments.
         */
        $hookAdapter->dispatch('wp_mail_failed', $error);
    }

    /**
     * Filter and normalize the message's headers, writing the result back onto the message and $atts.
     *
     * @since 1.0.0
     *
     * @param  MailMessage                                          $message     Message whose headers are processed.
     * @param  array<string, mixed>                                 $atts        wp_mail arguments; updated in place.
     * @param  \BooleanSmtp\Adapters\Contracts\HookAdapterContract  $hookAdapter Adapter used to fire the header hooks.
     * @return void
     */
    private function applyHeaderPipeline(
        MailMessage $message,
        array &$atts,
        \BooleanSmtp\Adapters\Contracts\HookAdapterContract $hookAdapter
    ): void {
        $bag = MailHeaders::fromWpMailHeaders($message->headers);

        /**
         * Filters the header bag built from the message before normalization.
         *
         * Return a MailHeaders instance, or an array of header lines or name/value pairs, to replace it.
         *
         * @since 1.0.0
         *
         * @param MailHeaders $headers Header bag parsed from the message's raw headers.
         * @param MailMessage $message Message the headers belong to.
         * @return MailHeaders The filtered headers.
         */
        $filtered = $hookAdapter->filter('boolean_smtp_mail_headers', $bag, $message);
        if ($filtered instanceof MailHeaders) {
            $bag = $filtered;
        } elseif (\is_array($filtered)) {
            $lines = [];
            foreach ($filtered as $name => $value) {
                if (\is_int($name)) {
                    $lines[] = (string) $value;
                } else {
                    $lines[] = $name . ': ' . (string) $value;
                }
            }
            $bag = MailHeaders::fromWpMailHeaders(implode("\n", $lines));
        }

        $normalized = $this->headerNormalizer->normalize($bag);
        /**
         * Fires after headers have been normalized, before they are written back onto the message.
         *
         * @since 1.0.0
         *
         * @param MailHeaders $headers Normalized header bag.
         * @param MailMessage $message Message the headers belong to.
         */
        $hookAdapter->dispatch('boolean_smtp_mail_headers_normalized', $normalized, $message);

        $headerString     = $normalized->toWpMailHeaderString();
        $message->headers = $headerString !== '' ? $headerString : $message->headers;
        $atts['headers']  = $message->headers;
    }
}
