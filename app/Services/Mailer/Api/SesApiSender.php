<?php

/**
 * AWS SES v1 Query API mail sender.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer\Api;

use BooleanSmtp\Adapters\Contracts\HttpAdapterContract;
use BooleanSmtp\Adapters\WordPress\HttpAdapter;
use BooleanSmtp\Services\Settings\SesApiVersionProvider;
use BooleanSmtp\Core\Foundation\Application;
use function BooleanSmtp\Core\app;

/**
 * Sends mail through the AWS SES v1 Query API's `SendRawEmail` action, authenticated with an
 * IAM access key and secret key.
 *
 * Also exposes `GetSendQuota` and `GetSendStatistics` for the account activity shown in the admin
 * UI. Delegates to {@see SesV2ApiSender} when the connection is configured to use the SES v2 JSON
 * API instead; see {@see \BooleanSmtp\Services\Settings\SesApiVersionProvider} for how the active
 * version is resolved.
 *
 * @since 1.0.0
 */
final class SesApiSender {
    /**
     * Diagnostic details from the most recent send, probe or quota/statistics call, for the
     * admin UI and logs.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    private array $lastResponseMeta = [];

    /**
     * HTTP client used to call the SES API.
     *
     * @since 1.0.0
     * @var HttpAdapterContract
     */
    private HttpAdapterContract $http;

    /**
     * Resolve the HTTP adapter used to call the SES API.
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
     * Get diagnostic details from the most recent send, probe or quota/statistics call.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> Request and response details for troubleshooting; empty before
     *                              the first call.
     */
    public function getLastResponseMeta(): array {
        return $this->lastResponseMeta;
    }

    /**
     * Send a raw MIME message through SES.
     *
     * Delegates to {@see SesV2ApiSender} when the connection resolves to the SES v2 API; otherwise
     * sends through the v1 Query API's `SendRawEmail` action, optionally against a custom endpoint
     * (for example a VPC PrivateLink endpoint) instead of the regional default.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $settings Decrypted connection settings; reads `access_key`,
     *                                          `secret` or `secret_key`, `region`,
     *                                          `custom_endpoint` and `configuration_set` (via the
     *                                          v2 request builder).
     * @param  string                $rawMime  Raw MIME message to send.
     * @return bool|\WP_Error True when SES accepted the message; a `WP_Error` describing the
     *                        failure otherwise.
     */
    public function sendRawMime(array $settings, string $rawMime): bool | \WP_Error {
        $this->lastResponseMeta = [];

        $credentials = $this->resolveValidatedCredentials($settings);
        if ($credentials instanceof \WP_Error) {
            return $credentials;
        }

        $settings  = $this->withSesApiCredentialAliases($settings);
        $accessKey = $credentials['access_key'];
        $secret    = $credentials['secret'];
        $region    = $credentials['region'];

        $apiVersion = SesApiVersionProvider::resolveVersion($settings);
        if ($apiVersion === 2) {
            $v2Sender               = new SesV2ApiSender($this->http);
            $result                 = $v2Sender->sendRawMime($settings, $rawMime);
            $this->lastResponseMeta = $v2Sender->getLastResponseMeta();
            return $result;
        }

        $customEndpoint = trim((string) ($settings['custom_endpoint'] ?? ''));
        if ($customEndpoint !== '') {
            $host = $customEndpoint;
            // Custom endpoints are assumed to be HTTPS unless the setting already specifies a scheme.
            $url = (strpos($host, 'https://') === 0 || strpos($host, 'http://') === 0)
            ? $customEndpoint
            : 'https://' . $host . '/';
        } else {
            $host = SesEndpoints::apiHost($region);
            $url  = 'https://' . $host . '/';
        }

        $payload = base64_encode($rawMime);

        $postFields = [
            'Action'          => 'SendRawEmail',
            'Version'         => '2010-12-01',
            'RawMessage.Data' => $payload
        ];

        $amzDate = gmdate('Ymd\THis\Z');
        $signed  = AwsSesSigV4::signedPostHeaders($accessKey, $secret, $region, $host, $amzDate, $postFields);

        $response = $this->http->post($url, [
            'timeout' => 30,
            'headers' => $signed['headers'],
            'body'    => $signed['body']
        ]);

        $this->mergeSesHttpExchangeIntoLastMeta($url, $signed, $response);

        if ($this->http->isError($response)) {
            return new \WP_Error('booleansmtp_ses_http', $this->http->getErrorMessage($response));
        }

        $code = $this->http->responseCode($response);
        $body = $this->http->responseBody($response);

        if ($code !== 200) {
            return $this->buildErrorFromResponse($code, $body, $region);
        }

        if (str_contains($body, '<Error>') || str_contains($body, 'ErrorResponse')) {
            return $this->buildErrorFromResponse($code, $body, $region);
        }

        $this->lastResponseMeta = \array_merge($this->lastResponseMeta, [
            'region'     => $region,
            'message_id' => $this->extractFirstXmlTagValue($body, 'MessageId'),
            'request_id' => $this->extractFirstXmlTagValue($body, 'RequestId')
        ]);

        return true;
    }

