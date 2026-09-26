<?php

/**
 * Microsoft Graph API mail sender.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer\Api;

use BooleanSmtp\Adapters\Contracts\HttpAdapterContract;
use BooleanSmtp\Adapters\WordPress\HttpAdapter;
use BooleanSmtp\Core\Foundation\Application;
use function BooleanSmtp\Core\app;

/**
 * Sends mail through the Microsoft Graph API's `sendMail` action, as a raw RFC 822 MIME body or
 * as a structured JSON message. A delivery mode another plugin provides is handed to
 * {@see ExtensionDeliveryModes}.
 *
 * OAuth access tokens for the delegated `api` mode are refreshed automatically when they are
 * missing or close to expiring.
 *
 * @since 1.0.0
 */
final class MicrosoftGraphMailSender {
    /**
     * How many seconds before its actual expiration an access token is treated as expired, so a
     * refresh can complete before the token is used to send a message.
     *
     * @since 1.0.0
     * @var int
     */
    private const TOKEN_REFRESH_LEEWAY_SECONDS = 300;

    /**
     * OAuth scope requested for the delegated (`api` mode) refresh token exchange.
     *
     * Must be kept in sync with {@see \BooleanSmtp\Http\Controllers\OAuthController::MICROSOFT_DELEGATED_SCOPE},
     * which requests the same scope during the initial authorization.
     *
     * @since 1.0.0
     * @var string
     */
    private const MICROSOFT_DELEGATED_SCOPE = 'offline_access https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/Mail.Send.Shared https://graph.microsoft.com/User.Read';

    /**
     * Diagnostic details about the most recent token resolution or delivery-mode dispatch, for
     * the admin UI and logs.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    private array $authDebug = [];

    /**
     * Token fields refreshed during the most recent call, pending persistence by the caller.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    private array $persistableTokenFields = [];

    /**
     * Diagnostic details about the most recent Graph HTTP request, for the admin UI and logs.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    private array $lastRequestDebug = [];

    /**
     * HTTP client used to call the Microsoft Graph and identity platform APIs.
     *
     * @since 1.0.0
     * @var HttpAdapterContract
     */
    private HttpAdapterContract $http;

    /**
     * Resolve the HTTP adapter used to call the Microsoft Graph and identity platform APIs.
     *
     * Uses the given adapter when provided, otherwise resolves `HttpAdapterContract` from the
     * container, falling back to a direct WordPress-backed adapter when the container is
     * unavailable or resolution fails.
     *
     * @since 1.0.0
     *
     * @param HttpAdapterContract|null $http HTTP adapter to use, or null to resolve one.
     */
    public function __construct(?HttpAdapterContract $http = null) {
        if ($http instanceof HttpAdapterContract) {
            $this->http = $http;
            return;
        }

        if (Application::hasInstance()) {
            try {
                $resolved = app(HttpAdapterContract::class);
                if ($resolved instanceof HttpAdapterContract) {
                    $this->http = $resolved;
                    return;
                }
            } catch (\Throwable) {
                // Fallback to WordPress adapter below.
            }
        }

        $this->http = new HttpAdapter();
    }

    /**
     * Send a message built by PHPMailer through Microsoft Graph.
     *
     * A delivery mode another plugin provides is handed to {@see ExtensionDeliveryModes}. In the
     * delegated `api` mode, a message with attachments is always
     * sent through the raw-MIME path, since the JSON `sendMail` payload built by
     * {@see self::buildGraphMessagePayload()} does not carry PHPMailer attachments; a message
     * without attachments is sent as JSON, with a raw-MIME fallback on failure. The raw-MIME path
     * does not itself extend support to attachments large enough to exceed Graph's inline
     * `sendMail` size limit of approximately 4 MB, which still requires a chunked upload session
     * that this integration does not implement; such a send fails rather than silently omitting
     * the attachment.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>              $settings  Decrypted connection settings. Passed
     *                                                        by reference because a token refresh
     *                                                        updates it in place.
     * @param  \PHPMailer\PHPMailer\PHPMailer     $phpmailer PHPMailer instance holding the message
     *                                                        to send.
     * @return bool|\WP_Error True when Graph accepted the message; a `WP_Error` describing the
     *                        failure otherwise.
     */
    public function sendGraphMessage(array &$settings, \PHPMailer\PHPMailer\PHPMailer $phpmailer): bool | \WP_Error {
        $this->lastRequestDebug = [];
        $deliveryMode = (string) ($settings['delivery_mode'] ?? 'api');

        if ($deliveryMode !== 'api') {
            return $this->dispatchExtensionMode('send', $deliveryMode, $settings, $this->extractMimeMessage($phpmailer));
        }

        $token = $this->getValidAccessToken($settings, false);
        if ($token === '') {
            return new \WP_Error('booleansmtp_graph', 'Microsoft Graph requires a valid OAuth access or refresh token.');
        }

        // The JSON payload does not carry attachments; route through raw MIME instead, which
        // PHPMailer already builds with attachments included.
        if ($this->hasAttachments($phpmailer)) {
            $mime = $this->extractMimeMessage($phpmailer);
            if ($mime !== '') {
                return $this->sendRawMime($settings, $mime);
            }
        }

        return $this->sendJsonMessageWithToken($settings, $phpmailer, $token);
    }

