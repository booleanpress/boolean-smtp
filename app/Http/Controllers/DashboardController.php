<?php

/**
 * REST controller for the plugin's dashboard: summary stats, charts, recent failures, and onboarding.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Http\Controllers;

use BooleanSmtp\Core\Http\Controller;
use BooleanSmtp\Core\Http\JsonResponse;
use BooleanSmtp\Core\Http\Request;
use BooleanSmtp\Contracts\EncryptorContract;
use BooleanSmtp\Http\Requests\ApplyOnboardingRequest;
use BooleanSmtp\Http\Requests\UpdateOnboardingRequest;
use BooleanSmtp\Models\Connection;
use BooleanSmtp\Repositories\ConnectionRepository;
use BooleanSmtp\Repositories\EmailLogRepository;
use BooleanSmtp\Services\Onboarding\OnboardingApplyService;
use BooleanSmtp\Services\Onboarding\OnboardingState;
use BooleanSmtp\Support\Settings;
use BooleanSmtp\Support\ReportPeriod;

/**
 * Aggregates email and connection data for the admin dashboard screen.
 *
 * @since 1.0.0
 */
class DashboardController extends Controller
{
    /**
     * @since 1.0.0
     *
     * @param EmailLogRepository   $logs        Reads email log statistics.
     * @param ConnectionRepository $connections Reads configured connections.
     * @param EncryptorContract    $encryptor   Decrypts connection settings to read the sender address.
     * @param Settings   $settings    Reads the default/fallback connection settings.
     */
    public function __construct(
        protected EmailLogRepository $logs,
        protected ConnectionRepository $connections,
        protected EncryptorContract $encryptor,
        protected Settings $settings
    ) {}

    /**
     * Handle `GET /booleansmtp/v1/dashboard/stats`.
     *
     * Reads a report period from the query string (see {@see ReportPeriod::fromQuery()}) and
     * returns email volume statistics, an activity heatmap, connection counts, and a summary of
     * the primary and fallback connections for that period.
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying the report-period query parameters (see
     *                          {@see ReportPeriod::fromQuery()}).
     * @return JsonResponse Period, email stats, heatmap, connection counts, and connection summaries.
     */
    public function stats(Request $request): JsonResponse
    {
        $period = ReportPeriod::fromQuery($request->all());

        $emailStats = $this->logs->getStats($period);

        $connections     = $this->connections->all();
        $activeCount     = $connections->filter(fn($c) => $c->is_active)->count();
        $healthyCount    = $connections->filter(fn($c) => $c->health_status === 'healthy')->count();

        $primaryConnection = $this->connections->getDefaultOrPrimary(
            $this->connectionIdFromSetting($this->settings->get('default_connection_id'))
        );
        $fallbackConnection = $this->fallbackConnection();
        $heatmap           = $this->logs->getActivityHeatmap($period);

        return $this->ok([
            'period'      => [
                'from' => $period->start->format('Y-m-d'),
                'to'   => $period->end->format('Y-m-d'),
                'days' => $period->days(),
            ],
            'email'       => $emailStats,
            'heatmap'     => $heatmap,
            'connections'  => [
                'total'   => $connections->count(),
                'active'  => $activeCount,
                'healthy' => $healthyCount,
            ],
            'primary_connection' => $primaryConnection ? $this->connectionSummary($primaryConnection) : null,
            'fallback_connection' => $fallbackConnection ? $this->connectionSummary($fallbackConnection) : null,
        ]);
    }

    /**
     * Handle `GET /booleansmtp/v1/dashboard/chart`.
     *
     * Reads a report period from the query string and returns the volume chart data for it.
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying the report-period query parameters.
     * @return JsonResponse Chart series for the requested period.
     */
    public function chart(Request $request): JsonResponse
    {
        $chart = $this->logs->getVolumeChart(ReportPeriod::fromQuery($request->all()));

        return $this->ok($chart);
    }

    /**
     * Handle `GET /booleansmtp/v1/dashboard/activity`.
     *
     * Reads `status` (`failed`, the default, or `delivered`), `limit` (clamped to 1-10, default 5)
     * and the period (`from`/`to` or `days`, as the other dashboard endpoints).
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying `status`, `limit` and the period parameters.
     * @return JsonResponse The latest messages of that outcome in the period, newest first.
     */
    public function recentActivity(Request $request): JsonResponse
    {
        $status = $request->get('status') === 'delivered' ? 'delivered' : 'failed';
        $limit  = max(1, min(10, (int) $request->get('limit', 5)));

        return $this->ok($this->logs->getRecentActivity($status, ReportPeriod::fromQuery($request->all()), $limit));
    }

