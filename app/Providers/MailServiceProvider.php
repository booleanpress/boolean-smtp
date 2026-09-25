<?php
/**
 * Binds the mail pipeline services and wires them into the WordPress mail hooks.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Providers;

use BooleanSmtp\Core\Container\ServiceProvider;
use BooleanSmtp\Core\Contracts\LoggerContract;
use BooleanSmtp\Adapters\Contracts\HookAdapterContract;
use BooleanSmtp\Support\Settings;
use BooleanSmtp\Services\Mailer\ApiMailDispatcher;
use BooleanSmtp\Services\Mailer\BooleanSmtpMailPipeline;
use BooleanSmtp\Services\Mailer\EmailDeliveryFailureNotifier;
use BooleanSmtp\Services\Mailer\EmailLogSendLifecycle;
use BooleanSmtp\Services\Mailer\Headers\MailHeadersNormalizer;
use BooleanSmtp\Services\Mailer\MailerManager;
use BooleanSmtp\Services\Mailer\MailFailureHandler;
use BooleanSmtp\Services\Simulation\SimulationService;

/**
 * Registers the mailer container bindings and listens on `phpmailer_init`, `pre_wp_mail`,
 * `wp_mail_succeeded`, and `wp_mail_failed` to route outgoing mail through the plugin's
 * transports, capture email logs, and support Email Simulation Mode.
 *
 * @since 1.0.0
 */
class MailServiceProvider extends ServiceProvider {
    /**
     * Hook adapter used to register listeners in a framework-agnostic way.
     *
     * @since 1.0.0
     * @var HookAdapterContract|null
     */
    private ?HookAdapterContract $hooks = null;

    /**
     * Bind the mailer pipeline services as container singletons.
     *
     * @since 1.0.0
     */
    public function register(): void {
        $this->app->singleton(MailerManager::class, function ($app) {
            return new MailerManager(
                $app,
                $app->make(\BooleanSmtp\Services\Mailer\SmtpDebugger::class),
                $app->make(LoggerContract::class),
            );
        });

        $this->app->singleton(EmailDeliveryFailureNotifier::class, function ($app) {
            return new EmailDeliveryFailureNotifier($app);
        });

        $this->app->singleton(MailFailureHandler::class, function ($app) {
            return new MailFailureHandler($app);
        });

        // One ladder per request: the queue worker and the failure handler share its state.
        $this->app->singleton(\BooleanSmtp\Services\Queue\QueueScheduler::class);
        $this->app->singleton(\BooleanSmtp\Services\Queue\RetryLadder::class);

        $this->app->singleton(ApiMailDispatcher::class, function ($app) {
            return new ApiMailDispatcher($app);
        });

        $this->app->singleton(MailHeadersNormalizer::class, function () {
            return new MailHeadersNormalizer();
        });

        $this->app->singleton(BooleanSmtpMailPipeline::class, function ($app) {
            return new BooleanSmtpMailPipeline($app, $app->make(MailHeadersNormalizer::class));
        });

        $this->app->singleton(EmailLogSendLifecycle::class, function ($app) {
            return new EmailLogSendLifecycle($app);
        });
    }

    /**
     * Register the mail-related WordPress hook listeners.
     *
     * Uses the hook adapter so the same listener registration works in both the WordPress
     * runtime and the Laravel adapter context. Wires: `phpmailer_init` (configures the
     * transport on the PHPMailer instance), `pre_wp_mail` (simulation short-circuit at
     * priority 1, then API dispatch at priority 5), `wp_mail_succeeded`, `wp_mail_failed`
     * (an early priority-5 listener and the priority-10 fallback handler), and
     * `boolean_smtp_oauth_refresh_failed` for OAuth notification delivery.
     *
     * @since 1.0.0
     */
    public function boot(): void {
        $this->hooks = $this->app->make(HookAdapterContract::class);

        $this->hooks->listen('phpmailer_init', [$this, 'interceptMail'], 999);
        $this->hooks->listen('pre_wp_mail', [$this, 'simulateWpMail'], 1, 2);
        $this->hooks->listen('pre_wp_mail', [$this, 'dispatchApiMail'], 5, 2);
        $this->hooks->listen('wp_mail_succeeded', [$this, 'onWpMailSucceeded'], 10);
        $this->hooks->listen('wp_mail_failed', [$this, 'onWpMailFailedEarly'], 5);
        $this->hooks->listen('wp_mail_failed', [$this, 'onWpMailFailed'], 10);

        $this->hooks->listen('boolean_smtp_oauth_refresh_failed', [$this, 'onOAuthRefreshFailed'], 10);
    }

    /**
     * Record a successful send and finalize any captured debug data.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $mailData Mail data supplied by `wp_mail_succeeded`,
     *                                        including the `log_id` of the email log entry.
     */
    public function onWpMailSucceeded(array $mailData): void {
        try {
            $this->app->make(EmailLogSendLifecycle::class)->onWpMailSucceeded($mailData);
            // Finalize debug capture after a successful send.
            $emailLogId = (int) ($mailData['log_id'] ?? 0);
            if ($emailLogId > 0) {
                $this->app->make(\BooleanSmtp\Services\Mailer\MailerManager::class)->finalizeDebugCapture($emailLogId);
            }
        } catch (\Throwable $e) {
            $this->logger()->error('wp_mail_succeeded handler: ' . $e->getMessage());
        }
    }

