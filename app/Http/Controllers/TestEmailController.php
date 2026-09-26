<?php

/**
 * REST controller for the Test Email utility on the dashboard.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Http\Controllers;

use BooleanSmtp\Core\Http\Controller;
use BooleanSmtp\Core\Http\JsonResponse;
use BooleanSmtp\Core\Http\Request;
use BooleanSmtp\Http\Requests\SendTestEmailRequest;
use BooleanSmtp\Repositories\ConnectionRepository;
use BooleanSmtp\Support\Settings;
use BooleanSmtp\Services\Connection\ConnectionHealthProbe;
use BooleanSmtp\Services\Debug\SmtpActivityCapture;
use BooleanSmtp\Services\Mailer\MailerManager;
use BooleanSmtp\Services\Mailer\SupervisedSend;
use BooleanSmtp\Services\Mailer\TestEmailRenderer;
use BooleanSmtp\Services\Onboarding\OnboardingState;
use BooleanSmtp\Support\Debug\ApiDebugResponse;

/**
 * Sends a real test email through `wp_mail()` and reports how it was delivered.
 *
 * @since 1.0.0
 */
class TestEmailController extends Controller {
    /**
     * Handle `POST /booleansmtp/v1/test-email`.
     *
     * Reads `to` (required), `subject`, `html`, `multipart`, and an optional `connection_id`
     * override, sends a real test message through `wp_mail()`, records the outcome on the
     * resolved connection's health status, and returns delivery details. When the "Test Email
     * Activity Console" setting is on, also captures a step-by-step send log and, for developers
     * with the API-debug filter enabled, the raw provider request/response. An inactive connection
     * is accepted only when it is the saved onboarding draft and the request marks that test.
     *
     * @since 1.0.0
     *
     * @param SendTestEmailRequest $request Request carrying `to`, `subject`, `html`, `multipart`, an
     *                          optional `connection_id`, and an onboarding-draft test marker.
     * @return JsonResponse `sent`, `to`, `subject`, `delivery_time_ms`, `requested_connection_id`,
     *                       `used_connection_id`, `error` (null when sent; safe Graph status and code
     *                       on failure, or raw failure text while diagnostic mode is on), and (when enabled) `debug_log`,
     *                       `resolved_mailer`, and `api_debug`.
     */
    public function send(SendTestEmailRequest $request): JsonResponse {
        $to                = (string) $request->get('to');
        $subject           = (string) $request->get('subject', 'Test Email - BooleanSMTP Check');
        $isHtml            = (bool) $request->get('html', true);
        $multipartOverride = $request->has('multipart') ? (bool) $request->get('multipart') : null;
        $requestedConnectionId = (int) $request->get('connection_id', 0);

        if ($requestedConnectionId > 0) {
            $connection = $this->make(ConnectionRepository::class)->find($requestedConnectionId);
            $draftId    = $this->make(OnboardingState::class)->all()['draft_connection_id'];
            $isOnboardingDraft = filter_var($request->get('onboarding_draft', false), FILTER_VALIDATE_BOOLEAN)
                && $draftId === $requestedConnectionId;

            if (!$connection || (!(bool) $connection->is_active && !$isOnboardingDraft)) {
                return $this->validationError([
                    'connection_id' => ['Select an active mailer. An inactive onboarding draft can only be tested from setup.']
                ]);
            }
        }

        $rendered = $this->make(TestEmailRenderer::class)->render(
            $requestedConnectionId > 0 ? $requestedConnectionId : null
        );
        $body = $isHtml ? $rendered['html'] : $rendered['plain'];

        $headers = $isHtml ? ['Content-Type: text/html; charset=UTF-8'] : [];

        $apiDebugEnabled    = ApiDebugResponse::enabled();
        $settingsRepo       = $this->make(Settings::class);
        $showActivityConsole = (bool) $settingsRepo->get('show_test_email_console');
        $debugger           = $showActivityConsole ? $this->make(SmtpActivityCapture::class) : null;
        if ($debugger !== null) {
            $debugger->startCapture();
        }

        $mailer                = $this->make(MailerManager::class);
        if ($requestedConnectionId > 0) {
            // Force connection via MailerManager (not via wp_mail filter).
            // This approach is framework-agnostic and works in both WordPress and Laravel contexts.
            // The forced connection is respected through the priority system in MailerManager->pickConnectionFromHints().
            $mailer->forceConnectionForNextSend($requestedConnectionId);
        }
        if ($isHtml && $multipartOverride !== null) {
            // Test Email Utility's "Include plain-text alternative" toggle overrides the site-wide
            // auto_plain_text setting for this one send, so the tool always reflects what was chosen.
            $mailer->forceAutoPlainTextForNextSend($multipartOverride);
        }

        // One user-initiated send: fallback retry off, diagnostics captured, listeners removed after.
        $startTime = microtime(true);
        $outcome   = $this->make(SupervisedSend::class)->run(
            static fn (): bool => (bool) wp_mail($to, $subject, $body, $headers),
            true
        );
        $debugLog  = $debugger !== null ? $debugger->stopCapture() : [];

        if ($outcome->exception !== null) {
            throw $outcome->exception;
        }

        $sent                    = $outcome->sent;
        $elapsed                 = $outcome->elapsedMs;
        $resolvedMailer          = $outcome->resolvedMailer;
        $capturedApiDebug        = $outcome->apiDebug;
        $lastWpMailFailedMessage = $outcome->failureMessage;
        $safeProviderFailure     = $sent ? null : self::safeGraphFailure($capturedApiDebug);


        // Inline "curl -v"-style raw HTTP details into Activity Console.
        // This panel is driven by `debug_log`, so we append a synthetic entry
        // derived from api_debug captured during the send attempt.
        if ($showActivityConsole && $apiDebugEnabled && \is_array($capturedApiDebug) && !empty($capturedApiDebug)) {
            $debugLog[] = [
                'timestamp'  => gmdate('H:i:s') . '.' . sprintf('%03d', (int) (fmod(microtime(true), 1) * 1000)),
                'elapsed_ms' => round((microtime(true) - $startTime) * 1000, 1),
                'level'      => 'debug',
                'message'    => self::formatCurlVLikeDebug($capturedApiDebug)
            ];

            if (isset($capturedApiDebug['token_check']) && \is_array($capturedApiDebug['token_check'])) {
                $tc         = $capturedApiDebug['token_check'];
                $debugLog[] = [
                    'timestamp'  => gmdate('H:i:s') . '.' . sprintf('%03d', (int) (fmod(microtime(true), 1) * 1000)),
                    'elapsed_ms' => round((microtime(true) - $startTime) * 1000, 1),
                    'level'      => 'debug',
                    'message'    => self::formatCurlVLikeDebug($tc)
                ];
            }
        }

        $resolvedConnectionId = is_array($resolvedMailer) ? (int) ($resolvedMailer['connection_id'] ?? 0) : 0;
        $usedConnectionId     = $resolvedConnectionId > 0 ? $resolvedConnectionId : $mailer->getLastConfiguredConnectionId();
        if (!$settingsRepo->get('simulation_enabled') && $usedConnectionId !== null) {
            $connections = $this->make(ConnectionRepository::class);
            if ($sent) {
                $connections->updateHealthStatus($usedConnectionId, 'healthy');
            } else {
                $connections->updateHealthStatus(
                    $usedConnectionId,
                    'error',
                    self::wpMailFailureMessage($lastWpMailFailedMessage)
                );
            }
        }

        /**
         * Fires after the admin test-email tool has attempted a send.
         *
         * Fires whether the send succeeded or failed; a simulated send (simulation mode on)
         * reports `success` true with the simulation as its provider.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $result {
         *     @type string      $to            Recipient address.
         *     @type string      $subject       Subject line.
         *     @type int|null    $connection_id Id of the connection used, `null` when none was resolved.
         *     @type bool        $success       Whether the message was accepted for delivery.
         *     @type string|null $error         Failure message, `null` on success.
         *     @type int         $elapsed_ms    Time the attempt took, in milliseconds.
         * }
         */
        \do_action('boolean_smtp_test_email_sent', [
            'to'            => (string) $to,
            'subject'       => (string) $subject,
            'connection_id' => $usedConnectionId !== null ? (int) $usedConnectionId : null,
            'success'       => $sent,
            'error'         => $sent ? null : self::wpMailFailureMessage($lastWpMailFailedMessage),
            'elapsed_ms'    => (int) $elapsed,
        ]);

        if ($sent) {
            // Any accepted test — from the wizard, this tool or the CLI — completes the
            // dashboard checklist's "send a test email" item.
            $this->make(OnboardingState::class)->update(['send_test' => true]);
        }

        $response = [
            'sent'                    => $sent,
            'to'                      => $to,
            'subject'                 => $subject,
            'delivery_time_ms'        => $elapsed,
            'requested_connection_id' => $requestedConnectionId > 0 ? $requestedConnectionId : null,
            'used_connection_id'      => $usedConnectionId,
            // Provider response bodies stay behind the diagnostic channel. Otherwise expose
            // only the bounded Graph status and code captured for this test attempt.
            'error'                   => $sent
                ? null
                : ($apiDebugEnabled ? self::wpMailFailureMessage($lastWpMailFailedMessage) : ($safeProviderFailure ?? 'The test email was not accepted. Check the connection settings and try again.')),
        ];

        if ($showActivityConsole) {
            $response['debug_log'] = $debugLog;
        }

        if ($apiDebugEnabled) {
            $response['resolved_mailer'] = $resolvedMailer;

            $apiDebugConnectionId = $requestedConnectionId > 0 ? $requestedConnectionId : $usedConnectionId;
            if (\is_array($capturedApiDebug) && !empty($capturedApiDebug)) {
                $response['api_debug'] = $capturedApiDebug;
            } elseif ($apiDebugConnectionId !== null) {
                $connections = $this->make(ConnectionRepository::class);
                $connection  = $connections->find((int) $apiDebugConnectionId);
                if ($connection) {
                    $rawSettings       = $connection->settings ?? [];
                    $decryptedSettings = \is_array($rawSettings) ? $this->make(\BooleanSmtp\Contracts\EncryptorContract::class)->decryptArray($rawSettings) : [];
                    $probe             = $this->make(ConnectionHealthProbe::class)->probe($connection, $decryptedSettings, false);
                    if (isset($probe['api_debug']) && \is_array($probe['api_debug'])) {
                        $response['api_debug'] = $probe['api_debug'];
                    }
                }
            }

            ApiDebugResponse::extend($response);
        }

        return $this->ok($response, $sent ? 'Test email sent successfully.' : 'Test email failed to send.');
    }

