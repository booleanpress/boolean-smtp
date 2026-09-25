<?php
/**
 * Edition policy: the alert sent when a scheduled health check finds a connection down.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Contracts\Editions;

/**
 * Sends the notification for a connection that failed its scheduled health check. The plugin
 * binds its own implementation; an add-on may bind another. Not a public extension point.
 *
 * @internal
 * @since 1.0.0
 */
interface HealthAlertContract
{
    /**
     * Notify the configured channels that a connection failed its health check.
     *
     * @since 1.0.0
     *
     * @param  array{connection_id: int, connection_name: string, driver: string} $alertData The failing connection.
     * @return void
     */
    public function unhealthy(array $alertData): void;
}
