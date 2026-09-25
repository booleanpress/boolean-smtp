<?php

/**
 * Simulation mail transport for development and staging environments.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer\Transports;

use BooleanSmtp\Contracts\TransportContract;

/**
 * Routes outgoing mail to a local mail-catching SMTP server (such as Mailpit or MailHog) instead
 * of a live provider. Supports a single delivery mode, SMTP, and never contacts a real mailbox.
 *
 * @since 1.0.0
 */
class SimulationTransport implements TransportContract
{
    /**
     * Get the transport driver identifier.
     *
     * @since 1.0.0
     *
     * @return string Always `simulation`.
     */
    public function getDriver(): string
    {
        return 'simulation';
    }

    /**
     * Get the human-readable transport name shown in the admin UI.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Simulation (Dev/Staging)';
    }

    /**
     * Configure PHPMailer to deliver through the local mail-catcher.
     *
     * Connects unauthenticated over plain SMTP to the configured host and port; no credentials or
     * encryption are used since the target is a local development tool, not a live mail server.
     *
     * @since 1.0.0
     *
     * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance being prepared for sending.
     * @param array<string, mixed>           $settings  Decrypted connection settings; see {@see self::getSettingsSchema()}.
     */
    public function configure(\PHPMailer\PHPMailer\PHPMailer $phpmailer, array $settings): void
    {
        $phpmailer->isSMTP();
        $phpmailer->Host     = $settings['host'] ?? 'localhost';
        $phpmailer->Port     = (int) ($settings['port'] ?? 1025);
        $phpmailer->SMTPAuth = false;
        $phpmailer->SMTPSecure = '';
    }

    /**
     * Validate connection settings.
     *
     * This transport has no required credentials, so validation always succeeds.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $settings Connection settings to validate.
     * @return array<string, string> Always empty.
     */
    public function validateSettings(array $settings): array
    {
        return [];
    }

    /**
     * Get the settings schema used to render the connection form in the admin UI.
     *
     * @since 1.0.0
     *
     * @return array<string, array{type: string, label: string, required: bool, default?: mixed}>
     */
    public function getSettingsSchema(): array
    {
        return [
            'from_email' => [
                'type'     => 'email',
                'label'    => 'From Email',
                'required' => true,
                'default'  => '',
            ],
            'force_from_email' => [
                'type'     => 'checkbox',
                'label'    => 'Force From email',
                'required' => false,
                'default'  => false,
            ],
            'return_path' => [
                'type'     => 'checkbox',
                'label'    => 'Set envelope sender (Return-Path) from From address',
                'required' => false,
                'default'  => true,
            ],
            'from_name' => [
                'type'     => 'text',
                'label'    => 'From Name',
                'required' => false,
                'default'  => '',
            ],
            'force_from_name' => [
                'type'     => 'checkbox',
                'label'    => 'Force From name',
                'required' => false,
                'default'  => false,
            ],
            'host' => [
                'type'     => 'text',
                'label'    => 'Mailpit/Mailhog Host',
                'required' => false,
                'default'  => 'localhost',
            ],
            'port' => [
                'type'     => 'number',
                'label'    => 'Port',
                'required' => false,
                'default'  => 1025,
            ],
        ];
    }

    /**
     * Get the supported delivery modes for this transport.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Delivery mode key mapped to its label; only `smtp` is supported.
     */
    public function getDeliveryModes(): array
    {
        return ['smtp' => 'SMTP (Local Dev)'];
    }

    /**
     * Get the SMTP host/port/encryption presets offered in the admin UI.
     *
     * @since 1.0.0
     *
     * @return array<string, array{host: string, port: int, encryption: string, label: string}>
     */
    public function getSmtpPresets(): array
    {
        return [
            'local_mailpit' => [
                'host'       => 'localhost',
                'port'       => 1025,
                'encryption' => 'none',
                'label'      => 'Local Mailpit (1025, No Enc)',
            ],
        ];
    }

    /**
     * Get validation rules for connection settings.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Field name mapped to its validation rule string.
     */
    public function getValidationRules(): array
    {
        return [
            'host' => 'required|string',
            'port' => 'required|integer',
        ];
    }
}

