<?php

/**
 * SMTP and API handshake checks shared by connection tests and scheduled health jobs.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Connection;

use BooleanSmtp\Contracts\EncryptorContract;
use BooleanSmtp\Models\Connection;
use BooleanSmtp\Repositories\ConnectionRepository;
use BooleanSmtp\Services\Mailer\Api\GmailApiSender;
use BooleanSmtp\Services\Mailer\Api\MicrosoftGraphMailSender;
use BooleanSmtp\Services\Mailer\Api\SesApiSender;
use BooleanSmtp\Services\Mailer\MailerManager;
use BooleanSmtp\Services\Settings\ConstantSettingsResolver;
use BooleanSmtp\Support\WordPressMailerLoader;
use function BooleanSmtp\Core\app;

/**
 * Verifies that a connection's stored credentials can actually reach its transport.
 *
 * Used both for the "Test connection" action in the admin UI and for scheduled health checks.
 * For SMTP drivers it performs a connect/disconnect handshake without sending a message; for API
 * drivers (SES, Google, Outlook) it performs an HTTPS credential probe against the provider.
 *
 * @since 1.0.0
 */
class ConnectionHealthProbe {
    /**
     * @since 1.0.0
     *
     * @param  MailerManager             $mailer           Resolves transports and API delivery mode.
     * @param  EncryptorContract         $encryptor        Encrypts and decrypts connection settings at rest.
     * @param  ConnectionRepository      $connections      Persists refreshed OAuth token fields.
     * @param  ConstantSettingsResolver  $constantResolver Applies constant-defined overrides to connection settings.
     */
    public function __construct(
        protected MailerManager $mailer,
        protected EncryptorContract $encryptor,
        protected ConnectionRepository $connections,
        protected ConstantSettingsResolver $constantResolver
    ) {}

    /**
     * Verify that a connection can authenticate with its transport.
     *
     * For the `php` and `simulation` drivers this always reports healthy without contacting a
     * transport. For API drivers, a successful probe also persists any refreshed OAuth token
     * fields back onto the connection.
     *
     * @since 1.0.0
     *
     * @param  Connection            $connection              Connection being probed.
     * @param  array<string, mixed>  $decrypted               Decrypted connection settings.
     * @param  bool                  $smtpHandshakeDebug      Whether to capture raw SMTP debug output for
     *                                                          SMTP-driver probes.
     * @param  bool                  $verifyApiSendCapability Whether to additionally verify send capability
     *                                                          (not just authentication) for API drivers that
     *                                                          support it.
     * @return array{
     *     healthy: bool,
     *     error?: string,
     *     debug?: string,
     *     resolved_mailer?: array<string, mixed>|null
     * } Whether the probe succeeded, and diagnostic details for the admin UI.
     */
    public function probe(Connection $connection, array $decrypted, bool $smtpHandshakeDebug = false, bool $verifyApiSendCapability = false): array {
        $driver = (string) $connection->driver;

        if (in_array($driver, ['php', 'simulation'], true)) {
            return ['healthy' => true];
        }

        WordPressMailerLoader::ensureLoaded();

        $decrypted = MailerManager::prepareDecryptedConnectionSettings($this->constantResolver, $driver, $decrypted);

        if ($this->mailer->isApiDeliveryMode($driver, $decrypted)) {
            $expiresAt = (int) ($decrypted['token_expires_at'] ?? 0);
            $apiDebug  = [
                'provider'                => $driver,
                'delivery_mode'           => 'api',
                'token_expires_at'        => $expiresAt,
                'token_seconds_remaining' => $expiresAt > 0 ? ($expiresAt - time()) : null
            ];

            $probe = null;
            if ($driver === 'ses') {
                $sender = app(SesApiSender::class);
                $probe  = $sender->probe($decrypted);
            } elseif ($driver === 'google') {
                $sender   = app(GmailApiSender::class);
                $probe    = $sender->probe($decrypted);
                $apiDebug = array_merge($apiDebug, $sender->getAuthDebug());
                $this->persistTokenUpdates($connection, $sender->consumePersistableTokenFields());
            } elseif ($driver === 'outlook') {
                $sender   = app(MicrosoftGraphMailSender::class);
                $probe    = $sender->probe($decrypted, $verifyApiSendCapability);
                $apiDebug = array_merge($apiDebug, $sender->getAuthDebug());
                $this->persistTokenUpdates($connection, $sender->consumePersistableTokenFields());
            } else {
                $probe = new \WP_Error('booleansmtp_api', 'Unsupported API driver.');
            }

            if (is_wp_error($probe)) {
                $apiDebug = array_merge($apiDebug, $this->extractWpErrorDebug($probe));
                return ['healthy' => false, 'error' => $probe->get_error_message(), 'api_debug' => $apiDebug];
            }

            return [
                'healthy'         => true,
                'debug'           => 'API credentials verified (HTTPS probe).',
                'api_debug'       => $apiDebug,
                'resolved_mailer' => [
                    'connection_id'   => (int) $connection->id,
                    'connection_name' => (string) ($connection->name ?? ''),
                    'connection_type' => $driver,
                    'delivery_mode'   => 'api'
                ]
            ];
        }

        $transports     = $this->mailer->getTransports();
        $transportClass = $transports[$driver] ?? null;

        if (!$transportClass || !class_exists($transportClass)) {
            return [
                'healthy' => false,
                'error'   => "Unknown transport driver: {$driver}"
            ];
        }

        $transport = new $transportClass();
        $phpmailer = WordPressMailerLoader::createInstance(true);
        $transport->configure($phpmailer, $decrypted);
        $phpmailer->Timeout = (int) ($decrypted['timeout'] ?? 15);

        $debugOutput = '';
        if ($smtpHandshakeDebug) {
            $phpmailer->SMTPDebug   = 2;
            $phpmailer->Debugoutput = function ($str) use (&$debugOutput): void {
                $debugOutput .= $str . "\n";
            };
        }

        try {
            $phpmailer->smtpConnect();
            $phpmailer->smtpClose();
        } catch (\Throwable $e) {
            return ['healthy' => false, 'error' => $e->getMessage()];
        }

        return [
            'healthy'         => true,
            'debug'           => $smtpHandshakeDebug ? $debugOutput : '',
            'resolved_mailer' => $this->buildMailerDiagnosticsFromPhpMailer($phpmailer, $connection)
        ];
    }

