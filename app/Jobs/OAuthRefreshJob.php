<?php
/**
 * Refreshes OAuth access tokens for API-based connections on a schedule.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Jobs;

use BooleanSmtp\Adapters\Contracts\ScheduleAdapterContract;
use BooleanSmtp\Contracts\EncryptorContract;
use BooleanSmtp\Repositories\ConnectionRepository;
use BooleanSmtp\Repositories\OAuthRefreshLogRepository;
use BooleanSmtp\Support\Settings;
use BooleanSmtp\Services\Connection\ConnectionHealthProbe;
use BooleanSmtp\Services\OAuth\OAuthErrorClassifier;
use BooleanSmtp\Services\OAuth\OAuthProviderRegistry;
use BooleanSmtp\Services\OAuth\OAuthRefreshRetryPolicy;
use BooleanSmtp\Services\OAuth\TokenExpirationChecker;
use function BooleanSmtp\Core\class_basename;

/**
 * Runs on the `boolean_smtp_oauth_refresh` WP-Cron event (schedule
 * `boolean_smtp_oauth_refresh`, interval configured by the `oauth_refresh_interval`
 * setting; see
 * {@see \BooleanSmtp\Providers\AppServiceProvider::registerHealthCheckCronSchedule()}) to
 * preemptively refresh OAuth access tokens for every active, API-mode connection whose
 * driver supports OAuth.
 *
 * Resolve it from the container; every collaborator is injected.
 *
 * @since 1.0.0
 */
class OAuthRefreshJob {
    /**
     * WP-Cron hook and schedule name the job runs on.
     *
     * @since 1.0.0
     * @var string
     */
    public const HOOK = 'boolean_smtp_oauth_refresh';

    /**
     * @since 1.0.0
     *
     * @param ConnectionRepository    $connections Active connections whose tokens may need a refresh.
     * @param Settings      $settings    Plugin settings (`oauth_refresh_enabled`, retries).
     * @param ScheduleAdapterContract $scheduler   WP-Cron access.
     * @param EncryptorContract       $encryptor   Decrypts connection settings.
     * @param ConnectionHealthProbe   $probe       Performs the refresh through a connection probe.
     * @param OAuthRefreshLogRepository $refreshLogs Records every attempt for the history panel.
     */
    public function __construct(
        private readonly ConnectionRepository $connections,
        private readonly Settings $settings,
        private readonly ScheduleAdapterContract $scheduler,
        private readonly EncryptorContract $encryptor,
        private readonly ConnectionHealthProbe $probe,
        private readonly OAuthRefreshLogRepository $refreshLogs,
    ) {}

    /**
     * Clear and reschedule the `boolean_smtp_oauth_refresh` WP-Cron event.
     *
     * Used after a settings change or plugin activation so the schedule reflects the
     * current `oauth_refresh_enabled` setting. Leaves nothing scheduled when the refresh
     * is disabled.
     *
     * @since 1.0.0
     */
    public function sync(): void {
        $this->scheduler->clearScheduledHook(self::HOOK);

        if (!$this->settings->get('oauth_refresh_enabled', true)) {
            return;
        }

        $this->scheduler->scheduleEvent(\time() + 60, self::HOOK, self::HOOK);
    }

    /**
     * Ensure the `boolean_smtp_oauth_refresh` WP-Cron event is scheduled when OAuth refresh
     * is enabled, and cleared when it is not.
     *
     * Called on WordPress `init` so a missing schedule (a fresh install, or one where
     * WP-Cron previously lost the event) self-heals without requiring a settings save.
     * Does nothing if an event is already scheduled.
     *
     * @since 1.0.0
     */
    public function ensureScheduled(): void {
        if (!$this->settings->get('oauth_refresh_enabled', true)) {
            $this->scheduler->clearScheduledHook(self::HOOK);

            return;
        }

        if ($this->scheduler->nextScheduled(self::HOOK) !== false) {
            return;
        }

        $this->scheduler->scheduleEvent(\time() + 60, self::HOOK, self::HOOK);
    }