    /**
     * Fetch the account's current SES sending limits via the `GetSendQuota` action.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $settings Decrypted connection settings.
     * @return array<string, string>|\WP_Error `Max24HourSend`, `MaxSendRate`, `SentLast24Hours`
     *                                         and `RequestId`, or a `WP_Error` describing the
     *                                         failure.
     */
    public function getSendQuota(array $settings): array | \WP_Error {
        $credentials = $this->resolveValidatedCredentials($settings);
        if ($credentials instanceof \WP_Error) {
            return $credentials;
        }

        $accessKey = $credentials['access_key'];
        $secret    = $credentials['secret'];
        $region    = $credentials['region'];

        $host = SesEndpoints::apiHost($region);
        $url  = 'https://' . $host . '/';

        $postFields = [
            'Action'  => 'GetSendQuota',
            'Version' => '2010-12-01'
        ];

        $amzDate = gmdate('Ymd\THis\Z');
        $signed  = AwsSesSigV4::signedPostHeaders($accessKey, $secret, $region, $host, $amzDate, $postFields);

        $response = $this->http->post($url, [
            'timeout' => 15,
            'headers' => $signed['headers'],
            'body'    => $signed['body']
        ]);

        if ($this->http->isError($response)) {
            return new \WP_Error('booleansmtp_ses_http', $this->http->getErrorMessage($response));
        }

        $code = $this->http->responseCode($response);
        $body = $this->http->responseBody($response);

        if ($code !== 200) {
            return $this->buildErrorFromResponse($code, $body, $region);
        }

        if (str_contains($body, '<Error>') || str_contains($body, 'ErrorResponse')) {
            return $this->buildErrorFromResponse($code, $body, $region);
        }

        return [
            'Max24HourSend'   => $this->extractFirstXmlTagValue($body, 'Max24HourSend'),
            'MaxSendRate'     => $this->extractFirstXmlTagValue($body, 'MaxSendRate'),
            'SentLast24Hours' => $this->extractFirstXmlTagValue($body, 'SentLast24Hours'),
            'RequestId'       => $this->extractFirstXmlTagValue($body, 'RequestId')
        ];
    }

