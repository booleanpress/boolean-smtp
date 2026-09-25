<?php

/**
 * Client for the EC2 Instance Metadata Service v2 (IMDSv2).
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\AWS;

use BooleanSmtp\Adapters\Contracts\HttpAdapterContract;
use BooleanSmtp\Adapters\WordPress\HttpAdapter;
use BooleanSmtp\Core\Foundation\Application;
use function BooleanSmtp\Core\app;

/**
 * Retrieves temporary credentials for an EC2 instance's attached IAM role via IMDSv2.
 *
 * Used as a credential source for the SES transports when the site runs on an EC2 instance with
 * an instance profile and no explicit access key is configured.
 *
 * @see https://docs.aws.amazon.com/AWSEC2/latest/UserGuide/configuring-instance-metadata-service.html
 *
 * @since 1.0.0
 */
final class IMDSv2Client {
    /**
     * Base metadata service URL for role name and role credential lookups.
     *
     * @since 1.0.0
     * @var string
     */
    private const METADATA_URL     = 'http://169.254.169.254/latest/meta-data';

    /**
     * Path, relative to {@see self::METADATA_URL}, that lists and returns IAM role credentials.
     *
     * @since 1.0.0
     * @var string
     */
    private const CREDENTIALS_PATH = '/iam/security-credentials';

    /**
     * Default lifetime, in seconds, requested for an IMDSv2 session token.
     *
     * @since 1.0.0
     * @var int
     */
    private const TOKEN_TTL        = 300;

    /**
     * Path used to request an IMDSv2 session token.
     *
     * @since 1.0.0
     * @var string
     */
    private const TOKEN_PATH       = '/latest/api/token';

    /**
     * HTTP client used to reach the instance metadata service.
     *
     * @since 1.0.0
     * @var HttpAdapterContract
     */
    private HttpAdapterContract $http;

    /**
     * Resolve the HTTP adapter used to reach the metadata service.
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
     * Fetch temporary credentials for the current EC2 instance's attached IAM role.
     *
     * Performs the IMDSv2 sequence: requests a session token, uses it to look up the attached
     * role name, then uses the same token to fetch that role's temporary credentials. Returns
     * null at any step that fails, including when the instance has no attached role or the
     * metadata service is unreachable.
     *
     * @since 1.0.0
     *
     * @return array{access_key: string, secret_key: string, token: string, expiration: string}|null
     *         Temporary AWS credentials and their expiration timestamp, or null when they could
     *         not be retrieved.
     */
    public function getCredentials(): ?array {
        $token = $this->fetchToken();
        if ($token === null) {
            return null;
        }

        $roleName = $this->fetchRoleName($token);
        if ($roleName === null) {
            return null;
        }

        $credentials = $this->fetchRoleCredentials($token, $roleName);
        return $credentials;
    }

    /**
     * Check whether the instance metadata service is reachable.
     *
     * Performs the same session-token request used by {@see self::getCredentials()} without
     * retrieving credentials, so it is safe to call as a lightweight availability check.
     *
     * @since 1.0.0
     *
     * @return bool True when the metadata service responds with a session token.
     */
    public function isAvailable(): bool {
        $token = $this->fetchToken();
        return $token !== null;
    }

    /**
     * Request an IMDSv2 session token.
     *
     * The token's requested lifetime, in seconds, can be overridden with the
     * `BOOLEANSMTP_AWS_EC2_IMDSV2_TOKEN_TTL` constant; it otherwise defaults to
     * {@see self::TOKEN_TTL}.
     *
     * @since 1.0.0
     *
     * @return string|null Session token, or null when the request failed or returned an empty body.
     */
    private function fetchToken(): ?string {
        $url      = 'http://169.254.169.254' . self::TOKEN_PATH;
        $tokenTtl = (int) (\defined('BOOLEANSMTP_AWS_EC2_IMDSV2_TOKEN_TTL') ? \constant('BOOLEANSMTP_AWS_EC2_IMDSV2_TOKEN_TTL') : self::TOKEN_TTL);

        $response = $this->http->request('PUT', $url, [
            'timeout' => 5,
            'headers' => [
                'X-aws-ec2-metadata-token-ttl-seconds' => (string) $tokenTtl
            ]
        ]);

        if ($this->http->isError($response)) {
            return null;
        }

        $body  = $this->http->responseBody($response);
        $token = trim($body);

        return $token !== '' ? $token : null;
    }

    /**
     * Fetch the name of the IAM role attached to this instance.
     *
     * @since 1.0.0
     *
     * @param  string  $token IMDSv2 session token.
     * @return string|null Role name, or null when the instance has no attached role or the
     *                      request failed.
     */
    private function fetchRoleName(string $token): ?string {
        $url = self::METADATA_URL . self::CREDENTIALS_PATH;

        $response = $this->http->get($url, [
            'timeout' => 5,
            'headers' => [
                'X-aws-ec2-metadata-token' => $token
            ]
        ]);

        if ($this->http->isError($response)) {
            return null;
        }

        $body = trim($this->http->responseBody($response));
        return $body !== '' ? $body : null;
    }

    /**
     * Fetch temporary credentials for a named IAM role.
     *
     * @since 1.0.0
     *
     * @param  string  $token    IMDSv2 session token.
     * @param  string  $roleName IAM role name, as returned by {@see self::fetchRoleName()}.
     * @return array{access_key: string, secret_key: string, token: string, expiration: string}|null
     *         Temporary AWS credentials and their expiration timestamp (UTC), or null when the
     *         request failed or the response was missing an access key or secret key.
     */
    private function fetchRoleCredentials(string $token, string $roleName): ?array {
        $url = self::METADATA_URL . self::CREDENTIALS_PATH . '/' . \urlencode($roleName);

        $response = $this->http->get($url, [
            'timeout' => 5,
            'headers' => [
                'X-aws-ec2-metadata-token' => $token
            ]
        ]);

        if ($this->http->isError($response)) {
            return null;
        }

        $body = trim($this->http->responseBody($response));
        if ($body === '') {
            return null;
        }

        $data = @\json_decode($body, true);
        if (!\is_array($data)) {
            return null;
        }

        $accessKey  = (string) ($data['AccessKeyId'] ?? '');
        $secretKey  = (string) ($data['SecretAccessKey'] ?? '');
        $token      = (string) ($data['Token'] ?? '');
        $expiration = (string) ($data['Expiration'] ?? '');

        if ($accessKey === '' || $secretKey === '') {
            return null;
        }

        return [
            'access_key' => $accessKey,
            'secret_key' => $secretKey,
            'token'      => $token,
            'expiration' => $expiration
        ];
    }
}
