<?php

/**
 * Notification channel driver that delivers alerts through a Telegram bot.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Notification\Channels;

use BooleanSmtp\Contracts\NotificationChannelContract;
use BooleanSmtp\Contracts\TranslatorContract;
use BooleanSmtp\Services\Notification\Presentation\AlertPresentation;
use BooleanSmtp\Services\Notification\Support\RemoteNotificationClient;
use BooleanSmtp\Core\Foundation\Application;
use function BooleanSmtp\Core\app;

/**
 * Sends alerts to a Telegram chat through the Telegram Bot API.
 *
 * @since 1.0.0
 */
class TelegramChannel implements NotificationChannelContract {
    /**
     * Creates the channel driver.
     *
     * @since 1.0.0
     *
     * @param TranslatorContract|null $translator Translator for user-facing strings; resolved
     *                                            from the container when omitted.
     */
    public function __construct(
        private ?TranslatorContract $translator = null
    ) {
    }

    /**
     * Returns the stable identifier for this channel type.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function getIdentifier(): string {
        return 'telegram';
    }

    /**
     * Returns the display name of this channel type.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function getName(): string {
        return 'Telegram';
    }

    /**
     * Sends a message to Telegram via the Bot API `sendMessage` endpoint.
     *
     * @since 1.0.0
     *
     * @param  string                $message  Human-readable alert message.
     * @param  array<string, mixed>  $settings Channel settings; requires `bot_token` and
     *                                         `chat_id`, accepts `message_thread_id`.
     * @param  array<string, mixed>  $context  Additional context used to shape the Telegram message.
     * @return bool True when Telegram accepted the message.
     *
     * @throws \RuntimeException When the bot token or chat id is missing, or Telegram rejects the request.
     */
    public function send(string $message, array $settings, array $context = []): bool {
        $botToken = $settings['bot_token'] ?? '';
        $chatId   = $settings['chat_id'] ?? '';

        if ($botToken === '' || $chatId === '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message is returned as JSON text or logged, never printed as HTML.
            throw new \RuntimeException($this->t('Bot token and Chat ID are required.'));
        }

        $url = 'https://api.telegram.org/bot' . $botToken . '/sendMessage';

        $text = AlertPresentation::telegramHtml($message, $context, $this->translator());
        /**
         * Filters the Telegram message text (HTML-formatted) before it is sent.
         *
         * @since 1.0.0
         *
         * @param string               $text     Telegram message text, formatted for Telegram's HTML parse mode.
         * @param string               $message  Human-readable alert message.
         * @param array<string, mixed> $context  Additional context passed to the channel.
         * @param array<string, mixed> $settings Channel settings the message was built from.
         * @return string The filtered text.
         */
        $text = \apply_filters('boolean_smtp_telegram_notification_text', $text, $message, $context, $settings);

        $fields = [
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true
        ];

        if (!empty($settings['message_thread_id'])) {
            $fields['message_thread_id'] = (int) $settings['message_thread_id'];
        }

        $result = RemoteNotificationClient::postTelegramApi($url, $fields, 15);

        if (!($result['success'] ?? false)) {
            $errorMessage = (string) ($result['error'] ?? $this->t('Telegram delivery failed.'));
            if (isset($result['retry_after'])) {
                $errorMessage .= ' ' . sprintf($this->t('Retry in %d seconds.'), (int) $result['retry_after']);
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message is returned as JSON text or logged, never printed as HTML.
            throw new \RuntimeException($errorMessage);
        }

        return true;
    }

    /**
     * Lists chats this bot has recently seen, for the setup UI's chat picker.
     *
     * Reads recent updates via the Bot API's `getUpdates` endpoint so the admin can pick a
     * chat id instead of copying it out of raw Telegram API JSON.
     *
     * @since 1.0.0
     *
     * @param  string $botToken Telegram bot token.
     * @return array<int, array{id: int|string, type: string, label: string}>
     */
    public function detectChats(string $botToken): array {
        if ($botToken === '') {
            return [];
        }

        $url      = 'https://api.telegram.org/bot' . $botToken . '/getUpdates?limit=100';
        $response = RemoteNotificationClient::getTelegramApi($url, 15);

        if (!($response['success'] ?? false)) {
            return [];
        }

        $chats = [];
        foreach ((array) ($response['result'] ?? []) as $update) {
            $chat = $update['message']['chat']
                ?? $update['channel_post']['chat']
                ?? $update['my_chat_member']['chat']
                ?? null;

            if (!is_array($chat) || !isset($chat['id'])) {
                continue;
            }

            $id = $chat['id'];
            if (isset($chats[$id])) {
                continue;
            }

            $chats[$id] = [
                'id'    => $id,
                'type'  => (string) ($chat['type'] ?? 'private'),
                'label' => $this->describeChatLabel($chat)
            ];
        }

        return array_values($chats);
    }

    /**
     * Builds a human-readable label for a Telegram chat.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $chat Raw chat object from the Telegram Bot API.
     * @return string
     */
    private function describeChatLabel(array $chat): string {
        $type = (string) ($chat['type'] ?? 'private');
        if ($type !== 'private' && !empty($chat['title'])) {
            return (string) $chat['title'];
        }

        $name = trim((string) ($chat['first_name'] ?? '') . ' ' . (string) ($chat['last_name'] ?? ''));
        if ($name !== '') {
            return !empty($chat['username']) ? $name . ' (@' . $chat['username'] . ')' : $name;
        }

        if (!empty($chat['username'])) {
            return '@' . $chat['username'];
        }

        return (string) ($chat['id'] ?? '');
    }

    /**
     * Validates the settings required to use this channel.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $settings Channel settings to validate.
     * @return array<string, string> Error messages keyed by setting name; empty when valid.
     */
    public function validateSettings(array $settings): array {
        $errors = [];
        if (empty($settings['bot_token'])) {
            $errors['bot_token'] = $this->t('Bot token is required.');
        }
        if (empty($settings['chat_id'])) {
            $errors['chat_id'] = $this->t('Chat ID is required.');
        }

        return $errors;
    }

    /**
     * Describes the settings fields this channel needs, for the admin UI.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>>
     */
    public function getSettingsSchema(): array {
        return [
            'bot_token'         => [
                'type'     => 'password',
                'label'    => $this->t('Bot token'),
                'required' => true,
                'secret'   => true
            ],
            'chat_id'           => [
                'type'     => 'text',
                'label'    => $this->t('Chat ID'),
                'required' => true
            ],
            'message_thread_id' => [
                'type'     => 'text',
                'label'    => $this->t('Forum topic ID (optional)'),
                'required' => false
            ]
        ];
    }

    /**
     * Resolves the translator instance, falling back to the container when none was injected.
     *
     * @since 1.0.0
     *
     * @return TranslatorContract|null
     */
    private function translator(): ?TranslatorContract {
        if ($this->translator !== null) {
            return $this->translator;
        }

        if (!Application::hasInstance()) {
            return null;
        }

        try {
            $resolved = app()->make(TranslatorContract::class);
            if ($resolved instanceof TranslatorContract) {
                $this->translator = $resolved;
                return $resolved;
            }
        } catch (\Throwable) {
            // Ignore resolution failure and fallback to source string.
        }

        return null;
    }

    /**
     * Translates a string, returning the source text when no translator is available.
     *
     * @since 1.0.0
     *
     * @param  string $text Source text to translate.
     * @return string
     */
    private function t(string $text): string {
        $translator = $this->translator();
        if ($translator === null) {
            return $text;
        }

        return $translator->translate($text);
    }
}
