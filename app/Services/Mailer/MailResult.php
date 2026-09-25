<?php

/**
 * Outcome of a single mail send attempt.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer;

/**
 * Outcome of {@see BooleanSmtpMailPipeline::send()}.
 *
 * @since 1.0.0
 */
final class MailResult
{
    /**
     * Create a result.
     *
     * @since 1.0.0
     *
     * @param bool        $success      True when the transport accepted the message for delivery.
     * @param string|null $errorMessage Reason the send failed; null when $success is true.
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $errorMessage = null,
    ) {
    }

    /**
     * Build a successful result.
     *
     * @since 1.0.0
     *
     * @return self
     */
    public static function ok(): self
    {
        return new self(true);
    }

    /**
     * Build a failed result carrying the reason for the failure.
     *
     * @since 1.0.0
     *
     * @param  string $message Reason the send failed.
     * @return self
     */
    public static function fail(string $message): self
    {
        return new self(false, $message);
    }
}