    /**
     * Read the SES verification status of a sender address and of its domain.
     *
     * One `GetIdentityVerificationAttributes` call for both identities. An identity SES has never
     * seen is absent from the response and reported as `null`; a known one carries SES's own
     * status word (`Success`, `Pending`, `Failed`, `TemporaryFailure`, `NotStarted`). Sending
     * from the address works when either the address or its domain is `Success`.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $settings  Decrypted connection settings (access key, secret, region).
     * @param  string               $fromEmail The sender address to check.
     * @return array{address: string, address_status: string|null, domain: string, domain_status: string|null, verified: bool}|\WP_Error
     */
    public function getIdentityVerification(array $settings, string $fromEmail): array | \WP_Error {
        $credentials = $this->resolveValidatedCredentials($settings);
        if ($credentials instanceof \WP_Error) {
            return $credentials;
        }

        $address = strtolower(trim($fromEmail));
        $domain  = str_contains($address, '@') ? substr($address, strrpos($address, '@') + 1) : '';
        if ($address === '' || $domain === '') {
            return new \WP_Error('booleansmtp_ses_identity', 'A sender address is required to check its SES identity.');
        }

        $region = $credentials['region'];
        $host   = SesEndpoints::apiHost($region);
        $url    = 'https://' . $host . '/';

        $postFields = [
            'Action'             => 'GetIdentityVerificationAttributes',
            'Version'            => '2010-12-01',
            'Identities.member.1' => $address,
            'Identities.member.2' => $domain,
        ];

        $amzDate = gmdate('Ymd\THis\Z');
        $signed  = AwsSesSigV4::signedPostHeaders($credentials['access_key'], $credentials['secret'], $region, $host, $amzDate, $postFields);

        $response = $this->http->post($url, [
            'timeout' => 15,
            'headers' => $signed['headers'],
            'body'    => $signed['body']
        ]);

        if ($this->http->isError($response)) {
            return new \WP_Error('booleansmtp_ses_http', $this->http->getErrorMessage($response));
        }

        $code = $this->http->responseCode($response);
        $body = $this->http->responseBody($response);

        if ($code !== 200 || str_contains($body, '<Error>') || str_contains($body, 'ErrorResponse')) {
            return $this->buildErrorFromResponse($code, $body, $region);
        }

        $statuses = [];
        $xml      = @simplexml_load_string($body);
        if ($xml instanceof \SimpleXMLElement) {
            foreach ($xml->xpath('//VerificationAttributes/entry') ?: [] as $entry) {
                $key = strtolower(trim((string) ($entry->key ?? '')));
                if ($key !== '') {
                    $statuses[$key] = trim((string) ($entry->value->VerificationStatus ?? ''));
                }
            }
        }

        $addressStatus = $statuses[$address] ?? null;
        $domainStatus  = $statuses[$domain] ?? null;

        return [
            'address'        => $address,
            'address_status' => $addressStatus !== '' ? $addressStatus : null,
            'domain'         => $domain,
            'domain_status'  => $domainStatus !== '' ? $domainStatus : null,
            'verified'       => $addressStatus === 'Success' || $domainStatus === 'Success',
        ];
    }

    /**
     * Fetch up to two weeks of sending activity via the `GetSendStatistics` action.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $settings Decrypted connection settings.
     * @return array<string, mixed>|\WP_Error {
     *     Sending activity, or a `WP_Error` describing the failure.
     *
     *     @type list<array{
     *         Bounces: string,
     *         Complaints: string,
     *         DeliveryAttempts: string,
     *         Rejects: string,
     *         Timestamp: string
     *     }> $SendDataPoints Per-interval send statistics.
     *     @type string $RequestId AWS request id.
     * }
     */
    public function getSendStatistics(array $settings): array | \WP_Error {
        $credentials = $this->resolveValidatedCredentials($settings);
        if ($credentials instanceof \WP_Error) {
            return $credentials;
        }

        $accessKey = $credentials['access_key'];
        $secret    = $credentials['secret'];
        $region    = $credentials['region'];

        $host = SesEndpoints::apiHost($region);
        $url  = 'https://' . $host . '/';

        $postFields = [
            'Action'  => 'GetSendStatistics',
            'Version' => '2010-12-01'
        ];

        $amzDate = gmdate('Ymd\THis\Z');
        $signed  = AwsSesSigV4::signedPostHeaders($accessKey, $secret, $region, $host, $amzDate, $postFields);

        $response = $this->http->post($url, [
            'timeout' => 15,
            'headers' => $signed['headers'],
            'body'    => $signed['body']
        ]);

        if ($this->http->isError($response)) {
            return new \WP_Error('booleansmtp_ses_http', $this->http->getErrorMessage($response));
        }

        $code = $this->http->responseCode($response);
        $body = $this->http->responseBody($response);

        if ($code !== 200) {
            return $this->buildErrorFromResponse($code, $body, $region);
        }

        if (str_contains($body, '<Error>') || str_contains($body, 'ErrorResponse')) {
            return $this->buildErrorFromResponse($code, $body, $region);
        }

        $xml = @simplexml_load_string($body);
        if (!($xml instanceof \SimpleXMLElement)) {
            return new \WP_Error('booleansmtp_ses_stats_xml', 'Failed to parse SES statistics response.', [
                'body' => $body
            ]);
        }

        $datapoints = [];
        $members    = $xml->xpath('//GetSendStatisticsResult/SendDataPoints/member') ?: [];
        foreach ($members as $datapoint) {
            $datapoints[] = [
                'Bounces'          => (string) $datapoint->Bounces,
                'Complaints'       => (string) $datapoint->Complaints,
                'DeliveryAttempts' => (string) $datapoint->DeliveryAttempts,
                'Rejects'          => (string) $datapoint->Rejects,
                'Timestamp'        => (string) $datapoint->Timestamp
            ];
        }

        return [
            'SendDataPoints' => $datapoints,
            'RequestId'      => $this->extractFirstXmlTagValue($body, 'RequestId')
        ];
    }

