<?php

/**
 * Notification channel driver that delivers alerts to a Slack webhook.
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
 * Sends alerts to a Slack channel through an incoming webhook.
 *
 * @since 1.0.0
 */
class SlackChannel implements NotificationChannelContract {
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
        return 'slack';
    }

    /**
     * Returns the display name of this channel type.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function getName(): string {
        return 'Slack';
    }

    /**
     * Sends a message to Slack via the configured incoming webhook.
     *
     * @since 1.0.0
     *
     * @param  string                $message  Human-readable alert message.
     * @param  array<string, mixed>  $settings Channel settings; requires `webhook_url`, accepts `allow_custom_domain`.
     * @param  array<string, mixed>  $context  Additional context used to shape the Slack message.
     * @return bool True when Slack accepted the webhook payload.
     *
     * @throws \RuntimeException When the webhook URL is missing or Slack rejects the request.
     */
    public function send(string $message, array $settings, array $context = []): bool {
        $webhookUrl = \trim((string) ($settings['webhook_url'] ?? ''));

        if ($webhookUrl === '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message is returned as JSON text or logged, never printed as HTML.
            throw new \RuntimeException($this->t('Slack Webhook URL is required.'));
        }

        $payload = AlertPresentation::slackPayload($message, $context, $this->translator());
        /**
         * Filters the Slack webhook payload before it is sent.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $payload  Slack webhook payload (blocks, text, and so on).
         * @param string               $message  Human-readable alert message.
         * @param array<string, mixed> $context  Additional context passed to the channel.
         * @param array<string, mixed> $settings Channel settings the payload was built from.
         * @return array<string, mixed> The filtered payload.
         */
        $payload = \apply_filters('boolean_smtp_slack_notification_payload', $payload, $message, $context, $settings);

        $result = RemoteNotificationClient::postJson($webhookUrl, $payload, 15, true);

        if (!($result['success'] ?? false)) {
            $errorMessage = (string) ($result['error'] ?? $this->t('Slack delivery failed.'));
            if (isset($result['retry_after'])) {
                $errorMessage .= ' ' . \sprintf($this->t('Retry in %d seconds.'), (int) $result['retry_after']);
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message is returned as JSON text or logged, never printed as HTML.
            throw new \RuntimeException($errorMessage);
        }

        return true;
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
        $errors     = [];
        $webhookUrl = \trim((string) ($settings['webhook_url'] ?? ''));

        if ($webhookUrl === '') {
            $errors['webhook_url'] = $this->t('Slack Webhook URL is required.');
        } elseif (!empty($settings['allow_custom_domain'])) {
            if (!\filter_var($webhookUrl, \FILTER_VALIDATE_URL) || !\str_starts_with($webhookUrl, 'https://')) {
                $errors['webhook_url'] = $this->t('Please enter a valid HTTPS URL.');
            }
        } elseif (!self::isSlackWebhookUrl($webhookUrl)) {
            $errors['webhook_url'] = $this->t('This does not look like a Slack webhook URL. If it\'s intentionally proxied through a third-party relay, check "This webhook URL is proxied through a third-party relay" below.');
        }

        return $errors;
    }

    /**
     * Determines whether a URL matches Slack's incoming-webhook URL shape.
     *
     * Slack Incoming Webhook URLs have a fixed, well-known shape:
     * `https://hooks.slack.com/services/{team_id}/{integration_id}/{token}`.
     *
     * @since 1.0.0
     *
     * @param  string $url URL to check.
     * @return bool
     */
    public static function isSlackWebhookUrl(string $url): bool {
        return (bool) \preg_match('#^https://hooks\.slack\.com/services/\S+/\S+/\S+$#i', $url);
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
            'webhook_url'         => [
                'type'     => 'text',
                'label'    => $this->t('Incoming Webhook URL'),
                'required' => true,
                'secret'   => true
            ],
            'allow_custom_domain' => [
                'type'     => 'checkbox',
                'label'    => $this->t('This webhook URL is proxied through a third-party relay'),
                'help'     => $this->t("Leave unchecked to require Slack's own webhook domain. Only enable this if you're intentionally routing the webhook through a custom proxy or relay service."),
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
