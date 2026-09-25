<?php

/**
 * Shared HTTP client for outbound notification-channel webhook requests.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Notification\Support;

use BooleanSmtp\Adapters\Contracts\HttpAdapterContract;
use BooleanSmtp\Adapters\WordPress\HttpAdapter;
use BooleanSmtp\Core\Foundation\Application;
use function BooleanSmtp\Core\app;

/**
 * Shared JSON POST for outbound webhooks (Discord, Slack, and so on) and the Telegram Bot API.
 *
 * @since 1.0.0
 */
final class RemoteNotificationClient {
    /**
     * Posts a JSON payload to a webhook URL.
     *
     * @since 1.0.0
     *
     * @param  string                $url      Destination webhook URL.
     * @param  array<string, mixed>  $payload  Payload to JSON-encode and send as the request body.
     * @param  int                   $timeout  Request timeout in seconds.
     * @param  bool                  $blocking Whether to wait for the response; when false the
     *                                         request fires without waiting.
     * @return array{success: bool, response_code: int, error?: string, retry_after?: int}
     */
    public static function postJson(string $url, array $payload, int $timeout = 15, bool $blocking = true): array {
        if ($url === '') {
            return ['success' => false, 'response_code' => 0, 'error' => 'Missing webhook URL.'];
        }

        $args = [
            'headers'     => [
                'Content-Type' => 'application/json; charset=utf-8',
                'User-Agent'   => 'BooleanSMTP/' . (defined('BOOLEAN_SMTP_VERSION') ? (string) \constant('BOOLEAN_SMTP_VERSION') : '1.0')
            ],
            'body'        => \wp_json_encode($payload),
            'timeout'     => $timeout,
            'redirection' => 3,
            'httpversion' => '1.1',
            /**
             * Filters whether outbound notification requests verify the remote SSL certificate.
             *
             * @since 1.0.0
             *
             * @param bool $sslverify Whether to verify the remote SSL certificate; true by default.
             * @return bool Whether to verify the TLS certificate.
             */
            'sslverify'   => (bool) \apply_filters('boolean_smtp_notification_sslverify', true)
        ];

        if (!$blocking) {
            $args['blocking'] = false;
            $args['timeout']  = 0.01;
        }

        /**
         * Filters the HTTP request arguments used for an outbound notification request.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $args    Request arguments passed to the WordPress HTTP API.
         * @param string               $url     Destination URL for the request.
         * @param array<string, mixed> $payload Payload the request is sending.
         * @return array<string, mixed> The filtered args.
         */
        $args = \apply_filters('boolean_smtp_notification_http_request_args', $args, $url, $payload);

        $response = self::http()->post($url, $args);

        if (!$blocking) {
            return ['success' => true, 'response_code' => 0];
        }

        if (self::http()->isError($response)) {
            return ['success' => false, 'response_code' => 0, 'error' => self::http()->getErrorMessage($response)];
        }

        $code = self::http()->responseCode($response);

        if ($code >= 200 && $code < 300) {
            return ['success' => true, 'response_code' => $code];
        }

        return self::webhookErrorResult($code, self::http()->responseBody($response));
    }

    /**
     * Normalizes a failed webhook response body into a result array.
     *
     * Discord and a JSON-shaped custom proxy carry `message`/`retry_after` in a JSON object;
     * Slack's Incoming Webhooks endpoint instead returns the entire error as a bare plain-text
     * token (for example `channel_not_found`), not JSON, so a body that is not a JSON object
     * falls back to that raw text itself (trimmed, length-capped) rather than a generic
     * message, since for Slack that raw text is the specific, actionable error.
     *
     * @since 1.0.0
     *
     * @param  int    $code    HTTP status code returned by the webhook endpoint.
     * @param  string $rawBody Raw response body returned by the webhook endpoint.
     * @return array{success: bool, response_code: int, error: string, retry_after?: int}
     */
    private static function webhookErrorResult(int $code, string $rawBody): array {
        $data = \json_decode($rawBody, true);

        if (\is_array($data)) {
            $message = $data['message'] ?? null;
            $error   = \is_scalar($message) ? (string) $message : 'Webhook request failed.';
        } else {
            $plainText = \trim($rawBody);
            $error     = $plainText !== '' ? \mb_substr($plainText, 0, 200) : 'Webhook request failed.';
        }

        $result = [
            'success'       => false,
            'response_code' => $code,
            'error'         => $error
        ];

        $retryAfter = \is_array($data) ? ($data['retry_after'] ?? null) : null;
        if (\is_numeric($retryAfter)) {
            $result['retry_after'] = (int) \round((float) $retryAfter);
        }

        return $result;
    }

