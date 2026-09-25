<?php

/**
 * Sends a notification when the email bounce rate crosses the configured threshold.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Notification\Alerts;

use BooleanSmtp\Services\Notification\NotificationManager;

/**
 * Notifies configured channels when the observed bounce rate is high.
 *
 * @since 1.0.0
 */
class HighBounceRateAlert
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
     * Builds and sends the high-bounce-rate notification.
     *
     * @since 1.0.0
     *
     * @param  float $bounceRate Bounce rate as a percentage (for example `12.5` for 12.5%).
     * @param  int   $total      Total number of emails counted in the sample.
     * @param  int   $failed     Number of failed emails counted in the sample.
     * @return void
     */
    public function trigger(float $bounceRate, int $total, int $failed): void
    {
        $message = "High email bounce rate detected: {$bounceRate}%\n\n"
            . "Total emails: {$total}\n"
            . "Failed: {$failed}\n"
            . "Time: " . gmdate('Y-m-d H:i:s') . " UTC";

        $this->notifier->notify('high_bounce_rate', $message, [
            'alert_type' => 'High Bounce Rate',
            'severity'   => 'warning',
        ]);
    }
}
