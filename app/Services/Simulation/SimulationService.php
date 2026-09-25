<?php

/**
 * Captures outgoing email as a log entry instead of sending it, for Email Simulation Mode.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Simulation;

use BooleanSmtp\Models\EmailLog;
use BooleanSmtp\Repositories\EmailLogRepository;
use function BooleanSmtp\Core\config;

/**
 * Email Simulation Mode: records outgoing messages to the email log without delivering them.
 *
 * Used to let a site test its mail-sending flows without contacting a real provider.
 *
 * @since 1.0.0
 */
class SimulationService
{
    /**
     * @since 1.0.0
     *
     * @param EmailLogRepository $logs Repository used to persist simulated sends.
     */
    public function __construct(
        protected EmailLogRepository $logs
    ) {}

    /**
     * Determine whether Email Simulation Mode is enabled.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return (bool) config('mail.simulation.enabled', false);
    }

    /**
     * Record a simulated send as an email log entry instead of delivering it.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $emailData Message data in the mail pipeline's internal shape.
     * @return array<string, mixed> The created email log entry.
     */
    public function capture(array $emailData): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $log = $this->logs->create([
            'to'                => $emailData['to'] ?? '',
            'cc'                => $emailData['cc'] ?? null,
            'bcc'               => $emailData['bcc'] ?? null,
            'from_email'        => $emailData['from_email'] ?? '',
            'from_name'         => $emailData['from_name'] ?? '',
            'subject'           => $emailData['subject'] ?? '',
            'body'              => $emailData['body'] ?? '',
            'headers'           => $emailData['headers'] ?? [],
            'status'            => 'simulated',
            'provider'          => 'simulation',
            'last_attempted_at' => $now,
        ]);

        $this->announce($log);

        return $log->toArray();
    }

    /**
     * Mark an existing log entry (a queued message) as simulated instead of sending it.
     *
     * @since 1.0.0
     *
     * @param  EmailLog $log The entry to mark; its status becomes `simulated`.
     * @return EmailLog The refreshed entry.
     */
    public function markCaptured(EmailLog $log): EmailLog
    {
        $log->update([
            'status'            => 'simulated',
            'provider'          => 'simulation',
            'reserved_at'       => null,
            'reserved_by'       => null,
            'last_attempted_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $log->refresh();

        $this->announce($log);

        return $log;
    }

    /**
     * Fire the capture event for a simulated entry. The one place the hook is fired from, so
     * every listener sees the same payload whether the message came from `wp_mail()`, the API
     * pipeline or the queue.
     *
     * @since 1.0.0
     *
     * @param EmailLog $log The simulated entry.
     */
    private function announce(EmailLog $log): void
    {
        $headers = $log->headers;

        /**
         * Fires after a message has been captured by Email Simulation Mode instead of being sent.
         *
         * Fired for every captured message, whether it came from `wp_mail()`, the API-mode
         * pipeline or the queue; the payload is the log entry that recorded it.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $payload {
         *     @type int                   $id          Id of the email log entry.
         *     @type string                $to          Recipient address(es), comma separated.
         *     @type string                $from_email  Sender address.
         *     @type string                $from_name   Sender name.
         *     @type string                $subject     Subject line.
         *     @type string                $body        Message body.
         *     @type array<string, string> $headers     Message headers.
         *     @type string                $captured_at UTC timestamp (`Y-m-d H:i:s`).
         * }
         */
        \do_action('boolean_smtp_simulation_captured', [
            'id'          => (int) $log->id,
            'to'          => (string) $log->to,
            'from_email'  => (string) ($log->from_email ?? ''),
            'from_name'   => (string) ($log->from_name ?? ''),
            'subject'     => (string) $log->subject,
            'body'        => (string) ($log->body ?? ''),
            'headers'     => \is_array($headers) ? $headers : [],
            'captured_at' => (string) ($log->last_attempted_at ?? gmdate('Y-m-d H:i:s')),
        ]);
    }

    /**
     * Get a page of simulated (not actually sent) email log entries.
     *
     * @since 1.0.0
     *
     * @param  int $limit Maximum number of entries to return.
     * @return array<string, mixed> Paginated results in the email log repository's page shape.
     */
    public function getSimulatedEmails(int $limit = 50): array
    {
        return $this->logs->paginate($limit, 1, ['status' => 'simulated']);
    }
}
