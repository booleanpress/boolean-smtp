<?php

/**
 * REST controller driving the delegated OAuth authorize/callback flow for Google, Microsoft, and Zoho.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Http\Controllers;

use BooleanSmtp\Core\Http\Controller;
use BooleanSmtp\Core\Http\JsonResponse;
use BooleanSmtp\Core\Http\Request;
use BooleanSmtp\Core\Http\Response;
use BooleanSmtp\Contracts\EncryptorContract;
use BooleanSmtp\Repositories\ConnectionRepository;
use BooleanSmtp\Adapters\Contracts\HttpAdapterContract;
use BooleanSmtp\Services\Mailer\OAuth\OAuthAccountIdentity;
use BooleanSmtp\Services\Mailer\OAuth\OAuthRedirectUri;
use BooleanSmtp\Services\Mailer\OAuth\OAuthState;

/**
 * Builds provider authorization URLs and exchanges the returned code for tokens.
 *
 * @since 1.0.0
 */
class OAuthController extends Controller {
    /**
     * Delegated Graph scope requested for Outlook's `api` mode.
     *
     * `Mail.Send.Shared` is requested unconditionally alongside `Mail.Send` so a customer can turn
     * on "Send as a shared mailbox" (the Outlook transport's `send_as_shared_mailbox` field)
     * without re-authorizing; an unused grant is harmless, and Microsoft only lets the token use
     * it when the signed-in account has Exchange "Send As"/"Send on behalf" rights to the target
     * mailbox.
     *
     * @since 1.0.0
     */
    private const MICROSOFT_DELEGATED_SCOPE = 'offline_access https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/Mail.Send.Shared https://graph.microsoft.com/User.Read';

    /**
     * Admin screens a callback may return to, as accepted in the authorize request's `return_to`.
     *
     * Anything else is ignored and the callback returns to the connection screen.
     *
     * @since 1.0.0
     * @var list<string>
     */
    private const RETURN_TO_SCREENS = ['onboard'];

    /**
     * @since 1.0.0
     *
     * @param ConnectionRepository $connections Reads and updates the connection being authorized.
     * @param EncryptorContract    $encryptor   Decrypts and re-encrypts connection settings.
     */
    public function __construct(
        protected ConnectionRepository $connections,
        protected EncryptorContract $encryptor
    ) {
        parent::__construct();
    }

    /**
     * Handle `GET /booleansmtp/v1/oauth/google/authorize`.
     *
     * Reads the `connection_id` query parameter and builds the Google OAuth consent URL for the
     * Gmail send scope.
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying the `connection_id` query parameter.
     * @return JsonResponse `{ authorization_url: string }`, or a 422 error when the connection id
     *                       is missing, not a Google connection, or has no client ID saved.
     */
    public function googleAuthorize(Request $request): JsonResponse {
        $connectionId = (int) $request->query('connection_id', 0);
        if ($connectionId <= 0) {
            return $this->error('connection_id is required.', 422);
        }

        $connection = $this->connections->findOrFail($connectionId);
        if ($connection->driver !== 'google') {
            return $this->error('Connection must use the Google driver.', 422);
        }

        $settings = $this->decryptedSettings($connection);
        $clientId = (string) ($settings['client_id'] ?? '');
        if ($clientId === '') {
            return $this->error('Save Google Client ID and Client Secret on the connection first.', 422);
        }

        $redirectUri = OAuthRedirectUri::google();
        $state       = OAuthState::encode($connectionId, 'google', $this->returnTo($request));
        $scope       = rawurlencode('openid email https://www.googleapis.com/auth/gmail.send');

        $url = sprintf(
            'https://accounts.google.com/o/oauth2/v2/auth?client_id=%s&redirect_uri=%s&response_type=code&scope=%s&access_type=offline&prompt=consent&state=%s',
            rawurlencode($clientId),
            rawurlencode($redirectUri),
            $scope,
            rawurlencode($state)
        );

        return $this->ok(['authorization_url' => $url]);
    }

