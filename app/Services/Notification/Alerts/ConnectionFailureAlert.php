<?php

/**
 * Sends a notification when an SMTP connection fails to deliver a message.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Notification\Alerts;

use BooleanSmtp\Services\Notification\NotificationManager;

/**
 * Notifies configured channels when a single send attempt fails on a connection.
 *
 * @since 1.0.0
 */
class ConnectionFailureAlert
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
     * Builds and sends the connection-failure notification.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $data Failure details; recognizes `connection_id`, `connection_name`, and `driver`.
     * @return void
     */
    public function trigger(array $data): void
    {
        $connectionName = $data['connection_name'] ?? 'Unknown';
        $driver         = $data['driver'] ?? 'Unknown';
        $connectionId   = (int) ($data['connection_id'] ?? 0);

        $message = 'Connection failure.';

        $this->notifier->notify('connection_failure', $message, [
            'connection_id' => $connectionId,
            'operational'   => [
                'kind'     => 'connection_failure',
                'severity' => 'error',
                'facts'    => [
                    'Connection' => (string) $connectionName,
                    'Mailer'     => (string) $driver,
                ],
            ],
        ]);
    }
}
