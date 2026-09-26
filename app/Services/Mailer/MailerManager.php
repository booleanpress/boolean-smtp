<?php

/**
 * Resolves connections and transports, and configures PHPMailer for outgoing messages.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer;

use BooleanSmtp\Core\Contracts\LoggerContract;
use BooleanSmtp\Core\Foundation\Application;
use BooleanSmtp\Contracts\EncryptorContract;
use BooleanSmtp\Contracts\MailerContract;
use BooleanSmtp\Services\Senders\SenderRouter;
use BooleanSmtp\Contracts\TransportContract;
use BooleanSmtp\Models\Connection;
use BooleanSmtp\Models\EmailLog;
use BooleanSmtp\Repositories\ConnectionRepository;
use BooleanSmtp\Repositories\EmailLogRepository;
use BooleanSmtp\Services\Simulation\SimulationService;
use BooleanSmtp\Support\Settings;
use BooleanSmtp\Services\Settings\ConstantSettingsResolver;
use function BooleanSmtp\Core\config;

/**
 * Central mail service: resolves the connection and transport for an outgoing message, configures
 * PHPMailer for the send, records email logs, and fires the plugin's mailer-lifecycle hooks.
 *
 * @since 1.0.0
 */
class MailerManager implements MailerContract {
    /**
     * Application container used to resolve repositories, settings, and other services.
     *
     * @since 1.0.0
     * @var Application
     */
    protected Application $app;

    /**
     * Transport classes registered for each driver, keyed by driver name.
     *
     * @since 1.0.0
     * @var array<string, class-string<TransportContract>>
     */
    protected array $transports         = [];

    /**
     * Transport instances already resolved during this request, keyed by driver name.
     *
     * @since 1.0.0
     * @var array<string, TransportContract>
     */
    protected array $resolvedTransports = [];

    /**
     * SMTP debug capture service for recording debug transcripts when debug_level > 0.
     *
     * @since 1.0.0
     * @var SmtpDebugger
     */
    protected SmtpDebugger $debugger;

    /**
     * Plugin logger.
     *
     * @since 1.0.0
     * @var LoggerContract
     */
    protected LoggerContract $logger;

    /**
     * When set, the next {@see configure()} call uses this connection instead of only getPrimary().
     * Cleared after use (e.g. log resend must reuse the original SMTP connection).
     * When set to a non-zero value, routing rules and filters will NOT override this connection.
     *
     * @since 1.0.0
     * @var int|null
     */
    protected ?int $forcedConnectionId = null;

    /**
     * When true, the forced connection should NOT be overridden by filters or routing rules.
     * Set to true when explicitly forcing a connection (e.g., test email).
     *
     * @since 1.0.0
     * @var bool
     */
    protected bool $forcedConnectionIsAbsolute = false;

    /**
     * When set, overrides the {@see Settings} `auto_plain_text` setting for the next
     * {@see configure()} call only (e.g. the Test Email Utility forcing multipart/alternative on
     * or off regardless of the site-wide default). Cleared after use.
     *
     * @since 1.0.0
     * @var bool|null
     */
    protected ?bool $forcedAutoPlainText = null;

    /**
     * When set, logEmail() will update this log ID instead of creating a new one.
     *
     * @since 1.0.0
     * @var int|null
     */
    protected ?int $forcedLogId = null;

    /**
     * True once the fallback connection delivered the message whose primary send failed, so
     * the send that is unwinding can report success to its caller.
     *
     * @since 1.0.0
     * @var bool
     */
    protected bool $fallbackDelivered = false;

    /**
     * Last connection used during {@see configure()} for the current request (fallback retry guard).
     *
     * @since 1.0.0
     * @var int|null
     */
    protected ?int $lastConfiguredConnectionId = null;

    /**
     * Email log row created for the in-flight send (cleared on success in {@see EmailLogSendLifecycle}).
     *
     * @since 1.0.0
     * @var int|null
     */
    protected ?int $pendingLogIdForCurrentSend = null;

    /**
     * Whether {@see $pendingLogIdForCurrentSend} already has its full raw headers recorded
     * (set by {@see persistLogAttempt()}, used by API-mode delivery). When false,
     * {@see EmailLogSendLifecycle} may backfill full headers from the real (SMTP-only)
     * `$GLOBALS['phpmailer']` — never when true, since that global is a different,
     * unrelated PHPMailer instance for an API-mode send.
     *
     * @since 1.0.0
     * @var bool
     */
    protected bool $pendingLogHeadersCaptured = false;

    /**
     * Timestamp (from microtime(true)) when the in-flight send started, used to compute
     * delivery duration in {@see takeLastSendDurationMs()}.
     *
     * @since 1.0.0
     * @var float|null
     */
    protected ?float $sendStartedAt = null;

    /**
     * When set, the next {@see logEmail()} uses this label instead of {@see captureSource()} (e.g. UI resend).
     *
     * @since 1.0.0
     * @var string|null
     */
    protected ?string $attemptSourceOverride = null;

    /**
     * Create the mailer manager, initializing the debug capture service and the default transports.
     *
     * @since 1.0.0
     *
     * @param Application    $app      Application container used to resolve repositories, settings, and other services.
     * @param SmtpDebugger   $debugger Captures SMTP conversation lines for the debug log.
     * @param LoggerContract $logger   Receives the resolved-mailer diagnostics when enabled.
     */
    public function __construct(Application $app, SmtpDebugger $debugger, LoggerContract $logger) {
        $this->app      = $app;
        $this->debugger = $debugger;
        $this->logger   = $logger;
        $this->registerDefaultTransports();
    }