    /**
     * Check whether a PHPMailer instance has one or more attachments.
     *
     * @since 1.0.0
     *
     * @param  \PHPMailer\PHPMailer\PHPMailer  $phpmailer PHPMailer instance to inspect.
     * @return bool True when the message has at least one attachment.
     */
    private function hasAttachments(\PHPMailer\PHPMailer\PHPMailer $phpmailer): bool {
        if (!\method_exists($phpmailer, 'getAttachments')) {
            return false;
        }

        $attachments = $phpmailer->getAttachments();

        return \is_array($attachments) && $attachments !== [];
    }

    /**
     * Send through, or check, a delivery mode another plugin provides.
     *
     * @since 1.0.0
     *
     * @param  string                $operation `send` or `probe`.
     * @param  string                $mode      The connection's delivery mode.
     * @param  array<string, mixed>  $settings  Decrypted connection settings. Passed by reference
     *                                          because the handling plugin may ask to store settings.
     * @param  string                $rawMime   Raw MIME message to send; empty for `probe`.
     * @return bool|\WP_Error True on success; a `WP_Error` describing the failure otherwise.
     */
    private function dispatchExtensionMode(string $operation, string $mode, array &$settings, string $rawMime = ''): bool | \WP_Error {
        $this->authDebug = [
            'token_source'            => 'delivery_mode:' . $mode,
            'refresh_attempted'       => false,
            'refresh_succeeded'       => false,
            'token_expires_at'        => null,
            'token_refresh_at'        => null,
            'token_seconds_remaining' => null
        ];

        [$result, $stored] = ExtensionDeliveryModes::dispatch($operation, $mode, 'outlook', $settings, $rawMime);

        $this->persistableTokenFields = $stored;
        foreach ($stored as $key => $value) {
            $settings[$key] = $value;
        }

        return $result;
    }

    /**
     * Resolve the Graph `sendMail` path for a connection.
     *
     * Returns `/me/sendMail` for the authorizing mailbox itself, or `/users/{mailbox}/sendMail`
     * when `send_as_shared_mailbox` is set, to send as a shared or delegated mailbox named in
     * `from_email`. Only meaningful for the delegated `api` mode.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $settings Decrypted connection settings.
     * @return string Graph API path, relative to the Graph base URL.
     */
    private function sendMailPath(array $settings): string {
        if (empty($settings['send_as_shared_mailbox'])) {
            return '/me/sendMail';
        }

        $mailbox = trim((string) ($settings['from_email'] ?? ''));
        if ($mailbox === '') {
            return '/me/sendMail';
        }

        return '/users/' . rawurlencode($mailbox) . '/sendMail';
    }

