<?php

/**
 * Short-circuits wp_mail() to send through a provider's HTTP API instead of SMTP.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer;

use BooleanSmtp\Core\Foundation\Application;
use BooleanSmtp\Contracts\EncryptorContract;
use BooleanSmtp\Models\Connection;
use BooleanSmtp\Repositories\ConnectionRepository;
use BooleanSmtp\Support\Settings;
use BooleanSmtp\Services\Mailer\Api\GmailApiSender;
use BooleanSmtp\Services\Mailer\Api\MicrosoftGraphMailSender;
use BooleanSmtp\Services\Mailer\Api\SesApiSender;
use BooleanSmtp\Services\Settings\ConstantSettingsResolver;

/**
 * Sends mail via provider HTTP APIs (SES / Gmail / Graph) when delivery_mode is api.
 * Uses pre_wp_mail to short-circuit core wp_mail after MIME is built with core PHPMailer.
 *
 * @since 1.0.0
 */
final class ApiMailDispatcher {
    /**
     * Create the dispatcher.
     *
     * @since 1.0.0
     *
     * @param Application $app Application container used to resolve connections, senders, and settings.
     */
    public function __construct(protected Application $app) {
    }

    /**
     * Handle a message on the pre_wp_mail filter, sending it through the resolved connection's
     * provider API when that connection is configured for API delivery.
     *
     * @since 1.0.0
     *
     * @param  mixed                 $pre  Current short-circuit value; returned unchanged when it is already non-null.
     * @param  array<string, mixed>  $atts wp_mail arguments after wp_mail filter
     * @return mixed Null to let the message fall through to the SMTP pipeline, or a bool result
     *                (true on success, false on failure) once this dispatcher has handled the send.
     */
    public function maybeHandle(mixed $pre, array $atts): mixed {
        if ($pre !== null) {
            return $pre;
        }

        $settingsRepo = $this->app->make(Settings::class);
        if ($settingsRepo->get('simulation_enabled')) {
            return null;
        }

        // A log id left over from an earlier send in this request must never receive this send's result.
        $this->app->make(MailerManager::class)->clearPendingLogIdForCurrentSend();

        $emailData = MailRoutingDataBuilder::fromWpMailAtts($atts, $settingsRepo);
        if ($emailData['to'] === '') {
            return null;
        }

        $mailer = $this->app->make(MailerManager::class);
        $conn   = $mailer->resolveConnectionForSend($emailData);
        if (!$conn) {
            return null;
        }

        $encryptor = $this->app->make(EncryptorContract::class);
        $resolver  = $this->app->make(ConstantSettingsResolver::class);
        $raw       = $conn->settings ?? [];
        $decrypted = \is_array($raw) ? $encryptor->decryptArray($raw) : [];
        $decrypted = MailerManager::prepareDecryptedConnectionSettings($resolver, (string) $conn->driver, $decrypted);

        if (!$mailer->isApiDeliveryMode((string) $conn->driver, $decrypted)) {
            // Not API mode: restore the forced connection for regular SMTP pipeline
            $mailer->forceConnectionForNextSend((int) $conn->id);
            return null;
        }

        try {
            $phpmailer = WpMailMimeBuilder::createPhpmailerFromAtts($atts);
        } catch (\Throwable $e) {
            return $this->failed($atts, $e->getMessage(), null, null, $settingsRepo);
        }

        // Keep PHPMailer diagnostics and from/sender normalization aligned with the
        // exact API connection selected for this send.
        $mailer->forceConnectionForNextSend((int) $conn->id);
        try {
            /**
             * Fires after PHPMailer is initialized.
             *
             * @since 1.0.0
             *
             * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer The PHPMailer instance, passed by reference.
             */
            \do_action_ref_array('phpmailer_init', [ &$phpmailer]); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own mail hook, fired on purpose so API sends keep wp_mail() behaviour for other plugins.
        } finally {
            $mailer->clearForcedConnectionForSend();
        }

        try {
            if (!$phpmailer->preSend()) {
                return $this->failed($atts, (string) $phpmailer->ErrorInfo, $phpmailer, $conn, $settingsRepo);
            }
        } catch (\Throwable $e) {
            return $this->failed($atts, $e->getMessage(), $phpmailer, $conn, $settingsRepo);
        }

        $mime = $phpmailer->getSentMIMEMessage();
        if ($mime === '') {
            return $this->failed($atts, 'Empty MIME message.', $phpmailer, $conn, $settingsRepo);
        }

        // Inject SES-specific headers (configuration_set, static_tags) into the MIME.
        if ($conn->driver === 'ses') {
            $mime = $this->injectSesHeadersIntoMime($mime, $decrypted);
        }

        $gmailSender   = null;
        $outlookSender = null;
        $sesSender     = null;
        if ($conn->driver === 'google') {
            $gmailSender = $this->app->make(GmailApiSender::class);
        } elseif ($conn->driver === 'outlook') {
            $outlookSender = $this->app->make(MicrosoftGraphMailSender::class);
        } elseif ($conn->driver === 'ses') {
            $sesSender = $this->app->make(SesApiSender::class);
        }

        $result = match ($conn->driver) {
            'ses'     => $sesSender->sendRawMime($decrypted, $mime),
            'google'  => $gmailSender->sendRawMime($decrypted, $mime),
            'outlook' => $outlookSender->sendGraphMessage($decrypted, $phpmailer),
            default   => new \WP_Error('booleansmtp_api', 'Unsupported API driver.'),
        };

        $debug = null;
        if ($gmailSender) {
            $this->persistTokenUpdates($conn, $gmailSender->consumePersistableTokenFields());
            $debug = array_merge([
                'provider'      => 'google',
                'delivery_mode' => (string) ($decrypted['delivery_mode'] ?? 'api')
            ], $gmailSender->getAuthDebug());
        } elseif ($outlookSender) {
            $this->persistTokenUpdates($conn, $outlookSender->consumePersistableTokenFields());
            $debug = array_merge([
                'provider'      => 'outlook',
                'delivery_mode' => (string) ($decrypted['delivery_mode'] ?? 'api')
            ], $outlookSender->getAuthDebug());
        } elseif ($sesSender) {
            $debug = array_merge([
                'provider'      => 'ses',
                'delivery_mode' => 'api'
            ], $sesSender->getLastResponseMeta());
        }

        if ($debug !== null) {
            $debug['error_code']    = is_wp_error($result) ? (string) $result->get_error_code() : null;
            $debug['error_message'] = is_wp_error($result) ? $result->get_error_message() : null;
            $debug['error_data']    = is_wp_error($result) ? $result->get_error_data() : null;

            /**
             * Fires with diagnostic details after an API-mode send attempt, whatever its outcome.
             *
             * The admin test-email tool and the debug logger capture this payload; it is not a
             * stable contract and may change between releases.
             *
             * @since 1.0.0
             *
             * @param array<string, mixed> $debug Diagnostic data: `provider` (`google`, `outlook`, `ses`),
             *        `delivery_mode`, `error_code`, `error_message` and `error_data` (the last three
             *        `null` on success), plus the provider's own auth or response details.
             */
            \do_action('boolean_smtp_api_debug', $debug);
        }

        if (is_wp_error($result)) {
            return $this->failed($atts, $result->get_error_message(), $phpmailer, $conn, $settingsRepo, $mime);
        }

        // Log (and set pendingLogIdForCurrentSend) BEFORE firing wp_mail_succeeded below —
        // EmailLogSendLifecycle::onWpMailSucceeded() reads that id synchronously from the
        // action, and would otherwise see a stale id left over from an earlier send in this
        // same request (or none at all), misattributing this delivery to the wrong log.
        if ($settingsRepo->get('log_emails')) {
            $this->logApiDelivery($phpmailer, $conn, true, $settingsRepo, $mailer, null, $mime);
        }

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
        \do_action('wp_mail_succeeded', $mailData); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own mail hook, fired on purpose so API sends keep wp_mail() behaviour for other plugins.

        return true;
    }