    /**
     * Record an early mail failure and finalize any captured debug data.
     *
     * Runs before {@see onWpMailFailed()} so debug capture is closed out even when the
     * lower-priority fallback handler does not run.
     *
     * @since 1.0.0
     *
     * @param \WP_Error $error Error supplied by the `wp_mail_failed` action.
     */
    public function onWpMailFailedEarly(\WP_Error $error): void {
        try {
            $this->app->make(EmailLogSendLifecycle::class)->onWpMailFailed($error);
            // Finalize debug capture after a failed send, including early failures.
            $this->app->make(\BooleanSmtp\Services\Mailer\MailerManager::class)->finalizeDebugCapture();
        } catch (\Throwable $e) {
            $this->logger()->error('wp_mail_failed handler: ' . $e->getMessage());
        }
    }

    /**
     * Run the fallback failure handler for a failed mail send.
     *
     * @since 1.0.0
     *
     * @param \WP_Error $error Error supplied by the `wp_mail_failed` action.
     */
    public function onWpMailFailed(\WP_Error $error): void {
        try {
            $this->app->make(MailFailureHandler::class)->handle($error);
        } catch (\Throwable $e) {
            $this->logger()->error('Fallback handler error: ' . $e->getMessage());
        }
    }

    /**
     * Configure PHPMailer with the active connection's transport settings.
     *
     * @since 1.0.0
     *
     * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance supplied by
     *                                                   `phpmailer_init`.
     */
    public function interceptMail(\PHPMailer\PHPMailer\PHPMailer $phpmailer): void {
        try {
            $mailer = $this->app->make(MailerManager::class);
            $mailer->configure($phpmailer);
        } catch (\Throwable $e) {
            $this->logger()->error('Mail interception error: ' . $e->getMessage());
        }
    }

    /**
     * Dispatch outgoing mail through the API transport when applicable.
     *
     * Runs on `pre_wp_mail` at priority 5, after the simulation short-circuit. Delegates to
     * {@see ApiMailDispatcher::maybeHandle()}, which returns null to let `wp_mail()` continue
     * with its default transport, or a non-null value that short-circuits it once the API
     * transport has handled the send.
     *
     * @since 1.0.0
     *
     * @param  mixed                 $preempt Current short-circuit value from `pre_wp_mail`.
     * @param  array<string, mixed>  $args    The `wp_mail()` arguments (`to`, `subject`,
     *                                         `message`, `headers`, `attachments`).
     * @return mixed The value to short-circuit `wp_mail()` with, or null to continue.
     */
    public function dispatchApiMail(mixed $preempt, array $args): mixed {
        try {
            return $this->app->make(ApiMailDispatcher::class)->maybeHandle($preempt, $args);
        } catch (\Throwable $e) {
            $this->logger()->error('API mail pre_wp_mail error: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Capture a simulated send and short-circuit `wp_mail()` when simulation mode is on.
     *
     * Runs on `pre_wp_mail` at priority 1, before the API dispatcher. When the
     * `simulation_enabled` setting is off, returns null so `wp_mail()` proceeds normally.
     *
     * @since 1.0.0
     *
     * @param  mixed                $preempt Current short-circuit value from `pre_wp_mail`.
     * @param  array<string, mixed> $args    The `wp_mail()` arguments (`to`, `subject`,
     *                                        `message`, `headers`, `attachments`).
     * @return bool|null True to short-circuit `wp_mail()` once the send has been captured,
     *                    or null to let `wp_mail()` run normally.
     */
    public function simulateWpMail(mixed $preempt, array $args): ?bool {
        try {
            $settings = $this->app->make(Settings::class);
            if (!$settings->get('simulation_enabled')) {
                return null;
            }

            $to      = $args['to'] ?? '';
            $subject = $args['subject'] ?? '';
            $message = $args['message'] ?? '';

            $headers = [];

            $simulation = $this->app->make(SimulationService::class);
            $simulation->capture([
                'to'         => is_array($to) ? implode(', ', $to) : (string) $to,
                'from_email' => (string) $settings->get('from_email'),
                'from_name'  => (string) $settings->get('from_name'),
                'subject'    => (string) $subject,
                'body'       => is_string($message) ? $message : (string) $message,
                'headers'    => $headers
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger()->error('Simulation pre_wp_mail error: ' . $e->getMessage());

            // Fail open so wp_mail still runs if simulation capture fails.
            return null;
        }
    }

    /**
     * Notify configured channels when an OAuth token refresh fails.
     *
     * @since 1.0.0
     *
     * @param array{connection_id: int, driver: string, error_code: string, error_message: string, recoverable: bool}
     *        $context Failure context supplied by the `boolean_smtp_oauth_refresh_failed` hook.
     */
    public function onOAuthRefreshFailed(array $context): void {
        try {
            $this->app->make(\BooleanSmtp\Services\OAuth\OAuthFailureNotifier::class)->notifyFailure(
                $context['connection_id'] ?? 0,
                $context['driver'] ?? 'unknown',
                $context['error_code'] ?? 'oauth_refresh_unknown',
                $context['recoverable'] ?? false
            );
        } catch (\Throwable $e) {
            $this->logger()->error('OAuth refresh failure notification error: ' . $e->getMessage());
        }
    }

    /**
     * The plugin logger, resolved lazily so hook callbacks never pay for it unless they fail.
     *
     * @since 1.0.0
     *
     * @return LoggerContract
     */
    private function logger(): LoggerContract {
        return $this->app->make(LoggerContract::class);
    }
}
