<?php

/**
 * A provider refused to exchange an OAuth authorization code.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer\OAuth;

/**
 * Raised when the token endpoint answered the exchange with an error of its own.
 *
 * The message is the provider's reason (`error_description`, else `error`), already trimmed and
 * safe to show: a wrong client secret, an expired code or a redirect URI mismatch each read
 * differently, and the person fixing the connection needs to know which it was.
 *
 * @since 1.0.0
 */
final class OAuthExchangeException extends \RuntimeException
{
    /**
     * Build the exception from a token endpoint's error body.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $body Decoded JSON body of the failed token response.
     * @return self|null The exception, or null when the body carries no error of its own.
     */
    public static function fromTokenResponse(array $body): ?self
    {
        $description = trim((string) ($body['error_description'] ?? ''));
        // Microsoft appends request tracing to every description; the reason ends before it.
        $description = trim((string) preg_replace('/\s*(Trace ID|Correlation ID|Timestamp):.*$/s', '', $description));
        $code        = trim((string) ($body['error'] ?? ''));
        if ($description === '' && $code === '') {
            return null;
        }

        $reason = $description !== '' ? $description : $code;
        if ($description !== '' && $code !== '' && !str_contains(strtolower($description), strtolower($code))) {
            $reason = $code . ': ' . $description;
        }

        return new self(mb_substr($reason, 0, 300));
    }
}
