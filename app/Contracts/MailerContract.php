<?php
/**
 * Contract for resolving and configuring outgoing mail transports.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Contracts;

/**
 * Guarantees a way to resolve the transport an outgoing message should use and to configure
 * PHPMailer to send through it.
 *
 * @since 1.0.0
 */
interface MailerContract
{
    /**
     * Configure a PHPMailer instance using the resolved connection.
     *
     * @since 1.0.0
     *
     * @param  \PHPMailer\PHPMailer\PHPMailer $phpmailer Instance to configure.
     */
    public function configure(\PHPMailer\PHPMailer\PHPMailer $phpmailer): void;

    /**
     * Resolve the transport that should send the given email.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $emailData Email data used to select a transport, such as a
     *                                          routing rule match.
     * @return TransportContract The transport to use.
     */
    public function resolveTransport(array $emailData = []): TransportContract;

    /**
     * Return every registered transport driver.
     *
     * @since 1.0.0
     *
     * @return array<string, class-string<TransportContract>> Transport implementations keyed by
     *                                                          driver identifier.
     */
    public function getTransports(): array;
}