    /**
     * Handle `GET /booleansmtp/v1/oauth/google/callback`.
     *
     * Reads the `code` and `state` query parameters Google appends to the redirect, exchanges the
     * code for tokens, stores the encrypted access/refresh tokens on the connection, and redirects
     * back to the connection's admin screen.
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying the `code`, `state`, and, on failure, `error`
     *                          query parameters Google appends to the redirect.
     * @return Response|JsonResponse A redirect to the admin connections screen with the outcome
     *                                encoded in the query string.
     */
    public function googleCallback(Request $request): Response | JsonResponse {
        $redirectBase = $this->adminConnectionsUrl();

        if ($request->query('error')) {
            // The provider echoes the state on a denied consent, so a wizard user returns to the wizard.
            $denied = OAuthState::decode((string) $request->query('state', ''));

            return $denied !== null && $denied['provider'] === 'google'
                ? $this->redirect($this->callbackReturnUrl('google', 'error', (string) $request->query('error'), $denied))
                : $this->redirect($redirectBase . '&oauth=google&status=error&message=' . rawurlencode((string) $request->query('error')));
        }

        $code  = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');
        if ($code === '' || $state === '') {
            return $this->redirect($redirectBase . '&oauth=google&status=error&message=' . rawurlencode('Missing code or state.'));
        }

        $decoded = OAuthState::decode($state);
        if ($decoded === null || $decoded['provider'] !== 'google') {
            return $this->redirect($redirectBase . '&oauth=google&status=error&message=' . rawurlencode('Invalid state.'));
        }

        $connection = $this->connections->findOrFail($decoded['connection_id']);
        if ($connection->driver !== 'google') {
            return $this->redirect($this->callbackReturnUrl('google', 'error', 'Wrong connection type.', $decoded));
        }

        $settings     = $this->decryptedSettings($connection);
        $clientId     = (string) ($settings['client_id'] ?? '');
        $clientSecret = (string) ($settings['client_secret'] ?? '');
        if ($clientId === '' || $clientSecret === '') {
            return $this->redirect($this->callbackReturnUrl('google', 'error', 'Missing client credentials.', $decoded));
        }

        $response = \wp_remote_post('https://oauth2.googleapis.com/token', [
            'timeout' => 15,
            'body'    => [
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'code'          => $code,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => OAuthRedirectUri::google()
            ]
        ]);

        if (is_wp_error($response)) {
            return $this->redirect($this->callbackReturnUrl('google', 'error', $response->get_error_message(), $decoded));
        }

        $body = json_decode(\wp_remote_retrieve_body($response), true);
        if (!\is_array($body) || empty($body['access_token'])) {
            return $this->redirect($this->callbackReturnUrl('google', 'error', 'Token exchange failed.', $decoded));
        }

        $settings['access_token']     = (string) $body['access_token'];
        $settings['refresh_token']    = isset($body['refresh_token']) ? (string) $body['refresh_token'] : ($settings['refresh_token'] ?? '');
        $settings['token_expires_at'] = isset($body['expires_in']) ? time() + (int) $body['expires_in'] : 0;

        // The `openid email` scopes put the signed-in account's address in the id token; the UI
        // shows it next to the From address so a mismatch is visible before the first send.
        $account = OAuthAccountIdentity::fromGoogleIdToken((string) ($body['id_token'] ?? ''));
        if ($account !== '') {
            $settings['oauth_account_email'] = $account;
        }

        $this->persistSettings($connection->id, $settings);

        return $this->redirect($this->callbackReturnUrl('google', 'success', null, $decoded));
    }

