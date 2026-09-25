<?php
/**
 * Core application identity and boot-time service provider configuration.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

return [
    // Human-readable plugin name shown in the admin UI and generated headers.
    'name'    => 'BooleanSMTP',
    // Plugin slug, used for the settings page, asset handles and file paths.
    'slug'    => 'boolean-smtp',
    // Prefix applied to option names, table names and cron hooks.
    'prefix'  => 'booleansmtp',

    // Service providers booted when the plugin starts, in registration order.
    'providers' => [
        \BooleanSmtp\Providers\AppServiceProvider::class,
        \BooleanSmtp\Providers\RouteServiceProvider::class,
        \BooleanSmtp\Providers\MailServiceProvider::class,
    ],
];
