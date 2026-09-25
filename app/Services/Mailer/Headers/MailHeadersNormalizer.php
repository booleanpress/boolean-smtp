<?php

/**
 * Normalizer that cleans up raw header values before they reach a transport.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer\Headers;

/**
 * Normalizes header values (whitespace, strip dangerous control chars for custom headers).
 *
 * @since 1.0.0
 */
final class MailHeadersNormalizer
{
    /**
     * Return a copy of the header bag with whitespace collapsed and control characters removed.
     *
     * @since 1.0.0
     *
     * @param  MailHeaders $headers Header bag to normalize.
     * @return MailHeaders New bag containing only the entries that remain non-empty after cleanup.
     */
    public function normalize(MailHeaders $headers): MailHeaders
    {
        $out = new MailHeaders();
        foreach ($headers->toHeaderLines() as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $name  = trim($name);
            $value = trim($value);
            $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
            $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? $clean);
            if ($name !== '' && $clean !== '') {
                $out->set($name, $clean);
            }
        }

        return $out;
    }
}