    /**
     * Posts a `application/x-www-form-urlencoded` request to the Telegram Bot API.
     *
     * @since 1.0.0
     *
     * @param  string                        $url     Telegram Bot API endpoint URL.
     * @param  array<string, string|int>     $fields  Form fields to send as the request body.
     * @param  int                           $timeout Request timeout in seconds.
     * @return array{success: bool, error?: string, retry_after?: int}
     */
    public static function postTelegramApi(string $url, array $fields, int $timeout = 15): array {
        if ($url === '') {
            return ['success' => false, 'error' => 'Missing bot token.'];
        }

        $args = [
            'body'        => $fields,
            'timeout'     => $timeout,
            'redirection' => 3,
            'httpversion' => '1.1',
            /**
             * Filters whether outbound notification requests verify the remote SSL certificate.
             *
             * @since 1.0.0
             *
             * @param bool $sslverify Whether to verify the remote SSL certificate; true by default.
             */
            'sslverify'   => (bool) \apply_filters('boolean_smtp_notification_sslverify', true)
        ];

        /**
         * Filters the HTTP request arguments used for an outbound notification request.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $args    Request arguments passed to the WordPress HTTP API.
         * @param string               $url     Destination URL for the request.
         * @param array<string, mixed> $payload Payload the request is sending.
         */
        $args = \apply_filters('boolean_smtp_notification_http_request_args', $args, $url, $fields);

        $response = self::http()->post($url, $args);

        if (self::http()->isError($response)) {
            return ['success' => false, 'error' => self::http()->getErrorMessage($response)];
        }

        $code = self::http()->responseCode($response);
        $raw  = self::http()->responseBody($response);
        $data = \json_decode($raw, true);

        if ($code >= 200 && $code < 300 && \is_array($data) && (($data['ok'] ?? false) === true)) {
            return ['success' => true];
        }

        return self::telegramErrorResult($data);
    }

    /**
     * Sends a GET request to the Telegram Bot API.
     *
     * Used for endpoints such as `getUpdates`, which the chat-id auto-detection UI relies on
     * during setup.
     *
     * @since 1.0.0
     *
     * @param  string $url     Telegram Bot API endpoint URL.
     * @param  int    $timeout Request timeout in seconds.
     * @return array{success: bool, result?: array<int, mixed>, error?: string, retry_after?: int}
     */
    public static function getTelegramApi(string $url, int $timeout = 15): array {
        if ($url === '') {
            return ['success' => false, 'error' => 'Missing bot token.'];
        }

        $args = [
            'timeout'     => $timeout,
            'redirection' => 3,
            'httpversion' => '1.1',
            /**
             * Filters whether outbound notification requests verify the remote SSL certificate.
             *
             * @since 1.0.0
             *
             * @param bool $sslverify Whether to verify the remote SSL certificate; true by default.
             */
            'sslverify'   => (bool) \apply_filters('boolean_smtp_notification_sslverify', true)
        ];

        /**
         * Filters the HTTP request arguments used for an outbound notification request.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $args    Request arguments passed to the WordPress HTTP API.
         * @param string               $url     Destination URL for the request.
         * @param array<string, mixed> $payload Payload the request is sending; empty for a GET request.
         */
        $args = \apply_filters('boolean_smtp_notification_http_request_args', $args, $url, []);

        $response = self::http()->get($url, $args);

        if (self::http()->isError($response)) {
            return ['success' => false, 'error' => self::http()->getErrorMessage($response)];
        }

        $code = self::http()->responseCode($response);
        $raw  = self::http()->responseBody($response);
        $data = \json_decode($raw, true);

        if ($code >= 200 && $code < 300 && \is_array($data) && (($data['ok'] ?? false) === true)) {
            return ['success' => true, 'result' => (array) ($data['result'] ?? [])];
        }

        return self::telegramErrorResult($data);
    }

    /**
     * Normalizes a failed Telegram Bot API JSON body into a result array.
     *
     * Surfaces the `description` field and, for a rate-limited (429) response,
     * `parameters.retry_after`.
     *
     * @since 1.0.0
     *
     * @param  mixed $data Decoded Telegram Bot API response body.
     * @return array{success: bool, error: string, retry_after?: int}
     */
    private static function telegramErrorResult(mixed $data): array {
        if (!\is_array($data)) {
            return ['success' => false, 'error' => 'Telegram API error.'];
        }

        $result = [
            'success' => false,
            'error'   => (string) ($data['description'] ?? 'Telegram API error.')
        ];

        $retryAfter = $data['parameters']['retry_after'] ?? null;
        if (\is_numeric($retryAfter)) {
            $result['retry_after'] = (int) $retryAfter;
        }

        return $result;
    }

    /**
     * Resolves the HTTP adapter, preferring the container when available.
     *
     * @since 1.0.0
     *
     * @return HttpAdapterContract
     */
    private static function http(): HttpAdapterContract {
        if (Application::hasInstance()) {
            try {
                $resolved = app(HttpAdapterContract::class);
                if ($resolved instanceof HttpAdapterContract) {
                    return $resolved;
                }
            } catch (\Throwable) {
                // Fallback below.
            }
        }

        return new HttpAdapter();
    }
}
