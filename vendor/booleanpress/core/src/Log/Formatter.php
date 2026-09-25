<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Log;

/**
 * Turns one log entry into one line.
 *
 * `[2026-09-23T14:02:11Z] warning: message {"key":"value"}` — UTC ISO 8601 time, the PSR-3 level,
 * the message with `{placeholder}`s filled from the context, the context as JSON. Carriage
 * returns and line feeds in the message are escaped and the JSON encoder escapes them in the
 * context, so an entry can never forge a second one (log injection). Context keys that look like
 * secrets are masked and a `Throwable` is reduced to where it came from.
 */
class Formatter
{
    /**
     * PSR-3 levels, most severe first.
     */
    public const LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    /**
     * Context keys whose values are never written.
     */
    public const SECRET_KEY_PATTERN = '/pass|pwd|secret|token|api[_-]?key|apikey|auth|bearer|credential|signature|private|cookie|session|salt/i';

    /**
     * Credentials written inline in text (`Authorization: Bearer abc`), masked wherever they appear.
     */
    public const INLINE_CREDENTIAL_PATTERN = '/\\b(Bearer|Basic)\\s+[A-Za-z0-9._~+\\/=-]+/i';

    /**
     * What a masked value is written as.
     */
    public const MASK = '********';

    /**
     * Whether an entry at `$level` passes a `$threshold` (both PSR-3 level names).
     */
    public static function passes(string $level, string $threshold): bool
    {
        $rank = array_flip(self::LEVELS);
        $level = strtolower($level);
        $threshold = strtolower($threshold);

        return isset($rank[$level]) && $rank[$level] <= ($rank[$threshold] ?? $rank['warning']);
    }

    /**
     * Format one entry.
     *
     * @param array<string, mixed> $context Already redacted.
     */
    public static function line(string $level, string $message, array $context, int $timestamp): string
    {
        $message = self::escape(self::scrub(self::interpolate($message, $context)));
        $line = '[' . gmdate('Y-m-d\TH:i:s\Z', $timestamp) . '] ' . strtolower($level) . ': ' . $message;

        if ($context !== []) {
            $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if (\is_string($json)) {
                $line .= ' ' . $json;
            }
        }

        return $line . "\n";
    }

    /**
     * Replace `{key}` placeholders with scalar context values (PSR-3 §1.2).
     *
     * @param array<string, mixed> $context
     */
    public static function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }

        $replace = [];
        foreach ($context as $key => $value) {
            if (\is_scalar($value) || $value === null || $value instanceof \Stringable) {
                $replace['{' . $key . '}'] = $value === null ? 'null' : (\is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
            }
        }

        return strtr($message, $replace);
    }

    /**
     * Mask credentials written inline in text.
     */
    public static function scrub(string $text): string
    {
        return (string) preg_replace(self::INLINE_CREDENTIAL_PATTERN, '$1 ' . self::MASK, $text);
    }

    /**
     * Escape carriage returns and line feeds so a message stays on one line.
     */
    public static function escape(string $text): string
    {
        return str_replace(["\r", "\n"], ['\\r', '\\n'], $text);
    }

    /**
     * Mask secret-looking keys, reduce exceptions and objects, recursively.
     *
     * @param array<mixed> $context
     * @return array<mixed>
     */
    public static function redact(array $context, int $depth = 0): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            if (\is_string($key) && preg_match(self::SECRET_KEY_PATTERN, $key)) {
                $out[$key] = self::mask($value);
                continue;
            }
            $out[$key] = self::value($value, $depth);
        }

        return $out;
    }

    /**
     * Everything under a secret-looking key: every value masked, empty ones left visible.
     */
    protected static function mask(mixed $value): mixed
    {
        if ($value === null || $value === '' || \is_bool($value)) {
            return $value;
        }
        if (\is_array($value)) {
            return array_map(static fn (mixed $v): mixed => self::mask($v), $value);
        }

        return self::MASK;
    }

    /**
     * One context value, made safe to encode.
     */
    protected static function value(mixed $value, int $depth): mixed
    {
        if ($value instanceof \Throwable) {
            return [
                'class' => $value::class,
                'message' => $value->getMessage(),
                'file' => $value->getFile() . ':' . $value->getLine(),
            ];
        }
        if (\is_array($value)) {
            return $depth >= 5 ? '[array]' : self::redact($value, $depth + 1);
        }
        if ($value instanceof \JsonSerializable) {
            try {
                $serialised = $value->jsonSerialize();
            } catch (\Throwable) {
                return '[object ' . $value::class . ']';
            }

            return \is_array($serialised) ? ($depth >= 5 ? '[array]' : self::redact($serialised, $depth + 1)) : self::value($serialised, $depth + 1);
        }
        if ($value instanceof \Stringable) {
            try {
                return self::scrub((string) $value);
            } catch (\Throwable) {
                return '[object ' . $value::class . ']';
            }
        }
        if (\is_string($value)) {
            return self::scrub($value);
        }
        if (\is_object($value)) {
            return '[object ' . $value::class . ']';
        }
        if (\is_resource($value)) {
            return '[resource]';
        }

        return $value;
    }
}