    /**
     * Handle `GET /booleansmtp/v1/oauth/microsoft/authorize`.
     *
     * Reads the `connection_id` query parameter and builds the Microsoft delegated
     * bring-your-own-app OAuth consent URL for the Graph mail-send scope. The hosted One Click
     * proxy flow is a separate, Pro-only integration and is not handled here.
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying the `connection_id` query parameter.
     * @return JsonResponse `{ authorization_url: string }`, or a 422 error when the connection id
     *                       is missing, not an Outlook connection, or has no client credentials saved.
     */
    public function microsoftAuthorize(Request $request): JsonResponse {
        $connectionId = (int) $request->query('connection_id', 0);
        if ($connectionId <= 0) {
            return $this->error('connection_id is required.', 422);
        }

        $connection = $this->connections->findOrFail($connectionId);
        if ($connection->driver !== 'outlook') {
            return $this->error('Connection must use the Outlook driver.', 422);
        }

        $settings     = $this->decryptedSettings($connection);
        $clientId     = (string) ($settings['client_id'] ?? '');
        $clientSecret = (string) ($settings['client_secret'] ?? '');
        if ($clientId === '' || $clientSecret === '') {
            return $this->error('Save Microsoft Client ID and Client Secret on the connection first.', 422);
        }

        $tenant       = (string) ($settings['tenant_id'] ?? 'common');
        $redirectUri  = OAuthRedirectUri::microsoft();
        $state        = OAuthState::encode($connectionId, 'microsoft', $this->returnTo($request));
        $scope        = self::MICROSOFT_DELEGATED_SCOPE;
        $authorizeUrl = $this->microsoftAuthorizeUrl($tenant);
        $fromEmail    = trim((string) ($settings['from_email'] ?? ''));

        $query = [
            'client_id'     => $clientId,
            'response_type' => 'code',
            'redirect_uri'  => $redirectUri,
            'response_mode' => 'query',
            'scope'         => $scope,
            // Always show account selection; this avoids sticky browser sessions
            // silently authorizing the wrong identity for sendMail.
            'prompt'        => 'select_account',
            'state'         => $state
        ];

        if ($this->isValidEmail($fromEmail)) {
            // Nudge Microsoft login UI toward the intended sending mailbox.
            $query['login_hint'] = $fromEmail;

            $domainHint = $this->microsoftDomainHintForEmail($fromEmail);
            if ($domainHint !== '') {
                $query['domain_hint'] = $domainHint;
            }
        }

        $url = $authorizeUrl . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $this->ok(['authorization_url' => $url]);
    }

