<?php

/**
 * Captures PHPMailer SMTP debug transcripts and persists them for troubleshooting.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer;

use BooleanSmtp\Repositories\DebugLogRepository;

/**
 * Captures the SMTP conversation PHPMailer reports through its `Debugoutput` callback while a
 * message is sent and stores it as a debug session file for that email log entry
 * (see {@see DebugLogRepository}). Credentials never reach the file: PHPMailer hides the
 * LOGIN/PLAIN exchange below its lowest debug level only, so every client line of an `AUTH`
 * exchange — including the XOAUTH2 token PHPMailer prints at any level — is replaced with
 * `[credentials hidden]` as it is captured.
 *
 * @since 1.0.0
 */
final class SmtpDebugger {
    /**
     * Collected debug lines during current send lifecycle.
     *
     * @since 1.0.0
     * @var array<int, array{timestamp: float, level: int, message: string}>
     */
    private array $capturedLines = [];

    /**
     * Connection ID (for context) being debugged.
     *
     * @since 1.0.0
     * @var int|null
     */
    private ?int $connectionId = null;

    /**
     * Email log ID to associate debug output with a specific send attempt.
     *
     * @since 1.0.0
     * @var int|null
     */
    private ?int $emailLogId = null;

    /**
     * Debug level being captured (0=off, 1-4=increasing verbosity).
     *
     * @since 1.0.0
     * @var int
     */
    private int $debugLevel = 0;

    /**
     * True between the client's `AUTH` command and the server's final reply to it.
     *
     * @since 1.0.0
     * @var bool
     */
    private bool $inAuthExchange = false;

    /**
     * Whether the captured transcript is written to a debug session file when the send ends.
     * The capture itself always runs while a debug level is set, so PHPMailer's output never
     * reaches the response; only the storage is a developer decision.
     *
     * @since 1.0.0
     * @var bool
     */
    private bool $store = true;

    /**
     * @since 1.0.0
     *
     * @param DebugLogRepository $debugLogs Stores the captured lines against an email log entry.
     */
    public function __construct(
        private readonly DebugLogRepository $debugLogs,
    ) {}

    /**
     * Start capturing SMTP debug output from PHPMailer instance.
     * PHPMailer will invoke the callback for each debug line.
     *
     * @since 1.0.0
     *
     * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer    Mailer instance to attach the debug callback to.
     * @param int $debugLevel Debug verbosity level (1-4)
     * @param ?int $connectionId Connection ID for context
     * @param ?int $emailLogId Email log ID to link debug output
     * @param bool $store Whether the transcript is written to a debug session file when the send ends.
     * @return void
     */
    public function startCapture(
        \PHPMailer\PHPMailer\PHPMailer $phpmailer,
        int $debugLevel = 2,
        ?int $connectionId = null,
        ?int $emailLogId = null,
        bool $store = true
    ): void {
        if ($debugLevel <= 0) {
            return; // No debug capture needed
        }

        $this->debugLevel     = min($debugLevel, 4); // Cap at 4
        $this->connectionId   = $connectionId;
        $this->emailLogId     = $emailLogId;
        $this->capturedLines  = [];
        $this->inAuthExchange = false;
        $this->store          = $store;

        // Set PHPMailer debug level
        $phpmailer->SMTPDebug = $this->debugLevel;

        // Set callback to capture debug output
        $phpmailer->Debugoutput = function (string $str, int $level): void {
            $this->capturedLines[] = [
                'timestamp' => microtime(true),
                'level'     => $level,
                'message'   => $this->hideCredentials(trim($str)),
            ];
        };
    }

    /**
     * Replace the client side of an SMTP `AUTH` exchange with `[credentials hidden]`.
     *
     * PHPMailer prints `CLIENT -> SERVER: …` for every command it sends. From the `AUTH`
     * command (whose inline argument — the PLAIN initial response or the XOAUTH2 token — is
     * masked too) until the server answers with anything but a `334` continuation, every
     * client line is a credential and is hidden. Server lines are kept.
     *
     * @since 1.0.0
     *
     * @param  string $line One debug line as PHPMailer reported it.
     * @return string The line, with credentials replaced.
     */
    public function hideCredentials(string $line): string {
        if (preg_match('/^(CLIENT -> SERVER: AUTH\s+[A-Z0-9-]+)(\s+\S.*)?$/i', $line, $m) === 1) {
            $this->inAuthExchange = true;

            return isset($m[2]) && $m[2] !== '' ? $m[1] . ' [credentials hidden]' : $line;
        }

        if (!$this->inAuthExchange) {
            return $line;
        }

        if (preg_match('/^SERVER -> CLIENT: (\d{3})/', $line, $m) === 1) {
            if ($m[1] !== '334') {
                $this->inAuthExchange = false;
            }

            return $line;
        }

        if (str_starts_with($line, 'CLIENT -> SERVER: ')) {
            return 'CLIENT -> SERVER: [credentials hidden]';
        }

        return $line;
    }

    /**
     * Stop capturing and store the collected lines as the entry's debug session file.
     * Safe to call multiple times; only persists if lines were captured and storage was asked
     * for when the capture started — otherwise the lines are discarded.
     *
     * @since 1.0.0
     *
     * @param  int|null $emailLogId The log entry the transcript belongs to, when the capture started before
     *                              the entry existed (the SMTP path logs after PHPMailer is configured).
     * @return int Count of debug log entries created
     */
    public function stopCaptureAndPersist(?int $emailLogId = null): int {
        if ($emailLogId !== null && $emailLogId > 0) {
            $this->emailLogId = $emailLogId;
        }

        if (empty($this->capturedLines) || $this->debugLevel === 0) {
            return 0;
        }

        if (!$this->store) {
            $this->capturedLines  = [];
            $this->connectionId   = null;
            $this->emailLogId     = null;
            $this->debugLevel     = 0;
            $this->inAuthExchange = false;
            $this->store          = true;

            return 0;
        }

        $batchLines = [];
        foreach ($this->capturedLines as $line) {
            if (empty($line['message'])) {
                continue;
            }

            $batchLines[] = [
                'level'     => $line['level'] ?? $this->debugLevel,
                'message'   => $line['message'],
                'context'   => [
                    'timestamp'  => $line['timestamp'] ?? microtime(true),
                    'debug_mode' => $this->debugLevel,
                ],
                'created_at' => gmdate('Y-m-d H:i:s'),
            ];
        }

        if (empty($batchLines)) {
            return 0;
        }

        $emailLogId   = $this->emailLogId ?? 0;
        $connectionId = $this->connectionId;

        $this->capturedLines  = [];
        $this->connectionId   = null;
        $this->emailLogId     = null;
        $this->debugLevel     = 0;
        $this->inAuthExchange = false;

        if ($emailLogId <= 0) {
            return 0;
        }

        $ok = $this->debugLogs->store($emailLogId, $batchLines, $connectionId);

        return $ok ? count($batchLines) : 0;
    }

    /**
     * Get all captured lines without persisting (useful for testing/previews).
     *
     * @since 1.0.0
     *
     * @return array<array{timestamp: float, level: int, message: string}>
     */
    public function getCapturedLines(): array {
        return $this->capturedLines;
    }

    /**
     * Clear captured lines without persisting.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function clearCapture(): void {
        $this->capturedLines  = [];
        $this->connectionId   = null;
        $this->emailLogId     = null;
        $this->debugLevel     = 0;
        $this->inAuthExchange = false;
    }
}
