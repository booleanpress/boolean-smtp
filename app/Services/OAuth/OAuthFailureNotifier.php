<?php

/**
 * Notification service for failed OAuth token refreshes.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\OAuth;

use BooleanSmtp\Core\Contracts\LoggerContract;
use BooleanSmtp\Repositories\ConnectionRepository;
use BooleanSmtp\Services\Notification\NotificationManager;

/**
 * Sends a normalized operational alert when an OAuth token refresh needs owner attention.
 *
 * @since 1.0.0
 */
final class OAuthFailureNotifier {
    /**
     * Creates the OAuth failure notifier.
     *
     * @since 1.0.0
     *
     * @param ConnectionRepository $connections   Loads the connection that failed to refresh.
     * @param NotificationManager  $notifications Delivers alerts through supported native channels.
     * @param LoggerContract       $logger        Records an alert that could not be built.
     */
    public function __construct(
        private readonly ConnectionRepository $connections,
        private readonly NotificationManager $notifications,
        private readonly LoggerContract $logger,
    ) {}

    /**
     * Notify supported native channels about a non-recoverable OAuth refresh failure.
     *
     * Legacy email and generic-webhook channel records are intentionally neither read nor
     * deleted here. The central notification manager resolves only currently supported Slack,
     * Discord, and Telegram drivers, retaining the user's stored legacy records untouched.
     * The raw provider message is not taken: it can contain provider-response or authorization
     * details, and the refresh job already records it in the connection's refresh history.
     *
     * @since 1.0.0
     *
     * @param  int     $connectionId Connection whose OAuth token failed to refresh.
     * @param  string  $driver       OAuth provider driver key (`google`, `outlook` or `zoho`).
     * @param  string  $errorCode    Standardized error code, as classified by
     *                               {@see OAuthErrorClassifier}.
     * @param  bool    $recoverable  Whether the error is expected to resolve on its own.
     * @return void
     */
    public function notifyFailure(
        int $connectionId,
        string $driver,
        string $errorCode,
        bool $recoverable = false
    ): void {
        if ($recoverable) {
            return;
        }

        try {
            $connection = $this->connections->findOrFail($connectionId);

            $this->notifications->notify('oauth_refresh_failure', 'OAuth refresh requires attention.', [
                'connection_id' => $connectionId,
                'operational'   => [
                    'kind'     => 'oauth_refresh_failure',
                    'severity' => 'error',
                    'facts'    => [
                        'Connection'    => (string) $connection->name,
                        'Provider'      => self::providerLabel($driver),
                        'Failure class' => self::errorLabel($errorCode),
                    ],
                ],
            ]);

            /**
             * Fires after an OAuth refresh failure has been handed to the supported notification channels.
             *
             * @since 1.0.0
             *
             * @param array<string, mixed> $event {
             *     Notification event data.
             *
             *     @type int    $connection_id Connection whose OAuth token failed to refresh.
             *     @type string $driver        OAuth provider driver key.
             *     @type string $error_code    Standardized error code.
             *     @type int    $timestamp     Unix timestamp (UTC) when notification dispatch started.
             * }
             */
            \do_action('boolean_smtp_oauth_failure_notified', [
                'connection_id' => $connectionId,
                'driver'        => $driver,
                'error_code'    => $errorCode,
                'timestamp'     => \time(),
            ]);
        } catch (\Throwable $e) {
            // A notification failure must not break the caller's underlying refresh job.
            $this->logger->warning('OAuth failure alert could not be built.', [
                'connection_id' => $connectionId,
                'error_code'    => $errorCode,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    /**
     * Returns the safe provider label displayed in an operational alert.
     *
     * @since 1.0.0
     *
     * @param  string $driver OAuth provider driver key.
     * @return string
     */
    private static function providerLabel(string $driver): string {
        return match ($driver) {
            'google'  => 'Gmail / Google',
            'outlook' => 'Microsoft Outlook / Office 365',
            'zoho'    => 'Zoho Mail',
            default   => \ucfirst($driver),
        };
    }

    /**
     * Maps a standardized OAuth error code to a safe human-readable failure class.
     *
     * @since 1.0.0
     *
     * @param  string $errorCode Standardized error code.
     * @return string Human-readable failure class.
     */
    private static function errorLabel(string $errorCode): string {
        return match ($errorCode) {
            'oauth_refresh_expired'         => 'Token expired',
            'oauth_refresh_invalid_grant'   => 'Invalid grant',
            'oauth_refresh_rate_limit'      => 'Rate limited',
            'oauth_refresh_network_timeout' => 'Network timeout',
            'oauth_refresh_provider_error'  => 'Provider error',
            default                         => 'Unknown refresh error',
        };
    }
}