    /**
     * Send a raw MIME message through Microsoft Graph's `sendMail` action.
     *
     * The message is base64-encoded and line-chunked at 76 characters, which Graph expects for a
     * raw MIME `sendMail` request and rejects otherwise even with a valid OAuth token. A 401
     * response is retried once with a forced token refresh, since some tenant policies can reject
     * a request with 401 even when the access token has not actually expired; when the retry still
     * fails, a diagnostic call to `/me` distinguishes a token/scope/tenant problem from a payload
     * problem.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $settings Decrypted connection settings. Passed by reference
     *                                          because a token refresh updates it in place.
     * @param  string                $mime     Raw MIME message to send.
     * @return bool|\WP_Error True when Graph accepted the message; a `WP_Error` describing the
     *                        failure otherwise.
     */
    public function sendRawMime(array &$settings, string $mime): bool | \WP_Error {
        $retryCount = 0;
        $token      = $this->getValidAccessToken($settings, false);
        if ($token === '') {
            return new \WP_Error('booleansmtp_graph', 'Microsoft Graph requires a valid OAuth access or refresh token.');
        }

        $diagnosticHint = '';

        $encodedMime    = chunk_split(base64_encode($mime), 76, "\n");
        $requestHeaders = [
            // Avoid leaking tokens in debug output.
            'Authorization' => 'Bearer [redacted]',
            'Content-Type'  => 'text/plain'
        ];
        $requestBodyPreview = \substr($encodedMime, 0, 220);
        $requestBodyPreview = \str_replace("\r", '', $requestBodyPreview);
        $requestBodyPreview = \str_replace("\n", '\\n', $requestBodyPreview);

        $requestUrl = $this->graphResourceUrl($this->sendMailPath($settings));
        do {
            $response = $this->http->request('POST', $requestUrl, [
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'text/plain'
                ],
                'body'    => $encodedMime
            ]);

            if ($this->http->isError($response)) {
                $this->lastRequestDebug = [
                    'request_url'    => $requestUrl,
                    'request_method' => 'POST',
                    'request_stage'  => 'send',
                    'retry'          => $retryCount,
                    'provider_error' => $this->http->getErrorMessage($response)
                ];
                return new \WP_Error('booleansmtp_graph_http', $this->http->getErrorMessage($response));
            }

            $code    = $this->http->responseCode($response);
            $body    = $this->http->responseBody($response);
            $headers = \is_array($response) ? ($response['headers'] ?? []) : [];

            // WordPress may return a WP_HTTP_Headers object, not a plain array.
            $providerHeaders = [];
            if (is_array($headers)) {
                $providerHeaders = $headers;
            } elseif (is_object($headers) && method_exists($headers, 'getAll')) {
                /** @var mixed $all */
                $all             = $headers->getAll();
                $providerHeaders = is_array($all) ? $all : [];
            }

            $this->lastRequestDebug = [
                'request_url'                 => $requestUrl,
                'request_method'              => 'POST',
                'request_stage'               => 'send',
                'retry'                       => $retryCount,
                'status'                      => $code,
                'provider_body'               => $body,
                'provider_headers'            => $providerHeaders,
                'request_headers'             => $requestHeaders,
                'request_body_base64_length'  => \strlen($encodedMime),
                'request_body_base64_preview' => $requestBodyPreview
            ];

            if ($code >= 300) {
                $json = json_decode($body, true);
                $providerCode = is_array($json) ? trim((string) ($json['error']['code'] ?? '')) : '';
                if ($providerCode !== '') {
                    $this->lastRequestDebug['provider_error_code'] = $providerCode;
                }
                $msg  = is_array($json) && isset($json['error']['message'])
                ? (string) $json['error']['message']
                : $body;

                // Strict tenant/policy setups sometimes return 401 even when the access token
                // isn't expired yet (revocation / invalid token). Retry once with a forced refresh.
                if ($code === 401 && $retryCount === 0) {
                    $hasRefresh = isset($settings['refresh_token']) && is_string($settings['refresh_token']) && trim($settings['refresh_token']) !== '';
                    if ($hasRefresh) {
                        $retryCount++;
                        $token = $this->getValidAccessToken($settings, true);
                        if ($token !== '') {
                            continue;
                        }

                        // When forced refresh fails (e.g. invalid client secret), return that root-cause
                        // instead of a secondary Graph token-check error.
                        $refreshMessage = (string) ($this->authDebug['refresh_error'] ?? 'Microsoft OAuth token refresh failed.');
                        $refreshStatus  = isset($this->authDebug['refresh_status_code']) ? (int) $this->authDebug['refresh_status_code'] : 0;
                        return new \WP_Error('booleansmtp_graph_refresh', $refreshMessage, [
                            'status'  => $refreshStatus,
                            'body'    => $body,
                            'headers' => $headers
                        ]);
                    }
                }

                // Diagnostic: confirm whether the token itself is accepted by Graph.
                // If /me also returns 401, this is a token/scopes/tenant problem (not payload formatting).
                if ($code === 401) {
                    $checkUrl     = $this->graphResourceUrl('/me');
                    $checkHeaders = [
                        'Authorization' => 'Bearer [redacted]',
                        'Content-Type'  => 'application/json'
                    ];

                    $checkResponse = $this->http->request('GET', $checkUrl, [
                        'timeout' => 20,
                        'headers' => [
                            'Authorization' => 'Bearer ' . $token,
                            'Content-Type'  => 'application/json'
                        ]
                    ]);

                    $checkStatus      = 0;
                    $checkBody        = '';
                    $checkRespHeaders = [];
                    if (!$this->http->isError($checkResponse)) {
                        $checkStatus     = $this->http->responseCode($checkResponse);
                        $checkBody       = $this->http->responseBody($checkResponse);
                        $checkHeadersObj = \is_array($checkResponse) ? ($checkResponse['headers'] ?? []) : [];
                        if (is_array($checkHeadersObj)) {
                            $checkRespHeaders = $checkHeadersObj;
                        } elseif (is_object($checkHeadersObj) && method_exists($checkHeadersObj, 'getAll')) {
                            $all              = $checkHeadersObj->getAll();
                            $checkRespHeaders = is_array($all) ? $all : [];
                        }
                    }

                    $this->lastRequestDebug['token_check'] = [
                        'request_url'      => $checkUrl,
                        'request_method'   => 'GET',
                        'request_headers'  => $checkHeaders,
                        'status'           => $checkStatus !== 0 ? $checkStatus : null,
                        'provider_body'    => $checkBody,
                        'provider_headers' => $checkRespHeaders
                    ];

                    $diagnosticHint = $this->diagnoseUnauthorizedSendMail($checkStatus !== 0 ? $checkStatus : null, $checkBody);
                    if ($diagnosticHint !== '') {
                        $this->lastRequestDebug['diagnostic_hint'] = $diagnosticHint;
                    }
                }

                $message = trim((string) $msg);
                if ($message === '') {
                    $message = 'Microsoft Graph sendMail returned HTTP ' . $code . '.';
                }
                if ($diagnosticHint !== '') {
                    $message .= ' ' . $diagnosticHint;
                }

                return new \WP_Error('booleansmtp_graph_http', $message, ['status' => $code, 'body' => $body, 'headers' => $headers]);
            }

            return true;
        } while ($retryCount < 2);