    /**
     * Configure a PHPMailer instance using the resolved connection: applies transport settings,
     * sender identity, plain-text alternative, and header filters, and records an email log entry.
     *
     * @since 1.0.0
     *
     * @param  \PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer instance to configure for the send.
     * @return void
     */
    public function configure(\PHPMailer\PHPMailer\PHPMailer $phpmailer): void {
        $settings  = $this->app->make(Settings::class);
        $encryptor = $this->app->make(EncryptorContract::class);

        $this->lastConfiguredConnectionId = null;

        // A new send attempt is starting: forget the previous send's log so a failure
        // (or a skipped/unlogged send) here can never be misattributed to it — see
        // ApiMailDispatcher, whose own phpmailer_init fires this before it knows
        // whether this send will end up being logged at all.
        $this->pendingLogIdForCurrentSend = null;
        $this->pendingLogHeadersCaptured  = false;

        if ($settings->get('simulation_enabled')) {
            $this->captureSimulation($phpmailer);
            return;
        }

        // Resolve connection logic
        $emailData  = MailRoutingDataBuilder::fromPhpmailer($phpmailer);
        $connection = $this->resolveConnection($emailData);
        if (!$connection) {
            return;
        }

        $connSettings      = $connection->settings ?? [];
        $decryptedSettings = $encryptor->decryptArray(
            is_array($connSettings) ? $connSettings : []
        );

        $driver            = (string) $connection->driver;
        $resolver          = $this->app->make(ConstantSettingsResolver::class);
        $decryptedSettings = self::prepareDecryptedConnectionSettings($resolver, $driver, $decryptedSettings);

        /**
         * Fires after a connection has been resolved for an outgoing message, before it is applied to PHPMailer.
         *
         * @since 1.0.0
         *
         * @param Connection            $connection Connection resolved for this send.
         * @param array<string, mixed>  $emailData  Routing data (to, subject, from, content_type).
         */
        \do_action('boolean_smtp_mail_connection_resolved', $connection, $emailData);

        // Capture PHPMailer's debug output whenever a level is set, so it never reaches the
        // response; store the transcript only when a developer asks for it (SMTP only).
        $debugLevel = (int) ($decryptedSettings['debug_level'] ?? 0);
        if ($debugLevel > 0 && !$this->isApiDeliveryMode($driver, $decryptedSettings)) {
            $this->debugger->startCapture(
                $phpmailer,
                $debugLevel,
                (int) $connection->id,
                $this->pendingLogIdForCurrentSend, // Always null here (reset at the top of configure()); set by logEmail() shortly after
                $this->shouldStoreSmtpTranscript($connection, $debugLevel)
            );
        }

        $apiMode = $this->isApiDeliveryMode((string) $connection->driver, $decryptedSettings);
        if (!$apiMode) {
            $transport = $this->resolveTransportByDriver((string) $connection->driver);
            $transport->configure($phpmailer, $decryptedSettings);
        }

        $this->applyConnectionIdentityAndEnvelope($phpmailer, $decryptedSettings, (string) $connection->driver);
        $this->applyGlobalSettings($phpmailer, $settings);

        $this->lastConfiguredConnectionId = (int) $connection->id;
        $this->emitMailerDiagnostics($phpmailer, $connection, $apiMode);

        /**
         * Fires immediately before a message is sent, after the connection and transport have been configured.
         *
         * @since 1.0.0
         *
         * @param array{to: string, subject: string, connection: array{id: int, driver: string}} $data
         *        Message and connection details for the send about to happen.
         */
        \do_action('boolean_smtp_before_send', [
            'to'         => $emailData['to'],
            'subject'    => $emailData['subject'],
            'connection' => ['id' => $connection->id, 'driver' => $connection->driver]
        ]);

        if ($this->resolveAutoPlainText($settings) && $phpmailer->ContentType === 'text/html') {
            $plainText          = $this->generatePlainText($phpmailer->Body);
            /**
             * Filters the automatically generated plain-text alternative body.
             *
             * Return a modified string to use as the message's plain-text part.
             *
             * @since 1.0.0
             *
             * @param string $plainText Plain-text body generated from the HTML body.
             * @param string $htmlBody  Original HTML body the plain-text version was generated from.
             * @return string The filtered plain text.
             */
            $plainText          = \apply_filters('boolean_smtp_plain_text_body', $plainText, $phpmailer->Body);
            $phpmailer->AltBody = $plainText;
        }

        /**
         * Filters the message body immediately before it is sent.
         *
         * Return a modified string to change what is sent.
         *
         * @since 1.0.0
         *
         * @param string $body Message body (HTML or plain text, depending on content type).
         * @param string $to   Primary recipient address.
         * @return string The filtered body.
         */
        $phpmailer->Body = \apply_filters('boolean_smtp_message_body', $phpmailer->Body, $emailData['to']);

        $headers = [];
        foreach ($phpmailer->getCustomHeaders() as $header) {
            $headers[$header[0]] = $header[1];
        }
        /**
         * Filters the custom headers collected from PHPMailer before the message is logged.
         *
         * Return a modified array to change what is recorded.
         *
         * @since 1.0.0
         *
         * @param array<string, string> $headers Custom header name/value pairs collected from PHPMailer.
         * @param string                $to      Primary recipient address.
         * @return array<string, string> The filtered headers.
         */
        $headers = \apply_filters('boolean_smtp_email_headers', $headers, $emailData['to']);

        if ($settings->get('log_emails') && !$apiMode) {
            /**
             * Fires before an outgoing message is offered to the email log.
             *
             * The message may still be kept out of the log by the source exclusions or by
             * `boolean_smtp_should_log_email`; `boolean_smtp_mail_attempt_recorded` fires only
             * when a row was written.
             *
             * @since 1.0.0
             *
             * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer  Mailer instance about to be logged.
             * @param Connection                      $connection Connection the message is being sent through.
             */
            \do_action('boolean_smtp_mail_before_log', $phpmailer, $connection);
            if ($this->logEmail($phpmailer, $connection, $headers)) {
                /**
                 * Fires after a send attempt has been recorded in the email log.
                 *
                 * @since 1.0.0
                 *
                 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer  Mailer instance that was logged.
                 * @param Connection                      $connection Connection the message is being sent through.
                 */
                \do_action('boolean_smtp_mail_attempt_recorded', $phpmailer, $connection);
            }
        }
    }

    /**
     * Keys merged with decrypted settings before normalization when resolving wp-config/env constants.
     *
     * Without this, a field ConnectionController::prepareSettingsForStorage() unset entirely from
     * the DB (because a wp-config constant or env var was defined for it at save time) would never
     * be re-checked here: ConstantSettingsResolver::resolve() only re-derives keys that already
     * exist in the decrypted settings array unless told to also check others via this list.
     *
     * @since 1.0.0
     *
     * @param  string $driver Connection driver slug.
     * @return array<string>|null null = only keys already present on the settings array
     */
    public static function expectedKeysForDriver(string $driver): ?array {
        if ($driver === 'ses') {
            // SES's wp_config/env field names (smtp_host, api_access_key, ...) differ from its
            // normalized getValidationRules()/getSettingsSchema() keys (access_key, secret,
            // region), so this stays a hand-maintained list rather than the generic derivation below.
            return [
                'access_key',
                'secret',
                'region',
                'api_access_key',
                'api_secret',
                'api_region',
                'smtp_username',
                'smtp_password',
                'smtp_region',
                'smtp_host',
                'smtp_port',
                'smtp_encryption'
            ];
        }

        $transportClass = self::transportClassForDriver($driver);
        if ($transportClass === null) {
            return null;
        }

        // getSettingsSchema(), not getValidationRules(): it's the complete field inventory
        // (including optional fields with no validation rule) and the exact same source the frontend's wp-config/env snippet
        // generator uses, so "every field the UI told the admin to define" always round-trips.
        return array_keys((new $transportClass())->getSettingsSchema());
    }

    /**
     * Resolve the transport class registered for a driver.
     *
     * config('mail.transports') is checked first so a transport another plugin registers there
     * is still picked up, but the built-in drivers are resolved from this
     * complete, hardcoded map (mirrors config/mail.php's 'transports' list) rather than relying
     * on config() alone — the bare PHPUnit unit-test bootstrap stubs config() to always return
     * its $default, so a config()-only lookup would silently resolve every non-php/smtp driver
     * to null there even though production always has it populated.
     *
     * @since 1.0.0
     *
     * @param  string $driver Connection driver slug.
     * @return string|null Fully qualified transport class name, or null when the driver is unknown.
     */
    private static function transportClassForDriver(string $driver): ?string {
        $configured = config('mail.transports', []);
        $class      = is_array($configured) ? ($configured[$driver] ?? null) : null;
        if (is_string($class) && class_exists($class)) {
            return $class;
        }

        $builtIn = [
            'php'          => Transports\PhpMailTransport::class,
            'smtp'         => Transports\SmtpTransport::class,
            'google'       => Transports\GoogleTransport::class,
            'outlook'      => Transports\OutlookTransport::class,
            'ses'          => Transports\SesTransport::class,
        ];

        $class = $builtIn[$driver] ?? null;

        return ($class !== null && class_exists($class)) ? $class : null;
    }

