<?php
/**
 * Notification channel registration and alert thresholds for connection and delivery problems.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

return [
    // Notification channel implementations, keyed by the channel identifier used in settings.
    'channels' => [
        'telegram' => \BooleanSmtp\Services\Notification\Channels\TelegramChannel::class,
        'slack'    => \BooleanSmtp\Services\Notification\Channels\SlackChannel::class,
        'discord'  => \BooleanSmtp\Services\Notification\Channels\DiscordChannel::class,
    ],

    // Conditions that trigger an outgoing notification, and how often each may repeat.
    'alerts' => [
        'connection_failure' => [
            'enabled'  => true,
            'cooldown' => 300, // seconds between repeated alerts
        ],
        // Available under the Pro notifications.advanced feature: fires when a scheduled health
        // check finds the connection still down, distinct from a single email failing to send.
        'connection_unhealthy' => [
            'enabled'  => true,
            'cooldown' => 1800, // seconds between repeated alerts for the same connection
        ],
        'oauth_refresh_failure' => [
            'enabled'  => true,
            'cooldown' => 1800, // seconds between repeated OAuth refresh alerts
        ],
        'high_bounce_rate' => [
            'enabled'   => true,
            'threshold' => 5, // percent
            'window'    => 3600, // seconds
        ],
        'queue_stalled' => [
            'enabled'   => false,
            'threshold' => 50, // emails stuck
            'window'    => 600, // seconds
        ],
    ],
];