    /**
     * Refresh OAuth tokens that are expired or within the preemptive refresh window.
     *
     * Does nothing when the `oauth_refresh_enabled` setting is off. For each active
     * connection whose driver supports OAuth and whose delivery mode is `api`, refreshes
     * the token when {@see TokenExpirationChecker::isExpiredOrStale()} reports it is
     * expired or within its preemptive refresh threshold (5 minutes before expiration),
     * retrying through {@see OAuthRefreshRetryPolicy::executeWithRetry()} up to the
     * `oauth_refresh_max_retries` setting (default 3). Every attempt is logged via
     * {@see OAuthRefreshLogRepository::log()} and fires `boolean_smtp_oauth_refresh_success` or
     * `boolean_smtp_oauth_refresh_failed`. An exception for one connection is logged and
     * does not stop the remaining connections from being processed.
     *
     * @since 1.0.0
     */
    public function handle(): void {
        if (!$this->settings->get('oauth_refresh_enabled', true)) {
            return;
        }

        OAuthProviderRegistry::initialize();

        $connections  = $this->connections->active();
        $oauthDrivers = OAuthProviderRegistry::getOAuthRefreshDrivers();

        foreach ($connections as $connection) {
            try {
                $driver = (string) $connection->driver;

                if (!\in_array($driver, $oauthDrivers, true)) {
                    continue;
                }

                $rawSettings = $connection->settings ?? [];
                $decrypted   = \is_array($rawSettings) ? $this->encryptor->decryptArray($rawSettings) : [];

                // OAuth tokens are specific to the API delivery mode.
                if ((string) ($decrypted['delivery_mode'] ?? '') !== 'api') {
                    continue;
                }

                $expirationField = OAuthProviderRegistry::getExpirationField($driver);
                $expiresAt       = (int) ($decrypted[$expirationField] ?? 0);
                $tokenExpStr     = (string) $expiresAt;

                if (!TokenExpirationChecker::isExpiredOrStale($tokenExpStr)) {
                    continue;
                }

                $startTime = \microtime(true);
                $result    = OAuthRefreshRetryPolicy::executeWithRetry(
                    function () use ($connection, $decrypted) {
                        $probeResult = $this->probe->probe($connection, $decrypted, false, false);

                        if (($probeResult['healthy'] ?? false) === true) {
                            return true;
                        }

                        $errorCode = (string) ($probeResult['api_debug']['error_code'] ?? 'oauth_refresh_probe_failed');
                        $errorMsg  = (string) ($probeResult['error'] ?? 'OAuth refresh probe failed.');
                        return new \WP_Error($errorCode, $errorMsg, [
                            'api_debug' => $probeResult['api_debug'] ?? null
                        ]);
                    },
                    \max(1, (int) $this->settings->get('oauth_refresh_max_retries', 3))
                );

                $responseTime = (int) ((\microtime(true) - $startTime) * 1000);
                $provider     = $driver;

                if ($result['success']) {
                    // Reload the connection so the log reflects the token_expires_at value
                    // the refresh just persisted.
                    try {
                        $connection = $this->connections->findOrFail((int) $connection->id);
                    } catch (\Throwable) {
                        // Proceed with the pre-refresh connection; the log is still created.
                    }

                    $this->refreshLogs->log(
                        $connection->id,
                        $provider,
                        'success',
                        'refresh_successful',
                        '',
                        $responseTime,
                        $result['attempts']
                    );

                    $refreshedSettings = \is_array($connection->settings ?? null) ? $this->encryptor->decryptArray($connection->settings) : [];
                    $newExpiresAt      = (int) ($refreshedSettings[$expirationField] ?? 0);

                    /**
                     * Fires after an OAuth token refresh succeeds.
                     *
                     * Fired by the scheduled refresh job and by a manual refresh from the admin UI
                     * alike, with the same payload.
                     *
                     * @since 1.0.0
                     *
                     * @param array<string, mixed> $payload {
                     *     @type int      $connection_id           Id of the refreshed connection.
                     *     @type string   $driver                  Normalized OAuth driver (`google`, `outlook`).
                     *     @type int      $attempts                Number of attempts made.
                     *     @type int      $response_time_ms        Time the token exchange took, in milliseconds.
                     *     @type int|null $token_expires_at        Unix timestamp the new token expires at, null when unknown.
                     *     @type int|null $token_seconds_remaining Seconds remaining until expiry, null when unknown.
                     * }
                     */
                    \do_action('boolean_smtp_oauth_refresh_success', [
                        'connection_id'           => (int) $connection->id,
                        'driver'                  => $driver,
                        'attempts'                => (int) $result['attempts'],
                        'response_time_ms'        => $responseTime,
                        'token_expires_at'        => $newExpiresAt > 0 ? $newExpiresAt : null,
                        'token_seconds_remaining' => $newExpiresAt > 0 ? $newExpiresAt - \time() : null,
                    ]);
                } else {
                    $retryResult  = $result['result'] ?? null;
                    $rawErrorCode = $retryResult instanceof \WP_Error
                    ? (string) $retryResult->get_error_code()
                    : (string) ($result['lastError'] ?? 'unknown');
                    $rawErrorMsg = $retryResult instanceof \WP_Error
                    ? (string) $retryResult->get_error_message()
                    : (string) ($result['lastError'] ?? 'OAuth refresh failed.');
                    $classified = OAuthErrorClassifier::classify($rawErrorCode, $provider);

                    $this->refreshLogs->log(
                        $connection->id,
                        $provider,
                        'failed',
                        $classified['code'],
                        $rawErrorMsg,
                        $responseTime,
                        $result['attempts']
                    );

                    /**
                     * Fires after an OAuth token refresh fails, once retries are exhausted.
                     *
                     * Listened to by {@see \BooleanSmtp\Providers\MailServiceProvider::onOAuthRefreshFailed()}
                     * to send a failure notification.
                     *
                     * @since 1.0.0
                     *
                     * @param array{
                     *     connection_id: int, driver: string, error_code: string,
                     *     error_message: string, attempts: int, response_time_ms: int, recoverable: bool
                     * } $data Failure details.
                     */
                    \do_action('boolean_smtp_oauth_refresh_failed', [
                        'connection_id'    => $connection->id,
                        'driver'           => $driver,
                        'error_code'       => $classified['code'],
                        'error_message'    => $rawErrorMsg,
                        'attempts'         => $result['attempts'],
                        'response_time_ms' => $responseTime,
                        'recoverable'      => $classified['recoverable'] ?? false
                    ]);
                }
            } catch (\Throwable $e) {
                try {
                    $errorCode  = 'exception_' . \strtolower(class_basename($e));
                    $classified = OAuthErrorClassifier::classify($errorCode, (string) ($connection->driver ?? 'unknown'));

                    $this->refreshLogs->log(
                        $connection->id ?? 0,
                        (string) ($connection->driver ?? 'unknown'),
                        'failed',
                        $classified['code'],
                        $e->getMessage(),
                        0,
                        0
                    );
                } catch (\Throwable) {
                    // Logging failure here must not surface as an uncaught exception.
                }
                continue;
            }
        }
    }
}