    /**
     * Apply wp-config/env constant overrides and mode field normalization to decrypted connection settings.
     * Same pipeline as {@see configure()}: constant/env overrides, then mode field merging.
     *
     * @since 1.0.0
     *
     * @param  ConstantSettingsResolver $resolver  Resolver used to apply wp-config/env constant overrides.
     * @param  string                   $driver    Connection driver slug.
     * @param  array<string, mixed>     $decrypted Decrypted connection settings.
     * @return array<string, mixed>
     */
    public static function prepareDecryptedConnectionSettings(ConstantSettingsResolver $resolver, string $driver, array $decrypted): array {
        $expectedKeys = self::expectedKeysForDriver($driver);
        $decrypted    = $resolver->resolve($driver, $decrypted, $expectedKeys);

        return self::normalizeConnectionSettings($decrypted);
    }

    /**
     * Normalize a connection's settings: reconcile legacy field name aliases (secret_key/secret,
     * set_return_path/return_path, wp-config/wp_config), and mirror credentials between the
     * generic access_key/secret/region fields and their SMTP- or API-mode-specific counterparts
     * depending on the connection's delivery_mode.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $settings Decrypted connection settings.
     * @return array<string, mixed>
     */
    public static function normalizeConnectionSettings(array $settings): array {
        if (isset($settings['set_return_path']) && !isset($settings['return_path'])) {
            $settings['return_path'] = $settings['set_return_path'];
        }
        if (isset($settings['secret_key']) && !isset($settings['secret'])) {
            $settings['secret'] = $settings['secret_key'];
        }
        if (($settings['key_store'] ?? '') === 'wp-config') {
            $settings['key_store'] = 'wp_config';
        }

        $deliveryMode = (string) ($settings['delivery_mode'] ?? 'smtp');
        if ($deliveryMode === 'api') {
            $apiAk = isset($settings['api_access_key']) ? trim((string) $settings['api_access_key']) : '';
            if ($apiAk === '' && isset($settings['access_key'])) {
                $legacy = trim((string) $settings['access_key']);
                if ($legacy !== '') {
                    $settings['api_access_key'] = $legacy;
                    $apiAk                      = $legacy;
                }
            }
            $apiSec = isset($settings['api_secret']) ? trim((string) $settings['api_secret']) : '';
            if ($apiSec === '' && isset($settings['secret'])) {
                $legacy = trim((string) $settings['secret']);
                if ($legacy !== '') {
                    $settings['api_secret'] = $legacy;
                    $apiSec                 = $legacy;
                }
            }
            $apiReg = isset($settings['api_region']) ? trim((string) $settings['api_region']) : '';
            if ($apiReg === '' && isset($settings['region'])) {
                $legacy = trim((string) $settings['region']);
                if ($legacy !== '') {
                    $settings['api_region'] = $legacy;
                    $apiReg                 = $legacy;
                }
            }
            if ($apiAk !== '') {
                $settings['access_key'] = $apiAk;
            } elseif (isset($settings['access_key'])) {
                $settings['access_key'] = trim((string) $settings['access_key']);
            }
            if ($apiSec !== '') {
                $settings['secret'] = $apiSec;
            } elseif (isset($settings['secret'])) {
                $settings['secret'] = trim((string) $settings['secret']);
            }
            if ($apiReg !== '') {
                $settings['region'] = $apiReg;
            } elseif (isset($settings['region'])) {
                $settings['region'] = trim((string) $settings['region']);
            }
        } else {
            $smtpUser = isset($settings['smtp_username']) ? trim((string) $settings['smtp_username']) : '';
            if ($smtpUser === '' && isset($settings['access_key'])) {
                $legacy = trim((string) $settings['access_key']);
                if ($legacy !== '') {
                    $settings['smtp_username'] = $legacy;
                    $smtpUser                  = $legacy;
                }
            }
            $smtpPass = isset($settings['smtp_password']) ? trim((string) $settings['smtp_password']) : '';
            if ($smtpPass === '' && isset($settings['secret'])) {
                $legacy = trim((string) $settings['secret']);
                if ($legacy !== '') {
                    $settings['smtp_password'] = $legacy;
                    $smtpPass                  = $legacy;
                }
            }
            $smtpReg = isset($settings['smtp_region']) ? trim((string) $settings['smtp_region']) : '';
            if ($smtpReg === '' && isset($settings['region'])) {
                $legacy = trim((string) $settings['region']);
                if ($legacy !== '') {
                    $settings['smtp_region'] = $legacy;
                    $smtpReg                 = $legacy;
                }
            }
            if ($smtpUser !== '') {
                $settings['access_key'] = $smtpUser;
            } elseif (isset($settings['access_key'])) {
                $settings['access_key'] = trim((string) $settings['access_key']);
            }
            if ($smtpPass !== '') {
                $settings['secret'] = $smtpPass;
            } elseif (isset($settings['secret'])) {
                $settings['secret'] = trim((string) $settings['secret']);
            }
            if ($smtpReg !== '') {
                $settings['region'] = $smtpReg;
            } elseif (isset($settings['region'])) {
                $settings['region'] = trim((string) $settings['region']);
            }
        }

        return $settings;
    }

    /**
     * Determine whether a connection is configured to deliver through a provider's HTTP API
     * rather than SMTP.
     *
     * @since 1.0.0
     *
     * @param  string                $driver   Connection driver slug.
     * @param  array<string, mixed>  $settings Decrypted connection settings; reads delivery_mode.
     * @return bool
     */
    public function isApiDeliveryMode(string $driver, array $settings): bool {
        $dualModeDrivers = ['ses', 'google', 'outlook'];

        if (!\in_array($driver, $dualModeDrivers, true)) {
            return false;
        }

        $deliveryMode = (string) ($settings['delivery_mode'] ?? 'smtp');

        // Google and Outlook send every mode except SMTP over their HTTP APIs, including a mode
        // another plugin provides.
        if ($driver === 'outlook' || $driver === 'google') {
            return $deliveryMode !== 'smtp';
        }

        return $deliveryMode === 'api';
    }

    /**
     * Resolve the connection an outgoing message should use, applying the same resolution order
     * as {@see configure()} (forced connection, explicit hint, routing rule, sender match, default).
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $emailData Routing data (to, subject, from, content_type).
     * @return Connection|null
     */
    public function resolveConnectionForSend(array $emailData): ?Connection {
        return $this->resolveConnection($emailData);
    }

    /**
     * Resolve the transport that should send the given email.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $emailData Routing fields; empty resolves the default connection.
     * @return TransportContract The transport to use.
     */
    public function resolveTransport(array $emailData = []): TransportContract {
        $connection = $this->resolveConnection($emailData);
        if (!$connection) {
            return $this->resolveTransportByDriver('php');
        }

        return $this->resolveTransportByDriver((string) $connection->driver);
    }

    /**
     * Return every registered transport driver.
     *
     * @since 1.0.0
     *
     * @return array<string, class-string<TransportContract>> Transport implementations keyed by driver identifier.
     */
    public function getTransports(): array {
        $registered = $this->transports;
        /**
         * Filters the map of registered transport drivers.
         *
         * Return a modified array to register, replace, or remove transports.
         *
         * @since 1.0.0
         *
         * @param array<string, class-string<TransportContract>> $registered Transport classes keyed by driver name.
         * @return array<string, class-string<TransportContract>> The filtered transport map, keyed by driver name.
         */
        return \apply_filters('boolean_smtp_transports', $registered);
    }

