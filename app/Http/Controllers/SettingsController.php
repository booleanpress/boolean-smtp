<?php

/**
 * REST controller for reading and updating the plugin's global settings.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Http\Controllers;

use BooleanSmtp\Core\Http\Controller;
use BooleanSmtp\Core\Http\JsonResponse;
use BooleanSmtp\Core\Http\Request;
use BooleanSmtp\Http\Requests\UpdateSettingsRequest;
use BooleanSmtp\Jobs\HealthCheckJob;
use BooleanSmtp\Jobs\OAuthRefreshJob;
use BooleanSmtp\Support\Settings;

/**
 * Exposes the plugin-wide settings record as a REST resource.
 *
 * @since 1.0.0
 */
class SettingsController extends Controller {
    /**
     * @since 1.0.0
     *
     * @param Settings $settings Repository backing the `booleanpress_options` settings record.
     */
    public function __construct(
        protected Settings $settings
    ) {}

    /**
     * Handle `GET /booleansmtp/v1/settings`.
     *
     * Returns every stored setting except the encryption key, which never leaves the server.
     *
     * @since 1.0.0
     *
     * @param Request $request Unused; every stored setting is returned.
     * @return JsonResponse All settings key/value pairs.
     */
    public function index(Request $request): JsonResponse {
        $all = $this->settings->all();

        unset($all['encryption_key']);

        return $this->ok($all);
    }

    /**
     * Handle `PUT /booleansmtp/v1/settings`.
     *
     * Reads the sender, routing, retry, logging, health-check and OAuth-refresh settings from the
     * request body and persists them as one record. Resyncs the health-check and OAuth-refresh
     * WordPress cron schedules afterward so a changed interval takes effect immediately.
     *
     * @since 1.0.0
     *
     * @param UpdateSettingsRequest $request Request carrying the sender, routing, retry, logging, health-check
     *                          and OAuth-refresh settings fields.
     * @return JsonResponse The updated settings (without the encryption key) on success, or a 500 error
     *                      when the save fails.
     */
    public function update(UpdateSettingsRequest $request): JsonResponse {
        $data = $request->validated();

        if (!$this->settings->update($data)) {
            return $this->error('Failed to save settings. Please check database connection and try again.', 500);
        }

        $this->make(HealthCheckJob::class)->sync();
        $this->make(OAuthRefreshJob::class)->sync();

        $all = $this->settings->all();

        unset($all['encryption_key']);

        return $this->ok($all, 'Settings updated.');
    }
}
