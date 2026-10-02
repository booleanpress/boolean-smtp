<?php

/**
 * REST controller for the alert setup of each provider (Telegram, Slack, Discord).
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Http\Controllers;

use BooleanSmtp\Core\Http\Controller;
use BooleanSmtp\Core\Http\JsonResponse;
use BooleanSmtp\Core\Http\Request;
use BooleanSmtp\Http\Requests\SaveNotificationChannelRequest;
use BooleanSmtp\Models\NotificationChannel;
use BooleanSmtp\Repositories\NotificationChannelRepository;
use BooleanSmtp\Services\Notification\Channels\TelegramChannel;
use BooleanSmtp\Services\Notification\NotificationManager;

/**
 * Each alert provider has one setup: read them all, save or remove one, and send a test through
 * one. Every route is keyed by the provider type. A setup's secrets (webhook URLs, bot tokens)
 * leave the server only with their last four characters showing, and a secret sent back that way
 * keeps the saved value.
 *
 * @since 1.0.0
 */
class NotificationController extends Controller
{
    /**
     * Handle `GET /booleansmtp/v1/notifications`.
     *
     * @since 1.0.0
     *
     * @param Request $request Unused.
     * @return JsonResponse `available` (each provider's name and settings schema, keyed by type) and
     *                      `channels` (each saved setup, secrets hidden, keyed by type).
     */
    public function index(Request $request): JsonResponse
    {
        $manager   = $this->make(NotificationManager::class);
        $available = $manager->getAvailableChannels();

        $channels = [];
        foreach ($this->make(NotificationChannelRepository::class)->all() as $type => $setup) {
            if (isset($available[$type])) {
                $channels[$type] = $this->present($manager, $setup);
            }
        }

        return $this->ok([
            'available' => $available,
            'channels'  => (object) $channels,
        ]);
    }

    /**
     * Handle `PUT /booleansmtp/v1/notifications/{type}`.
     *
     * Creates the provider's setup or updates it. `settings` are validated by the provider and
     * replace the saved ones, except a secret sent back hidden, which keeps its saved value;
     * `is_active` alone turns a saved setup on or off.
     *
     * @since 1.0.0
     *
     * @param SaveNotificationChannelRequest $request Request carrying the `type` route parameter and
     *                                                `settings` and/or `is_active`.
     * @return JsonResponse The saved setup, secrets hidden; 404 for an unknown provider, 422 for
     *                      invalid settings.
     */
    public function update(SaveNotificationChannelRequest $request): JsonResponse
    {
        $type    = (string) $request->param('type');
        $manager = $this->make(NotificationManager::class);
        if (!$manager->isAvailable($type)) {
            return $this->notFound('Unknown alert provider.');
        }

        $repo     = $this->make(NotificationChannelRepository::class);
        $saved    = $repo->find($type);
        $data     = $request->validated();
        $settings = $data['settings'] ?? null;

        if (is_array($settings)) {
            $settings         = $manager->restoreSecrets($type, $settings, $saved?->settings ?? []);
            $data['settings'] = $settings;

            $errors = $manager->validateSettings($type, $settings);
            if ($errors !== []) {
                return $this->validationError(array_map(static fn (string $message): array => [$message], $errors));
            }
        } elseif ($saved === null) {
            return $this->validationError(['settings' => ['Enter the settings for this provider.']]);
        }

        $setup = $repo->save($type, array_intersect_key($data, ['settings' => true, 'is_active' => true]));

        return $this->ok($this->present($manager, $setup), 'Alert settings saved.');
    }

    /**
     * Handle `DELETE /booleansmtp/v1/notifications/{type}`.
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying the `type` route parameter.
     * @return JsonResponse Confirmation; 404 when the provider is not set up.
     */
    public function destroy(Request $request): JsonResponse
    {
        $type = (string) $request->param('type');
        if (!$this->make(NotificationChannelRepository::class)->delete($type)) {
            return $this->notFound('This provider is not set up.');
        }

        return $this->ok(null, 'Alert provider disconnected.');
    }

    /**
     * Handle `POST /booleansmtp/v1/notifications/{type}/test`.
     *
     * Sends a test alert with the `settings` in the request, or with the saved setup when the
     * request carries none, so settings can be checked before they are saved. A secret sent back
     * hidden is tested with its saved value.
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying the `type` route parameter and optional `settings`.
     * @return JsonResponse The test result; 404 for an unknown provider, 422 when there is nothing to test.
     */
    public function test(Request $request): JsonResponse
    {
        $type    = (string) $request->param('type');
        $manager = $this->make(NotificationManager::class);
        if (!$manager->isAvailable($type)) {
            return $this->notFound('Unknown alert provider.');
        }

        $saved    = $this->make(NotificationChannelRepository::class)->find($type)?->settings ?? [];
        $settings = $request->get('settings');
        if (is_array($settings)) {
            $settings = $manager->restoreSecrets($type, $settings, $saved);
        } elseif ($saved !== []) {
            $settings = $saved;
        }
        if (!is_array($settings)) {
            return $this->validationError(['settings' => ['Enter the settings for this provider.']]);
        }

        $result = $manager->testChannel($type, $settings);

        return $this->ok($result, $result['success'] ? 'Test notification sent.' : 'Test failed.');
    }

    /**
     * Handle `POST /booleansmtp/v1/notifications/telegram/detect-chats`.
     *
     * Reads `bot_token` and asks the Telegram Bot API which chats the bot can currently message,
     * so the admin can pick a chat ID without looking it up manually. A token sent back hidden
     * uses the saved one.
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying `bot_token`.
     * @return JsonResponse The detected `chats`.
     */
    public function detectTelegramChats(Request $request): JsonResponse
    {
        $this->validate($request, [
            'bot_token' => 'required|string',
        ]);

        $saved    = $this->make(NotificationChannelRepository::class)->find('telegram')?->settings ?? [];
        $settings = $this->make(NotificationManager::class)
            ->restoreSecrets('telegram', ['bot_token' => (string) $request->get('bot_token')], $saved);

        $chats = $this->make(TelegramChannel::class)->detectChats((string) $settings['bot_token']);

        return $this->ok(['chats' => $chats]);
    }

    /**
     * A setup as the admin screen receives it, secrets hidden.
     *
     * @since 1.0.0
     *
     * @param  NotificationManager $manager Knows each provider's secret fields.
     * @param  NotificationChannel $setup   The saved setup.
     * @return array<string, mixed>
     */
    private function present(NotificationManager $manager, NotificationChannel $setup): array
    {
        $data             = $setup->toArray();
        $data['settings'] = $manager->maskSettings($setup->type, is_array($data['settings']) ? $data['settings'] : []);

        return $data;
    }
}
