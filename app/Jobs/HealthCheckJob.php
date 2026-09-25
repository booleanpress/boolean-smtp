<?php
/**
 * Probes every active connection's health on a schedule and alerts on failure.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Jobs;

use BooleanSmtp\Adapters\Contracts\ScheduleAdapterContract;
use BooleanSmtp\Contracts\EncryptorContract;
use BooleanSmtp\Contracts\Editions\HealthAlertContract;
use BooleanSmtp\Core\Foundation\Application;
use BooleanSmtp\Models\Connection;
use BooleanSmtp\Core\Contracts\LoggerContract;
use BooleanSmtp\Repositories\ConnectionRepository;
use BooleanSmtp\Support\Settings;
use BooleanSmtp\Services\Connection\ConnectionHealthProbe;

/**
 * Runs on the `boolean_smtp_health_check` WP-Cron event (schedule `boolean_smtp_health`,
 * interval configured by the `health_check_interval` setting; see
 * {@see \BooleanSmtp\Providers\AppServiceProvider::registerHealthCheckCronSchedule()}) to
 * probe every active connection and record its health status.
 *
 * Resolve it from the container; every collaborator is injected.
 *
 * @since 1.0.0
 */
class HealthCheckJob {
    /**
     * WP-Cron hook the job runs on.
     *
     * @since 1.0.0
     * @var string
     */
    public const HOOK = 'boolean_smtp_health_check';

    /**
     * WP-Cron schedule name (registered from the `health_check_interval` setting).
     *
     * @since 1.0.0
     * @var string
     */
    public const SCHEDULE = 'boolean_smtp_health';

    /**
     * @since 1.0.0
     *
     * @param ConnectionRepository    $connections   Active connections and their health status.
     * @param Settings      $settings      Plugin settings (`health_check_enabled`).
     * @param ScheduleAdapterContract $scheduler     WP-Cron access.
     * @param EncryptorContract       $encryptor     Decrypts connection settings before probing.
     * @param ConnectionHealthProbe   $probe         Performs the connection health probe.
     * @param Application             $app           Container; the health-alert policy is resolved from it when an alert is due.
     * @param LoggerContract          $logger        Records an alert that could not be sent.
     */
    public function __construct(
        private readonly ConnectionRepository $connections,
        private readonly Settings $settings,
        private readonly ScheduleAdapterContract $scheduler,
        private readonly EncryptorContract $encryptor,
        private readonly ConnectionHealthProbe $probe,
        private readonly Application $app,
        private readonly LoggerContract $logger,
    ) {}

    /**
     * Clear and reschedule the `boolean_smtp_health_check` WP-Cron event.
     *
     * Used after a settings change or plugin activation so the schedule reflects the
     * current `health_check_enabled` setting. Leaves nothing scheduled when health checks
     * are disabled.
     *
     * @since 1.0.0
     */
    public function sync(): void {
        $this->scheduler->clearScheduledHook(self::HOOK);

        if (!$this->settings->get('health_check_enabled')) {
            return;
        }

        $this->scheduler->scheduleEvent(\time() + 60, self::SCHEDULE, self::HOOK);
    }

    /**
     * Ensure the `boolean_smtp_health_check` WP-Cron event is scheduled when health checks
     * are enabled, and cleared when they are not.
     *
     * Called on WordPress `init` so a missing schedule (a fresh install, or one where
     * WP-Cron previously lost the event) self-heals without requiring a settings save.
     * Does nothing if an event is already scheduled.
     *
     * @since 1.0.0
     */
    public function ensureScheduled(): void {
        if (!$this->settings->get('health_check_enabled')) {
            $this->scheduler->clearScheduledHook(self::HOOK);

            return;
        }

        if ($this->scheduler->nextScheduled(self::HOOK) !== false) {
            return;
        }

        $this->scheduler->scheduleEvent(\time() + 60, self::SCHEDULE, self::HOOK);
    }

    /**
     * Probe every active connection and record its health status.
     *
     * For each unhealthy connection, sends the alert the site's health-alert policy
     * ({@see HealthAlertContract}) chooses. Alert delivery failures are swallowed so they
     * cannot break the health check run.
     *
     * @since 1.0.0
     */
    public function handle(): void {
        foreach ($this->connections->active() as $connection) {
            $result = $this->checkConnection($connection);

            $this->connections->updateHealthStatus(
                $connection->id,
                $result['healthy'] ? 'healthy' : 'error',
                $result['error'] ?? null
            );

            /**
             * Fires after the scheduled health check has probed one connection and recorded
             * the outcome on it.
             *
             * Fires for healthy and unhealthy connections alike, so a monitoring integration
             * can track every check; the plugin's own alert channels only react to failures.
             *
             * @since 1.0.0
             *
             * @param \BooleanSmtp\Models\Connection $connection The probed connection, with its health status updated.
             * @param bool                            $healthy    Whether the probe succeeded.
             * @param string|null                     $error      The probe's error message, `null` when healthy.
             */
            \do_action('boolean_smtp_health_check_result', $connection, (bool) $result['healthy'], $result['error'] ?? null);

            if ($result['healthy']) {
                continue;
            }

            try {
                $alertData = [
                    'connection_id'   => $connection->id,
                    'connection_name' => $connection->name,
                    'driver'          => $connection->driver,
                ];

                $this->app->make(HealthAlertContract::class)->unhealthy($alertData);
            } catch (\Throwable $e) {
                // Alert delivery failure must not break the health check run.
                $this->logger->warning('Connection health alert could not be sent.', [
                    'connection_id' => (int) $connection->id,
                    'error'         => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Decrypt a connection's settings and run its health probe.
     *
     * @since 1.0.0
     *
     * @param  Connection $connection Connection to probe.
     * @return array{healthy: bool, error?: string} Probe outcome; `error` is present only
     *         when `healthy` is false.
     */
    private function checkConnection(Connection $connection): array {
        try {
            $settings  = $connection->settings ?? [];
            $decrypted = is_array($settings) ? $this->encryptor->decryptArray($settings) : [];

            $result = $this->probe->probe($connection, $decrypted, false);

            if (!$result['healthy']) {
                return ['healthy' => false, 'error' => $result['error'] ?? 'Health check failed'];
            }

            return ['healthy' => true];
        } catch (\Throwable $e) {
            return ['healthy' => false, 'error' => $e->getMessage()];
        }
    }
}