    /**
     * Build the connection diagnostics shown in the admin UI from a configured PHPMailer instance.
     *
     * @since 1.0.0
     *
     * @param  \PHPMailer\PHPMailer\PHPMailer  $phpmailer  PHPMailer instance configured for the connection.
     * @param  Connection                      $connection Connection the diagnostics describe.
     * @return array<string, mixed> Transport configuration values relevant to troubleshooting.
     */
    private function buildMailerDiagnosticsFromPhpMailer(\PHPMailer\PHPMailer\PHPMailer $phpmailer, Connection $connection): array {
        return [
            'connection_id'   => (int) $connection->id,
            'connection_name' => (string) $connection->name,
            'connection_type' => (string) $connection->driver,
            'mailer'          => (string) ($phpmailer->Mailer ?? ''),
            'host'            => (string) ($phpmailer->Host ?? ''),
            'port'            => (int) ($phpmailer->Port ?? 0),
            'smtp_auth'       => (bool) ($phpmailer->SMTPAuth ?? false),
            'encryption'      => (string) ($phpmailer->SMTPSecure ?? ''),
            'from'            => (string) ($phpmailer->From ?? ''),
            'sender'          => (string) ($phpmailer->Sender ?? ''),
            'from_name'       => (string) ($phpmailer->FromName ?? '')
        ];
    }

    /**
     * Merge refreshed OAuth token fields into a connection's encrypted settings.
     *
     * Does nothing when `$tokenFields` is empty, so probes that did not refresh a token never
     * trigger a write.
     *
     * @since 1.0.0
     *
     * @param  Connection            $connection Connection to update.
     * @param  array<string, mixed>  $tokenFields Token fields to merge into the stored settings, keyed by
     *                                             setting name.
     */
    private function persistTokenUpdates(Connection $connection, array $tokenFields): void {
        if (empty($tokenFields)) {
            return;
        }

        $raw     = $connection->settings ?? [];
        $current = \is_array($raw) ? $this->encryptor->decryptArray($raw) : [];

        foreach ($tokenFields as $key => $value) {
            $current[$key] = $value;
        }

        $this->connections->update((int) $connection->id, [
            'settings' => $this->encryptor->encryptArray($current)
        ]);
    }

    /**
     * Flatten a `WP_Error` from a provider probe into a debug array for the admin UI.
     *
     * @since 1.0.0
     *
     * @param  \WP_Error  $error Error returned by the provider probe.
     * @return array<string, mixed> Error code, message and, when available, the provider's HTTP status
     *                              and response body.
     */
    private function extractWpErrorDebug(\WP_Error $error): array {
        $debug = [
            'error_code'    => (string) $error->get_error_code(),
            'error_message' => (string) $error->get_error_message()
        ];

        $data = $error->get_error_data();
        if (is_array($data)) {
            if (isset($data['status'])) {
                $debug['status'] = (int) $data['status'];
            }
            if (isset($data['body']) && is_string($data['body'])) {
                $debug['provider_body'] = $data['body'];
            }
        } elseif ($data !== null) {
            $debug['error_data'] = $data;
        }

        return $debug;
    }
}
