<?php

/**
 * Captures a live SMTP transcript for the connection test tool in the admin UI.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Debug;

use BooleanSmtp\Adapters\Contracts\HookAdapterContract;

/**
 * Captures PHPMailer's SMTP conversation and mailer-resolution diagnostics for a single test send.
 *
 * @since 1.0.0
 */
class SmtpActivityCapture
{
    /**
     * Captured log entries for the current capture session, oldest first.
     *
     * @since 1.0.0
     * @var list<array{timestamp: string, elapsed_ms: float, level: string, message: string}>
     */
    private array $log = [];

    /**
     * Whether a capture session is currently active.
     *
     * @since 1.0.0
     * @var bool
     */
    private bool $capturing = false;

    /**
     * Microtime the current capture session started, used to compute elapsed time per entry.
     *
     * @since 1.0.0
     * @var float|null
     */
    private ?float $startTime = null;

    /**
     * @since 1.0.0
     *
     * @param HookAdapterContract $hooks Registers the PHPMailer and mailer-resolution listeners.
     */
    public function __construct(
        private readonly HookAdapterContract $hooks,
    ) {}

    /**
     * Start capturing PHPMailer SMTP debug output and mailer-resolution diagnostics.
     *
     * Resets any previously captured log.
     *
     * @since 1.0.0
     */
    public function startCapture(): void
    {
        $this->log       = [];
        $this->capturing = true;
        $this->startTime = microtime(true);

        $this->hooks->listen('phpmailer_init', [$this, 'attachDebugger'], 1000, 1);
        $this->hooks->listen('boolean_smtp_mailer_resolved', [$this, 'captureMailerResolved'], 1000, 1);
    }

    /**
     * Stop capturing and return the captured log.
     *
     * @since 1.0.0
     *
     * @return list<array{timestamp: string, elapsed_ms: float, level: string, message: string}>
     */
    public function stopCapture(): array
    {
        $this->capturing = false;
        $this->hooks->removeListener('phpmailer_init', [$this, 'attachDebugger'], 1000);
        $this->hooks->removeListener('boolean_smtp_mailer_resolved', [$this, 'captureMailerResolved'], 1000);

        return $this->log;
    }

    /**
     * Attach a debug output handler to a PHPMailer instance for the `phpmailer_init` hook.
     *
     * Does nothing when no capture session is active.
     *
     * @since 1.0.0
     *
     * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer
     */
    public function attachDebugger(\PHPMailer\PHPMailer\PHPMailer $phpmailer): void
    {
        if (!$this->capturing) {
            return;
        }

        $phpmailer->SMTPDebug = 3;
        $phpmailer->Debugoutput = function (string $str, int $level) {
            $elapsed = round((microtime(true) - $this->startTime) * 1000, 1);
            $type    = $this->classifyMessage($str, $level);

            $this->log[] = [
                'timestamp' => gmdate('H:i:s') . '.' . sprintf('%03d', (int)(fmod(microtime(true), 1) * 1000)),
                'elapsed_ms' => $elapsed,
                'level'      => $type,
                'message'    => trim($str),
            ];
        };
    }

    /**
     * Get the log captured so far without ending the capture session.
     *
     * @since 1.0.0
     *
     * @return list<array{timestamp: string, elapsed_ms: float, level: string, message: string}>
     */
    public function getLog(): array
    {
        return $this->log;
    }

    /**
     * Record the mailer-resolution diagnostics fired by the `boolean_smtp_mailer_resolved` hook.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $diagnostics
     */
    public function captureMailerResolved(array $diagnostics): void
    {
        if (!$this->capturing) {
            return;
        }

        $elapsed = round((microtime(true) - $this->startTime) * 1000, 1);
        $encoded = json_encode($diagnostics, JSON_UNESCAPED_SLASHES);
        $this->log[] = [
            'timestamp' => gmdate('H:i:s') . '.' . sprintf('%03d', (int)(fmod(microtime(true), 1) * 1000)),
            'elapsed_ms' => $elapsed,
            'level'      => 'info',
            'message'    => '[Mailer resolved] ' . ($encoded ?: '{}'),
        ];
    }

    /**
     * Classify a PHPMailer debug line into a log severity level.
     *
     * @since 1.0.0
     *
     * @param  string $message Raw PHPMailer debug output line.
     * @param  int    $level   PHPMailer's own debug level for the line.
     * @return string One of `error`, `warn`, `info`, or `debug`.
     */
    private function classifyMessage(string $message, int $level): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'error') || str_contains($lower, 'failed') || $level === 1) {
            return 'error';
        }

        if (str_contains($lower, 'warning') || str_contains($lower, 'latency')) {
            return 'warn';
        }

        if (str_starts_with(trim($message), 'CLIENT -> SERVER') || str_starts_with(trim($message), '>')) {
            return 'info';
        }

        return 'debug';
    }
}
