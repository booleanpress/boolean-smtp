<?php

/**
 * AWS SES v2 JSON API mail sender.
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
 * Sends mail through the AWS SES v2 `SendEmail` JSON API.
 *
 * Selected as the active SES transport when the `enable_ses_v2_api` setting is enabled; see
 * {@see \BooleanSmtp\Services\Settings\SesApiVersionProvider} for how the active version is
 * resolved. Complements {@see SesApiSender}, which uses the SES v1 Query API.
 *
 * @see https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_SendEmail.html
 *
 * @since 1.0.0
 */
final class SesV2ApiSender {
    /**
     * Diagnostic details from the most recent send attempt, for the admin UI and logs.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    private array $lastResponseMeta = [];

    /**
     * HTTP client used to call the SES v2 API.
     *
     * @since 1.0.0
     * @var HttpAdapterContract
     */
    private HttpAdapterContract $http;

    /**
     * Resolve the HTTP adapter used to call the SES v2 API.
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
     * Get diagnostic details from the most recent send attempt.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> Request and response details for troubleshooting; empty before
     *                              the first send attempt.
     */
    public function getLastResponseMeta(): array {
        return $this->lastResponseMeta;
    }

    /**
     * Send a raw MIME message through the SES v2 API.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $settings Decrypted connection settings; must include IAM
     *                                          access key and secret key (or their API-field
     *                                          aliases).
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

        $payload = SesV2RequestBuilder::buildSendEmailRequest($rawMime, $settings);
        $body    = @\json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return new \WP_Error('booleansmtp_ses_v2_json', 'Failed to encode SES v2 request payload.');
        }

        $customEndpoint = trim((string) ($settings['custom_endpoint'] ?? ''));
        $url            = SesV2RequestBuilder::getV2EndpointUrl($region, $customEndpoint);
        $host           = \wp_parse_url($url, PHP_URL_HOST) ?: SesEndpoints::apiHost($region);

        $amzDate = \gmdate('Ymd\THis\Z');
        $signed  = AwsSesSigV4::signedPostHeaders(
            $accessKey,
            $secret,
            $region,
            $host,
            $amzDate,
            [], // The v2 API sends a JSON body, not form-encoded fields.
            'POST',
            '/v2/email/outbound-emails',
            'application/json',
            $body
        );

        $response = $this->http->post($url, [
            'timeout' => 30,
            'headers' => $signed['headers'],
            'body'    => $body
        ]);

        $this->mergeSesHttpExchangeIntoLastMeta($url, $signed, $response, $body);

        if ($this->http->isError($response)) {
            return new \WP_Error('booleansmtp_ses_v2_http', $this->http->getErrorMessage($response));
        }

        $code     = $this->http->responseCode($response);
        $respBody = $this->http->responseBody($response);

        if ($code !== 200 && $code !== 201) {
            return $this->buildErrorFromResponse($code, $respBody, $region);
        }

        $messageId = SesV2RequestBuilder::extractMessageIdFromResponse($respBody);

        $this->lastResponseMeta = \array_merge($this->lastResponseMeta, [
            'region'     => $region,
            'message_id' => $messageId,
            'http_code'  => $code
        ]);

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
        $accessKey = \trim((string) ($settings['access_key'] ?? ''));
        if ($accessKey === '') {
            $v = \trim((string) ($settings['api_access_key'] ?? ''));
            if ($v !== '') {
                $settings['access_key'] = $v;
            }
        }

        $secret = \trim((string) ($settings['secret'] ?? $settings['secret_key'] ?? ''));
        if ($secret === '') {
            $v = \trim((string) ($settings['api_secret'] ?? ''));
            if ($v !== '') {
                $settings['secret'] = $v;
            }
        }

        $region = \trim((string) ($settings['region'] ?? ''));
        if ($region === '') {
            $v = \trim((string) ($settings['api_region'] ?? ''));
            if ($v !== '') {
                $settings['region'] = $v;
            }
        }

        return $settings;
    }

    /**
     * Resolve and validate the IAM credentials needed to sign a SES v2 request.
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
        $accessKey = \trim((string) ($settings['access_key'] ?? ''));
        $secret    = \trim((string) ($settings['secret'] ?? $settings['secret_key'] ?? ''));
        $region    = \trim((string) ($settings['region'] ?? 'us-east-1'));

        if ($accessKey === '' || $secret === '') {
            return new \WP_Error('booleansmtp_ses_v2_api', 'SES API requires IAM access key and secret key.');
        }

        if ($this->isLikelyMaskedSecretPlaceholder($accessKey) || $this->isLikelyMaskedSecretPlaceholder($secret)) {
            return new \WP_Error(
                'booleansmtp_ses_v2_auth',
                'SES credentials appear masked. Re-enter full Access Key ID and Secret Access Key before sending.',
                ['ses_hint' => 'Masked placeholders cannot be used for SigV4 signing.']
            );
        }

        if (\preg_match('/\s/', $accessKey) === 1) {
            return new \WP_Error(
                'booleansmtp_ses_v2_auth',
                'Access Key ID contains whitespace. This causes an invalid SigV4 Authorization header (IncompleteSignature).',
                ['ses_hint' => 'Remove spaces/newlines from Access Key ID and save again.']
            );
        }

        if (\preg_match('/\s/', $secret) === 1) {
            return new \WP_Error(
                'booleansmtp_ses_v2_auth',
                'Secret Access Key contains whitespace. This breaks request signing.',
                ['ses_hint' => 'Remove spaces/newlines from Secret Access Key and save again.']
            );
        }

        if (\strlen($secret) !== 40) {
            return new \WP_Error(
                'booleansmtp_ses_v2_auth',
                \sprintf(
                    'Secret Access Key length is %d, expected 40 characters. This usually means the key is truncated or mismatched.',
                    \strlen($secret)
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

        return \preg_match('/^\*{4,}\S{0,8}$/', $value) === 1;
    }

    /**
     * Build a `WP_Error` from a non-success SES v2 API response.
     *
     * Classifies the SES error code into an authentication, permission or validation error where
     * possible, and records the outcome in {@see self::$lastResponseMeta} for the admin UI.
     *
     * @since 1.0.0
     *
     * @param  int     $status HTTP status code returned by SES.
     * @param  string  $body   Raw response body.
     * @param  string  $region AWS region the request was sent to.
     * @return \WP_Error Error describing the failure, with `status`, `body`, `ses_error_code` and
     *                    `ses_hint` in its error data.
     */
    private function buildErrorFromResponse(int $status, string $body, string $region): \WP_Error {
        $error = SesV2RequestBuilder::extractErrorFromResponse($body);
        $code  = $error['code'];
        $msg   = $error['message'];

        $wpErrorCode = 'booleansmtp_ses_v2_http';
        $hint        = 'Confirm AWS credentials, SES v2 region, and verified sender identity.';

        if (\in_array($code, ['InvalidSignatureException', 'AuthFailure', 'UnrecognizedClientException', 'UnauthorizedOperation', 'IncompleteSignature'], true)) {
            $wpErrorCode = 'booleansmtp_ses_v2_auth';
            $hint        = 'Authentication failed. Verify Access Key ID and Secret Access Key.';
            if ($code === 'IncompleteSignature') {
                $hint = 'Authorization header is malformed. Check Access Key ID/Secret for spaces, line breaks, or masked values.';
            }
        } elseif (\in_array($code, ['AccessDeniedException', 'AccessDenied'], true)) {
            $wpErrorCode = 'booleansmtp_ses_v2_permission';
            $hint        = 'IAM policy must allow ses:SendEmail and related v2 permissions.';
        } elseif (\in_array($code, ['ValidationException', 'ValidationError'], true)) {
            $wpErrorCode = 'booleansmtp_ses_v2_validation';
            $hint        = 'Invalid request parameters. Verify sender identity, configuration set, and tags.';
        }

        if ($msg === '') {
            $msg = $body !== '' ? \trim($body) : 'Unknown SES v2 API error.';
        }

        $message = 'SES v2 error';
        if ($code !== '') {
            $message .= ' (' . $code . ')';
        }
        $message .= ': ' . $msg;

        $this->lastResponseMeta = \array_merge($this->lastResponseMeta, [
            'provider'       => 'ses_v2',
            'delivery_mode'  => 'api_v2',
            'region'         => $region,
            'http_status'    => $status,
            'status'         => (string) $status,
            'ses_error_code' => $code,
            'ses_hint'       => $hint
        ]);

        return new \WP_Error($wpErrorCode, $message, [
            'status'         => $status,
            'body'           => $body,
            'ses_error_code' => $code,
            'ses_hint'       => $hint
        ]);
    }