    /**
     * Register a transport class for a driver.
     *
     * @since 1.0.0
     *
     * @param  string $driver Driver name the transport handles.
     * @param  string $class  Fully qualified transport class name; must implement {@see TransportContract}.
     * @return void
     */
    public function registerTransport(string $driver, string $class): void {
        $this->transports[$driver] = $class;
    }

    /**
     * Whether a transport exists for a driver: one of the plugin's own drivers, or one another
     * plugin registered through `boolean_smtp_transports`.
     *
     * @since 1.0.0
     *
     * @param  string $driver Driver name to check.
     * @return bool
     */
    public function hasTransport(string $driver): bool {
        if ($driver === '') {
            return false;
        }

        return \array_key_exists($driver, $this->getTransports()) || self::transportClassForDriver($driver) !== null;
    }

    /**
     * Resolve (and cache) the transport instance registered for a driver, falling back to the
     * "php" transport when the driver has no registration.
     *
     * @since 1.0.0
     *
     * @param  string $driver Driver name to resolve.
     * @return TransportContract
     */
    public function resolveTransportByDriver(string $driver): TransportContract {
        if (isset($this->resolvedTransports[$driver])) {
            return $this->resolvedTransports[$driver];
        }

        $transports = $this->getTransports();
        $class      = $transports[$driver] ?? $transports['php'];

        $this->resolvedTransports[$driver] = new $class();
        return $this->resolvedTransports[$driver];
    }

    /**
     * Force the next outgoing message to use this connection ID (single-use),
     * even if that connection is not active.
     *
     * @since 1.0.0
     *
     * @param  int|null $connectionId Connection id to force, or null to clear any forced connection.
     * @return void
     */
    public function forceConnectionForNextSend(?int $connectionId): void {
        $this->forcedConnectionId         = $connectionId;
        $this->forcedConnectionIsAbsolute = $connectionId !== null && $connectionId > 0;
    }

    /**
     * Force (or suppress) the auto-generated plain-text alternative for the next {@see configure()}
     * call only, overriding the site-wide `auto_plain_text` setting. Pass null to stop overriding.
     *
     * @since 1.0.0
     *
     * @param  bool|null $on True to force the plain-text alternative on, false to force it off, null to stop.
     * @return void
     */
    public function forceAutoPlainTextForNextSend(?bool $on): void {
        $this->forcedAutoPlainText = $on;
    }

    /**
     * Clear a connection previously forced with {@see forceConnectionForNextSend()}.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function clearForcedConnectionForSend(): void {
        $this->forcedConnectionId         = null;
        $this->forcedConnectionIsAbsolute = false;
    }

    /**
     * Force the next log write to update an existing log row instead of creating a new one.
     *
     * @since 1.0.0
     *
     * @param  int|null $logId Log id to update, or null to clear.
     * @return void
     */
    public function setForcedLogId(?int $logId): void {
        $this->forcedLogId = $logId;
    }

    /**
     * Clear a log id previously forced with {@see setForcedLogId()}.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function clearForcedLogId(): void {
        $this->forcedLogId = null;
    }

    /**
     * Get the log id the next send is forced onto, if any.
     *
     * @since 1.0.0
     *
     * @return int|null
     */
    public function getForcedLogId(): ?int {
        return $this->forcedLogId;
    }

    /**
     * Record that the fallback connection delivered the message whose primary send failed.
     *
     * Called by the fallback handler from inside `wp_mail_failed`; the send that is unwinding
     * reads it through {@see takeFallbackDelivered()} and returns true instead of false.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function markFallbackDelivered(): void {
        $this->fallbackDelivered = true;
    }

    /**
     * Whether the fallback connection delivered the message of the send that is unwinding.
     * Reading it clears it, so it never leaks into the next send.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function takeFallbackDelivered(): bool {
        $delivered               = $this->fallbackDelivered;
        $this->fallbackDelivered = false;

        return $delivered;
    }

    /**
     * Get the connection id used the last time {@see configure()} ran for this request.
     *
     * @since 1.0.0
     *
     * @return int|null
     */
    public function getLastConfiguredConnectionId(): ?int {
        return $this->lastConfiguredConnectionId;
    }

    /**
     * Get the email log id created for the send currently in flight, if any.
     *
     * @since 1.0.0
     *
     * @return int|null
     */
    public function getPendingLogIdForCurrentSend(): ?int {
        return $this->pendingLogIdForCurrentSend;
    }

    /**
     * Clear the email log id recorded for the send currently in flight.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function clearPendingLogIdForCurrentSend(): void {
        $this->pendingLogIdForCurrentSend = null;
    }

    /**
     * Determine whether the pending log's full raw headers have already been recorded.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function pendingLogHeadersAlreadyCaptured(): bool {
        return $this->pendingLogHeadersCaptured;
    }

    /**
     * Override the log source label used the next time an attempt is logged.
     *
     * @since 1.0.0
     *
     * @param  string|null $label Label to use instead of the backtrace-derived source, or null to clear.
     * @return void
     */
    public function setAttemptSourceOverride(?string $label): void {
        $this->attemptSourceOverride = $label;
    }

    /**
     * Clear a log source label previously set with {@see setAttemptSourceOverride()}.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function clearAttemptSourceOverride(): void {
        $this->attemptSourceOverride = null;
    }

    /**
     * Called immediately before PHPMailer::send() for delivery_time_ms.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function markSendStart(): void {
        $this->sendStartedAt = microtime(true);
    }

    /**
     * Compute and clear the duration, in milliseconds, of the send started with {@see markSendStart()}.
     *
     * @since 1.0.0
     *
     * @return int|null Duration in milliseconds, or null when no send was in progress.
     */
    public function takeLastSendDurationMs(): ?int {
        if ($this->sendStartedAt === null) {
            return null;
        }
        $ms                  = (int) round((microtime(true) - $this->sendStartedAt) * 1000);
        $this->sendStartedAt = null;

        return $ms;
    }

    /**
     * Resolve the connection an outgoing message should use, trying in order: an explicitly
     * forced or hinted connection, a routing-rule match, a connection whose from_email matches
     * a sender the caller set (never the site's default sender), and finally the configured default
     * or primary connection.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $emailData Routing data (to, subject, from, content_type).
     * @return Connection|null
     */
    protected function resolveConnection(array $emailData): ?Connection {
        $repo     = $this->app->make(ConnectionRepository::class);
        $settings = $this->app->make(Settings::class);

        $candidate = $this->pickConnectionFromHints($repo, $emailData);
        if ($candidate !== null) {
            return $this->applyResolveConnectionFilter($candidate, $emailData);
        }

        $ruleConnection = $this->resolveRoutingRuleConnection($repo, $emailData);
        if ($ruleConnection !== null) {
            return $this->applyResolveConnectionFilter($ruleConnection, $emailData);
        }

        // Sender matching reacts to a From the caller chose. The site's default sender (the one
        // filled in when a message sets none) and WordPress's own `wordpress@` fallback say nothing
        // about where the message should go, so they never override the default connection.
        $from = (string) ($emailData['from'] ?? '');
        if ($from !== '' && !$this->isSiteDefaultSender($from, $settings)) {
            $senderConnection = $this->findConnectionBySenderEmail($from, $emailData);
            if ($senderConnection !== null) {
                return $this->applyResolveConnectionFilter($senderConnection, $emailData);
            }
        }

        $rawDefault = $settings->get('default_connection_id');
        $defaultId  = null;
        if ($rawDefault !== null && $rawDefault !== '' && is_numeric($rawDefault)) {
            $i         = (int) $rawDefault;
            $defaultId = $i > 0 ? $i : null;
        }

        $connection = $repo->getDefaultOrPrimary($defaultId);
        if (!$connection) {
            return null;
        }

        return $this->applyResolveConnectionFilter($connection, $emailData);
    }

