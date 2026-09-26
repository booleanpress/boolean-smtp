<?php
/**
 * Outgoing mail configuration: default transport, sender identity, logging, delivery
 * safeguards and the registered transport drivers.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

return [
    // Transport driver used when a connection does not specify one.
    'default' => 'smtp',

    // Fallback sender identity used when a connection does not set its own From address/name.
    'from' => [
        'name'    => '',
        'address' => '',
    ],

    // When true, every outgoing email is forced to use the 'from' address above, overriding
    // whatever address the calling code set.
    'force_from' => false,

    // Email simulation mode: when enabled, sends are intercepted and logged instead of delivered.
    'simulation' => [
        'enabled' => false,
    ],

    // Automatic generation of a plain-text alternative body for HTML emails.
    'plain_text' => [
        'auto_generate' => true,
    ],

    // Email log retention and whether the message body is stored.
    'logging' => [
        'enabled'   => true,
        'body'      => false,
        'retention' => 30, // days
    ],

    // Behavior when the primary connection fails to send.
    'fallback' => [
        'enabled'    => true,
        'auto_retry' => true,
        'max_retries' => 2,
    ],

    // Scheduled connectivity health checks for configured connections.
    'health_check' => [
        'enabled'  => true,
        'interval' => 15, // minutes
    ],

    // Encryption key used to encrypt sensitive connection settings at rest.
    'encryption_key' => '',

    // Transport driver implementations, keyed by the driver identifier used in connection settings.
    'transports' => [
        'php'          => \BooleanSmtp\Services\Mailer\Transports\PhpMailTransport::class,
        'smtp'         => \BooleanSmtp\Services\Mailer\Transports\SmtpTransport::class,
        'google'       => \BooleanSmtp\Services\Mailer\Transports\GoogleTransport::class,
        'outlook'      => \BooleanSmtp\Services\Mailer\Transports\OutlookTransport::class,
        'ses'          => \BooleanSmtp\Services\Mailer\Transports\SesTransport::class,
    ],
];
