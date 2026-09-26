<?php

/**
 * Builds safe, channel-native operational alert payloads.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Notification\Presentation;

use BooleanSmtp\Contracts\TranslatorContract;
use BooleanSmtp\Core\Foundation\Application;
use function BooleanSmtp\Core\app;

/**
 * Normalizes operational alerts before rendering them for Slack, Discord, or Telegram.
 *
 * @since 1.0.0
 */
final class AlertPresentation {
    /**
     * Context `presentation` value retained for delivery-failure integration compatibility.
     *
     * @since 1.0.0
     * @var string
     */
    public const PRESENTATION_DELIVERY_FAILURE = 'delivery_failure';

    /**
     * Builds the heading text shown at the top of a notification.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>     $context    Alert context; `alert_type` is used when present.
     * @param  TranslatorContract|null  $translator Translator for user-facing strings.
     * @return string
     */
    public static function heading(array $context, ?TranslatorContract $translator = null): string {
        return self::limit(
            self::siteName() . ': ' . (string) ($context['alert_type'] ?? self::translate('BooleanSMTP alert', $translator)),
            150
        );
    }

    /**
     * Returns the current site's display name.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public static function siteName(): string {
        if (\function_exists('wp_specialchars_decode') && \function_exists('get_bloginfo')) {
            return \wp_specialchars_decode((string) \get_bloginfo('name'), \ENT_QUOTES);
        }

        return 'WordPress';
    }

    /**
     * Returns the current site's URL.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public static function siteUrl(): string {
        return \function_exists('site_url') ? (string) \site_url() : '';
    }

    /**
     * Returns the URL of the plugin's admin page.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public static function booleanSmtpAdminUrl(): string {
        if (!\function_exists('admin_url')) {
            return '';
        }

        return (string) \admin_url('admin.php?page=boolean-smtp');
    }

    /**
     * Returns a link to the plugin's email logs screen.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public static function emailLogsHashUrl(): string {
        return self::booleanSmtpAdminUrl() . '#/logs';
    }

    /**
     * Returns a link to a single email log entry, or the log list when no id is given.
     *
     * @since 1.0.0
     *
     * @param  int|null $logId Email log id, or null to link to the log list.
     * @return string
     */
    public static function emailLogHashUrl(?int $logId): string {
        if ($logId === null || $logId <= 0) {
            return self::emailLogsHashUrl();
        }

        return self::booleanSmtpAdminUrl() . '#/logs/' . $logId;
    }