    /**
     * Verify that a connection's SES credentials are usable, via a `GetSendQuota` call.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $settings Decrypted connection settings.
     * @return bool|\WP_Error True when the credentials are usable; a `WP_Error` describing the
     *                        failure otherwise.
     */
    public function probe(array $settings): bool | \WP_Error {
        $this->lastResponseMeta = [];

        $credentials = $this->resolveValidatedCredentials($settings);
        if ($credentials instanceof \WP_Error) {
            return $credentials;
        }

        $accessKey = $credentials['access_key'];
        $secret    = $credentials['secret'];
        $region    = $credentials['region'];

        $host = SesEndpoints::apiHost($region);
        $url  = 'https://' . $host . '/';

        $postFields = [
            'Action'  => 'GetSendQuota',
            'Version' => '2010-12-01'
        ];

        $amzDate = gmdate('Ymd\THis\Z');
        $signed  = AwsSesSigV4::signedPostHeaders($accessKey, $secret, $region, $host, $amzDate, $postFields);

        $response = $this->http->post($url, [
            'timeout' => 15,
            'headers' => $signed['headers'],
            'body'    => $signed['body']
        ]);

        if ($this->http->isError($response)) {
            return new \WP_Error('booleansmtp_ses_http', $this->http->getErrorMessage($response));
        }

        $code = $this->http->responseCode($response);
        $body = $this->http->responseBody($response);

        if ($code !== 200) {
            return $this->buildErrorFromResponse($code, $body, $region);
        }

        if (str_contains($body, '<Error>') || str_contains($body, 'ErrorResponse')) {
            return $this->buildErrorFromResponse($code, $body, $region);
        }

        $this->lastResponseMeta = [
            'provider'      => 'ses',
            'delivery_mode' => 'api',
            'region'        => $region,
            'http_status'   => $code,
            'request_id'    => $this->extractFirstXmlTagValue($body, 'RequestId')
        ];

        return true;
    }

    /**
     * Fill in the canonical credential fields from their API-specific field aliases.
     *
     * SES connection settings are stored under `api_access_key`, `api_secret` and `api_region`;
     * this normalizes them to the `access_key`, `secret` and `region` names used internally by
     * this sender, without overwriting values already present under those names.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $settings Decrypted connection settings.
     * @return array<string, mixed> Settings with canonical credential fields filled in where missing.
     */
    private function withSesApiCredentialAliases(array $settings): array {
        $accessKey = trim((string) ($settings['access_key'] ?? ''));
        if ($accessKey === '') {
            $v = trim((string) ($settings['api_access_key'] ?? ''));
            if ($v !== '') {
                $settings['access_key'] = $v;
            }
        }

        $secret = trim((string) ($settings['secret'] ?? $settings['secret_key'] ?? ''));
        if ($secret === '') {
            $v = trim((string) ($settings['api_secret'] ?? ''));
            if ($v !== '') {
                $settings['secret'] = $v;
            }
        }

        $region = trim((string) ($settings['region'] ?? ''));
        if ($region === '') {
            $v = trim((string) ($settings['api_region'] ?? ''));
            if ($v !== '') {
                $settings['region'] = $v;
            }
        }

        return $settings;
    }