    /**
     * Handle `GET /booleansmtp/v1/oauth/microsoft/callback`.
     *
     * Reads the `code` and `state` query parameters Microsoft appends to the redirect, exchanges
     * the code for tokens, stores the encrypted access/refresh tokens on the connection, and
     * redirects back to the connection's admin screen.
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying the `code`, `state`, and, on failure,
     *                          `error`/`error_description` query parameters Microsoft appends to
     *                          the redirect.
     * @return Response|JsonResponse A redirect to the admin connections screen with the outcome
     *                                encoded in the query string.
     */
    public function microsoftCallback(Request $request): Response | JsonResponse {
        $redirectBase = $this->adminConnectionsUrl();

        if ($request->query('error')) {
            $message = (string) $request->query('error_description', $request->query('error'));
            // The provider echoes the state on a denied consent, so a wizard user returns to the wizard.
            $denied = OAuthState::decode((string) $request->query('state', ''));

            return $denied !== null && $denied['provider'] === 'microsoft'
                ? $this->redirect($this->callbackReturnUrl('microsoft', 'error', $message, $denied))
                : $this->redirect($redirectBase . '&oauth=microsoft&status=error&message=' . rawurlencode($message));
        }

        $code  = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');
        if ($code === '' || $state === '') {
            return $this->redirect($redirectBase . '&oauth=microsoft&status=error&message=' . rawurlencode('Missing code or state.'));
        }

        $decoded = OAuthState::decode($state);
        if ($decoded === null || $decoded['provider'] !== 'microsoft') {
            return $this->redirect($redirectBase . '&oauth=microsoft&status=error&message=' . rawurlencode('Invalid state.'));
        }

        $connection = $this->connections->findOrFail($decoded['connection_id']);
        if ($connection->driver !== 'outlook') {
            return $this->redirect($this->callbackReturnUrl('microsoft', 'error', 'Wrong connection type.', $decoded));
        }

        $settings     = $this->decryptedSettings($connection);
        $clientId     = (string) ($settings['client_id'] ?? '');
        $clientSecret = (string) ($settings['client_secret'] ?? '');
        $tenant       = (string) ($settings['tenant_id'] ?? 'common');
        if ($clientId === '' || $clientSecret === '') {
            return $this->redirect($this->callbackReturnUrl('microsoft', 'error', 'Missing client credentials.', $decoded));
        }

        $tokenUrl = $this->microsoftTokenUrl($tenant);

        $response = \wp_remote_post($tokenUrl, [
            'timeout' => 15,
            'body'    => [
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'code'          => $code,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => OAuthRedirectUri::microsoft(),
                'scope'         => self::MICROSOFT_DELEGATED_SCOPE
            ]
        ]);

        if (is_wp_error($response)) {
            return $this->redirect($this->callbackReturnUrl('microsoft', 'error', $response->get_error_message(), $decoded));
        }

        $body = json_decode(\wp_remote_retrieve_body($response), true);
        if (!\is_array($body) || empty($body['access_token'])) {
            $message = $this->formatMicrosoftTokenExchangeError($body);
            return $this->redirect($this->callbackReturnUrl('microsoft', 'error', $message, $decoded));
        }

        $settings['access_token']     = (string) $body['access_token'];
        $settings['refresh_token']    = isset($body['refresh_token']) ? (string) $body['refresh_token'] : ($settings['refresh_token'] ?? '');
        $settings['token_expires_at'] = isset($body['expires_in']) ? time() + (int) $body['expires_in'] : 0;

        // `User.Read` lets one Graph call name the signed-in mailbox; the UI shows it next to the
        // From address. A failure here is not a failed authorization.
        $account = OAuthAccountIdentity::fromMicrosoftGraph((string) $body['access_token'], $this->make(HttpAdapterContract::class));
        if ($account !== '') {
            $settings['oauth_account_email'] = $account;
        }

        $this->persistSettings($connection->id, $settings);

        return $this->redirect($this->callbackReturnUrl('microsoft', 'success', null, $decoded));
    }

    /**
     * Handle `GET /booleansmtp/v1/oauth/zoho/authorize`.
     *
     * Reads the `connection_id` query parameter and builds the Zoho Mail OAuth consent URL for
     * the connection's configured data-center region.
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying the `connection_id` query parameter.
     * @return JsonResponse `{ authorization_url: string }`, or a 422 error when the connection id
     *                       is missing, not a Zoho connection, or has no client ID saved.
     */
    public function zohoAuthorize(Request $request): JsonResponse {
        $connectionId = (int) $request->query('connection_id', 0);
        if ($connectionId <= 0) {
            return $this->error('connection_id is required.', 422);
        }

        $connection = $this->connections->findOrFail($connectionId);
        if ($connection->driver !== 'zoho') {
            return $this->error('Connection must use the Zoho driver.', 422);
        }

        $settings = $this->decryptedSettings($connection);
        $clientId = (string) ($settings['client_id'] ?? '');
        if ($clientId === '') {
            return $this->error('Save Zoho Client ID and Client Secret on the connection first.', 422);
        }

        $region      = (string) ($settings['region'] ?? 'us');
        $authDomain  = self::zohoAuthDomain($region);
        $redirectUri = OAuthRedirectUri::zoho();
        $state       = OAuthState::encode($connectionId, 'zoho');
        $scope       = rawurlencode('ZohoMail.messages.ALL ZohoMail.accounts.READ');

        $url = sprintf(
            '%s/oauth/v2/auth?client_id=%s&redirect_uri=%s&response_type=code&scope=%s&access_type=offline&prompt=consent&state=%s',
            $authDomain,
            rawurlencode($clientId),
            rawurlencode($redirectUri),
            $scope,
            rawurlencode($state)
        );

        return $this->ok(['authorization_url' => $url]);
    }

