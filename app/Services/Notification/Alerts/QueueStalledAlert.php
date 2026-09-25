<?php

/**
 * Sends a notification when the email queue appears stalled.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Notification\Alerts;

use BooleanSmtp\Services\Notification\NotificationManager;

/**
 * Notifies configured channels when emails are stuck in the pending state.
 *
 * @since 1.0.0
 */
class QueueStalledAlert
{
    /**
     * Creates the alert.
     *
     * @since 1.0.0
     *
     * @param NotificationManager $notifier Manager used to dispatch the alert to configured channels.
     */
    public function __construct(
        protected NotificationManager $notifier
    ) {}

    /**
     * Builds and sends the queue-stalled notification.
     *
     * @since 1.0.0
     *
     * @param  int $stuckCount Number of emails currently stuck in the pending state.
     * @return void
     */
    public function trigger(int $stuckCount): void
    {
        $message = "Email queue appears stalled: {$stuckCount} emails stuck in pending state.\n\n"
            . "Please check your SMTP connections and queue worker.\n"
            . "Time: " . gmdate('Y-m-d H:i:s') . " UTC";

        $this->notifier->notify('queue_stalled', $message, [
            'alert_type' => 'Queue Stalled',
            'severity'   => 'warning',
        ]);
    }
}