    /**
     * Resolve and validate the IAM credentials needed to sign an SES request.
     *
     * Rejects credentials that are empty, that look like a masked placeholder (as shown by the
     * admin UI for an already-saved secret), that contain whitespace (which produces an invalid
     * SigV4 `Authorization` header), or where the secret key is not exactly 40 characters, since
     * that is a reliable sign of a truncated or mismatched key.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $settings Decrypted connection settings.
     * @return array{access_key: string, secret: string, region: string}|\WP_Error
     *         Validated credentials and region, or a `WP_Error` describing why they are unusable.
     */
    private function resolveValidatedCredentials(array $settings): array | \WP_Error {
        $settings  = $this->withSesApiCredentialAliases($settings);
        $accessKey = trim((string) ($settings['access_key'] ?? ''));
        $secret    = trim((string) ($settings['secret'] ?? $settings['secret_key'] ?? ''));
        $region    = trim((string) ($settings['region'] ?? 'us-east-1'));

        if ($accessKey === '' || $secret === '') {
            return new \WP_Error('booleansmtp_ses_api', 'SES API requires IAM access key and secret key.');
        }

        if ($this->isLikelyMaskedSecretPlaceholder($accessKey) || $this->isLikelyMaskedSecretPlaceholder($secret)) {
            return new \WP_Error(
                'booleansmtp_ses_auth',
                'SES credentials appear masked. Re-enter full Access Key ID and Secret Access Key before sending.',
                ['ses_hint' => 'Masked placeholders cannot be used for SigV4 signing.']
            );
        }

        if (preg_match('/\s/', $accessKey) === 1) {
            return new \WP_Error(
                'booleansmtp_ses_auth',
                'Access Key ID contains whitespace. This causes an invalid SigV4 Authorization header (IncompleteSignature).',
                ['ses_hint' => 'Remove spaces/newlines from Access Key ID and save again.']
            );
        }

        if (preg_match('/\s/', $secret) === 1) {
            return new \WP_Error(
                'booleansmtp_ses_auth',
                'Secret Access Key contains whitespace. This breaks request signing.',
                ['ses_hint' => 'Remove spaces/newlines from Secret Access Key and save again.']
            );
        }

        if (strlen($secret) !== 40) {
            return new \WP_Error(
                'booleansmtp_ses_auth',
                sprintf(
                    'Secret Access Key length is %d, expected 40 characters. This usually means the key is truncated or mismatched.',
                    strlen($secret)
                ),
                ['ses_hint' => 'Create/copy a full AWS Secret Access Key and save again.']
            );
        }

        return [
            'access_key' => $accessKey,
            'secret'     => $secret,
            'region'     => $region !== '' ? $region : 'us-east-1'
        ];
    }

    /**
     * Check whether a credential value looks like a masked placeholder rather than a real secret.
     *
     * The admin UI displays an already-saved secret masked as a run of asterisks followed by a
     * short suffix; this detects that shape so it is never mistaken for a real credential.
     *
     * @since 1.0.0
     *
     * @param  string  $value Candidate credential value.
     * @return bool True when the value matches the masked-placeholder pattern.
     */
    private function isLikelyMaskedSecretPlaceholder(string $value): bool {
        if ($value === '') {
            return false;
        }

        return preg_match('/^\*{4,}\S{0,8}$/', $value) === 1;
    }

