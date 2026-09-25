<?php

/**
 * Names the account an OAuth authorization was granted for.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer\OAuth;

use BooleanSmtp\Adapters\Contracts\HttpAdapterContract;

/**
 * The signed-in account behind a Google or Microsoft token response.
 *
 * The address is stored on the connection as `oauth_account_email` and shown next to the From
 * address so a mismatch is visible before the first send. Nothing is authorised on the strength
 * of it.
 *
 * @since 1.0.0
 */
final class OAuthAccountIdentity
{
    /**
     * The `email` claim of an OpenID Connect id token, without verifying its signature.
     *
     * The token arrived in the token endpoint's own TLS response, so its payload is trusted for
     * display only.
     *
     * @since 1.0.0
     *
     * @param string $idToken The `id_token` from the token response, or an empty string.
     * @return string The lower-cased address, or an empty string when the token carries none.
     */
    public static function fromGoogleIdToken(string $idToken): string
    {
        $parts = explode('.', $idToken);
        if (count($parts) < 2) {
            return '';
        }

        $segment = $parts[1];
        $payload = base64_decode(strtr($segment, '-_', '+/') . str_repeat('=', (4 - strlen($segment) % 4) % 4), true);
        $claims  = $payload === false ? null : json_decode($payload, true);
        $email   = \is_array($claims) ? trim((string) ($claims['email'] ?? '')) : '';

        return \is_email($email) ? strtolower($email) : '';
    }

    /**
     * The signed-in Microsoft account's address, from Graph `/me`.
     *
     * @since 1.0.0
     *
     * @param string              $accessToken A delegated access token with `User.Read`.
     * @param HttpAdapterContract $http        HTTP client to call Graph with.
     * @return string `mail`, else `userPrincipalName`, lower-cased; an empty string on any failure.
     */
    public static function fromMicrosoftGraph(string $accessToken, HttpAdapterContract $http): string
    {
        if ($accessToken === '') {
            return '';
        }

        $response = $http->get('https://graph.microsoft.com/v1.0/me?$select=mail,userPrincipalName', [
            'timeout' => 10,
            'headers' => ['Authorization' => 'Bearer ' . $accessToken, 'Accept' => 'application/json']
        ]);

        if ($http->isError($response) || $http->responseCode($response) !== 200) {
            return '';
        }

        $body = json_decode($http->responseBody($response), true);
        if (!\is_array($body)) {
            return '';
        }

        foreach (['mail', 'userPrincipalName'] as $key) {
            $value = trim((string) ($body[$key] ?? ''));
            if ($value !== '' && \is_email($value)) {
                return strtolower($value);
            }
        }

        return '';
    }
}