    /**
     * Escapes text for Telegram's HTML parse mode.
     *
     * @since 1.0.0
     *
     * @param  string $text Text to escape.
     * @return string
     */
    public static function escapeTelegramHtml(string $text): string {
        return \htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Builds the label/value fields describing a delivery-failure email.
     *
     * This compatibility helper retains the existing filter. Native payloads are built through
     * {@see self::operationalAlert()} and use only a safe subset of this data.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>     $email      Email details (provider, to, subject, error,
     *                                              source, log_id, connection_id).
     * @param  array<string, mixed>     $context    Additional alert context.
     * @param  TranslatorContract|null  $translator Translator for field labels.
     * @return array<string, string>
     */
    public static function deliveryFailureFields(array $email, array $context, ?TranslatorContract $translator = null): array {
        $fields = [
            self::translate('Website URL', $translator) => self::siteUrl(),
            self::translate('Provider', $translator)    => (string) ($email['provider'] ?? ''),
            self::translate('To', $translator)          => (string) ($email['to'] ?? ''),
            self::translate('Subject', $translator)     => (string) ($email['subject'] ?? ''),
            self::translate('Error', $translator)       => (string) ($email['error'] ?? ''),
            self::translate('Source', $translator)      => (string) ($email['source'] ?? ''),
        ];

        if (!empty($email['log_id'])) {
            $fields[self::translate('Log ID', $translator)] = (string) $email['log_id'];
        }

        if (!empty($email['connection_id'])) {
            $fields[self::translate('Connection ID', $translator)] = (string) $email['connection_id'];
        }

        /**
         * Filters the label/value fields shown for a delivery-failure notification.
         *
         * @since 1.0.0
         *
         * @param array<string, string> $fields  Field labels mapped to their values.
         * @param array<string, mixed>  $email   Email details the fields were built from.
         * @param array<string, mixed>  $context Additional alert context.
         * @return array<string, string> The filtered fields.
         */
        return \apply_filters('boolean_smtp_alert_delivery_failure_fields', $fields, $email, $context);
    }

    /**
     * Normalizes a plain message and context into the shared operational alert model.
     *
     * Values carrying connection secrets or transport internals are dropped. Callers should pass
     * only owner-safe facts, but the final guard keeps accidental secrets out of every channel.
     *
     * @since 1.0.0
     *
     * @param  string                    $message    Fallback human-readable alert summary.
     * @param  array<string, mixed>      $context    Alert context; supports an `operational` array.
     * @param  TranslatorContract|null   $translator Translator for user-facing strings.
     * @return array{severity: string, title: string, summary: string, timestamp: string,
     *               facts: array<string, string>, action: array{label: string, url: string}}
     */
    public static function operationalAlert(string $message, array $context, ?TranslatorContract $translator = null): array {
        $operational = isset($context['operational']) && \is_array($context['operational'])
            ? $context['operational']
            : self::legacyOperationalContext($message, $context, $translator);

        $kind      = (string) ($operational['kind'] ?? 'generic');
        $severity  = self::severity((string) ($operational['severity'] ?? $context['severity'] ?? 'warning'));
        $title     = self::safeText((string) ($operational['title'] ?? self::titleFor($kind, $context, $translator)));
        $summary   = self::safeText((string) ($operational['summary'] ?? self::summaryFor($kind, $message, $translator)));
        $timestamp = self::utcTimestamp((string) ($operational['timestamp'] ?? ''));
        $facts     = self::safeFacts($operational['facts'] ?? []);
        $action    = self::safeAction($operational['action'] ?? self::defaultAction($kind, $context, $translator), $translator);

        return [
            'severity'  => $severity,
            'title'     => self::limit($title, 256),
            'summary'   => self::limit($summary, 4000),
            'timestamp' => $timestamp,
            'facts'     => $facts,
            'action'    => $action,
        ];
    }

    /**
     * Builds a Slack Block Kit payload from the normalized operational alert.
     *
     * @since 1.0.0
     *
     * @param  string                    $message    Human-readable alert message.
     * @param  array<string, mixed>      $context    Alert context.
     * @param  TranslatorContract|null   $translator Translator for user-facing strings.
     * @return array<string, mixed>
     */
    public static function slackPayload(string $message, array $context, ?TranslatorContract $translator = null): array {
        return self::slackOperational(self::operationalAlert($message, $context, $translator), $translator);
    }

    /**
     * Builds a Slack Block Kit payload for a delivery-failure alert.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>     $email      Email details the alert is about.
     * @param  array<string, mixed>     $context    Additional alert context.
     * @param  TranslatorContract|null  $translator Translator for user-facing strings.
     * @return array<string, mixed>
     */
    public static function slackDeliveryFailure(array $email, array $context, ?TranslatorContract $translator = null): array {
        $context['presentation'] = self::PRESENTATION_DELIVERY_FAILURE;
        $context['email']        = $email;

        return self::slackPayload('', $context, $translator);
    }

    /**
     * Builds a Discord embed payload from the normalized operational alert.
     *
     * @since 1.0.0
     *
     * @param  string                    $message         Human-readable alert message.
     * @param  array<string, mixed>      $context         Alert context.
     * @param  string                    $webhookUsername Display name Discord shows for the webhook message.
     * @param  TranslatorContract|null   $translator      Translator for user-facing strings.
     * @return array<string, mixed>
     */
    public static function discordPayload(string $message, array $context, string $webhookUsername, ?TranslatorContract $translator = null): array {
        $alert = self::operationalAlert($message, $context, $translator);
        $fields = [];
        foreach (\array_slice($alert['facts'], 0, 10, true) as $label => $value) {
            $fields[] = [
                'name'   => self::limit(self::discordText($label), 256),
                'value'  => self::limit(self::discordText($value), 1024),
                'inline' => self::characterLength($value) <= 42,
            ];
        }

        return [
            'username' => self::limit(self::discordText($webhookUsername), 80),
            'content'  => null,
            'embeds'   => [[
                'title'       => self::limit(self::discordText('[' . self::severityLabel($alert['severity'], $translator) . '] ' . $alert['title']), 256),
                'description' => self::limit(self::discordText($alert['summary']), 4096),
                'color'       => self::discordColor($alert['severity']),
                'fields'      => $fields,
                'footer'      => ['text' => self::limit(self::discordText('BooleanSMTP · ' . $alert['timestamp']), 2048)],
                'timestamp'   => self::discordTimestamp($alert['timestamp']),
                'url'         => $alert['action']['url'],
            ]],
        ];
    }

    /**
     * Builds a Telegram HTML message from the normalized operational alert.
     *
     * @since 1.0.0
     *
     * @param  string                    $message    Human-readable alert message.
     * @param  array<string, mixed>      $context    Alert context.
     * @param  TranslatorContract|null   $translator Translator for user-facing strings.
     * @return string
     */
    public static function telegramHtml(string $message, array $context, ?TranslatorContract $translator = null): string {
        $alert = self::operationalAlert($message, $context, $translator);
        $lines = ['<b>[' . self::escapeTelegramHtml(self::severityLabel($alert['severity'], $translator)) . '] ' . self::escapeTelegramHtml($alert['title']) . '</b>', '', self::escapeTelegramHtml($alert['summary'])];
        $lines[] = '';
        $lines[] = '<b>' . self::escapeTelegramHtml(self::translate('UTC', $translator)) . ':</b> ' . self::escapeTelegramHtml($alert['timestamp']);

        foreach (\array_slice($alert['facts'], 0, 10, true) as $label => $value) {
            $lines[] = '<b>' . self::escapeTelegramHtml($label) . ':</b> ' . self::escapeTelegramHtml($value);
        }

        if ($alert['action']['url'] !== '') {
            $lines[] = '';
            $lines[] = '<a href="' . self::escapeTelegramHtml($alert['action']['url']) . '">' . self::escapeTelegramHtml($alert['action']['label']) . '</a>';
        }

        return self::limit(\implode("\n", $lines), 4096);
    }

    /**
     * Builds the model used for legacy contexts that have not yet supplied `operational` data.
     *
     * @since 1.0.0
     *
     * @param  string                    $message    Human-readable alert message.
     * @param  array<string, mixed>      $context    Alert context.
     * @param  TranslatorContract|null   $translator Translator for user-facing strings.
     * @return array<string, mixed>
     */
    private static function legacyOperationalContext(string $message, array $context, ?TranslatorContract $translator): array {
        if (($context['presentation'] ?? '') === self::PRESENTATION_DELIVERY_FAILURE && isset($context['email']) && \is_array($context['email'])) {
            $email = $context['email'];

            return [
                'kind'     => 'delivery_failure',
                'severity' => $context['severity'] ?? 'error',
                'summary'  => self::summaryFor('delivery_failure', $message, $translator),
                'facts'    => self::deliveryFailureFacts($email, $context, $translator),
                'action'   => [
                    'label' => self::translate('Open BooleanSMTP logs', $translator),
                    'url'   => self::emailLogHashUrl(isset($email['log_id']) ? (int) $email['log_id'] : null),
                ],
            ];
        }

        return [
            'kind'     => 'generic',
            'severity' => $context['severity'] ?? 'warning',
            'title'    => (string) ($context['alert_type'] ?? ''),
            'summary'  => $message,
            'facts'    => [],
        ];
    }

    /**
     * Builds the owner-safe facts shown for a final delivery failure.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>     $email      Email details from the final-failure event.
     * @param  array<string, mixed>     $context    Additional delivery-failure context passed to the filter.
     * @param  TranslatorContract|null  $translator Translator for field labels.
     * @return array<string, string>
     */
    private static function deliveryFailureFacts(array $email, array $context, ?TranslatorContract $translator): array {
        $fields    = self::deliveryFailureFields($email, $context, $translator);
        $excluded  = [
            self::translate('Website URL', $translator),
            self::translate('Error', $translator),
        ];
        $facts = [];

        foreach ($fields as $label => $value) {
            if (\in_array($label, $excluded, true)) {
                continue;
            }

            $facts[$label] = $value;
        }

        return $facts;
    }

    /**
     * Returns the translated title associated with an operational alert kind.
     *
     * @since 1.0.0
     *
     * @param  string                    $kind       Operational alert kind.
     * @param  array<string, mixed>      $context    Alert context.
     * @param  TranslatorContract|null   $translator Translator for user-facing strings.
     * @return string
     */
    private static function titleFor(string $kind, array $context, ?TranslatorContract $translator): string {
        return match ($kind) {
            'delivery_failure'      => self::translate('Email Delivery Failure', $translator),
            'connection_failure'    => self::translate('Connection Failure', $translator),
            'oauth_refresh_failure' => self::translate('OAuth Refresh Requires Attention', $translator),
            'notification_test'     => self::translate('Notification Channel Test', $translator),
            default                 => (string) ($context['alert_type'] ?? self::translate('BooleanSMTP alert', $translator)),
        };
    }

    /**
     * Returns the translated summary associated with an operational alert kind.
     *
     * @since 1.0.0
     *
     * @param  string                    $kind       Operational alert kind.
     * @param  string                    $message    Fallback human-readable alert message.
     * @param  TranslatorContract|null   $translator Translator for user-facing strings.
     * @return string
     */
    private static function summaryFor(string $kind, string $message, ?TranslatorContract $translator): string {
        return match ($kind) {
            'delivery_failure'      => self::translate('A message could not be delivered after all configured attempts.', $translator),
            'connection_failure'    => self::translate('A mailer connection failed and needs review.', $translator),
            'oauth_refresh_failure' => self::translate('An OAuth connection could not refresh and needs re-authorization.', $translator),
            'notification_test'     => self::translate('This is a test of this BooleanSMTP notification channel.', $translator),
            default                 => $message,
        };
    }

    /**
     * Returns the default action associated with an operational alert kind.
     *
     * @since 1.0.0
     *
     * @param  string                    $kind       Operational alert kind.
     * @param  array<string, mixed>      $context    Alert context.
     * @param  TranslatorContract|null   $translator Translator for user-facing strings.
     * @return array{label: string, url: string}
     */
    private static function defaultAction(string $kind, array $context, ?TranslatorContract $translator): array {
        if (\in_array($kind, ['connection_failure', 'oauth_refresh_failure'], true) && !empty($context['connection_id'])) {
            return [
                'label' => self::translate('Open connection settings', $translator),
                'url'   => self::booleanSmtpAdminUrl() . '#/connections/' . (int) $context['connection_id'],
            ];
        }

        if ($kind === 'delivery_failure' && isset($context['email']) && \is_array($context['email']) && !empty($context['email']['log_id'])) {
            return [
                'label' => self::translate('Open BooleanSMTP logs', $translator),
                'url'   => self::emailLogHashUrl((int) $context['email']['log_id']),
            ];
        }

        return [
            'label' => self::translate('Open BooleanSMTP logs', $translator),
            'url'   => self::emailLogsHashUrl(),
        ];
    }

    /**
     * Renders a normalized alert as a Slack Block Kit payload.
     *
     * @since 1.0.0
     *
     * @param  array{severity: string, title: string, summary: string, timestamp: string,
     *               facts: array<string, string>, action: array{label: string, url: string}} $alert Normalized alert.
     * @param  TranslatorContract|null $translator Translator for user-facing strings.
     * @return array<string, mixed>
     */
    private static function slackOperational(array $alert, ?TranslatorContract $translator): array {
        $header = self::limit(self::slackText('[' . self::severityLabel($alert['severity'], $translator) . '] ' . self::siteName() . ': ' . $alert['title']), 150);
        $blocks = [[
            'type' => 'header',
            'text' => [
                'type'  => 'plain_text',
                'text'  => $header,
                'emoji' => true,
            ],
        ], [
            'type' => 'section',
            'text' => [
                'type' => 'mrkdwn',
                'text' => self::limit(self::slackText($alert['summary']), 3000),
            ],
        ]];

        $fields = [];
        foreach (\array_slice($alert['facts'], 0, 10, true) as $label => $value) {
            $fields[] = [
                'type' => 'mrkdwn',
                'text' => self::limit('*' . self::slackText($label) . ':*' . "\n" . self::slackText($value), 2000),
            ];
        }

        if ($fields !== []) {
            $blocks[] = ['type' => 'section', 'fields' => $fields];
        }

        $blocks[] = [
            'type'     => 'context',
            'elements' => [[
                'type' => 'mrkdwn',
                'text' => self::limit(self::slackText(self::translate('UTC', $translator) . ': ' . $alert['timestamp']), 2000),
            ]],
        ];

        if ($alert['action']['url'] !== '') {
            $blocks[] = [
                'type'     => 'actions',
                'elements' => [[
                    'type'      => 'button',
                    'text'      => ['type' => 'plain_text', 'text' => self::limit($alert['action']['label'], 75), 'emoji' => true],
                    'url'       => $alert['action']['url'],
                    'action_id' => 'boolean_smtp_open_alert',
                ]],
            ];
        }

        return [
            'text'   => self::limit($header . "\n" . self::slackText($alert['summary']), 4000),
            'blocks' => $blocks,
        ];
    }

    /**
     * Accepts only facts safe for third-party operational channels.
     *
     * @since 1.0.0
     *
     * @param  mixed $facts Candidate label/value map.
     * @return array<string, string>
     */
    private static function safeFacts(mixed $facts): array {
        if (!\is_array($facts)) {
            return [];
        }

        $safe = [];
        foreach ($facts as $label => $value) {
            $label = self::safeText((string) $label);
            $value = self::safeText((string) $value);
            if ($label === '' || $value === '' || self::isRestrictedFact($label)) {
                continue;
            }

            $safe[self::limit($label, 256)] = self::limit($value, 1000);
            if (\count($safe) === 10) {
                break;
            }
        }

        return $safe;
    }

    /**
     * Determines whether a fact label describes a secret or transport internal.
     *
     * @since 1.0.0
     *
     * @param  string $label Fact label.
     * @return bool True when the fact must not be sent to an alert channel.
     */
    private static function isRestrictedFact(string $label): bool {
        return (bool) \preg_match('/(?:host|port|auth(?:entication)?|password|secret|token|credential|oauth|error|raw|api[ _-]?(?:payload|response)|provider[ _-]?response)/i', $label);
    }

    /**
     * Normalizes a potential action into a safe label and HTTP(S) URL.
     *
     * @since 1.0.0
     *
     * @param  mixed                     $action     Candidate action data.
     * @param  TranslatorContract|null   $translator Translator for fallback labels.
     * @return array{label: string, url: string}
     */
    private static function safeAction(mixed $action, ?TranslatorContract $translator): array {
        if (!\is_array($action)) {
            return ['label' => self::translate('Open BooleanSMTP', $translator), 'url' => ''];
        }

        $url = \trim((string) ($action['url'] ?? ''));
        if (!\filter_var($url, \FILTER_VALIDATE_URL) || !\preg_match('#^https?://#i', $url)) {
            $url = '';
        }

        $label = self::safeText((string) ($action['label'] ?? self::translate('Open BooleanSMTP', $translator)));

        return ['label' => self::limit($label, 75), 'url' => $url];
    }

    /**
     * Normalizes a severity value to the supported operations palette.
     *
     * @since 1.0.0
     *
     * @param  string $severity Candidate severity.
     * @return string One of `info`, `warning`, or `error`.
     */
    private static function severity(string $severity): string {
        return \in_array($severity, ['info', 'warning', 'error'], true) ? $severity : 'warning';
    }

    /**
     * Returns the short visible label for an operational severity.
     *
     * @since 1.0.0
     *
     * @param  string                    $severity   Operational severity.
     * @param  TranslatorContract|null   $translator Translator for the label.
     * @return string
     */
    private static function severityLabel(string $severity, ?TranslatorContract $translator): string {
        return match ($severity) {
            'error' => self::translate('ERROR', $translator),
            'info'  => self::translate('INFO', $translator),
            default => self::translate('WARNING', $translator),
        };
    }

    /**
     * Returns a UTC timestamp for the current event, or preserves a valid supplied UTC timestamp.
     *
     * @since 1.0.0
     *
     * @param  string $timestamp Candidate timestamp.
     * @return string UTC timestamp in ISO-8601 form.
     */
    private static function utcTimestamp(string $timestamp): string {
        if ($timestamp !== '' && \preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $timestamp)) {
            return $timestamp;
        }

        return \gmdate('c');
    }

