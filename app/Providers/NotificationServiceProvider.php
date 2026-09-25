<?php
/**
 * Wires the notification manager and the final-failure alert listener.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Providers;

use BooleanSmtp\Core\Container\ServiceProvider;
use BooleanSmtp\Core\Contracts\LoggerContract;
use BooleanSmtp\Services\Notification\NotificationManager;
use BooleanSmtp\Services\Notification\Presentation\AlertPresentation;

/**
 * Binds the notification manager and alerts configured channels when email delivery
 * exhausts its retries.
 *
 * @since 1.0.0
 */
class NotificationServiceProvider extends ServiceProvider {
    /**
     * Bind {@see NotificationManager} as a container singleton.
     *
     * @since 1.0.0
     */
    public function register(): void {
        $this->app->singleton(NotificationManager::class);
    }

    /**
     * Listen for the final email delivery failure event.
     *
     * @since 1.0.0
     */
    public function boot(): void {
        $this->addAction('boolean_smtp_email_delivery_failed_final', [$this, 'onEmailDeliveryFailedFinal']);
    }

    /**
     * Send a notification alert when an email has permanently failed to send.
     *
     * Triggered by the `boolean_smtp_email_delivery_failed_final` hook after all retry
     * attempts have been exhausted. Failures raised while notifying are swallowed so a
     * broken notification channel never interrupts the email delivery flow.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $data Failure context; expected keys are `to`, `subject`,
     *                                   and `error`.
     */
    public function onEmailDeliveryFailedFinal(array $data): void {
        try {
            $notifier = $this->app->make(NotificationManager::class);

            $message = 'Email delivery failed.';

            $notifier->notify('connection_failure', $message, [
                'connection_id' => isset($data['connection_id']) ? (int) $data['connection_id'] : 0,
                'severity'      => 'error',
                'presentation'  => AlertPresentation::PRESENTATION_DELIVERY_FAILURE,
                'email'         => $data,
            ]);
        } catch (\Throwable $e) {
            // Notification delivery failures must not interrupt the email flow, but they are recorded.
            $this->app->make(LoggerContract::class)->warning(
                'The delivery-failure alert could not be sent.',
                ['error' => $e->getMessage()]
            );
        }
    }
}