    /**
     * Report a failed API send and return the outcome the caller should see: false, or true
     * when the fallback connection delivered the message from inside `wp_mail_failed`.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>                $atts         wp_mail arguments after wp_mail filter
     * @param  string                               $message      Failure reason.
     * @param  \PHPMailer\PHPMailer\PHPMailer|null  $phpmailer    Mailer instance involved in the failure, if any.
     * @param  Connection|null                      $connection   Connection the send was attempted through, if any.
     * @param  Settings                             $settingsRepo Settings source for the log_emails setting.
     * @param  string|null                          $mime         Raw MIME message actually sent, if available.
     * @return bool
     */
    private function failed(
        array $atts,
        string $message,
        ? \PHPMailer\PHPMailer\PHPMailer $phpmailer,
        ?Connection $connection,
        Settings $settingsRepo,
        ?string $mime = null
    ) : bool {
        $this->failWpMail($atts, $message, $phpmailer, $connection, $settingsRepo, $mime);

        return $this->app->make(MailerManager::class)->takeFallbackDelivered();
    }

    /**
     * Log the delivery failure (when applicable) and fire wp_mail_failed for a send handled by this dispatcher.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>                $atts         wp_mail arguments after wp_mail filter
     * @param  string                               $message      Failure reason.
     * @param  \PHPMailer\PHPMailer\PHPMailer|null  $phpmailer    Mailer instance involved in the failure, if any.
     * @param  Connection|null                      $connection   Connection the send was attempted through, if any.
     * @param  Settings                   $settingsRepo Settings source for the log_emails setting.
     * @param  string|null                          $mime         Raw MIME message actually sent, if available.
     * @return void
     */
    private function failWpMail(
        array $atts,
        string $message,
        ? \PHPMailer\PHPMailer\PHPMailer $phpmailer,
        ?Connection $connection,
        Settings $settingsRepo,
        ?string $mime = null
    ) : void {
        if ($phpmailer !== null) {
            $phpmailer->ErrorInfo = $message;
        }

        // Log (and set pendingLogIdForCurrentSend) BEFORE firing wp_mail_failed below — both
        // EmailLogSendLifecycle::onWpMailFailed() and, downstream, MailFailureHandler's fallback
        // retry / EmailDeliveryFailureNotifier read that id synchronously from the action.
        $mailer = $this->app->make(MailerManager::class);
        if ($settingsRepo->get('log_emails') && $phpmailer !== null && $connection !== null) {
            $this->logApiDelivery($phpmailer, $connection, false, $settingsRepo, $mailer, $message, $mime);
        } else {
            // No log row will be written for this failure (logging is off, or this failed
            // before phpmailer_init even ran, e.g. a MIME-build error) — clear any pending
            // log id left over from an earlier send in this request so it isn't misattributed.
            $mailer->clearPendingLogIdForCurrentSend();
        }

        $mailData = [
            'to'          => $atts['to'] ?? [],
            'subject'     => $atts['subject'] ?? '',
            'message'     => $atts['message'] ?? '',
            'headers'     => $atts['headers'] ?? '',
            'attachments' => $atts['attachments'] ?? []
        ];
        /**
         * Fires after a message has failed to send.
         *
         * @since 1.0.0
         *
         * @param \WP_Error $error WordPress error carrying the failure message and the mail arguments.
         */
        \do_action('wp_mail_failed', new \WP_Error('wp_mail_failed', $message, $mailData)); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own mail hook, fired on purpose so API sends keep wp_mail() behaviour for other plugins.
    }