    /**
     * Handle `GET /booleansmtp/v1/oauth/zoho/callback`.
     *
     * Reads the `code` and `state` query parameters Zoho appends to the redirect, exchanges the
     * code for tokens, stores the encrypted access/refresh tokens and API domain on the
     * connection, and redirects back to the admin connections screen.
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying the `code`, `state`, and, on failure, `error`
     *                          query parameters Zoho appends to the redirect.
     * @return Response|JsonResponse A redirect to the admin connections screen with the outcome
     *                                encoded in the query string.
     */
    public function zohoCallback(Request $request): Response | JsonResponse {
        $redirectBase = $this->adminConnectionsUrl();

        if ($request->query('error')) {
            return $this->redirect($redirectBase . '&oauth=zoho&status=error&message=' . rawurlencode((string) $request->query('error')));
        }

        $code  = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');
        if ($code === '' || $state === '') {
            return $this->redirect($redirectBase . '&oauth=zoho&status=error&message=' . rawurlencode('Missing code or state.'));
        }

        $decoded = OAuthState::decode($state);
        if ($decoded === null || $decoded['provider'] !== 'zoho') {
            return $this->redirect($redirectBase . '&oauth=zoho&status=error&message=' . rawurlencode('Invalid state.'));
        }

        $connection = $this->connections->findOrFail($decoded['connection_id']);
        if ($connection->driver !== 'zoho') {
            return $this->redirect($redirectBase . '&oauth=zoho&status=error&message=' . rawurlencode('Wrong connection type.'));
        }

        $settings     = $this->decryptedSettings($connection);
        $clientId     = (string) ($settings['client_id'] ?? '');
        $clientSecret = (string) ($settings['client_secret'] ?? '');
        $region       = (string) ($settings['region'] ?? 'us');
        if ($clientId === '' || $clientSecret === '') {
            return $this->redirect($redirectBase . '&oauth=zoho&status=error&message=' . rawurlencode('Missing client credentials.'));
        }

        $tokenUrl = self::zohoAuthDomain($region) . '/oauth/v2/token';

        $response = \wp_remote_post($tokenUrl, [
            'timeout' => 15,
            'body'    => [
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'code'          => $code,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => OAuthRedirectUri::zoho()
            ]
        ]);

        if (is_wp_error($response)) {
            return $this->redirect($redirectBase . '&oauth=zoho&status=error&message=' . rawurlencode($response->get_error_message()));
        }

        $body = json_decode(\wp_remote_retrieve_body($response), true);
        if (!\is_array($body) || empty($body['access_token'])) {
            return $this->redirect($redirectBase . '&oauth=zoho&status=error&message=' . rawurlencode('Token exchange failed.'));
        }

        $settings['access_token']     = (string) $body['access_token'];
        $settings['refresh_token']    = isset($body['refresh_token']) ? (string) $body['refresh_token'] : ($settings['refresh_token'] ?? '');
        $settings['token_expires_at'] = isset($body['expires_in']) ? time() + (int) $body['expires_in'] : 0;
        $settings['api_domain']       = (string) ($body['api_domain'] ?? 'https://mail.zoho.com');

        $this->persistSettings($connection->id, $settings);

        return $this->redirect($redirectBase . '&oauth=zoho&status=success');
    }