    /**
     * Resolve the connection a routing rule selects for the message, if any.
     *
     * The plugin evaluates no routing rules itself; another plugin that does returns the matched
     * connection through the filter below.
     *
     * @since 1.0.0
     *
     * @param  ConnectionRepository  $repo      Repository used to load the matched connection.
     * @param array<string, mixed> $emailData Routing data (to, subject, from, content_type).
     * @return Connection|null
     */
    protected function resolveRoutingRuleConnection(ConnectionRepository $repo, array $emailData): ?Connection {
        /**
         * Filters the connection a routing rule resolves for an outgoing message.
         *
         * Return a connection array with at least an "id" key to route the message to that
         * connection, or null to defer to the next resolution step.
         *
         * @since 1.0.0
         *
         * @param array{id: int}|null  $resolved  Connection data to route to, or null when no rule matched.
         * @param array<string, mixed> $emailData Routing data (to, subject, from, content_type).
         * @return array{id: int}|null The connection to use, or `null` to fall back to the default resolution.
         */
        $resolved = \apply_filters('boolean_smtp_resolve_routing_rule_connection', null, $emailData);
        if (is_array($resolved) && isset($resolved['id'])) {
            $connection = $repo->find((int) $resolved['id']);
            return $connection && $connection->is_active ? $connection : null;
        }

        return null;
    }

    /**
     * Whether an address is the sender a message gets when it sets none: the site's default
     * sender (`from_email` setting) or WordPress's `wordpress@<site>` fallback.
     *
     * @since 1.0.0
     *
     * @param  string   $address  Lowercased sender address.
     * @param  Settings $settings Settings holding the site's default sender.
     * @return bool
     */
    protected function isSiteDefaultSender(string $address, Settings $settings): bool {
        $address = MailRoutingDataBuilder::normalizeAddress($address);
        $default = MailRoutingDataBuilder::normalizeAddress((string) $settings->get('from_email', ''));

        return ($default !== '' && $address === $default) || str_starts_with($address, 'wordpress@');
    }

    /**
     * The active connection that sends for a sender: among the active connections whose
     * decrypted `from_email` matches the normalized sender (case-insensitive), the one the site's
     * sender-routing policy picks.
     *
     * @since 1.0.0
     *
     * @param  string               $normalizedFrom Lowercased sender address to match against.
     * @param  array<string, mixed> $emailData      Routing data passed to the policy.
     * @return Connection|null
     */
    protected function findConnectionBySenderEmail(string $normalizedFrom, array $emailData = []): ?Connection {
        if ($normalizedFrom === '') {
            return null;
        }

        $repo      = $this->app->make(ConnectionRepository::class);
        $encryptor = $this->app->make(EncryptorContract::class);

        $matches = [];
        foreach ($repo->active() as $connection) {
            $raw       = $connection->settings ?? [];
            $decrypted = \is_array($raw) ? $encryptor->decryptArray($raw) : [];
            $decrypted = self::normalizeConnectionSettings($decrypted);
            $connFrom  = isset($decrypted['from_email']) ? strtolower(trim((string) $decrypted['from_email'])) : '';
            if ($connFrom !== '' && $connFrom === $normalizedFrom) {
                $matches[] = $connection;
            }
        }

        if ($matches === []) {
            return null;
        }

        return $this->app->make(SenderRouter::class)->pick($matches, $emailData);
    }

    /**
     * Prefer a forced connection ID, then an explicit connection_id in $emailData, then fall through to getPrimary().
     *
     * @since 1.0.0
     *
     * @param  ConnectionRepository  $repo      Repository used to load candidate connections.
     * @param  array<string, mixed>  $emailData Routing fields extracted from the message; reads connection_id.
     * @return Connection|null
     */
    protected function pickConnectionFromHints(ConnectionRepository $repo, array $emailData): ?Connection {
        if ($this->forcedConnectionId !== null) {
            $id = $this->forcedConnectionId;

            if ($id > 0) {
                $direct = $repo->find($id);
                if ($direct) {
                    // Only clear after confirming connection exists
                    $this->forcedConnectionId = null;
                    return $direct;
                }
            }

            // If forced connection not found but was explicitly set, clear both state flags
            // to prevent protecting non-existent connections on subsequent resolution attempts
            $this->forcedConnectionId         = null;
            $this->forcedConnectionIsAbsolute = false;
        }

        if (!empty($emailData['connection_id'])) {
            $direct = $repo->find((int) $emailData['connection_id']);
            if ($direct && $direct->is_active) {
                return $direct;
            }
        }

        return null;
    }

    /**
     * Give the {@see 'boolean_smtp_resolve_connection'} filter a chance to redirect the resolved
     * connection to a different one, unless the connection was forced with the "absolute" flag.
     *
     * @since 1.0.0
     *
     * @param  Connection            $connection Connection resolved so far.
     * @param array<string, mixed> $emailData Routing data (to, subject, from, content_type).
     * @return Connection
     */
    protected function applyResolveConnectionFilter(Connection $connection, array $emailData): Connection {
        // If the connection was forced and should not be overridden by filters, return it as-is
        if ($this->forcedConnectionIsAbsolute) {
            return $connection;
        }

        $repo = $this->app->make(ConnectionRepository::class);

        $connectionArray = $connection->toArray();
        /**
         * Filters the connection resolved for an outgoing message, after routing rules and sender matching have run.
         *
         * Return a connection array with a different "id" to redirect the send to that connection.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $connectionArray Resolved connection, from {@see Connection::toArray()}.
         * @param array<string, mixed> $emailData Routing data (to, subject, from, content_type).
         * @return array<string, mixed> The filtered connection data.
         */
        $resolved        = \apply_filters('boolean_smtp_resolve_connection', $connectionArray, $emailData);

        if (is_array($resolved) && isset($resolved['id']) && (int) $resolved['id'] !== $connection->id) {
            $altConnection = $repo->find((int) $resolved['id']);
            if ($altConnection && $altConnection->is_active) {
                return $altConnection;
            }
        }

        return $connection;
    }

    /**
     * Per-connection Return-Path and force From flags. Global settings in {@see applyGlobalSettings()} win when force_from is on.
     *
     * @since 1.0.0
     *
     * @param  \PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer instance being configured.
     * @param  array<string, mixed>            $settings  Decrypted connection settings
     * @param  string                           $driver    Connection driver slug.
     * @return void
     */
    protected function applyConnectionIdentityAndEnvelope(
        \PHPMailer\PHPMailer\PHPMailer $phpmailer,
        array $settings,
        string $driver
    ): void {
        $forceFromEmail = $this->isTruthy($settings['force_from_email'] ?? false);
        $forceFromName  = $this->isTruthy($settings['force_from_name'] ?? false);

        if ($forceFromEmail && !empty($settings['from_email'])) {
            $phpmailer->From = (string) $settings['from_email'];
        }
        if ($forceFromName && !empty($settings['from_name'])) {
            $phpmailer->FromName = (string) $settings['from_name'];
        }

        $returnPath = $settings['return_path'] ?? true;
        if (\is_string($returnPath)) {
            $returnPath = \in_array(strtolower($returnPath), ['yes', 'true', '1', 'on'], true);
        } elseif (!\is_bool($returnPath)) {
            $returnPath = (bool) $returnPath;
        }

        if ($returnPath) {
            if (!empty($phpmailer->From)) {
                $phpmailer->Sender = $phpmailer->From;
            }
        } else {
            $phpmailer->Sender = '';
        }
    }

