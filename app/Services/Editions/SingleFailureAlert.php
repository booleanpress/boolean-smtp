<?php
/**
 * The plugin's health-check alert: a connection-failure notice.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Editions;

use BooleanSmtp\Contracts\Editions\HealthAlertContract;
use BooleanSmtp\Services\Notification\Alerts\ConnectionFailureAlert;
use BooleanSmtp\Services\Notification\NotificationManager;

/**
 * Reports a connection that failed its health check as a connection failure.
 *
 * @since 1.0.0
 */
final class SingleFailureAlert implements HealthAlertContract
{
    /**
     * @since 1.0.0
     *
     * @param NotificationManager $notifications Delivers the alert to the configured channels.
     */
    public function __construct(private readonly NotificationManager $notifications) {}

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     *
     * @param  array{connection_id: int, connection_name: string, driver: string} $alertData The failing connection.
     * @return void
     */
    public function unhealthy(array $alertData): void
    {
        (new ConnectionFailureAlert($this->notifications))->trigger($alertData);
    }
}