    /**
     * Describe Graph's failed send stages without exposing provider bodies or request data.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed>|null $debug Captured provider diagnostic payload.
     * @return string|null Safe Graph failure description, or null when unavailable.
     */
    private static function safeGraphFailure(?array $debug): ?string {
        if ($debug === null || ($debug['provider'] ?? null) !== 'outlook') {
            return null;
        }

        $stages = [];
        if (isset($debug['initial_json_error']) && \is_array($debug['initial_json_error'])) {
            $initial = self::safeGraphAttempt($debug['initial_json_error'], 'JSON');
            if ($initial !== null) {
                $stages[] = $initial;
            }
        }

        $final = self::safeGraphAttempt($debug, $stages !== [] ? 'MIME retry' : 'send');
        if ($final !== null) {
            $stages[] = $final;
        }

        return $stages !== []
            ? 'Microsoft Graph test send failed (' . implode('; ', $stages) . '). Review the reported send details.'
            : null;
    }

    /**
     * Format one Graph attempt using only a bounded HTTP status and error identifier.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $attempt Provider attempt metadata.
     * @param string $label Stage label for the message.
     * @return string|null Safe stage description, or null when no safe detail exists.
     */
    private static function safeGraphAttempt(array $attempt, string $label): ?string {
        $status = (int) ($attempt['status'] ?? 0);
        $rawCode = $attempt['provider_error_code'] ?? '';
        $code = \is_string($rawCode) ? $rawCode : '';
        $parts = [];
        if ($status >= 100 && $status <= 599) {
            $parts[] = 'HTTP ' . $status;
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,79}$/', $code) === 1) {
            $parts[] = $code;
        }