    /**
     * Record the request and response of a SES v2 API call into {@see self::$lastResponseMeta}.
     *
     * The request body preview is truncated to 500 characters and the response body to 10,000
     * characters so the stored diagnostics stay a reasonable size while still being useful for
     * troubleshooting.
     *
     * @since 1.0.0
     *
     * @param  string                                   $url         Request URL.
     * @param  array{headers: array<string, string>}    $signed      Signed request headers, as
     *                                                                 returned by
     *                                                                 {@see AwsSesSigV4::signedPostHeaders()}.
     * @param  mixed                                    $response    Raw HTTP adapter response.
     * @param  string                                   $requestBody Raw request body that was sent.
     */
    private function mergeSesHttpExchangeIntoLastMeta(string $url, array $signed, mixed $response, string $requestBody): void {
        $base = [
            'provider'             => 'ses_v2',
            'delivery_mode'        => 'api_v2',
            'request_url'          => $url,
            'request_method'       => 'POST',
            'request_headers'      => $signed['headers'],
            'request_body_length'  => \strlen($requestBody),
            'request_body_preview' => \strlen($requestBody) > 500 ? \substr($requestBody, 0, 500) . '...' : $requestBody
        ];

        if ($this->http->isError($response)) {
            $base['transport_error'] = $this->http->getErrorMessage($response);
            $this->lastResponseMeta  = \array_merge($this->lastResponseMeta, $base);
            return;
        }

        $code     = $this->http->responseCode($response);
        $respBody = $this->http->responseBody($response);

        $base['http_status']      = $code;
        $base['status']           = (string) $code;
        $base['provider_headers'] = $this->flattenWpHttpHeaders($response);
        $base['provider_body']    = \strlen($respBody) > 10000 ? \substr($respBody, 0, 10000) . '...' : $respBody;

        $this->lastResponseMeta = \array_merge($this->lastResponseMeta, $base);
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

        return [];
    }
}