    /**
     * Build a `WP_Error` from a non-success SES v1 Query API response.
     *
     * Classifies the SES error code into an authentication, permission, throttling or
     * message-rejection error where possible, and records the outcome in
     * {@see self::$lastResponseMeta} for the admin UI.
     *
     * @since 1.0.0
     *
     * @param  int     $status HTTP status code returned by SES.
     * @param  string  $body   Raw XML response body.
     * @param  string  $region AWS region the request was sent to.
     * @return \WP_Error Error describing the failure, with `status`, `body`, `ses_error_code`,
     *                    `ses_request_id` and `ses_hint` in its error data.
     */
    private function buildErrorFromResponse(int $status, string $body, string $region): \WP_Error {
        $errorCode    = $this->extractFirstXmlTagValue($body, 'Code');
        $errorMessage = $this->extractFirstXmlTagValue($body, 'Message');
        $requestId    = $this->extractFirstXmlTagValue($body, 'RequestId');

        if ($errorCode === '' && $status >= 500) {
            $errorCode = 'ServiceUnavailable';
        }

        $wpErrorCode = 'booleansmtp_ses_http';
        $hint        = 'Confirm AWS credentials, SES region, and verified sender identity.';

        if (in_array($errorCode, ['InvalidClientTokenId', 'SignatureDoesNotMatch', 'MissingAuthenticationToken', 'AuthFailure', 'UnrecognizedClientException', 'IncompleteSignature'], true)) {
            $wpErrorCode = 'booleansmtp_ses_auth';
            $hint        = 'Credential signature/auth failed. Verify Access Key ID, Secret Access Key, and region constants.';
            if ($errorCode === 'IncompleteSignature') {
                $hint = 'Authorization header is malformed. Check Access Key ID/Secret for spaces, line breaks, or masked values.';
            }
        } elseif (in_array($errorCode, ['AccessDenied', 'AccessDeniedException'], true)) {
            $wpErrorCode = 'booleansmtp_ses_permission';
            $hint        = 'IAM policy must allow ses:SendRawEmail (and ses:GetSendQuota for verify checks).';
        } elseif (in_array($errorCode, ['Throttling', 'ThrottlingException'], true)) {
            $wpErrorCode = 'booleansmtp_ses_throttled';
            $hint        = 'SES throttled this request. Lower send rate or increase SES sending limits.';
        } elseif ($errorCode === 'MessageRejected') {
            $wpErrorCode = 'booleansmtp_ses_message_rejected';
            $hint        = 'SES rejected the message. Verify From Email identity and recipient/domain restrictions.';
        } elseif ($status === 0) {
            $wpErrorCode = 'booleansmtp_ses_network';
            $hint        = 'Network failure when calling SES API. Check outbound connectivity and DNS.';
        }

        if ($errorMessage === '') {
            $errorMessage = $body !== '' ? trim($body) : 'Unknown SES API error.';
        }

        $this->lastResponseMeta = \array_merge($this->lastResponseMeta, [
            'provider'       => 'ses',
            'delivery_mode'  => 'api',
            'region'         => $region,
            'http_status'    => $status,
            'status'         => (string) $status,
            'request_id'     => $requestId,
            'ses_error_code' => $errorCode,
            'ses_hint'       => $hint
        ]);

        $message = 'SES API error';
        if ($errorCode !== '') {
            $message .= ' (' . $errorCode . ')';
        }
        $message .= ': ' . $errorMessage;

        return new \WP_Error($wpErrorCode, $message, [
            'status'         => $status,
            'body'           => $body,
            'ses_error_code' => $errorCode,
            'ses_request_id' => $requestId,
            'ses_hint'       => $hint
        ]);
    }