    /**
     * Interpret a loosely typed settings value (bool, number, or string like "yes"/"on") as a boolean.
     *
     * @since 1.0.0
     *
     * @param  mixed $value Value to interpret.
     * @return bool
     */
    protected function isTruthy(mixed $value): bool {
        if (\is_bool($value)) {
            return $value;
        }
        if ($value === null || $value === '') {
            return false;
        }
        if (\is_int($value) || \is_float($value)) {
            return (bool) $value;
        }

        return \in_array(strtolower(trim((string) $value)), ['1', 'yes', 'true', 'on'], true);
    }

    /**
     * Apply the site-wide default sender identity to PHPMailer. With the "force from" setting on,
     * the global identity overrides even a per-connection identity; otherwise it only fills in
     * values the connection left empty.
     *
     * @since 1.0.0
     *
     * @param  \PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer instance being configured.
     * @param  Settings              $settings  Settings source for the global sender identity.
     * @return void
     */
    protected function applyGlobalSettings(\PHPMailer\PHPMailer\PHPMailer $phpmailer, Settings $settings): void {
        $fromEmail = $settings->get('from_email');
        $fromName  = $settings->get('from_name');

        if ($settings->get('force_from')) {
            if ($fromEmail) {
                $phpmailer->From   = $fromEmail;
                $phpmailer->Sender = $fromEmail;
            }
            if ($fromName) {
                $phpmailer->FromName = $fromName;
            }
        } else {
            if ($fromEmail && empty($phpmailer->From)) {
                $phpmailer->From   = $fromEmail;
                $phpmailer->Sender = $fromEmail;
            }
            if ($fromName && empty($phpmailer->FromName)) {
                $phpmailer->FromName = $fromName;
            }
        }
    }

    /**
     * Build a diagnostic snapshot of the resolved mailer configuration, fire it for extensions,
     * and optionally write it to the PHP error log.
     *
     * @since 1.0.0
     *
     * @param  \PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer instance that was configured.
     * @param  Connection                      $connection Connection the message will be sent through.
     * @param  bool                             $apiMode    True when delivering through a provider API, not SMTP.
     * @return void
     */
    protected function emitMailerDiagnostics(\PHPMailer\PHPMailer\PHPMailer $phpmailer, Connection $connection, bool $apiMode = false): void {
        $diagnostics = [
            'connection_id'   => (int) $connection->id,
            'connection_name' => (string) ($connection->name ?? ''),
            'connection_type' => (string) ($connection->driver ?? ''),
            'delivery_mode'   => $apiMode ? 'api' : 'smtp',
            'mailer'          => $apiMode ? 'api' : (string) ($phpmailer->Mailer ?? ''),
            'host'            => $apiMode ? '' : (string) ($phpmailer->Host ?? ''),
            'port'            => $apiMode ? 0 : (int) ($phpmailer->Port ?? 0),
            'smtp_auth'       => $apiMode ? false : (bool) ($phpmailer->SMTPAuth ?? false),
            'encryption'      => $apiMode ? '' : (string) ($phpmailer->SMTPSecure ?? ''),
            'from'            => (string) ($phpmailer->From ?? ''),
            'sender'          => (string) ($phpmailer->Sender ?? ''),
            'from_name'       => (string) ($phpmailer->FromName ?? '')
        ];

        /**
         * Fires after the mailer has been fully configured for a send, with diagnostic details
         * about the resolved connection and transport.
         *
         * @since 1.0.0
         *
         * @param array{
         *     connection_id: int, connection_name: string, connection_type: string, delivery_mode: string,
         *     mailer: string, host: string, port: int, smtp_auth: bool, encryption: string,
         *     from: string, sender: string, from_name: string
         * } $diagnostics Diagnostic snapshot of the resolved mailer configuration.
         */
        \do_action('boolean_smtp_mailer_resolved', $diagnostics);

        if (!\function_exists('error_log')) {
            return;
        }

        $settings         = $this->app->make(Settings::class);
        $logToPhpErrorLog = (bool) $settings->get('log_mailer_diagnostics');
        /**
         * Filters whether the resolved mailer diagnostics are written to the plugin log.
         *
         * Return false to suppress the log line for this send.
         *
         * @since 1.0.0
         *
         * @param bool                  $logToPhpErrorLog Whether to log the diagnostics; reflects the
         *                                                log_mailer_diagnostics setting by default.
         * @param array<string, mixed>  $diagnostics      Diagnostic snapshot that would be logged.
         * @return bool Whether to log the resolved mailer to the PHP error log.
         */
        $logToPhpErrorLog = (bool) \apply_filters('boolean_smtp_log_mailer_resolved_to_error_log', $logToPhpErrorLog, $diagnostics);

        if (!$logToPhpErrorLog) {
            return;
        }

        $this->logger->info('Mailer resolved', $diagnostics);
    }

    /**
     * Record a message as simulated instead of sending it, and point PHPMailer at a local
     * discard endpoint as a safety net in case a caller sends anyway.
     *
     * @since 1.0.0
     *
     * @param  \PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer instance carrying the message to capture.
     * @return void
     */
    protected function captureSimulation(\PHPMailer\PHPMailer\PHPMailer $phpmailer): void {
        $to = $phpmailer->getToAddresses()[0][0] ?? '';

        $this->app->make(SimulationService::class)->capture([
            'to'         => $to,
            'from_email' => $phpmailer->From,
            'from_name'  => $phpmailer->FromName,
            'subject'    => $phpmailer->Subject,
            'body'       => $phpmailer->Body,
            'headers'    => []
        ]);

        $phpmailer->Mailer   = 'smtp';
        $phpmailer->Host     = 'localhost';
        $phpmailer->Port     = 1025;
        $phpmailer->SMTPAuth = false;
    }

