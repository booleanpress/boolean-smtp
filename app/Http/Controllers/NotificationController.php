<?php

/**
 * REST controller managing internal notification channels (Slack, Telegram, webhooks, etc.).
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Http\Controllers;

use BooleanSmtp\Core\Http\Controller;
use BooleanSmtp\Core\Http\Request;
use BooleanSmtp\Http\Requests\UpdateNotificationChannelRequest;
use BooleanSmtp\Http\Requests\StoreNotificationChannelRequest;
use BooleanSmtp\Http\Requests\BulkNotificationChannelsRequest;
use BooleanSmtp\Core\Http\JsonResponse;
use BooleanSmtp\Repositories\NotificationChannelRepository;
use BooleanSmtp\Services\Notification\NotificationManager;

/**
 * CRUD and test endpoints for the channels that receive delivery-failure alerts.
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
     * @param Request $request Unused; every configured channel and available type is returned.
     * @return JsonResponse Configured `channels` and the `available` channel types.
     */
    public function index(Request $request): JsonResponse
    {
        $repo      = $this->make(NotificationChannelRepository::class);
        $manager   = $this->make(NotificationManager::class);
        $available = $manager->getAvailableChannels();

        // Keep legacy records visible and manageable even when their driver is no longer shipped.
        // The notification manager naturally skips an unsupported type during dispatch.
        $channels = array_values(array_map(fn($ch) => $ch->toArray(), $repo->all()));

        return $this->ok([
            'channels'  => $channels,
            'available' => $available,
        ]);
    }

    /**
     * Handle `POST /booleansmtp/v1/notifications`.
     *
     * Reads `type`, `name`, `settings`, and an optional `is_active` flag. Rejects the request
     * when the account has already reached the channel-type limit for the current license tier.
     *
     * @since 1.0.0
     *
     * @param StoreNotificationChannelRequest $request Request carrying `type`, `name`, `settings`, and an optional
     *                          `is_active` flag.
     * @return JsonResponse The created channel, or a 422 error when the type limit is reached.
     */
    public function store(StoreNotificationChannelRequest $request): JsonResponse
    {
        $type    = (string) $request->get('type');
        $repo    = $this->make(NotificationChannelRepository::class);
        $manager = $this->make(NotificationManager::class);

        $max = $manager->maxChannelsForType($type);
        if ($repo->countByType($type) >= $max) {
            return $this->error($manager->channelLimitMessage($type, $max), 422);
        }

        $channel = $repo->create([
            'type'      => $type,
            'name'      => $request->get('name'),
            'settings'  => $request->get('settings'),
            'is_active' => $request->get('is_active', true),
        ]);

        return $this->created($channel->toArray(), 'Notification channel added.');
    }

    /**
     * Handle `PUT /booleansmtp/v1/notifications/{id}`.
     *
     * Reads `name`, `settings`, and `is_active` from the request and applies whichever of them
     * are present.
     *
     * @since 1.0.0
     *
     * @param UpdateNotificationChannelRequest $request Request carrying the channel `id` route parameter and `name`,
     *                          `settings`, `is_active`.
     * @return JsonResponse The updated channel.
     */
    public function update(UpdateNotificationChannelRequest $request): JsonResponse
    {
        $id   = (int) $request->param('id');
        $repo = $this->make(NotificationChannelRepository::class);
        $repo->findOrFail($id);

        $data    = $request->validated();
        $channel = $repo->update($id, $data);

        return $this->ok($channel->toArray(), 'Channel updated.');
    }

    /**
     * Handle `POST /booleansmtp/v1/notifications/bulk`.
     *
     * Reads `ids` and `action` (`delete`, `enable`, or `disable`) and applies the action to each
     * channel id, skipping any that fail or do not exist.
     *
     * @since 1.0.0
     *
     * @param BulkNotificationChannelsRequest $request Request carrying `ids` and `action` (`delete`, `enable`, or
     *                          `disable`).
     * @return JsonResponse Confirmation message with the number of channels affected.
     */
    public function bulk(BulkNotificationChannelsRequest $request): JsonResponse
    {
        $ids    = $request->get('ids');
        $action = $request->get('action');
        $repo   = $this->make(NotificationChannelRepository::class);
        $count  = 0;

        foreach ($ids as $id) {
            try {
                $channel = $repo->find((int) $id);
                if (!$channel) {
                    continue;
                }

                if ($action === 'delete') {
                    $repo->destroy((int) $id);
                } elseif ($action === 'enable') {
                    $repo->setEnabled((int) $id, true);
                } elseif ($action === 'disable') {
                    $repo->setEnabled((int) $id, false);
                }
                $count++;
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $this->ok(null, "Bulk action '{$action}' completed for {$count} internal notification channels.");
    }

    /**
     * Handle `DELETE /booleansmtp/v1/notifications/{id}`.
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying the channel `id` route parameter.
     * @return JsonResponse Confirmation message.
     */
    public function destroy(Request $request): JsonResponse
    {
        $id   = (int) $request->param('id');
        $repo = $this->make(NotificationChannelRepository::class);
        $repo->findOrFail($id);
        $repo->destroy($id);

        return $this->ok(null, 'Channel deleted.');
    }

    /**
     * Handle `POST /booleansmtp/v1/notifications/telegram/detect-chats`.
     *
     * Reads `bot_token` and asks the Telegram Bot API which chats the bot can currently message,
     * so the admin can pick a chat ID without looking it up manually.
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

        $channel = new \BooleanSmtp\Services\Notification\Channels\TelegramChannel();
        $chats   = $channel->detectChats((string) $request->get('bot_token'));

        return $this->ok(['chats' => $chats]);
    }

    /**
     * Handle `POST /booleansmtp/v1/notifications/test`.
     *
     * Reads `type` and `settings` and sends a real test notification through that channel type
     * without saving it, so the admin can verify credentials before creating the channel.
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying `type` and `settings`.
     * @return JsonResponse The test result.
     */
    public function test(Request $request): JsonResponse
    {
        $this->validate($request, [
            'type'     => 'required|string',
            'settings' => 'required|array',
        ]);

        $manager = $this->make(NotificationManager::class);
        $result  = $manager->testChannel(
            $request->get('type'),
            $request->get('settings')
        );

        return $this->ok($result, $result['success'] ? 'Test notification sent.' : 'Test failed.');
    }
}
