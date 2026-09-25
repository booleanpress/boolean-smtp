<?php
/**
 * Outcome of a supervised send.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer;

/**
 * What {@see SupervisedSend::run()} observed while one send ran.
 *
 * @since 1.0.0
 */
final class SupervisedSendResult {
    /**
     * @since 1.0.0
     *
     * @param bool                      $sent           Whether the send callable returned true.
     * @param string                    $failureMessage Last `wp_mail_failed` message, empty when none fired.
     * @param array<string, mixed>|null $resolvedMailer `boolean_smtp_mailer_resolved` payload, when captured.
     * @param array<string, mixed>|null $apiDebug       `boolean_smtp_api_debug` payload, when captured.
     * @param int                       $elapsedMs      Wall-clock duration of the send, in milliseconds.
     * @param \Throwable|null           $exception      Exception thrown by the send callable, if any.
     */
    public function __construct(
        public readonly bool $sent,
        public readonly string $failureMessage,
        public readonly ?array $resolvedMailer,
        public readonly ?array $apiDebug,
        public readonly int $elapsedMs,
        public readonly ?\Throwable $exception,
    ) {}

    /**
     * The most specific failure description available.
     *
     * @since 1.0.0
     *
     * @param string $fallback Used when neither `wp_mail_failed` nor an exception said why.
     * @return string
     */
    public function failureDetail(string $fallback = ''): string {
        if ($this->failureMessage !== '') {
            return $this->failureMessage;
        }

        if ($this->exception !== null && $this->exception->getMessage() !== '') {
            return $this->exception->getMessage();
        }

        return $fallback;
    }
}
