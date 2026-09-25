<?php

/**
 * Request payload builder for the AWS SES v2 JSON API.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer\Api;

/**
 * Builds and parses requests for the SES v2 `SendEmail` JSON API.
 *
 * Complements the SES v1 Query API used by {@see SesApiSender}, structuring fields (configuration
 * set, tags) natively instead of as raw MIME headers.
 *
 * @see https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_SendEmail.html
 *
 * @since 1.0.0
 */
final class SesV2RequestBuilder {
    /**
     * Build a v2 `SendEmail` request payload from a raw MIME message and connection settings.
     *
     * The `Destination` field is intentionally omitted: SES v2 raw-email sends resolve recipients
     * from the MIME headers, and an empty `Destination` object triggers a `SerializationException`
     * from the API.
     *
     * @since 1.0.0
     *
     * @param  string                $rawMime  Raw MIME message to send.
     * @param  array<string, mixed>  $settings Connection settings; reads `from_email`,
     *                                          `configuration_set` and `static_tags`.
     * @return array<string, mixed> JSON-serializable request body for `POST /v2/email/outbound-emails`.
     */
    public static function buildSendEmailRequest(string $rawMime, array $settings): array {
        $configurationSet = trim((string) ($settings['configuration_set'] ?? ''));
        $staticTags       = $settings['static_tags'] ?? [];
        $fromEmail        = trim((string) ($settings['from_email'] ?? ''));

        $payload = [
            'Content' => [
                'Raw' => [
                    'Data' => base64_encode($rawMime)
                ]
            ]
        ];

        if ($fromEmail !== '') {
            $payload['FromEmailAddress'] = $fromEmail;
        }

        if ($configurationSet !== '') {
            $payload['ConfigurationSetName'] = $configurationSet;
        }

        if (!empty($staticTags) && \is_array($staticTags)) {
            $tags = [];
            foreach ($staticTags as $name => $value) {
                $tags[] = [
                    'Name'  => (string) $name,
                    'Value' => (string) $value
                ];
            }
            if (!empty($tags)) {
                $payload['EmailTags'] = $tags;
            }
        }

        return $payload;
    }

    /**
     * Extract the message id from a successful v2 `SendEmail` response.
     *
     * @since 1.0.0
     *
     * @param  string  $responseBody JSON response body.
     * @return string Message id, or an empty string when the body is empty, not valid JSON, or
     *                 has no `MessageId` field.
     */
    public static function extractMessageIdFromResponse(string $responseBody): string {
        if ($responseBody === '') {
            return '';
        }

        $data = @\json_decode($responseBody, true);
        if (!\is_array($data)) {
            return '';
        }

        return (string) ($data['MessageId'] ?? '');
    }

    /**
     * Parse a v2 API error response into a standardized code and message.
     *
     * Falls back to a truncated excerpt of the raw body when it is not valid JSON, which happens
     * for some non-API error responses (for example an HTML error page from an intermediate proxy).
     *
     * @since 1.0.0
     *
     * @param  string  $responseBody JSON error response body.
     * @return array{code: string, message: string} Error code and message; both may be empty or
     *                                               generic when the response could not be parsed.
     */
    public static function extractErrorFromResponse(string $responseBody): array {
        if ($responseBody === '') {
            return ['code' => '', 'message' => 'Unknown error: empty response'];
        }

        $data = @\json_decode($responseBody, true);
        if (!\is_array($data)) {
            $fallback = trim($responseBody);
            if (\strlen($fallback) > 500) {
                $fallback = \substr($fallback, 0, 500) . '...';
            }
            return ['code' => '', 'message' => $fallback ?: 'Unknown error (malformed response)'];
        }

        // SES v2 errors report a type such as "com.amazon...#ValidationException"; keep only the
        // part after the last '#' as the standardized error code.
        $code = (string) ($data['__type'] ?? '');
        if ($code !== '' && str_contains($code, '#')) {
            $parts = explode('#', $code);
            $code  = (string) end($parts);
        }

        $message = (string) ($data['message'] ?? $data['Message'] ?? '');

        if ($message === '') {
            $message = 'SES v2 API error (unknown message)';
        }

        return ['code' => $code, 'message' => $message];
    }

    /**
     * Build the v2 `SendEmail` endpoint URL for a region, or a custom endpoint override.
     *
     * @since 1.0.0
     *
     * @param  string  $region         AWS region, for example `us-east-1`.
     * @param  string  $customEndpoint Custom endpoint host or URL, for example a VPC PrivateLink
     *                                  endpoint, used instead of the regional default when given.
     * @return string Full v2 endpoint URL, including the `/v2/email/outbound-emails` path.
     */
    public static function getV2EndpointUrl(string $region, string $customEndpoint = ''): string {
        if ($customEndpoint !== '') {
            if (!\str_starts_with($customEndpoint, 'https://') && !\str_starts_with($customEndpoint, 'http://')) {
                $customEndpoint = 'https://' . $customEndpoint;
            }
            if (!\str_ends_with($customEndpoint, '/')) {
                $customEndpoint .= '/';
            }
            return $customEndpoint . 'v2/email/outbound-emails';
        }

        $host = SesEndpoints::apiHost($region);
        return 'https://' . $host . '/v2/email/outbound-emails';
    }
}
