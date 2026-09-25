<?php

/**
 * REST controller for browsing, resending, and deleting logged emails.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Http\Controllers;

use BooleanSmtp\Core\Contracts\LoggerContract;
use BooleanSmtp\Core\Http\Controller;
use BooleanSmtp\Core\Http\JsonResponse;
use BooleanSmtp\Core\Http\Request;
use BooleanSmtp\Http\Concerns\ResolvesPagination;
use BooleanSmtp\Contracts\TranslatorContract;
use BooleanSmtp\Models\EmailLog;
use BooleanSmtp\Repositories\ConnectionRepository;
use BooleanSmtp\Repositories\EmailLogRepository;
use BooleanSmtp\Support\Settings;
use BooleanSmtp\Services\Mailer\MailerManager;
use BooleanSmtp\Services\Mailer\SupervisedSend;
use BooleanSmtp\Support\Debug\WordPressDebugLogger;

/**
 * Backs the Email Logs screen: listing, viewing, resending, and deleting log entries.
 *
 * @since 1.0.0
 */
class EmailLogController extends Controller {
    use ResolvesPagination;

    /**
     * Lazily resolved translator, cached for the lifetime of the request.
     *
     * @since 1.0.0
     * @var TranslatorContract|null
     */
    private ?TranslatorContract $translator = null;

    /**
     * @since 1.0.0
     *
     * @param EmailLogRepository $logs   Reads and writes email log entries.
     * @param LoggerContract     $logger Records resend failures.
     */
    public function __construct(
        protected EmailLogRepository $logs,
        protected LoggerContract $logger,
    ) {}

    /**
     * Handle `GET /booleansmtp/v1/logs`.
     *
     * Reads `per_page`, `page`, `status`, `provider`, `search`, `date_from`, and `date_to` from
     * the query string. Raw provider error details are replaced with a generic message unless
     * the developer API-debug filter is enabled (see {@see WordPressDebugLogger::canExposeApiDebugResponse()}).
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying `per_page`, `page`, `status`, `provider`,
     *                          `search`, `date_from`, `date_to`.
     * @return JsonResponse Paginated email log entries.
     */
    public function index(Request $request): JsonResponse {
        ['page' => $page, 'per_page' => $perPage] = $this->resolvePagination($request, 25);

        $filters = [
            'status'    => $request->get('status'),
            'provider'  => $request->get('provider'),
            'search'    => $request->get('search'),
            'date_from' => $request->get('date_from'),
            'date_to'   => $request->get('date_to')
        ];

        $result = $this->logs->paginate($perPage, $page, $filters);
        $includeRawApiDiagnostics = WordPressDebugLogger::canExposeApiDebugResponse();
        if (!$includeRawApiDiagnostics && isset($result['data']) && \is_array($result['data'])) {
            $result['data'] = array_map(function (mixed $row): array {
                $data = $row instanceof EmailLog ? $row->toArray() : (array) $row;

                return self::sanitizeBrowserLogData($data);
            }, $result['data']);
        }

        return $this->ok($result);
    }

    /**
     * Handle `GET /booleansmtp/v1/logs/{id}`.
     *
     * Includes the full message body and attachments, subject to the same API-debug gating as
     * {@see index()}.
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying the log `id` route parameter.
     * @return JsonResponse The email log entry.
     */
    public function show(Request $request): JsonResponse {
        $id  = (int) $request->param('id');
        $log = $this->logs->findOrFail($id);

        $data                = $log->toArray();
        $data['body']        = $log->body;
        $data['attachments'] = $log->attachments ?: [];
        if (!WordPressDebugLogger::canExposeApiDebugResponse()) {
            $data = self::sanitizeBrowserLogData($data);
        }

        return $this->ok($data);
    }

