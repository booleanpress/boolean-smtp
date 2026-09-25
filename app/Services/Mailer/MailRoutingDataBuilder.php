<?php

/**
 * Extracts the normalized recipient, sender, and subject fields used by routing rules.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer;

use BooleanSmtp\Support\Settings;

/**
 * Normalized fields for smart routing rules and sender-based connection matching.
 *
 * @since 1.0.0
 */
final class MailRoutingDataBuilder
{
    /**
     * Build routing fields from a populated PHPMailer instance.
     *
     * @since 1.0.0
     *
     * @param  \PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer instance already populated with recipients and headers.
     * @return array{to: string, subject: string, from: string, content_type: ?string}
     */
    public static function fromPhpmailer(\PHPMailer\PHPMailer\PHPMailer $phpmailer): array
    {
        $to = $phpmailer->getToAddresses()[0][0] ?? '';
        $from = self::normalizeAddress((string) $phpmailer->From);
        $contentType = trim((string) ($phpmailer->ContentType ?? ''));

        return [
            'to'            => $to,
            'subject'       => (string) $phpmailer->Subject,
            'from'          => $from,
            'content_type'  => $contentType !== '' ? $contentType : null,
        ];
    }

    /**
     * Same shape as {@see fromPhpmailer()} for API pre_wp_mail resolution (before PHPMailer is fully built).
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $atts     wp_mail arguments
     * @param  Settings   $settings Settings source for the default from address when headers omit one.
     * @return array{to: string, subject: string, from: string, content_type: ?string}
     */
    public static function fromWpMailAtts(array $atts, Settings $settings): array
    {
        $to = $atts['to'] ?? '';
        if (\is_array($to)) {
            $first = (string) ($to[0] ?? '');
        } else {
            $parts = explode(',', (string) $to);
            $first = trim($parts[0] ?? '');
        }

        $headers = $atts['headers'] ?? '';
        $from    = self::parseFromFromHeaders($headers);
        if ($from === '') {
            $from = self::normalizeAddress((string) $settings->get('from_email', ''));
        }

        $contentType = self::parseContentTypeFromHeaders($headers);

        return [
            'to'           => $first,
            'subject'      => (string) ($atts['subject'] ?? ''),
            'from'         => $from,
            'content_type' => $contentType,
        ];
    }

    /**
     * Lowercase address for comparisons; supports `Name <user@host>`.
     *
     * @since 1.0.0
     *
     * @param  string $raw Raw address, with or without a display name.
     * @return string
     */
    public static function normalizeAddress(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (preg_match('/<\s*([^>]+)\s*>/', $raw, $m)) {
            $raw = trim($m[1]);
        }

        $raw = trim($raw, "\"'");

        return strtolower($raw);
    }

    /**
     * Extract and normalize the From address from raw header lines.
     *
     * @since 1.0.0
     *
     * @param  array<int|string, mixed>|string $headers wp_mail-style headers.
     * @return string Normalized From address, or an empty string when none is present.
     */
    private static function parseFromFromHeaders(mixed $headers): string
    {
        foreach (self::headerLines($headers) as $line) {
            if (preg_match('/^From:\s*(.+)$/i', $line, $m)) {
                return self::normalizeAddress(trim($m[1]));
            }
        }

        return '';
    }

    /**
     * Extract the Content-Type value from raw header lines.
     *
     * @since 1.0.0
     *
     * @param  array<int|string, mixed>|string $headers wp_mail-style headers.
     * @return string|null Content-Type value, or null when none is present.
     */
    private static function parseContentTypeFromHeaders(mixed $headers): ?string
    {
        foreach (self::headerLines($headers) as $line) {
            if (preg_match('/^Content-Type:\s*(.+)$/i', $line, $m)) {
                $v = trim($m[1]);

                return $v !== '' ? $v : null;
            }
        }

        return null;
    }

    /**
     * Normalize wp_mail-style headers (string or array) into a flat list of "Name: value" lines.
     *
     * @since 1.0.0
     *
     * @param  array<int|string, mixed>|string $headers wp_mail-style headers.
     * @return list<string>
     */
    private static function headerLines(mixed $headers): array
    {
        if ($headers === '' || $headers === null) {
            return [];
        }

        $lines = [];
        if (\is_array($headers)) {
            foreach ($headers as $key => $value) {
                if (\is_int($key)) {
                    $lines[] = trim((string) $value);
                } else {
                    $lines[] = trim((string) $key) . ': ' . trim((string) $value);
                }
            }
        } else {
            $split = preg_split('/\r\n|\n|\r/', (string) $headers) ?: [];
            foreach ($split as $line) {
                $lines[] = trim((string) $line);
            }
        }

        return $lines;
    }
}