    /**
     * Persist an email log row for an API-mode delivery attempt.
     *
     * @since 1.0.0
     *
     * @param  \PHPMailer\PHPMailer\PHPMailer $phpmailer    Mailer instance the attempt was built from.
     * @param  Connection                     $connection   Connection the attempt was made through.
     * @param  bool                            $success      True when the attempt succeeded.
     * @param  Settings              $settingsRepo Settings source for whether body content may be logged.
     * @param  MailerManager                   $mailer       Mailer used to resolve the log source and persist the row.
     * @param  string|null                     $errorMessage Failure reason, when $success is false.
     * @param  string|null                     $rawMime      Raw MIME actually sent, when available.
     * @return void
     */
    private function logApiDelivery(
        \PHPMailer\PHPMailer\PHPMailer $phpmailer,
        Connection $connection,
        bool $success,
        Settings $settingsRepo,
        MailerManager $mailer,
        ?string $errorMessage = null,
        ?string $rawMime = null
    ): void {
        // resolveLogSourceForAttempt() (not captureEmailSource()) so a UI resend's
        // "Manually Resent" attemptSourceOverride is honored the same way the SMTP path does.
        $source = $mailer->resolveLogSourceForAttempt();

        $toAddresses = $phpmailer->getToAddresses();
        $to          = $toAddresses[0][0] ?? '';

        $ccAddresses = $phpmailer->getCcAddresses();
        $cc          = implode(', ', array_map(static fn($a) => $a[0], $ccAddresses));

        $bccAddresses = $phpmailer->getBccAddresses();
        $bcc          = implode(', ', array_map(static fn($a) => $a[0], $bccAddresses));

        // preSend() has already run on this $phpmailer by the time logApiDelivery() is called
        // (both call sites: maybeHandle() after the send succeeds, failWpMail() after a
        // preSend()-or-later failure), so getSentMIMEMessage() has the full assembled headers —
        // not just custom ones added via addCustomHeader(). Prefer the caller-supplied $rawMime
        // when given: for SES it's the MIME actually put on the wire, including the
        // X-SES-CONFIGURATION-SET / X-SES-MESSAGE-TAGS headers injectSesHeadersIntoMime() adds,
        // which getSentMIMEMessage() alone would never see (that injection never touches $phpmailer).
        $headers = MailerManager::parseRawHeaders($rawMime ?? $phpmailer->getSentMIMEMessage());

        if (! $mailer->shouldLogEmail([
            'to'         => $to,
            'cc'         => $cc,
            'bcc'        => $bcc,
            'from_email' => (string) $phpmailer->From,
            'from_name'  => (string) $phpmailer->FromName,
            'subject'    => (string) $phpmailer->Subject,
            'headers'    => $headers,
            'source'     => $source,
        ], $connection)) {
            return;
        }

        $status = $success ? 'delivered' : 'failed';
        $data   = [
            'to'            => $to,
            'cc'            => $cc ?: null,
            'bcc'           => $bcc ?: null,
            'from_email'    => $phpmailer->From,
            'from_name'     => $phpmailer->FromName,
            'subject'       => $phpmailer->Subject,
            'body'          => $mailer->isBodyLoggingAllowed() ? $phpmailer->Body : null,
            'headers'       => $headers,
            'attachments'   => $mailer->extractAttachmentMeta($phpmailer),
            'status'        => $status,
            'provider'      => $connection->driver,
            'connection_id' => $connection->id,
            'source'        => $source,
            'error_message' => $success ? null : $errorMessage
        ];

        $attemptRow = [
            'timestamp'     => time(),
            'date'          => gmdate('Y-m-d H:i:s'),
            'status'        => $status,
            'connection_id' => (int) $connection->id,
            'provider'      => (string) $connection->driver,
            'source'        => $source
        ];
        if (!$success && $errorMessage) {
            $attemptRow['error'] = $errorMessage;
        }

        // Respects a UI resend's forced log id (updates that log's own attempts/history)
        // instead of always creating a new row — see MailerManager::persistLogAttempt().
        $mailer->persistLogAttempt($data, $attemptRow);
    }

