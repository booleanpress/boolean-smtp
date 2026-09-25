<?php

/**
 * AWS Signature Version 4 signer for the SES Query API.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer\Api;

/**
 * Builds the request headers and body required to sign an SES Query API POST request.
 *
 * @since 1.0.0
 */
final class AwsSesSigV4 {
    /**
     * Sign a request to the SES Query API with AWS Signature Version 4.
     *
     * When `$rawBody` is provided, signing uses that body together with the given method,
     * content type and canonical URI instead of building the body from `$postFields`.
     *
     * @since 1.0.0
     *
     * @param  string                 $accessKey    AWS access key id.
     * @param  string                 $secretKey    AWS secret access key.
     * @param  string                 $region       AWS region, for example `us-east-1`.
     * @param  string                 $host         Request host, for example `email.us-east-1.amazonaws.com`.
     * @param  string                 $amzDate      Request timestamp in `Ymd\THis\Z` format, UTC.
     * @param  array<string, string>  $postFields   Form fields to sign, sent as
     *                                               `application/x-www-form-urlencoded` pairs. Ignored when
     *                                               `$rawBody` is provided.
     * @param  string                 $method       HTTP method used for the request.
     * @param  string                 $canonicalUri Request path used in the canonical request.
     * @param  string                 $contentType  Content-Type header value used in the canonical request.
     * @param  string|null            $rawBody      Raw request body to sign instead of `$postFields`.
     * @return array{headers: array<string, string>, body: string} The headers required to authenticate the
     *                                                              request and the exact body they were signed
     *                                                              against.
     */
    public static function signedPostHeaders(
        string $accessKey,
        string $secretKey,
        string $region,
        string $host,
        string $amzDate,
        array $postFields,
        string $method = 'POST',
        string $canonicalUri = '/',
        string $contentType = 'application/x-www-form-urlencoded',
        ?string $rawBody = null
    ): array {
        $service    = 'ses';
        $algorithm  = 'AWS4-HMAC-SHA256';
        $credential = $accessKey . '/' . substr($amzDate, 0, 8) . '/' . $region . '/' . $service . '/aws4_request';

        if ($rawBody === null) {
            ksort($postFields);
            $body = http_build_query($postFields, '', '&', PHP_QUERY_RFC3986);
        } else {
            $body = $rawBody;
        }

        $method       = strtoupper($method);
        $canonicalUri = $canonicalUri !== '' ? $canonicalUri : '/';
        if ($canonicalUri[0] !== '/') {
            $canonicalUri = '/' . $canonicalUri;
        }

        $hashedPayload = hash('sha256', $body);

        $canonicalHeaders =
        'content-type:' . strtolower($contentType) . "\n" .
        'host:' . strtolower($host) . "\n" .
            'x-amz-content-sha256:' . $hashedPayload . "\n" .
            'x-amz-date:' . $amzDate . "\n";

        $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';

        $canonicalRequest =
            $method . "\n" .
            $canonicalUri . "\n" .
            "\n" .
            $canonicalHeaders . "\n" .
            $signedHeaders . "\n" .
            $hashedPayload;

        $stringToSign =
        $algorithm . "\n" .
        $amzDate . "\n" .
        substr($amzDate, 0, 8) . '/' . $region . '/' . $service . '/aws4_request' . "\n" .
        hash('sha256', $canonicalRequest);

        $kDate     = hash_hmac('sha256', substr($amzDate, 0, 8), 'AWS4' . $secretKey, true);
        $kRegion   = hash_hmac('sha256', $region, $kDate, true);
        $kService  = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning  = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authHeader = $algorithm .
            ' Credential=' . $credential .
            ', SignedHeaders=' . $signedHeaders .
            ', Signature=' . $signature;

        return [
            'headers' => [
                'Host'                 => $host,
                'Content-Type'         => $contentType,
                'X-Amz-Date'           => $amzDate,
                'X-Amz-Content-SHA256' => $hashedPayload,
                'Authorization'        => $authHeader
            ],
            'body'    => $body
        ];
    }
}