        return $parts !== [] ? $label . ': ' . implode(' ', $parts) : null;
    }

    /**
     * Resolve the best available failure message for a failed test send.
     *
     * Prefers the message captured from the `wp_mail_failed` action, then falls back to
     * PHPMailer's `ErrorInfo` global, then a generic message.
     *
     * @since 1.0.0
     *
     * @param string $wpMailFailedFromAction Message from {@see wp_mail_failed} when present (e.g. API mail errors).
     * @return string The resolved failure message.
     */
    private static function wpMailFailureMessage(string $wpMailFailedFromAction = ''): string {
        $fromAction = trim($wpMailFailedFromAction);
        if ($fromAction !== '') {
            return $fromAction;
        }

        if (isset($GLOBALS['phpmailer']) && \is_object($GLOBALS['phpmailer']) && isset($GLOBALS['phpmailer']->ErrorInfo)) {
            $err = trim((string) $GLOBALS['phpmailer']->ErrorInfo);
            if ($err !== '') {
                return $err;
            }
        }

        return 'wp_mail returned false.';
    }

    /**
     * Format an API sender's debug data as a `curl -v`-style transcript for the Activity Console.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $apiDebug Debug structure returned by an API-mode transport
     *                                        (request URL, method, headers, body preview, status,
     *                                        provider response headers/body).
     * @return string The formatted transcript.
     */
    private static function formatCurlVLikeDebug(array $apiDebug): string {
        $method = isset($apiDebug['request_method']) ? (string) $apiDebug['request_method'] : 'POST';
        $url    = isset($apiDebug['request_url']) ? (string) $apiDebug['request_url'] : '';
        $status = (string) ($apiDebug['status'] ?? $apiDebug['http_status'] ?? '');

        $requestHeaders = isset($apiDebug['request_headers']) && \is_array($apiDebug['request_headers'])
        ? $apiDebug['request_headers']
        : [];

        $bodyLen     = isset($apiDebug['request_body_base64_length']) ? (string) $apiDebug['request_body_base64_length'] : null;
        $bodyPreview = isset($apiDebug['request_body_base64_preview']) ? (string) $apiDebug['request_body_base64_preview'] : null;

        $providerHeaders = isset($apiDebug['provider_headers']) && \is_array($apiDebug['provider_headers'])
        ? $apiDebug['provider_headers']
        : [];

        $lines   = [];
        $lines[] = '* curl -v';
        if ($url !== '') {
            $lines[] = '> ' . $method . ' ' . $url;
        } else {
            $lines[] = '> ' . $method . ' [url n/a]';
        }

        foreach ($requestHeaders as $k => $v) {
            $lines[] = '> ' . (string) $k . ': ' . (string) $v;
        }

        if ($bodyLen !== null) {
            $lines[] = '> Body(base64) length: ' . $bodyLen;
        }
        if ($bodyPreview !== null && $bodyPreview !== '') {
            $lines[] = '> Body(base64) preview: ' . $bodyPreview;
        }

        $reqPreview = isset($apiDebug['request_body_preview']) && \is_string($apiDebug['request_body_preview'])
        ? $apiDebug['request_body_preview']
        : '';
        if ($reqPreview !== '') {
            $rbLen   = isset($apiDebug['request_body_length']) ? (string) $apiDebug['request_body_length'] : '';
            $lines[] = $rbLen !== ''
            ? '> Request body (preview, ' . $rbLen . ' bytes total):'
            : '> Request body (preview):';
            $lines[] = $reqPreview;
        }

        $statusLine = $status !== ''
        ? ('< HTTP/1.1 ' . $status)
        : '< HTTP/1.1 [status n/a]';
        $lines[] = $statusLine;

        foreach ($providerHeaders as $k => $v) {
            $lines[] = '< ' . (string) $k . ': ' . (string) $v;
        }

        // Provider body is often empty for 401; include if present.
        if (isset($apiDebug['provider_body']) && is_string($apiDebug['provider_body']) && trim($apiDebug['provider_body']) !== '') {
            $lines[] = '';
            $lines[] = '< Body: ' . $apiDebug['provider_body'];
        }

        return implode("\n", $lines);
    }

}