    /**
     * Inject SES-specific headers into a raw MIME message before it is sent.
     * Configuration Set uses the X-SES-CONFIGURATION-SET header.
     * Static tags are injected as an X-SES-MESSAGE-TAGS header (JSON format).
     *
     * @since 1.0.0
     *
     * @param  string                $mime     Raw MIME message to modify.
     * @param  array<string, mixed>  $settings Decrypted connection settings; reads configuration_set and static_tags.
     * @return string Modified MIME
     */
    private function injectSesHeadersIntoMime(string $mime, array $settings): string {
        if ($mime === '') {
            return $mime;
        }

        $headersToAdd = [];

        // Configuration Set
        $configSet = trim((string) ($settings['configuration_set'] ?? ''));
        if ($configSet !== '') {
            $headersToAdd['X-SES-CONFIGURATION-SET'] = $configSet;
        }

        // Static Message Tags
        $staticTags = $settings['static_tags'] ?? [];
        if (!empty($staticTags) && \is_array($staticTags)) {
            $tagsJson = @\json_encode($staticTags, JSON_UNESCAPED_SLASHES);
            if ($tagsJson !== false && $tagsJson !== '') {
                $headersToAdd['X-SES-MESSAGE-TAGS'] = $tagsJson;
            }
        }

        if (empty($headersToAdd)) {
            return $mime;
        }

        // Insert headers before the first double CRLF (header/body separator)
        $separator = "\r\n\r\n";
        $pos       = \strpos($mime, $separator);

        if ($pos === false) {
            // No headers/body separator found; try with LF only
            $separator = "\n\n";
            $pos       = \strpos($mime, $separator);
        }

        if ($pos === false) {
            // Can't find separator; prepend headers anyway
            $headerLines = [];
            foreach ($headersToAdd as $name => $value) {
                $headerLines[] = $name . ': ' . $value;
            }
            return \implode("\r\n", $headerLines) . "\r\n\r\n" . $mime;
        }

        $headerSection = \substr($mime, 0, $pos);
        $bodySection   = \substr($mime, $pos+\strlen($separator));

        // Build new header section with injected SES headers
        $headerLines = \explode("\r\n", $headerSection);
        // Alternative: handle mixed line endings
        if (\count($headerLines) === 1) {
            $headerLines = \explode("\n", $headerSection);
        }

        // Append SES-specific headers before the separator
        foreach ($headersToAdd as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        return \implode("\r\n", $headerLines) . "\r\n\r\n" . $bodySection;
    }

    /**
     * Persist provider token fields refreshed during a send back onto the connection's encrypted settings.
     *
     * @since 1.0.0
     *
     * @param  Connection             $connection  Connection to update.
     * @param  array<string, mixed>   $tokenFields Token fields to merge into settings; no-op when empty.
     * @return void
     */
    private function persistTokenUpdates(Connection $connection, array $tokenFields): void {
        if (empty($tokenFields)) {
            return;
        }

        $encryptor = $this->app->make(EncryptorContract::class);
        $repo      = $this->app->make(ConnectionRepository::class);
        $raw       = $connection->settings ?? [];
        $current   = \is_array($raw) ? $encryptor->decryptArray($raw) : [];

        foreach ($tokenFields as $key => $value) {
            $current[$key] = $value;
        }

        $repo->update((int) $connection->id, [
            'settings' => $encryptor->encryptArray($current)
        ]);
    }
}