    /**
     * Resolve the configured fallback connection, when fallback is enabled.
     *
     * @since 1.0.0
     *
     * @return Connection|null The fallback connection, or null when fallback is disabled or unset.
     */
    private function fallbackConnection(): ?Connection
    {
        if (! $this->settings->get('fallback_enabled')) {
            return null;
        }

        $fallbackId = $this->connectionIdFromSetting($this->settings->get('fallback_connection_id'));

        return $fallbackId === null ? null : $this->connections->find($fallbackId);
    }

    /**
     * Normalize a stored connection id setting to a positive integer.
     *
     * @since 1.0.0
     *
     * @param mixed $value Raw setting value.
     * @return int|null The connection id, or null when the value is empty or not numeric.
     */
    private function connectionIdFromSetting(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    /**
     * Build the compact connection summary shown on the dashboard.
     *
     * @since 1.0.0
     *
     * @param Connection $connection Connection to summarize.
     * @return array{name: string, driver: string, health_status: string, sender: string}
     */
    private function connectionSummary(Connection $connection): array
    {
        return [
            'name'          => $connection->name,
            'driver'        => $connection->driver,
            'health_status' => $connection->health_status,
            'sender'        => $this->connectionSender($connection),
        ];
    }

    /**
     * Decrypt a connection's settings and read its sender address for display.
     *
     * @since 1.0.0
     *
     * @param Connection $connection Connection to read.
     * @return string The sender address, or an empty string when it cannot be read.
     */
    private function connectionSender(Connection $connection): string
    {
        $rawSettings = $connection->settings ?? [];
        if (!is_array($rawSettings)) {
            return '';
        }

        try {
            $settings = $this->encryptor->decryptArray($rawSettings);
        } catch (\Throwable) {
            return '';
        }

        foreach (['from_email', 'sender_email', 'email'] as $key) {
            $sender = trim((string) ($settings[$key] ?? ''));
            if ($sender !== '') {
                return $sender;
            }
        }

        return '';
    }

    /**
     * Handle `GET /booleansmtp/v1/dashboard/onboarding`.
     *
     * Returns the guided-onboarding progress record: every key of the contract, stored values
     * merged over the defaults.
     *
     * @since 1.0.0
     *
     * @param Request $request Unused; onboarding progress is read from stored settings.
     * @return JsonResponse The onboarding record (step flags, draft/applied connection ids, migration source).
     */
    public function getOnboarding(Request $request): JsonResponse
    {
        return $this->ok($this->make(OnboardingState::class)->all());
    }

    /**
     * Handle `POST /booleansmtp/v1/dashboard/onboarding`.
     *
     * Merges the `onboarding` object into the stored record. Unknown keys are dropped and every
     * value is cast to its key's type.
     *
     * @since 1.0.0
     *
     * @param UpdateOnboardingRequest $request Request carrying the `onboarding` object.
     * @return JsonResponse The saved onboarding record.
     */
    public function updateOnboarding(UpdateOnboardingRequest $request): JsonResponse
    {
        $data = $request->get('onboarding', []);

        $saved = $this->make(OnboardingState::class)->update(\is_array($data) ? $data : []);

        return $this->ok($saved, 'Onboarding progress updated.');
    }

    /**
     * Handle `POST /booleansmtp/v1/dashboard/onboarding/apply`.
     *
     * Activates the reviewed draft connection and stores the preferences chosen on the review
     * step: primary connection, log retention and, for the migration branch, the historical-log
     * import.
     *
     * @since 1.0.0
     *
     * @param ApplyOnboardingRequest $request Request carrying `connection_id`, `make_primary`,
     *                          `log_retention_days`, `import_logs` and `migration_source`.
     * @return JsonResponse The applied connection, what changed, and the onboarding record.
     */
    public function apply(ApplyOnboardingRequest $request): JsonResponse
    {
        $retention = $request->get('log_retention_days');
        $source    = trim((string) $request->get('migration_source', ''));

        $result = $this->make(OnboardingApplyService::class)->apply(
            (int) $request->get('connection_id'),
            (bool) $request->get('make_primary', true),
            $retention === null || $retention === '' ? null : (int) $retention,
            (bool) $request->get('import_logs', false),
            $source !== '' ? $source : null
        );

        return $this->ok($result, 'Setup applied.');
    }
}