        // Should never get here, but keep return type stable.
        return new \WP_Error('booleansmtp_graph', 'Microsoft Graph sendMail failed.');
    }

    /**
     * Turn a diagnostic `/me` call's result into a human-readable hint after a `sendMail` 401.
     *
     * @since 1.0.0
     *
     * @param  int|null  $tokenCheckStatus HTTP status of the `/me` diagnostic call, or null when
     *                                      the call itself failed.
     * @param  string    $tokenCheckBody   Raw response body of the `/me` diagnostic call.
     * @return string Human-readable hint, or an empty string when no more specific diagnosis
     *                could be made.
     */
    private function diagnoseUnauthorizedSendMail(?int $tokenCheckStatus, string $tokenCheckBody): string {
        if ($tokenCheckStatus === null) {
            return '';
        }

        if ($tokenCheckStatus === 401) {
            return 'Graph token validation also failed. Reconnect Microsoft OAuth and verify app credentials/scopes.';
        }

        if ($tokenCheckStatus !== 200) {
            return '';
        }

        $profile = \json_decode($tokenCheckBody, true);
        if (!\is_array($profile)) {
            return '';
        }

        $mail = trim((string) ($profile['mail'] ?? ''));
        $upn  = trim((string) ($profile['userPrincipalName'] ?? ''));

        if ($mail === '' && str_contains($upn, '#EXT#')) {
            return 'Authenticated identity appears to be a guest user without a mailbox in this tenant. Re-authorize with a mailbox-enabled account in this tenant or use tenant_id=common with a multi-tenant/personal app and reconnect.';
        }

        if ($mail === '') {
            return 'Authenticated Microsoft profile has no mailbox address. Use an account with an Exchange mailbox and set From Email to that mailbox.';
        }

        return '';
    }

    /**
     * Send a message as structured Graph JSON, for the delegated `api` mode only.
     *
     * A delivery mode another plugin provides never calls this method. Attachment presence is already routed around this
     * method by {@see self::sendGraphMessage()}'s attachment check, so this path only runs for
     * attachment-free messages. Falls back to the raw-MIME path on any JSON send failure.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>           $settings  Decrypted connection settings. Passed by
     *                                                     reference because a raw-MIME fallback may
     *                                                     trigger a token refresh that updates it
     *                                                     in place.
     * @param  \PHPMailer\PHPMailer\PHPMailer  $phpmailer PHPMailer instance holding the message to
     *                                                     send.
     * @param  string                          $token     OAuth access token.
     * @return bool|\WP_Error True when Graph accepted the message; a `WP_Error` describing the
     *                        failure otherwise.
     */
    private function sendJsonMessageWithToken(
        array &$settings,
        \PHPMailer\PHPMailer\PHPMailer $phpmailer,
        string $token
    ): bool | \WP_Error {
        $requestUrl = $this->graphResourceUrl($this->sendMailPath($settings));
        $payload    = [
            'saveToSentItems' => true,
            'message'         => $this->buildGraphMessagePayload($phpmailer)
        ];

        $response = $this->http->request('POST', $requestUrl, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json'
            ],
            'body'    => \wp_json_encode($payload)
        ]);

        if ($this->http->isError($response)) {
            $this->lastRequestDebug = [
                'request_url'    => $requestUrl,
                'request_method' => 'POST',
                'request_stage'  => 'send_json',
                'provider_error' => $this->http->getErrorMessage($response)
            ];

            $mime = $this->extractMimeMessage($phpmailer);
            if ($mime !== '') {
                $result = $this->sendRawMime($settings, $mime);
                $this->lastRequestDebug['initial_json_error'] = [
                    'status'              => null,
                    'provider_error_code' => 'transport_error'
                ];
                return $result;
            }

            return new \WP_Error('booleansmtp_graph_http', $this->http->getErrorMessage($response));
        }

        $code = $this->http->responseCode($response);
        $body = $this->http->responseBody($response);

        $this->lastRequestDebug = [
            'request_url'    => $requestUrl,
            'request_method' => 'POST',
            'request_stage'  => 'send_json',
            'status'         => $code,
            'provider_body'  => $body
        ];

        if ($code >= 300) {
            $json = json_decode($body, true);
            $providerCode = is_array($json) ? trim((string) ($json['error']['code'] ?? '')) : '';
            if ($providerCode !== '') {
                $this->lastRequestDebug['provider_error_code'] = $providerCode;
            }

            $mime = $this->extractMimeMessage($phpmailer);
            if ($mime !== '') {
                $result = $this->sendRawMime($settings, $mime);
                $this->lastRequestDebug['initial_json_error'] = [
                    'status'              => $code,
                    'provider_error_code' => $providerCode
                ];
                return $result;
            }

            $msg  = is_array($json) && isset($json['error']['message'])
            ? (string) $json['error']['message']
            : ($body !== '' ? $body : 'Microsoft Graph sendMail failed.');

            return new \WP_Error('booleansmtp_graph_http', (string) $msg, ['status' => $code, 'body' => $body]);
        }

        return true;
    }

    /**
     * Build a Graph `message` JSON object from PHPMailer's fields.
     *
     * Does not carry attachments; see {@see self::sendGraphMessage()} for how messages with
     * attachments are routed instead.
     *
     * @since 1.0.0
     *
     * @param  \PHPMailer\PHPMailer\PHPMailer  $phpmailer PHPMailer instance holding the message.
     * @return array<string, mixed> Graph `message` object, ready to embed in a `sendMail` request.
     */
    private function buildGraphMessagePayload(\PHPMailer\PHPMailer\PHPMailer $phpmailer): array {
        $contentType = strtolower((string) $phpmailer->ContentType) === 'text/plain' ? 'Text' : 'HTML';

        $message = [
            'subject' => (string) $phpmailer->Subject,
            'body'    => [
                'contentType' => $contentType,
                'content'     => (string) $phpmailer->Body
            ]
        ];

        $to = $this->normalizeRecipientsForGraph($this->readAddressList($phpmailer, 'getToAddresses', 'to'));
        if ($to !== []) {
            $message['toRecipients'] = $to;
        }

        $cc = $this->normalizeRecipientsForGraph($this->readAddressList($phpmailer, 'getCcAddresses', 'cc'));
        if ($cc !== []) {
            $message['ccRecipients'] = $cc;
        }

        $bcc = $this->normalizeRecipientsForGraph($this->readAddressList($phpmailer, 'getBccAddresses', 'bcc'));
        if ($bcc !== []) {
            $message['bccRecipients'] = $bcc;
        }

        $replyTo = $this->normalizeRecipientsForGraph($this->readAddressList($phpmailer, 'getReplyToAddresses', 'ReplyTo'));
        if ($replyTo !== []) {
            $message['replyTo'] = $replyTo;
        }

        $from = trim((string) $phpmailer->From);
        if ($from !== '') {
            $fromAddress = ['address' => $from];
            if (trim((string) $phpmailer->FromName) !== '') {
                $fromAddress['name'] = (string) $phpmailer->FromName;
            }

            $message['from'] = ['emailAddress' => $fromAddress];
        }

        return $message;
    }

    /**
     * Convert `[email, name]` address rows into Graph `recipient` objects.
     *
     * @since 1.0.0
     *
     * @param  array<int, array<int, string>>  $addresses Address rows, each `[email, name]`.
     * @return array<int, array<string, array<string, string>>> List of Graph `recipient` objects;
     *         rows with an empty email are skipped.
     */
    private function normalizeRecipientsForGraph(array $addresses): array {
        $recipients = [];

        foreach ($addresses as $row) {
            $email = trim((string) ($row[0] ?? ''));
            $name  = trim((string) ($row[1] ?? ''));
            if ($email === '') {
                continue;
            }

            $emailAddress = ['address' => $email];
            if ($name !== '') {
                $emailAddress['name'] = $name;
            }

            $recipients[] = ['emailAddress' => $emailAddress];
        }

        return $recipients;
    }

    /**
     * Extract the fully-built raw MIME message from a PHPMailer instance.
     *
     * @since 1.0.0
     *
     * @param  \PHPMailer\PHPMailer\PHPMailer  $phpmailer PHPMailer instance holding the message.
     * @return string Raw MIME message, or an empty string when PHPMailer has not built one.
     */
    private function extractMimeMessage(\PHPMailer\PHPMailer\PHPMailer $phpmailer): string {
        if (\method_exists($phpmailer, 'getSentMIMEMessage')) {
            $mime = $phpmailer->getSentMIMEMessage();
            return \is_string($mime) ? $mime : '';
        }

        return '';
    }

    /**
     * Read a PHPMailer address list, preferring its accessor method over the raw property.
     *
     * @since 1.0.0
     *
     * @param  \PHPMailer\PHPMailer\PHPMailer  $phpmailer PHPMailer instance to read from.
     * @param  string                          $method    Accessor method name, for example
     *                                                      `getToAddresses`.
     * @param  string                          $property  Fallback property name, used when the
     *                                                      accessor method does not exist.
     * @return array<int, array<int, string>> Normalized `[email, name]` address rows.
     */
    private function readAddressList(\PHPMailer\PHPMailer\PHPMailer $phpmailer, string $method, string $property): array {
        if (\method_exists($phpmailer, $method)) {
            $value = $phpmailer->{$method}();
            if (\is_array($value)) {
                return $this->normalizeAddressRows($value);
            }

            return [];
        }

        $fallback = $phpmailer->{$property} ?? [];
        if (!\is_array($fallback)) {
            return [];
        }

        return $this->normalizeAddressRows($fallback);
    }

    /**
     * Normalize PHPMailer address rows, in either indexed `[email, name]` or associative
     * `[email => name]` form, into a consistent list of `[email, name]` pairs.
     *
     * @since 1.0.0
     *
     * @param  array<int|string, mixed>  $rows Raw address rows from a PHPMailer property.
     * @return array<int, array<int, string>> Normalized `[email, name]` address rows; rows with an
     *         empty email are skipped.
     */
    private function normalizeAddressRows(array $rows): array {
        $out = [];

        foreach ($rows as $key => $value) {
            if (\is_array($value)) {
                $email = (string) ($value[0] ?? '');
                $name  = (string) ($value[1] ?? '');
                if ($email !== '') {
                    $out[] = [$email, $name];
                }
                continue;
            }

            if (\is_string($key)) {
                $email = trim($key);
                if ($email !== '') {
                    $out[] = [$email, \is_string($value) ? $value : ''];
                }
            }
        }

        return $out;
    }

    /**
     * Verify that a connection's Microsoft Graph credentials are usable.
     *
     * A delivery mode another plugin provides is handed to {@see ExtensionDeliveryModes}.
     * Otherwise resolves a valid OAuth access token, refreshing it
     * first if needed; when `$verifySendCapability` is true, additionally sends a real (but
     * unsaved) `sendMail` request to the connection's From address to confirm send capability,
     * rather than only validating the token.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $settings             Decrypted connection settings. Passed
     *                                                       by reference because a token refresh
     *                                                       updates it in place.
     * @param  bool                  $verifySendCapability Whether to also verify send capability
     *                                                       with a live `sendMail` call, not just
     *                                                       token validity.
     * @return bool|\WP_Error True when the credentials (and, if checked, send capability) are
     *                        usable; a `WP_Error` describing the failure otherwise.
     */
    public function probe(array &$settings, bool $verifySendCapability = false): bool | \WP_Error {
        $deliveryMode = (string) ($settings['delivery_mode'] ?? 'api');

        if ($deliveryMode !== 'api') {
            return $this->dispatchExtensionMode('probe', $deliveryMode, $settings);
        }

        $forceRefresh = !empty($settings['force_refresh']);
        $token        = $this->getValidAccessToken($settings, $forceRefresh);
        if ($token === '') {
            return new \WP_Error('booleansmtp_graph', 'Microsoft Graph requires a valid OAuth access or refresh token.');
        }

        if ($verifySendCapability) {
            $fromEmail = trim((string) ($settings['from_email'] ?? ''));
            if ($fromEmail === '') {
                return new \WP_Error('booleansmtp_graph_probe', 'Microsoft Graph send capability check requires a valid From Email.');
            }

            $checkUrl      = $this->graphResourceUrl($this->sendMailPath($settings));
            $checkResponse = $this->http->request('POST', $checkUrl, [
                'timeout' => 20,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json'
                ],
                'body'    => \wp_json_encode([
                    'saveToSentItems' => false,
                    'message'         => [
                        'subject'      => '[BooleanSMTP] Microsoft capability check',
                        'body'         => [
                            'contentType' => 'Text',
                            'content'     => 'BooleanSMTP connection capability check.'
                        ],
                        'toRecipients' => [[
                            'emailAddress' => ['address' => $fromEmail]
                        ]]
                    ]
                ])
            ]);

            if ($this->http->isError($checkResponse)) {
                $this->lastRequestDebug = [
                    'request_stage'  => 'probe',
                    'probe_strategy' => 'send_capability',
                    'request_url'    => $checkUrl,
                    'provider_error' => $this->http->getErrorMessage($checkResponse)
                ];

                return new \WP_Error('booleansmtp_graph_probe', $this->http->getErrorMessage($checkResponse));
            }

            $code                   = $this->http->responseCode($checkResponse);
            $body                   = $this->http->responseBody($checkResponse);
            $this->lastRequestDebug = [
                'request_stage'  => 'probe',
                'probe_strategy' => 'send_capability',
                'request_url'    => $checkUrl,
                'status'         => $code,
                'provider_body'  => $body
            ];

            if ($code >= 300) {
                $json    = json_decode($body, true);
                $message = is_array($json) && isset($json['error']['message'])
                ? (string) $json['error']['message']
                : ($body !== '' ? $body : 'Microsoft capability check failed.');

                return new \WP_Error('booleansmtp_graph_probe', $message, [
                    'status' => $code,
                    'body'   => $body
                ]);
            }

            return true;
        }

        $this->lastRequestDebug = [
            'request_stage'  => 'probe',
            'probe_strategy' => 'token_validation_only'
        ];
        return true;
    }

    /**
     * Get diagnostic details about the most recent token resolution, dispatch and HTTP request.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> Combined diagnostic details for the admin UI and logs.
     */
    public function getAuthDebug(): array {
        return array_merge($this->authDebug, $this->lastRequestDebug);
    }

    /**
     * Take and clear the token fields refreshed during the most recent call.
     *
     * Callers use this to persist a refreshed access token, expiration and refresh token back
     * onto the connection; the internal buffer is cleared so the same fields are not persisted
     * twice.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> Refreshed token fields keyed by setting name; empty when
     *                              nothing was refreshed since the last call.
     */
    public function consumePersistableTokenFields(): array {
        $fields                       = $this->persistableTokenFields;
        $this->persistableTokenFields = [];

        return $fields;
    }

    /**
     * Resolve a usable Microsoft Graph OAuth access token, refreshing it first if it is missing,
     * close to expiring, or a refresh is explicitly requested.
     *
     * A token is treated as expired when `$forceRefresh` is true, when no expiration is recorded,
     * or when fewer than {@see self::TOKEN_REFRESH_LEEWAY_SECONDS} seconds remain before its
     * recorded expiration (a Unix timestamp, UTC). When a refresh is attempted and fails, an
     * already-expired token still returns an empty string, while a token that has not yet expired
     * is returned as-is so a send can still be attempted with it.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $settings     Decrypted connection settings. Passed by
     *                                               reference because a successful refresh updates
     *                                               `access_token`, `token_expires_at` and
     *                                               `refresh_token` in place.
     * @param  bool                  $forceRefresh Whether to refresh the token even if it has not
     *                                               yet reached the leeway threshold.
     * @return string Valid access token, or an empty string when none could be resolved.
     */
    private function getValidAccessToken(array &$settings, bool $forceRefresh): string {
        $accessToken  = (string) ($settings['access_token'] ?? '');
        $refreshToken = (string) ($settings['refresh_token'] ?? '');
        $expiresAt    = (int) ($settings['token_expires_at'] ?? 0);
        $now          = time();
        $refreshAt    = $expiresAt > 0 ? ($expiresAt - self::TOKEN_REFRESH_LEEWAY_SECONDS) : 0;
        $isExpired    = $forceRefresh || $expiresAt <= 0 || ($refreshAt <= $now);

        $this->authDebug = [
            'token_source'            => 'unknown',
            'refresh_attempted'       => false,
            'refresh_succeeded'       => false,
            'token_expires_at'        => $expiresAt,
            'token_refresh_at'        => $refreshAt,
            'token_seconds_remaining' => $expiresAt > 0 ? ($expiresAt - $now) : null
        ];
        $this->persistableTokenFields = [];

        if (!$isExpired && $accessToken !== '') {
            $this->authDebug['token_source'] = 'access_token_cached';
            return $accessToken;
        }

        $clientId     = \trim((string) ($settings['client_id'] ?? ''));
        $clientSecret = \trim((string) ($settings['client_secret'] ?? ''));
        $tenantId     = \trim((string) ($settings['tenant_id'] ?? 'common'));

        if ($refreshToken === '' || $clientId === '' || $clientSecret === '') {
            $this->authDebug['token_source'] = 'missing_refresh_or_client';
            if ($isExpired) {
                return '';
            }
            return $accessToken;
        }

        /** This filter is documented in app/Services/Mailer/OAuth/OAuthManualTokenExchange.php */
        $tokenUrl = (string) apply_filters(
            'boolean_smtp_microsoft_token_url',
            "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token",
            $tenantId
        );
        $this->authDebug['refresh_attempted'] = true;

        $response = $this->http->post($tokenUrl, [
            'timeout' => 20,
            'body'    => [
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type'    => 'refresh_token',
                'scope'         => self::MICROSOFT_DELEGATED_SCOPE
            ]
        ]);

        if ($this->http->isError($response)) {
            $this->authDebug['token_source']  = 'refresh_failed_network';
            $this->authDebug['refresh_error'] = $this->http->getErrorMessage($response);
            if ($isExpired) {
                return '';
            }
            return $accessToken;
        }

        $status  = $this->http->responseCode($response);
        $rawBody = $this->http->responseBody($response);
        $body    = json_decode($rawBody, true);
        if (isset($body['access_token']) && is_string($body['access_token']) && $body['access_token'] !== '') {
            $newAccessToken = (string) $body['access_token'];
            $newExpiresAt   = isset($body['expires_in']) ? (time() + (int) $body['expires_in']) : 0;
            $newRefresh     = isset($body['refresh_token']) ? (string) $body['refresh_token'] : $refreshToken;

            $settings['access_token']     = $newAccessToken;
            $settings['token_expires_at'] = $newExpiresAt;
            if ($newRefresh !== '') {
                $settings['refresh_token'] = $newRefresh;
            }

            $this->persistableTokenFields = [
                'access_token'     => $newAccessToken,
                'token_expires_at' => $newExpiresAt,
                'refresh_token'    => $newRefresh
            ];
            $this->authDebug['token_source']            = 'refresh_token_exchange';
            $this->authDebug['refresh_succeeded']       = true;
            $this->authDebug['refresh_status_code']     = $status;
            $this->authDebug['token_expires_at']        = $newExpiresAt;
            $this->authDebug['token_seconds_remaining'] = $newExpiresAt > 0 ? ($newExpiresAt - time()) : null;

            return $newAccessToken;
        }

        $this->authDebug['token_source']        = 'refresh_failed_response';
        $this->authDebug['refresh_status_code'] = $status;
        if (is_array($body)) {
            $this->authDebug['refresh_error'] = (string) ($body['error_description'] ?? $body['error'] ?? 'Token refresh failed.');
            if (isset($body['error'])) {
                $this->authDebug['refresh_error_code'] = (string) $body['error'];
            }
            if (isset($body['error_description'])) {
                $this->authDebug['refresh_error_description'] = (string) $body['error_description'];
            }
            $this->authDebug['refresh_provider_body'] = $body;
        } else {
            $this->authDebug['refresh_error']         = $rawBody !== '' ? $rawBody : 'Token refresh failed.';
            $this->authDebug['refresh_provider_body'] = $rawBody;
        }
        if ($isExpired) {
            return '';
        }
        return $accessToken;
    }

    /**
     * Build a full Microsoft Graph API URL from a resource path.
     *
     * @since 1.0.0
     *
     * @param  string  $path Resource path, for example `/me/sendMail`.
     * @return string Full URL, combining the Graph base URL with the resource path.
     */
    private function graphResourceUrl(string $path): string {
        /**
         * Filters the Microsoft Graph API base URL.
         *
         * Allows targeting a national cloud deployment (for example Microsoft Graph for US
         * Government or operated by 21Vianet) instead of the global endpoint.
         *
         * @since 1.0.0
         *
         * @param string $baseUrl Default Microsoft Graph base URL.
         * @return string The filtered base URL.
         */
        $base = (string) apply_filters('boolean_smtp_microsoft_graph_base_url', 'https://graph.microsoft.com/v1.0');

        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}
