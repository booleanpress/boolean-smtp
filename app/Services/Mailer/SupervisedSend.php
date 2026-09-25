<?php
/**
 * Runs one user-initiated send under supervision.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer;

use BooleanSmtp\Adapters\Contracts\HookAdapterContract;

/**
 * Runs a single send (a test email, a resend, a connection test) with the automatic fallback
 * retry disabled and the pipeline's diagnostics captured, then removes every listener it added.
 *
 * A user-initiated send tests one specific connection: letting {@see MailFailureHandler} fall
 * back to another connection would misreport the result and log an unrelated attempt. The
 * failure reason is taken from `wp_mail_failed`, the only signal API-mode transports emit.
 *
 * @since 1.0.0
 */
final class SupervisedSend {
    /**
     * Priority of the fallback-skipping filter (after any site filter).
     *
     * @since 1.0.0
     * @var int
     */
    private const SKIP_FALLBACK_PRIORITY = 999;

    /**
     * Priority of the diagnostics captures (after every other listener).
     *
     * @since 1.0.0
     * @var int
     */
    private const CAPTURE_PRIORITY = 9999;

    /**
     * @since 1.0.0
     *
     * @param HookAdapterContract $hooks Registers and removes the temporary listeners.
     */
    public function __construct(
        private readonly HookAdapterContract $hooks,
    ) {}

    /**
     * Run the send.
     *
     * @since 1.0.0
     *
     * @param  callable(): bool $send                Performs the send and returns whether it succeeded.
     * @param  bool             $captureDiagnostics  Also capture the resolved-mailer and API debug payloads.
     * @return SupervisedSendResult
     */
    public function run(callable $send, bool $captureDiagnostics = false): SupervisedSendResult {
        $resolvedMailer = null;
        $apiDebug       = null;
        $failureMessage = '';
        $exception      = null;
        $sent           = false;

        // Not `static`: Brain Monkey's hook tracking fingerprints callbacks via Closure::bind(),
        // which PHP deprecates for closures declared `static`.
        $skipFallbackRetry     = function (): bool {
            return true;
        };
        $captureResolvedMailer = function (array $payload) use (&$resolvedMailer): void {
            $resolvedMailer = $payload;
        };
        $captureApiDebug       = function (array $payload) use (&$apiDebug): void {
            $apiDebug = $payload;
        };
        $captureFailure        = function (\WP_Error $error) use (&$failureMessage): void {
            $failureMessage = $error->get_error_message();
        };

        $this->hooks->listen('boolean_smtp_skip_fallback_retry', $skipFallbackRetry, self::SKIP_FALLBACK_PRIORITY, 2);
        $this->hooks->listen('wp_mail_failed', $captureFailure, 1, 1);
        if ($captureDiagnostics) {
            $this->hooks->listen('boolean_smtp_mailer_resolved', $captureResolvedMailer, self::CAPTURE_PRIORITY, 1);
            $this->hooks->listen('boolean_smtp_api_debug', $captureApiDebug, self::CAPTURE_PRIORITY, 1);
        }

        $startedAt = microtime(true);

        try {
            $sent = (bool) $send();
        } catch (\Throwable $e) {
            $exception = $e;
        } finally {
            $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

            $this->hooks->removeListener('boolean_smtp_skip_fallback_retry', $skipFallbackRetry, self::SKIP_FALLBACK_PRIORITY);
            $this->hooks->removeListener('wp_mail_failed', $captureFailure, 1);
            if ($captureDiagnostics) {
                $this->hooks->removeListener('boolean_smtp_mailer_resolved', $captureResolvedMailer, self::CAPTURE_PRIORITY);
                $this->hooks->removeListener('boolean_smtp_api_debug', $captureApiDebug, self::CAPTURE_PRIORITY);
            }
        }

        return new SupervisedSendResult($sent, trim($failureMessage), $resolvedMailer, $apiDebug, $elapsedMs, $exception);
    }
}