    /**
     * Record a pending email log entry for an SMTP-mode send, ahead of it actually being sent.
     * The row is finalized to "delivered" or "failed" later by {@see EmailLogSendLifecycle}.
     *
     * @since 1.0.0
     *
     * @param  \PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer instance carrying the message to log.
     * @param  Connection                      $connection Connection the message is being sent through.
     * @param  array<string, string>|null       $headers    Custom headers to record, as returned by the
     *                                                      `boolean_smtp_email_headers` filter; read from the mailer when null.
     * @return bool Whether a log entry was written or updated.
     */
    protected function logEmail(
        \PHPMailer\PHPMailer\PHPMailer $phpmailer,
        Connection $connection,
        ?array $headers = null
    ): bool {
        $settings = $this->app->make(Settings::class);
        if ($settings->get('simulation_enabled')) {
            return false;
        }

        $logRepo = $this->app->make(EmailLogRepository::class);

        $toAddresses = $phpmailer->getToAddresses();
        $to          = $toAddresses[0][0] ?? '';

        $ccAddresses = $phpmailer->getCcAddresses();
        $cc          = implode(', ', array_map(fn($a) => $a[0], $ccAddresses));

        $bccAddresses = $phpmailer->getBccAddresses();
        $bcc          = implode(', ', array_map(fn($a) => $a[0], $bccAddresses));

        if ($headers === null) {
            $headers = [];
            foreach ($phpmailer->getCustomHeaders() as $header) {
                $headers[$header[0]] = $header[1];
            }
        }

        $source = $this->resolveLogSourceForAttempt();
        if (! $this->shouldLogEmail([
            'to'         => $to,
            'cc'         => $cc,
            'bcc'        => $bcc,
            'from_email' => (string) $phpmailer->From,
            'from_name'  => (string) $phpmailer->FromName,
            'subject'    => (string) $phpmailer->Subject,
            'headers'    => $headers,
            'source'     => $source,
        ], $connection)) {
            return false;
        }

        $attemptRow = [
            'timestamp'     => time(),
            'date'          => gmdate('Y-m-d H:i:s'),
            'status'        => 'pending',
            'connection_id' => (int) $connection->id,
            'provider'      => (string) $connection->driver,
            'source'        => $source
        ];

        $data = [
            'to'            => $to,
            'cc'            => $cc ?: null,
            'bcc'           => $bcc ?: null,
            'from_email'    => $phpmailer->From,
            'from_name'     => $phpmailer->FromName,
            'subject'       => $phpmailer->Subject,
            'body'          => $this->isBodyLoggingAllowed() ? $phpmailer->Body : null,
            'headers'       => $headers,
            'attachments'   => $this->extractAttachmentMeta($phpmailer),
            'status'        => 'pending',
            'provider'      => $connection->driver,
            'connection_id' => $connection->id,
            'source'        => $source
        ];

        if ($this->forcedLogId) {
            $log = $logRepo->find($this->forcedLogId);
            if ($log) {
                $log->refresh();

                $attempts   = $log->attempts ?: [];
                $attempts[] = $attemptRow;

                // A forced row (a queued message, a resend, the fallback retry) already holds the
                // message as it was requested; only the attempt is new. Its recipients, body,
                // headers and source stay as stored — a retry is sent from them.
                foreach (['to', 'cc', 'bcc', 'subject', 'body', 'headers', 'source'] as $field) {
                    unset($data[$field]);
                }
                if ($log->headers === null || $log->headers === []) {
                    $data['headers'] = $headers;
                }

                $data['attempts'] = $attempts;
                $data['status']   = 'pending';
                $log->update($data);
                $this->pendingLogIdForCurrentSend = (int) $log->id;
                $this->clearForcedLogId();

                return false;
            }
        }

        $data['attempts']                 = [$attemptRow];
        $created                          = $logRepo->create($data);
        $this->pendingLogIdForCurrentSend = (int) $created->id;

        return true;
    }

    /**
     * Append one delivery attempt to a log row — updating the log a resend is
     * forced onto (via {@see setForcedLogId()}), or creating a fresh row seeded
     * with just this attempt otherwise. Used by API-mode delivery ({@see
     * \BooleanSmtp\Services\Mailer\ApiMailDispatcher}), which — unlike {@see
     * logEmail()}'s SMTP path — already knows the final status by the time it
     * logs, so there's no separate "pending" phase to finalize later.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $data Log fields (to/cc/bcc/subject/body/headers/attachments/status/provider/connection_id/source/error_message) — must NOT include 'attempts'.
     * @param array<string, mixed> $attemptRow One attempts[] entry (timestamp/date/status/connection_id/provider/source/error?).
     * @return void
     */
    public function persistLogAttempt(array $data, array $attemptRow): void {
        $logRepo = $this->app->make(EmailLogRepository::class);

        // Callers of persistLogAttempt() (currently only ApiMailDispatcher) always
        // supply fully-parsed headers up front, unlike logEmail()'s SMTP path.
        $this->pendingLogHeadersCaptured = true;

        if ($this->forcedLogId) {
            $log = $logRepo->find($this->forcedLogId);
            if ($log) {
                $log->refresh();

                $attempts   = $log->attempts ?: [];
                $attempts[] = $attemptRow;

                $data['attempts'] = $attempts;
                $log->update($data);
                $this->pendingLogIdForCurrentSend = (int) $log->id;
                $this->clearForcedLogId();

                return;
            }
        }

        $data['attempts']                 = [$attemptRow];
        $created                          = $logRepo->create($data);
        $this->pendingLogIdForCurrentSend = (int) $created->id;
    }