    /**
     * Replace raw provider error details with a generic message before a log row reaches the browser.
     *
     * Raw provider response bodies can be persisted as failed-delivery errors. Diagnostic data is
     * kept server-side unless the developer API-debug gate is enabled, so Email Logs applies the
     * same response boundary as the Test Email and connection-test endpoints.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $data Log row as an array.
     * @return array<string, mixed> The same row with sensitive error details masked.
     */
    private static function sanitizeBrowserLogData(array $data): array {
        if (isset($data['error_message']) && trim((string) $data['error_message']) !== '') {
            $data['error_message'] = self::safeDeliveryError();
        }

        if (isset($data['attempts']) && \is_array($data['attempts'])) {
            foreach ($data['attempts'] as $index => $attempt) {
                if (!\is_array($attempt) || !isset($attempt['error']) || trim((string) $attempt['error']) === '') {
                    continue;
                }

                $attempt['error']        = self::safeDeliveryError();
                $data['attempts'][$index] = $attempt;
            }
        }

        return $data;
    }

    /**
     * The generic delivery-failure message shown to the browser in place of a raw provider error.
     *
     * @since 1.0.0
     *
     * @return string The generic error message.
     */
    private static function safeDeliveryError(): string {
        return 'Email delivery failed. Review the connection settings and retry.';
    }