    /**
     * Converts the UTC timestamp into the RFC 3339 value Discord expects.
     *
     * @since 1.0.0
     *
     * @param  string $timestamp UTC timestamp.
     * @return string RFC 3339 timestamp.
     */
    private static function discordTimestamp(string $timestamp): string {
        return \str_replace('+00:00', 'Z', $timestamp);
    }

    /**
     * Returns the Discord colour assigned to an operational severity.
     *
     * @since 1.0.0
     *
     * @param  string $severity Operational severity.
     * @return int Decimal colour value.
     */
    private static function discordColor(string $severity): int {
        return match ($severity) {
            'error' => 15_158_332,
            'info'  => 5_799_097,
            default => 16_776_960,
        };
    }

    /**
     * Escapes text that will be interpolated into Slack mrkdwn.
     *
     * @since 1.0.0
     *
     * @param  string $text Text to escape.
     * @return string Slack-safe text.
     */
    private static function slackText(string $text): string {
        return \str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], self::safeText($text));
    }

    /**
     * Avoids accidental Discord mentions in values supplied by alert context.
     *
     * @since 1.0.0
     *
     * @param  string $text Text to render.
     * @return string Discord-safe text.
     */
    private static function discordText(string $text): string {
        return \str_replace('@', "@\u{200B}", self::safeText($text));
    }

    /**
     * Removes control characters and normalizes whitespace in a channel-bound value.
     *
     * @since 1.0.0
     *
     * @param  string $text Text to normalize.
     * @return string Safe plain text.
     */
    private static function safeText(string $text): string {
        $text = \preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';

        return \trim(\preg_replace('/[\r\n]+/', ' ', $text) ?? '');
    }

    /**
     * Truncates a string to a maximum character length with an omission marker.
     *
     * @since 1.0.0
     *
     * @param  string $text Text to truncate.
     * @param  int    $max  Maximum character length.
     * @return string Truncated text when needed.
     */
    private static function limit(string $text, int $max): string {
        if ($max <= 0 || self::characterLength($text) <= $max) {
            return $max <= 0 ? '' : $text;
        }

        if ($max === 1) {
            return '…';
        }

        if (\function_exists('mb_substr')) {
            return \mb_substr($text, 0, $max - 1) . '…';
        }

        return \substr($text, 0, $max - 1) . '…';
    }

    /**
     * Counts characters with multibyte support when the extension is available.
     *
     * @since 1.0.0
     *
     * @param  string $text Text to measure.
     * @return int Character count.
     */
    private static function characterLength(string $text): int {
        return \function_exists('mb_strlen') ? \mb_strlen($text) : \strlen($text);
    }

    /**
     * Translates a string, applying `{{key}}` replacements when no translator is available.
     *
     * @since 1.0.0
     *
     * @param  string                    $text         Source text to translate.
     * @param  TranslatorContract|null   $translator   Translator to use; resolved from the container when omitted.
     * @param  array<string, mixed>      $replacements Values substituted for `{{key}}` placeholders.
     * @return string
     */
    private static function translate(string $text, ?TranslatorContract $translator = null, array $replacements = []): string {
        $resolvedTranslator = self::resolveTranslator($translator);
        if ($resolvedTranslator !== null) {
            return $resolvedTranslator->translate($text, $replacements);
        }

        foreach ($replacements as $key => $value) {
            $text = \str_replace('{{' . $key . '}}', (string) $value, $text);
        }

        return $text;
    }

    /**
     * Resolves a translator, falling back to the container when none was given.
     *
     * @since 1.0.0
     *
     * @param  TranslatorContract|null $translator Translator to use, if already available.
     * @return TranslatorContract|null
     */
    private static function resolveTranslator(?TranslatorContract $translator = null): ?TranslatorContract {
        if ($translator !== null) {
            return $translator;
        }

        if (!Application::hasInstance()) {
            return null;
        }

        try {
            $resolved = app()->make(TranslatorContract::class);

            return $resolved instanceof TranslatorContract ? $resolved : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