    /**
     * Identify the source (plugin/theme) that initiated the email.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function captureEmailSource(): ?string {
        return $this->captureSource();
    }

    /**
     * Whether the message body is stored with a log entry. Bodies are what Preview and Resend
     * work from, so they are stored unless the `boolean_smtp_should_store_message_body` filter
     * says otherwise.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isBodyLoggingAllowed(): bool {
        /**
         * Filters whether a message body is stored with its email log entry.
         *
         * Return false to keep bodies out of the log (recipients, subject and delivery details
         * are still logged); Preview and Resend then have no body to work from.
         *
         * @since 1.0.0
         *
         * @param bool $store Whether to store the body. Default true.
         * @return bool Whether to store the body.
         */
        return (bool) \apply_filters('boolean_smtp_should_store_message_body', true);
    }

    /**
     * Lightweight attachment metadata (name, MIME type, size) for a log entry —
     * never the file content itself. PHPMailer::getAttachments() returns tuples
     * shaped `[path, filename, name, encoding, type, isStringAttachment, ...]`;
     * see {@see \PHPMailer\PHPMailer\PHPMailer::addAttachment()}.
     *
     * @since 1.0.0
     *
     * @param  \PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer instance whose attachments should be summarized.
     * @return array<int, array{name: string, type: ?string, size: ?int}>
     */
    public function extractAttachmentMeta(\PHPMailer\PHPMailer\PHPMailer $phpmailer): array {
        $meta = [];

        foreach ($phpmailer->getAttachments() as $attachment) {
            $path               = $attachment[0] ?? null;
            $name               = (string) ($attachment[2] ?? ($attachment[1] ?? ''));
            $type               = $attachment[4] ?? null;
            $isStringAttachment = (bool) ($attachment[5] ?? false);

            $size = null;
            if ($isStringAttachment) {
                if (is_string($path)) {
                    $size = strlen($path);
                }
            } elseif (is_string($path) && is_readable($path)) {
                $bytes = @filesize($path);
                $size  = $bytes !== false ? $bytes : null;
            }

            $meta[] = [
                'name' => $name,
                'type' => $type !== null ? (string) $type : null,
                'size' => $size
            ];
        }

        return $meta;
    }

    /**
     * Parses a raw MIME message (or just its header block) — e.g. from
     * {@see \PHPMailer\PHPMailer\PHPMailer::getSentMIMEMessage()}, only safe to call after
     * `preSend()` has run — into a flat name => value map for the "Raw Headers" log view.
     * Folded (continuation) header lines are joined back onto the preceding header.
     *
     * @since 1.0.0
     *
     * @param  string $rawMime Raw MIME message, or just its header block.
     * @return array<string, string>
     */
    public static function parseRawHeaders(string $rawMime): array {
        $separator = "\r\n\r\n";
        $pos       = strpos($rawMime, $separator);
        if ($pos === false) {
            $separator = "\n\n";
            $pos       = strpos($rawMime, $separator);
        }

        $headerBlock = $pos === false ? $rawMime : substr($rawMime, 0, $pos);
        $lines       = preg_split('/\r\n|\n/', $headerBlock) ?: [];

        $headers = [];
        $lastKey = null;
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if (($line[0] === ' ' || $line[0] === "\t") && $lastKey !== null) {
                $headers[$lastKey] .= ' ' . trim($line);
                continue;
            }

            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            [$name, $value]  = $parts;
            $name            = trim($name);
            $headers[$name]  = trim($value);
            $lastKey         = $name;
        }

        return $headers;
    }

    /**
     * Whether a message is written to the email log, as decided by the
     * `boolean_smtp_should_log_email` filter. Runs after the global logging setting has allowed
     * the message.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $mailData   The message as the filter documents it.
     * @param  Connection|null      $connection Connection the message is sent through, when known.
     * @return bool
     */
    public function shouldLogEmail(array $mailData, ?Connection $connection): bool {
        // A queued message, a resend or a fallback retry updates the log entry it already has.
        if ($this->forcedLogId) {
            return true;
        }

        /**
         * Filters whether a message is written to the email log.
         *
         * Runs after the global logging setting has allowed the message, immediately before its
         * log entry would be created. Return `false` to keep this message out of the log — a
         * newsletter, a message with personal data, a high-volume transactional stream, or
         * everything a given plugin sends. Not consulted for a
         * queued message, a resend or a fallback retry: their log entry already exists and
         * records the attempt.
         *
         * @since 1.0.0
         *
         * @param  bool            $log        Whether to log the message. Default `true`.
         * @param  array<string, mixed> $mail_data  The message: `to`, `cc`, `bcc`, `from_email`, `from_name`, `subject`, `headers` (name => value) and `source` (the plugin or theme that sent it, when known).
         * @param  \BooleanSmtp\Models\Connection|null $connection The connection the message is sent through, `null` when it is not known yet.
         * @return bool Whether to log the message.
         */
        return (bool) \apply_filters('boolean_smtp_should_log_email', true, $mailData, $connection);
    }

    /**
     * Uses {@see $attemptSourceOverride} once when set (e.g. BooleanSMTP UI resend), else backtrace.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function resolveLogSourceForAttempt(): string {
        if ($this->attemptSourceOverride !== null) {
            $label                       = $this->attemptSourceOverride;
            $this->attemptSourceOverride = null;

            return $label;
        }

        return $this->captureSource() ?? 'Unknown';
    }

    /**
     * Identify the plugin, theme, or wp-content location that appears to have triggered the
     * current email by walking the call stack and skipping WordPress core and BooleanSMTP itself.
     *
     * @since 1.0.0
     *
     * @return string|null Source label ("Plugin: slug", "Theme: slug", "WP Content/...", or "Unknown").
     */
    protected function captureSource(): ?string {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- names the plugin or theme that sent the email, shown in the email log's Source column.
        $source    = 'Unknown';

        foreach ($backtrace as $step) {
            if (!isset($step['file'])) {
                continue;
            }

            $file = wp_normalize_path($step['file']);

            // Skip WordPress core
            if (str_contains($file, '/wp-includes/') || str_contains($file, '/wp-admin/')) {
                continue;
            }

            // Skip BooleanSMTP itself
            if (str_contains($file, '/plugins/boolean-smtp/')) {
                continue;
            }

            // Identify Plugins
            if (preg_match('/\/plugins\/([^\/]+)\//', $file, $matches)) {
                return 'Plugin: ' . $matches[1];
            }

            // Identify Themes
            if (preg_match('/\/themes\/([^\/]+)\//', $file, $matches)) {
                return 'Theme: ' . $matches[1];
            }

            // If it's a file in WP_CONTENT but not plugin/theme (e.g. mu-plugins)
            if (str_contains($file, '/wp-content/')) {
                $rel = str_replace(wp_normalize_path(WP_CONTENT_DIR), '', $file);
                return 'WP Content' . $rel;
            }
        }

        return $source;
    }

    /**
     * Single-use override of the `auto_plain_text` setting, set via {@see forceAutoPlainTextForNextSend()}.
     *
     * @since 1.0.0
     *
     * @param  Settings $settings Settings source for the site-wide auto_plain_text default.
     * @return bool
     */
    protected function resolveAutoPlainText(Settings $settings): bool {
        $forced                    = $this->forcedAutoPlainText;
        $this->forcedAutoPlainText = null;

        return $forced ?? (bool) $settings->get('auto_plain_text');
    }

    /**
     * Generate a plain-text alternative body from an HTML message.
     *
     * @since 1.0.0
     *
     * @param  string $html HTML message body.
     * @return string Plain-text rendering of the HTML body.
     */
    protected function generatePlainText(string $html): string {
        $text = $html;
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/p>/i', "\n\n", $text);
        $text = preg_replace('/<\/h[1-6]>/i', "\n\n", $text);
        $text = preg_replace('/<\/li>/i', "\n", $text);
        $text = preg_replace('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>([^<]+)<\/a>/i', '$2 ($1)', $text);
        $text = \wp_strip_all_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);

        return trim($text);
    }

    /**
     * Seed the transport registry from config('mail.transports'), falling back to the built-in
     * php and smtp transports when no configuration is present.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerDefaultTransports(): void {
        $configTransports = config('mail.transports', []);

        foreach ($configTransports as $driver => $class) {
            if (class_exists($class)) {
                $this->transports[$driver] = $class;
            }
        }

        if (empty($this->transports)) {
            $this->transports = [
                'php'  => Transports\PhpMailTransport::class,
                'smtp' => Transports\SmtpTransport::class
            ];
        }
    }

    /**
     * Whether the SMTP transcript of a send is stored as a debug session file.
     *
     * The connection's "SMTP Debug Level" sets how much PHPMailer reports; nothing is written to
     * disk unless the `boolean_smtp_should_store_smtp_transcript` filter allows it.
     *
     * @since 1.0.0
     *
     * @param  Connection $connection Connection the message is sent through.
     * @param  int        $level      The connection's SMTP debug level (1–4).
     * @return bool
     */
    protected function shouldStoreSmtpTranscript(Connection $connection, int $level): bool {
        /**
         * Filters whether the SMTP transcript of a send is stored as a debug session file.
         *
         * Consulted for every SMTP send through a connection whose "SMTP Debug Level" is above
         * 0. Off by default: the level only shapes the live diagnostics the Test Email screen shows.
         * Return `true` to keep the transcript (credentials hidden) under
         * `wp-content/uploads/boolean-smtp/debug-sessions/`, one file per email log entry, for
         * seven days; read them with `GET tools/debug-logs` or the log entry's debug session.
         *
         * @since 1.0.0
         *
         * @param  bool                           $store      Whether to store the transcript. Default `false`.
         * @param  \BooleanSmtp\Models\Connection $connection The connection the message is sent through.
         * @param  int                            $level      The connection's SMTP debug level (1–4).
         * @return bool Whether to store the transcript.
         */
        return (bool) \apply_filters('boolean_smtp_should_store_smtp_transcript', false, $connection, $level);
    }

    /**
     * Finalize and persist debug logs captured during the last send operation.
     * Called from email lifecycle hooks (wp_mail_succeeded, wp_mail_failed) to store debug transcripts.
     * Safe to call multiple times; logs are only persisted once.
     *
     * @since 1.0.0
     *
     * @param ?int $emailLogId Override email_log_id if known (updates pending log reference)
     * @return int Count of debug log entries persisted
     */
    public function finalizeDebugCapture(?int $emailLogId = null): int {
        if ($emailLogId !== null) {
            // Update pending log reference before persisting
            $this->pendingLogIdForCurrentSend = $emailLogId;
        }

        // The capture started before the log entry existed; hand the debugger the id it belongs to.
        return $this->debugger->stopCaptureAndPersist($this->pendingLogIdForCurrentSend);
    }
}