    /**
     * Handle `POST /booleansmtp/v1/logs/{id}/resend`.
     *
     * Resends the stored message body and headers for one log entry through its original
     * connection (or the primary connection when that one is no longer active), and updates the
     * log's status and attempt history in place rather than creating a new log row. Skipped
     * entirely, and simulated instead, when Email Simulation Mode is on. Suppresses the mailer's
     * automatic fallback-retry so a user-initiated resend tests only the intended connection.
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying the log `id` route parameter.
     * @return JsonResponse The updated log entry on success, or a 422 error describing why the
     *                       resend could not be attempted or failed.
     */
    public function resend(Request $request): JsonResponse {
        $id  = (int) $request->param('id');
        $log = $this->logs->findOrFail($id);

        $simulationEnabled = (bool) $this->make(Settings::class)->get('simulation_enabled');
        if ($simulationEnabled) {
            $log->update([
                'status'   => 'simulated',
                'provider' => 'simulation'
            ]);

            return $this->ok(null, 'Email resent successfully (simulated).');
        }

        $connections = $this->make(ConnectionRepository::class);
        $connection  = null;
        if ($log->connection_id) {
            $c = $connections->find((int) $log->connection_id);
            if ($c && $c->is_active) {
                $connection = $c;
            }
        }
        if ($connection === null) {
            $connection = $connections->getPrimary();
        }
        if ($connection === null) {
            return $this->error(
                'Resend failed: no active mail connection. Add or activate a connection in BooleanSMTP settings.',
                422
            );
        }

        $mailer = $this->make(MailerManager::class);

        // Body is missing only for older logs predating body logging, simulated
        // sends, or a site whose message-body policy does not store bodies.
        $body = $log->body;
        if (empty($body)) {
            if (!$mailer->isBodyLoggingAllowed()) {
                return $this->error(
                    'Resend failed: Email body storage is turned off in Settings. '
                    . 'Enable "Store Email Message Body" to allow resending.',
                    422
                );
            }

            return $this->error(
                'Resend failed: Email body is missing for this log entry. Cannot resend without a message body.',
                422
            );
        }

        $mailer->forceConnectionForNextSend((int) $connection->id);
        $mailer->setForcedLogId((int) $log->id); // Tell mailer to update this log instead of creating a new one
        $mailer->setAttemptSourceOverride($this->t('Manually Resent'));

        $headers = $this->buildWpMailHeaders($log);
        $error   = null;
        $sent    = false;

        // One user-initiated send testing THIS connection: SupervisedSend disables the automatic
        // fallback retry (a silent switch would misreport the result and log an unrelated attempt)
        // and captures the wp_mail_failed message, the only failure detail API-mode transports emit.
        try {
            $outcome = $this->make(SupervisedSend::class)->run(
                static fn (): bool => (bool) wp_mail($log->to, (string) $log->subject, $body, $headers)
            );
        } finally {
            $mailer->clearForcedConnectionForSend();
            $mailer->clearForcedLogId();
            $mailer->clearAttemptSourceOverride();
        }

        $sent = $outcome->sent;
        if ($outcome->exception !== null) {
            $error = $outcome->exception->getMessage();
            $this->logger->error('Resend failed: ' . $error, ['email_log_id' => (int) $log->id]);
        }
        $lastWpMailFailedMessage = $outcome->failureMessage;

        // CRITICAL: Refresh log to get the 'pending' attempt record added by MailerManager in logEmail()
        $log->refresh();

        $detail = trim($lastWpMailFailedMessage);
        if ($detail === '' && isset($GLOBALS['phpmailer']) && \is_object($GLOBALS['phpmailer']) && isset($GLOBALS['phpmailer']->ErrorInfo)) {
            $detail = trim((string) $GLOBALS['phpmailer']->ErrorInfo);
        }

        if (!$sent && $error === null && $detail === '') {
            $detail = 'Transport returned false without specific error.';
        }

        $status       = $sent ? 'delivered' : 'failed';
        $errorMessage = $sent ? null : ($error ?: $detail);

        // Update the log with final status of this attempt
        $attempts   = $log->attempts ?: [];
        $lastIndex  = count($attempts) - 1;
        $lastStatus = $lastIndex >= 0 ? (string) ($attempts[$lastIndex]['status'] ?? '') : '';

        // MailerManager adds a pending row; EmailLogSendLifecycle may already have set delivered/failed
        // before we run (wp_mail_succeeded / wp_mail_failed). Do not append a second attempt in that case.
        if ($lastIndex >= 0 && $lastStatus === 'pending') {
            $attempts[$lastIndex]['status'] = $status;
            if ($errorMessage) {
                $attempts[$lastIndex]['error'] = $errorMessage;
            }
        } elseif ($lastIndex >= 0 && \in_array($lastStatus, ['delivered', 'failed'], true)) {
            // Pipeline lifecycle already finalized this attempt; keep attempts as-is.
        } else {
            // Fallback: manually record if MailerManager didn't (e.g. configure never ran)
            $attempts[] = [
                'timestamp'     => time(),
                'date'          => gmdate('Y-m-d H:i:s'),
                'status'        => $status,
                'error'         => $errorMessage,
                'connection_id' => $connection->id,
                'provider'      => $connection->driver,
                'source'        => $this->t('Manually Resent')
            ];
        }

        $log->update([
            'status'        => $status,
            'error_message' => $errorMessage,
            'retries'       => (int) $log->retries + 1,
            'attempts'      => $attempts
        ]);

        if ($sent) {
            $data = $log->toArray();
            if (!WordPressDebugLogger::canExposeApiDebugResponse()) {
                $data = self::sanitizeBrowserLogData($data);
            }

            return $this->ok($data, 'Email resent successfully.');
        }

        // The 3rd arg to error() lands in the response's `errors` key (meant for small
        // validation-error dicts, e.g. ['field' => 'message']) — passing the full model
        // here previously made the frontend dump the entire log row into the error text.
        $browserError = WordPressDebugLogger::canExposeApiDebugResponse()
            ? ($errorMessage ?: 'Unknown error')
            : self::safeDeliveryError();

        return $this->error('Resend failed: ' . $browserError, 422);
    }