    /**
     * Zoho OAuth account/authorization domain per data-center region.
     *
     * @since 1.0.0
     */
    private const ZOHO_AUTH_DOMAINS = [
        'us' => 'https://accounts.zoho.com',
        'eu' => 'https://accounts.zoho.eu',
        'in' => 'https://accounts.zoho.in',
        'cn' => 'https://accounts.zoho.com.cn',
        'au' => 'https://accounts.zoho.com.au',
        'jp' => 'https://accounts.zoho.jp',
        'ca' => 'https://accounts.zohocloud.ca'
    ];

    /**
     * Resolve the Zoho auth domain for a data-center region, falling back to the US domain.
     *
     * @since 1.0.0
     *
     * @param string $region Zoho data-center region code.
     * @return string The region's OAuth account/authorization domain.
     */
    private static function zohoAuthDomain(string $region): string {
        return self::ZOHO_AUTH_DOMAINS[$region] ?? self::ZOHO_AUTH_DOMAINS['us'];
    }

    /**
     * Decrypt a connection's stored settings.
     *
     * @since 1.0.0
     *
     * @param \BooleanSmtp\Models\Connection $connection Connection to read.
     * @return array<string, mixed> Decrypted settings.
     */
    private function decryptedSettings(\BooleanSmtp\Models\Connection $connection): array {
        $raw = $connection->settings ?? [];

        return \is_array($raw) ? $this->encryptor->decryptArray($raw) : [];
    }

    /**
     * Encrypt and persist a connection's settings.
     *
     * @since 1.0.0
     *
     * @param int                   $connectionId Connection to update.
     * @param array<string, mixed>  $settings     Plaintext settings to encrypt and store.
     */
    private function persistSettings(int $connectionId, array $settings): void {
        $this->connections->update($connectionId, [
            'settings' => $this->encryptor->encryptArray($settings)
        ]);
    }

    /**
     * Build the URL of the admin Connections screen.
     *
     * @since 1.0.0
     *
     * @return string The admin Connections screen URL.
     */
    private function adminConnectionsUrl(): string {
        return (string) admin_url('admin.php?page=boolean-smtp');
    }

    /**
     * Build the URL of a single connection's admin screen.
     *
     * @since 1.0.0
     *
     * @param int                    $connectionId Connection id to deep-link to.
     * @param array<string, string>  $query        Additional query arguments to append.
     * @return string The admin connection screen URL.
     */
    private function adminConnectionUrl(int $connectionId, array $query = []): string {
        $query = array_merge(['page' => 'boolean-smtp'], $query);

        return (string) admin_url('admin.php?' . http_build_query($query)) . '#/connections/' . $connectionId;
    }

    /**
     * Build the URL of the setup wizard's Connect step for a draft connection.
     *
     * @since 1.0.0
     *
     * @param int                   $connectionId Draft connection the wizard is configuring.
     * @param array<string, string> $query        Additional query arguments to append.
     * @return string The admin wizard URL, deep-linked to the Connect step.
     */
    private function adminOnboardingUrl(int $connectionId, array $query = []): string {
        $query = array_merge(['page' => 'boolean-smtp'], $query);

        return (string) admin_url('admin.php?' . http_build_query($query)) . '#/onboard?step=connect&connection=' . $connectionId;
    }

    /**
     * The admin screen the callback should return to, read from the authorize request.
     *
     * Only the screens in {@see RETURN_TO_SCREENS} are accepted; anything else returns null so the
     * callback lands on the connection screen as it always did.
     *
     * @since 1.0.0
     *
     * @param Request $request The authorize request, optionally carrying `return_to`.
     * @return string|null The accepted screen name, or null.
     */
    private function returnTo(Request $request): ?string {
        $screen = trim((string) $request->query('return_to', ''));

        return \in_array($screen, self::RETURN_TO_SCREENS, true) ? $screen : null;
    }

