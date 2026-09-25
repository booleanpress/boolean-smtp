<?php
/**
 * WP-CLI command that sends a test email through the configured mail pipeline.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Console\Commands;

use BooleanSmtp\Core\Console\Command;
use BooleanSmtp\Services\Mailer\SupervisedSend;
use BooleanSmtp\Services\Mailer\TestEmailRenderer;

/**
 * `wp boolean-smtp test-email <to>` — send a test message with `wp_mail()` and report the outcome.
 *
 * @since 1.0.0
 */
class TestEmailCommand extends Command {
    /**
     * @since 1.0.0
     * @var string
     */
    protected string $name = 'test-email';

    /**
     * @since 1.0.0
     * @var string
     */
    protected string $description = 'Send a test email through BooleanSMTP.';

    /**
     * @since 1.0.0
     * @var array<array<string, mixed>>
     */
    protected array $synopsis = [
        ['type' => 'positional', 'name' => 'to', 'description' => 'Recipient email address.'],
        ['type' => 'assoc', 'name' => 'subject', 'description' => 'Subject line.', 'optional' => true, 'default' => 'Test Email - BooleanSMTP Check'],
        ['type' => 'flag', 'name' => 'html', 'description' => 'Send an HTML body instead of plain text.', 'optional' => true],
    ];

    /**
     * @since 1.0.0
     *
     * @param SupervisedSend     $send     Runs the send with failure capture.
     * @param TestEmailRenderer $renderer Renders the shared configured test receipt.
     */
    public function __construct(
        private readonly SupervisedSend $send,
        private readonly TestEmailRenderer $renderer
    ) {}

    /**
     * Send the test message and print the result with the elapsed time.
     *
     * @since 1.0.0
     *
     * @param array<int, string>   $args      `[0]` is the recipient address.
     * @param array<string, mixed> $assocArgs `subject` (string) and `html` (flag).
     */
    public function handle(array $args, array $assocArgs): void {
        $to = trim((string) $this->argument($args, 0, ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error('Please provide a valid recipient email address.');

            return;
        }

        $subject = (string) $this->option($assocArgs, 'subject', 'Test Email - BooleanSMTP Check');
        $html    = $this->hasOption($assocArgs, 'html');

        $this->info("Sending test email to {$to}...");

        $rendered = $this->renderer->render();
        $body     = $html ? $rendered['html'] : $rendered['plain'];
        $headers = $html ? ['Content-Type: text/html; charset=UTF-8'] : [];

        $result = $this->send->run(static fn (): bool => (bool) wp_mail($to, $subject, $body, $headers));

        if ($result->sent) {
            $this->success("Test email sent to {$to} ({$result->elapsedMs}ms).");

            return;
        }

        $this->error("Failed to send test email to {$to}: " . $result->failureDetail('the mailer returned false.'));
    }
}