    /**
     * Record the request and response of an SES v1 Query API call into
     * {@see self::$lastResponseMeta}.
     *
     * The request body preview is truncated to 1,200 bytes and the response body to 50,000 bytes
     * so the stored diagnostics stay a reasonable size while still being useful for
     * troubleshooting.
     *
     * @since 1.0.0
     *
     * @param  string                                                $url      Request URL.
     * @param  array{headers: array<string, string>, body: string}   $signed   Signed request
     *                                                                          headers and body, as
     *                                                                          returned by
     *                                                                          {@see AwsSesSigV4::signedPostHeaders()}.
     * @param  mixed                                                 $response Raw HTTP adapter
     *                                                                          response, as returned
     *                                                                          by `wp_remote_post()`.
     */
    private function mergeSesHttpExchangeIntoLastMeta(string $url, array $signed, mixed $response): void {
        $bodyLen = \strlen($signed['body']);
        $preview = $signed['body'];
        if ($bodyLen > 1200) {
            $preview = \substr($signed['body'], 0, 1200) . "\n... [truncated for debug preview]";
        }
        $base = [
            'provider'             => 'ses',
            'delivery_mode'        => 'api',
            'request_url'          => $url,
            'request_method'       => 'POST',
            'request_headers'      => $signed['headers'],
            'request_body_length'  => $bodyLen,
            'request_body_preview' => $preview
        ];
        if ($this->http->isError($response)) {
            $base['transport_error'] = $this->http->getErrorMessage($response);
            $this->lastResponseMeta  = \array_merge($this->lastResponseMeta, $base);

            return;
        }
        $code                     = $this->http->responseCode($response);
        $body                     = $this->http->responseBody($response);
        $base['http_status']      = $code;
        $base['status']           = (string) $code;
        $base['provider_headers'] = $this->flattenWpHttpHeaders($response);
        $base['provider_body']    = $this->truncateSesDebugString($body, 50000);
        $this->lastResponseMeta   = \array_merge($this->lastResponseMeta, $base);
    }

    /**
     * Normalize a WordPress HTTP API response's headers into a plain array.
     *
     * @since 1.0.0
     *
     * @param  mixed  $response Raw HTTP adapter response.
     * @return array<string, mixed> Response headers, or an empty array when none could be read.
     */
    private function flattenWpHttpHeaders(mixed $response): array {
        $headers = null;
        if (\is_array($response)) {
            $headers = $response['headers'] ?? null;
        } elseif (\is_object($response) && \method_exists($response, 'headers')) {
            $headers = $response->headers();
        }

        if (\is_array($headers)) {
            return $headers;
        }
        if (\is_object($headers) && \method_exists($headers, 'getAll')) {
            $all = $headers->getAll();

            return \is_array($all) ? $all : [];
        }
        if (\is_object($headers)) {
            $out = [];
            foreach ($headers as $k => $v) {
                $out[(string) $k] = $v;
            }

            return $out;
        }

        return [];
    }

    /**
     * Truncate a debug string to a maximum byte length, appending a truncation marker.
     *
     * @since 1.0.0
     *
     * @param  string  $s        String to truncate.
     * @param  int     $maxBytes Maximum length, in bytes, before truncation.
     * @return string Original string, or the first `$maxBytes` bytes with a truncation marker
     *                appended.
     */
    private function truncateSesDebugString(string $s, int $maxBytes): string {
        if (\strlen($s) <= $maxBytes) {
            return $s;
        }

        return \substr($s, 0, $maxBytes) . "\n... [truncated]";
    }

    /**
     * Extract the text content of the first occurrence of an XML tag.
     *
     * @since 1.0.0
     *
     * @param  string  $xml XML document to search.
     * @param  string  $tag Tag name to find, without angle brackets.
     * @return string Decoded, trimmed tag content, or an empty string when the tag is not found.
     */
    private function extractFirstXmlTagValue(string $xml, string $tag): string {
        if ($xml === '' || $tag === '') {
            return '';
        }

        $pattern = '/<' . preg_quote($tag, '/') . '>(.*?)<\\/' . preg_quote($tag, '/') . '>/s';
        if (preg_match($pattern, $xml, $matches) !== 1) {
            return '';
        }

        return trim((string) html_entity_decode((string) ($matches[1] ?? ''), ENT_QUOTES));
    }
}
