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
        'oauth_refresh_failure' => [
            'enabled'  => true,
            'cooldown' => 1800, // seconds between repeated OAuth refresh alerts
        ],
    ],
];