    /**
     * Build the header lines to pass to `wp_mail()` when resending a logged email.
     *
     * `wp_mail()` expects each header as a "Name: value" string; associative arrays from logs
     * only carry values. The stored header set includes standard envelope headers (Date, From,
     * Message-ID, MIME-Version, Content-Type, ...) captured from the original send, but PHPMailer
     * regenerates every one of those fresh on every send. Forwarding the original values back
     * would duplicate them or reuse a stale Message-ID, which providers with a strict raw-MIME
     * parser reject outright. Only genuinely custom headers (`X-*`, `List-Unsubscribe`, ...) are
     * carried over; an HTML content type is added automatically when the body looks like HTML and
     * none is already present.
     *
     * @since 1.0.0
     *
     * @param EmailLog $log Log entry whose stored headers and body are used to build the resend.
     * @return array<int, string> Header lines suitable for `wp_mail()`.
     */
    protected function buildWpMailHeaders(EmailLog $log): array {
        $raw   = $log->headers;
        $lines = [];

        if (is_array($raw) && $raw !== []) {
            foreach ($raw as $name => $value) {
                if (is_int($name)) {
                    if (is_string($value) && str_contains($value, ':')) {
                        [$headerName] = explode(':', $value, 2);
                        if (!$this->isStandardEnvelopeHeader($headerName)) {
                            $lines[] = $value;
                        }
                    }
                } elseif (is_scalar($value) && !$this->isStandardEnvelopeHeader((string) $name)) {
                    $lines[] = $name . ': ' . (string) $value;
                }
            }
        }

        $body = $log->body ?? '';
        if (is_string($body) && $body !== '' && preg_match('/<\s*html|<\s*body|<\s*br|<\s*p[\s>]/i', $body)) {
            array_unshift($lines, 'Content-Type: text/html; charset=UTF-8');
        }

        return $lines;
    }

    /**
     * Check whether a header name is one of the standard envelope headers `wp_mail()` regenerates itself.
     *
     * @since 1.0.0
     *
     * @param string $name Header name to check (case-insensitive).
     * @return bool True when the header is a standard envelope header.
     */
    private function isStandardEnvelopeHeader(string $name): bool {
        static $standard = [
            'date', 'from', 'to', 'cc', 'bcc', 'subject', 'reply-to',
            'message-id', 'mime-version', 'content-type', 'content-transfer-encoding',
            'x-mailer', 'return-path', 'sender',
        ];

        return \in_array(\strtolower(\trim($name)), $standard, true);
    }

    /**
     * Handle `DELETE /booleansmtp/v1/logs/{id}`.
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying the log `id` route parameter.
     * @return JsonResponse Confirmation message.
     */
    public function destroy(Request $request): JsonResponse {
        $id = (int) $request->param('id');
        $this->logs->delete($id);

        return $this->ok(null, 'Email log deleted.');
    }

    /**
     * Handle `DELETE /booleansmtp/v1/logs`.
     *
     * Reads `select_all` and either `filters` (to resolve every matching id) or an explicit `ids`
     * list, then deletes each entry, skipping any that fail.
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying `select_all` and either `filters` or `ids`.
     * @return JsonResponse Confirmation message, or a 422 error when no ids are provided.
     */
    public function bulkDestroy(Request $request): JsonResponse {
        $selectAll = filter_var($request->get('select_all', false), FILTER_VALIDATE_BOOLEAN);
        $ids       = [];

        if ($selectAll) {
            $filters = $request->get('filters', []);
            if (!is_array($filters)) {
                $filters = [];
            }
            $ids = $this->logs->getIdsByFilters($filters);
        } else {
            $ids = $request->get('ids', []);
        }

        if (!is_array($ids) || empty($ids)) {
            return $this->error('No IDs provided.', 422);
        }

        foreach ($ids as $id) {
            try {
                $this->logs->delete((int) $id);
            } catch (\Throwable) {
                continue;
            }
        }

        return $this->ok(null, 'Selected email logs deleted.');
    }