    /**
     * Build the callback's redirect for an outcome that is known after the state was verified.
     *
     * Returns to the setup wizard when the state asked for it, otherwise to the connection screen.
     *
     * @since 1.0.0
     *
     * @param string                                                                                  $provider Provider slug (`google`, `microsoft`).
     * @param string                                                                                  $status   `success` or `error`.
     * @param string|null                                                                             $message  Error message for the `error` status.
     * @param array{connection_id: int, provider: string, ts: int, site_url: string, return_to: string|null} $decoded  The verified state payload.
     * @return string The admin URL to redirect to.
     */
    private function callbackReturnUrl(string $provider, string $status, ?string $message, array $decoded): string {
        $query = ['oauth' => $provider, 'status' => $status];
        if ($message !== null && $message !== '') {
            $query['message'] = $message;
        }

        $connectionId = (int) $decoded['connection_id'];

        return ($decoded['return_to'] ?? null) === 'onboard'
            ? $this->adminOnboardingUrl($connectionId, $query)
            : $this->adminConnectionUrl($connectionId, $query);
    }

    /**
     * Resolve the Microsoft identity platform authorize endpoint for a tenant.
     *
     * @since 1.0.0
     *
     * @param string $tenant Microsoft tenant id, domain, or `common`/`organizations`/`consumers`.
     * @return string The authorize endpoint URL.
     */
    private function microsoftAuthorizeUrl(string $tenant): string {
        /**
         * Filters the Microsoft identity platform authorize URL used for delegated OAuth.
         *
         * @since 1.0.0
         *
         * @param string $url    Default authorize endpoint for the given tenant.
         * @param string $tenant Microsoft tenant id, domain, or `common`/`organizations`/`consumers`.
         * @return string The filtered URL.
         */
        return (string) apply_filters(
            'boolean_smtp_microsoft_authorize_url',
            "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/authorize",
            $tenant
        );
    }

    /**
     * Resolve the Microsoft identity platform token endpoint for a tenant.
     *
     * @since 1.0.0
     *
     * @param string $tenant Microsoft tenant id, domain, or `common`/`organizations`/`consumers`.
     * @return string The token endpoint URL.
     */
    private function microsoftTokenUrl(string $tenant): string {
        /** This filter is documented in app/Services/Mailer/OAuth/OAuthManualTokenExchange.php */
        return (string) apply_filters(
            'boolean_smtp_microsoft_token_url',
            "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token",
            $tenant
        );
    }

    /**
     * Validate an email address.
     *
     * @since 1.0.0
     *
     * @param string $value Value to validate.
     * @return bool True when the value is a syntactically valid email address.
     */
    private function isValidEmail(string $value): bool {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Resolve the Microsoft login `domain_hint` for an email address's domain.
     *
     * @since 1.0.0
     *
     * @param string $email Address to derive the domain hint from.
     * @return string `consumers` for personal Microsoft accounts, `organizations` otherwise, or
     *                 an empty string when the address has no domain part.
     */
    private function microsoftDomainHintForEmail(string $email): string {
        $parts = explode('@', strtolower($email));
        $host  = $parts[1] ?? '';

        if ($host === '') {
            return '';
        }

        if (in_array($host, ['outlook.com', 'hotmail.com', 'live.com', 'msn.com'], true)) {
            return 'consumers';
        }

        return 'organizations';
    }

    /**
     * Format a readable error message from a failed Microsoft token exchange response.
     *
     * @since 1.0.0
     *
     * @param mixed $body Decoded JSON response body from the token endpoint.
     * @return string A human-readable error message.
     */
    private function formatMicrosoftTokenExchangeError(mixed $body): string {
        if (!\is_array($body)) {
            return 'Token exchange failed.';
        }

        $error = trim((string) ($body['error'] ?? ''));
        $desc  = trim((string) ($body['error_description'] ?? ''));

        if ($desc !== '') {
            return 'Token exchange failed: ' . $desc;
        }

        if ($error !== '') {
            return 'Token exchange failed: ' . $error;
        }

        return 'Token exchange failed.';
    }
}