    /**
     * Handle `POST /booleansmtp/v1/logs/resend`.
     *
     * Reads `select_all` and either `filters` (to resolve every matching id) or an explicit `ids`
     * list, then resends each log entry through its original connection (or the primary
     * connection when unavailable), skipping entries with no usable connection. Suppresses the
     * mailer's automatic fallback-retry for the same reason as {@see resend()}.
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying `select_all` and either `filters` or `ids`.
     * @return JsonResponse Success and failure counts, or a 422 error when no ids are provided.
     */
    public function bulkResend(Request $request): JsonResponse {
        $selectAll = filter_var($request->get('select_all', false), FILTER_VALIDATE_BOOLEAN);
        $ids       = [];

        if ($selectAll) {
            $filters = $request->get('filters', []);
            if (!is_array($filters)) {
                $filters = [];
            }
            $ids = $this->logs->getIdsByFilters($filters);
        } else {
            $ids = $request->get('ids', []);
        }

        if (!is_array($ids) || empty($ids)) {
            return $this->error('No IDs provided.', 422);
        }

        $connections = $this->make(ConnectionRepository::class);
        $mailer      = $this->make(MailerManager::class);

        $successCount = 0;
        $failCount    = 0;

        // Same rationale as the single resend(): each of these is a user-initiated send
        // testing its own connection, so SupervisedSend keeps the automatic fallback retry off.
        $supervised = $this->make(SupervisedSend::class);

        foreach ($ids as $id) {
            try {
                $log = $this->logs->findOrFail((int) $id);

                $connection = null;
                if ($log->connection_id) {
                    $c = $connections->find((int) $log->connection_id);
                    if ($c && $c->is_active) {
                        $connection = $c;
                    }
                }
                if ($connection === null) {
                    $connection = $connections->getPrimary();
                }
                if ($connection === null) {
                    $failCount++;
                    continue;
                }

                $mailer->forceConnectionForNextSend((int) $connection->id);
                $mailer->setForcedLogId((int) $log->id); // Update this log's own history instead of creating a new row
                $mailer->setAttemptSourceOverride($this->t('Manually Resent'));
                $headers = $this->buildWpMailHeaders($log);

                $outcome = $supervised->run(
                    static fn (): bool => (bool) wp_mail($log->to, (string) $log->subject, $log->body ?? '', $headers)
                );
                if ($outcome->exception !== null) {
                    throw $outcome->exception;
                }
                $sent = $outcome->sent;

                $log->refresh();
                $log->update([
                    'status'  => $sent ? 'delivered' : 'failed',
                    'retries' => (int) $log->retries + 1
                ]);

                if ($sent) {
                    $successCount++;
                } else {
                    $failCount++;
                }
            } catch (\Throwable $e) {
                $this->logger->error('Bulk resend failed: ' . $e->getMessage());
                $failCount++;
            } finally {
                $mailer->clearForcedConnectionForSend();
                $mailer->clearForcedLogId();
                $mailer->clearAttemptSourceOverride();
            }
        }

        return $this->ok([
            'success' => $successCount,
            'failed'  => $failCount
        ], "Resent {$successCount} emails. ({$failCount} failed)");
    }

    /**
     * Handle `GET /booleansmtp/v1/logs/queue/stats`.
     *
     * @since 1.0.0
     *
     * @param Request $request Unused; counts are read directly from the email log table.
     * @return JsonResponse Counts of queued, processing, failed, and delivered log entries.
     */
    public function queueStats(Request $request): JsonResponse {
        $stats = [
            'queued'     => EmailLog::query()->where('status', 'queued')->count(),
            'processing' => EmailLog::query()->where('status', 'sending')->count(),
            'failed'     => EmailLog::query()->where('status', 'failed')->count(),
            'delivered'  => EmailLog::query()->where('status', 'delivered')->count()
        ];

        return $this->ok($stats);
    }

    /**
     * Resolve and cache the translator for this request.
     *
     * @since 1.0.0
     *
     * @return TranslatorContract The resolved translator.
     */
    protected function translator(): TranslatorContract {
        if ($this->translator === null) {
            $this->translator = $this->make(TranslatorContract::class);
        }

        return $this->translator;
    }

    /**
     * Translate a string using the resolved translator.
     *
     * @since 1.0.0
     *
     * @param string                $text         Source text to translate.
     * @param array<string, mixed>  $replacements Named placeholder replacements.
     * @return string The translated string.
     */
    protected function t(string $text, array $replacements = []): string {
        return $this->translator()->translate($text, $replacements);
    }
}
