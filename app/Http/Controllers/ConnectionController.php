<?php

/**
 * REST controller for mail connection CRUD, testing, credential verification, and OAuth management.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Http\Controllers;

use BooleanSmtp\Core\Http\Controller;
use BooleanSmtp\Core\Http\JsonResponse;
use BooleanSmtp\Core\Http\Request;
use BooleanSmtp\Http\Requests\UpdateConnectionRequest;
use BooleanSmtp\Http\Requests\StoreConnectionRequest;
use BooleanSmtp\Http\Concerns\ResolvesPagination;
use BooleanSmtp\Core\Database\Schema\Schema;
use BooleanSmtp\Contracts\EncryptorContract;
use BooleanSmtp\Models\Connection;
use BooleanSmtp\Repositories\ConnectionRepository;
use BooleanSmtp\Repositories\OAuthRefreshLogRepository;
use BooleanSmtp\Services\Connection\ConnectionHealthProbe;
use BooleanSmtp\Services\Debug\SmtpActivityCapture;
use BooleanSmtp\Services\Editions\SenderCandidate;
use BooleanSmtp\Services\Editions\SenderGuard;
use BooleanSmtp\Services\Editions\SenderNotices;
use BooleanSmtp\Services\Mailer\Api\SesApiSender;
use BooleanSmtp\Services\Mailer\MailerManager;
use BooleanSmtp\Services\Mailer\SupervisedSend;
use BooleanSmtp\Services\Mailer\TestEmailRenderer;
use BooleanSmtp\Services\Mailer\OAuth\OAuthExchangeException;
use BooleanSmtp\Services\Mailer\OAuth\OAuthManualTokenExchange;
use BooleanSmtp\Services\Settings\CredentialSourceDetector;
use BooleanSmtp\Services\Settings\ConstantSettingsResolver;
use BooleanSmtp\Support\Debug\WordPressDebugLogger;
use BooleanSmtp\Support\WordPressMailerLoader;
use BooleanSmtp\Core\Foundation\Application;

/**
 * Manages mail connections: CRUD, connection testing, credential verification, and OAuth token
 * exchange, save, and refresh.
 *
 * @since 1.0.0
 */
class ConnectionController extends Controller {
    use ResolvesPagination;

    /**
     * @since 1.0.0
     *
     * @param ConnectionRepository $connections Reads and writes connection records.
     * @param EncryptorContract    $encryptor   Encrypts and decrypts connection settings at rest.
     * @param OAuthRefreshLogRepository $refreshLogs Refresh history for the OAuth endpoints.
     */
    public function __construct(
        protected ConnectionRepository $connections,
        protected EncryptorContract $encryptor,
        protected OAuthRefreshLogRepository $refreshLogs
    ) {}

    /**
     * Handle `GET /booleansmtp/v1/connections`.
     *
     * @since 1.0.0
     *
     * @param Request $request Current request; no parameters are read.
     * @return JsonResponse Every connection, with settings masked and enriched with schema,
     *                       OAuth/credential-source metadata and a `sender_notice` (a line that
     *                       explains a connection sharing a sender the rule does not allow, or null).
     */
    public function index(Request $request): JsonResponse {
        $connections = $this->connections->all();
        $notices     = $this->make(SenderNotices::class)->forConnections($connections);

        $result = $connections->map(function ($conn) use ($notices) {
            return $this->buildConnectionResponse($conn) + ['sender_notice' => $notices[(int) $conn->id] ?? null];
        });

        return $this->ok($result->toArray());
    }

    /**
     * Handle `GET /booleansmtp/v1/connections/{id}`.
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying the connection `id` route parameter.
     * @return JsonResponse The connection, with settings masked and enriched with schema and
     *                       OAuth/credential-source metadata.
     */
    public function show(Request $request): JsonResponse {
        $id         = (int) $request->param('id');
        $connection = $this->connections->findOrFail($id);

        return $this->ok($this->buildConnectionResponse($connection));
    }

    /**
     * Handle `POST /booleansmtp/v1/connections`.
     *
     * Reads `name`, `driver`, `settings`, and `priority`. Checks that the delivery mode is one the
     * transport offers and that the sender rule allows the From address, validates the
     * transport-specific settings, encrypts sensitive settings before storage, and creates the
     * connection.
     *
     * @since 1.0.0
     *
     * @param StoreConnectionRequest $request Request carrying `name`, `driver`, `settings`, `priority`, and
     *                          `is_active`.
     * @return JsonResponse The created connection, or a 422 error when validation, the delivery
     *                       mode or the sender rule refuses it.
     */
    public function store(StoreConnectionRequest $request): JsonResponse {
        /**
         * Filters a connection's data before it is created through the REST API.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $data Connection fields (`name`, `driver`, `settings`,
         *                                    `priority`) whitelisted from the request. Return the
         *                                    array to use for creation.
         * @return array<string, mixed> The filtered data.
         */
        $data = \apply_filters('boolean_smtp_connection_data', $request->only([
            'name', 'driver', 'settings', 'priority'
        ]));

        $modeError = $this->assertDeliveryModeOffered((string) ($data['driver'] ?? ''), (array) ($data['settings'] ?? []));
        if ($modeError !== null) {
            return $modeError;
        }

        $settings        = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $validationError = $this->validateTransportSettings($request, (string) ($data['driver'] ?? ''), $settings);
        if ($validationError !== null) {
            return $validationError;
        }

        $senderError = $this->checkSender(
            (string) ($data['driver'] ?? ''),
            $settings,
            (string) ($data['name'] ?? ''),
            null,
            SenderCandidate::REASON_CREATE
        );
        if ($senderError !== null) {
            return $senderError;
        }

        if (!empty($settings)) {
            $data['settings'] = $this->prepareSettingsForStorage((string) ($data['driver'] ?? ''), $settings);
        }

        if (isset($data['settings']) && is_array($data['settings'])) {
            $data['settings'] = $this->encryptor->encryptArray($data['settings']);
        }

        $data['is_active']     = $request->get('is_active', true);
        $data['priority']      = (int) $request->get('priority', 0);
        $data['health_status'] = 'unknown';

        $connection = $this->connections->create($data);

        /**
         * Fires after a connection has been created through the REST API.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $payload {
         *     @type int    $id        Id of the created connection.
         *     @type string $name      Connection name.
         *     @type string $driver    Transport driver key.
         *     @type int    $timestamp Unix timestamp of creation.
         * }
         */
        \do_action('boolean_smtp_connection_created', [
            'id'        => $connection->id,
            'name'      => $connection->name,
            'driver'    => $connection->driver,
            'timestamp' => time()
        ]);

        return $this->created($this->buildConnectionResponse($connection), 'Connection created.');
    }

    /**
     * Handle `PUT /booleansmtp/v1/connections/{id}`.
     *
     * Reads `name`, `driver`, `settings`, `is_active`, and `priority`. When `settings` is present,
     * checks the delivery mode is one the transport offers, preserves OAuth identity/secret/token
     * fields the UI sent as masked placeholders, validates the transport settings, and encrypts
     * sensitive settings before storage. A changed From address, or switching the connection on,
     * is checked against the sender rule.
     *
     * @since 1.0.0
     *
     * @param UpdateConnectionRequest $request Request carrying the connection `id` route parameter and `name`,
     *                          `driver`, `settings`, `is_active`, `priority` fields.
     * @return JsonResponse The updated connection, or a 422 error when validation, the delivery
     *                       mode or the sender rule refuses it.
     */
    public function update(UpdateConnectionRequest $request): JsonResponse {
        $id       = (int) $request->param('id');
        $existing = $this->connections->findOrFail($id);

        $data = $request->only(['name', 'driver', 'settings', 'is_active', 'priority']);

        $senderError = $this->checkSenderOnUpdate($existing, $data);
        if ($senderError !== null) {
            return $senderError;
        }

        if (array_key_exists('settings', $data) && is_array($data['settings'])) {
            $driver = (string) ($data['driver'] ?? $existing->driver ?? '');

            // A delivery mode must be one the transport offers on every save, not only when the
            // connection is created, so a mode an extension no longer provides cannot be kept.
            $existingSettings = $this->decryptedConnectionSettings($existing);
            $effectiveMode    = (string) ($data['settings']['delivery_mode'] ?? $existingSettings['delivery_mode'] ?? '');
            $modeError        = $this->assertDeliveryModeOffered($driver, ['delivery_mode' => $effectiveMode]);
            if ($modeError !== null) {
                return $modeError;
            }

            $data['settings'] = $this->preserveOAuthIdentityFields($existing, $data['settings']);
            $data['settings'] = $this->preserveOAuthClientSecretField($existing, $data['settings']);
            $data['settings'] = $this->preserveOAuthTokenFields($existing, $data['settings']);
            $data['settings'] = $this->preserveMaskedSecrets($existing, $data['settings']);
            $oauthGuardError  = $this->validateOAuthTokenGuard($existing, $data['settings']);
            if ($oauthGuardError !== null) {
                return $oauthGuardError;
            }
            $validationError = $this->validateTransportSettings($request, $driver, $data['settings']);
            if ($validationError !== null) {
                return $validationError;
            }

            $data['settings'] = $this->prepareSettingsForStorage($driver, $data['settings']);
        }

        if (isset($data['settings']) && is_array($data['settings'])) {
            $data['settings'] = $this->encryptor->encryptArray($data['settings']);
        }

        $connection = $this->connections->update($id, $data);

        /**
         * Fires after a connection has been updated through the REST API.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $payload {
         *     @type int                  $id        Id of the updated connection.
         *     @type array<string, mixed> $data      The connection's full record after the update.
         *     @type int                  $timestamp Unix timestamp of the update.
         * }
         */
        \do_action('boolean_smtp_connection_updated', [
            'id'        => $connection->id,
            'data'      => $connection->toArray(),
            'timestamp' => time()
        ]);

        return $this->ok($this->buildConnectionResponse($connection), 'Connection updated.');
    }

    /**
     * Handle `DELETE /booleansmtp/v1/connections/{id}`.
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying the connection `id` route parameter.
     * @return JsonResponse Confirmation message.
     */
    public function destroy(Request $request): JsonResponse {
        $id         = (int) $request->param('id');
        $connection = $this->connections->findOrFail($id);

        /**
         * Fires immediately before a connection is deleted.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $payload {
         *     @type int                  $id        Id of the connection about to be deleted.
         *     @type array<string, mixed> $data      The connection's full record before deletion.
         *     @type int                  $timestamp Unix timestamp of the request.
         * }
         */
        \do_action('boolean_smtp_before_connection_delete', [
            'id'        => (int) $connection->id,
            'data'      => $connection->toArray(),
            'timestamp' => time()
        ]);

        $this->connections->delete($id);

        /**
         * Fires after a connection has been deleted.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $payload {
         *     @type int $id        Id of the deleted connection.
         *     @type int $timestamp Unix timestamp of the deletion.
         * }
         */
        \do_action('boolean_smtp_connection_deleted', [
            'id'        => $id,
            'timestamp' => time()
        ]);

        return $this->ok(null, 'Connection deleted.');
    }

    /**
     * Handle `DELETE /booleansmtp/v1/connections`.
     *
     * Reads `ids` and deletes each matching connection, skipping any that fail. Fires the same
     * `boolean_smtp_before_connection_delete` and `boolean_smtp_connection_deleted` hooks as
     * {@see destroy()} for each connection removed.
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying the `ids` array of connection ids to delete.
     * @return JsonResponse Confirmation message with the number of connections deleted, or a 422
     *                       error when no ids are provided.
     */
    public function bulkDestroy(Request $request): JsonResponse {
        $ids = $request->get('ids', []);

        if (!is_array($ids) || empty($ids)) {
            return $this->error('No IDs provided.', 422);
        }

        $count = 0;
        foreach ($ids as $id) {
            try {
                $connection = $this->connections->find((int) $id);
                if (!$connection) {
                    continue;
                }

                /** This action is documented in {@see ConnectionController::destroy()}. */
                \do_action('boolean_smtp_before_connection_delete', [
                    'id'        => (int) $id,
                    'timestamp' => time(),
                    'data'      => $connection->toArray()
                ]);

                $this->connections->delete((int) $id);
                $count++;

                /** This action is documented in {@see ConnectionController::destroy()}. */
                \do_action('boolean_smtp_connection_deleted', [
                    'id'        => (int) $id,
                    'timestamp' => time()
                ]);
            } catch (\Throwable) {
                continue;
            }
        }

        return $this->ok(null, "Successfully deleted {$count} connections.");
    }

    /**
     * Handle `POST /booleansmtp/v1/connections/{id}/test`.
     *
     * Sends a real message through the connection (via `wp_mail()` for the WordPress mail driver,
     * or a direct health probe for every other driver), updates the connection's health status,
     * and returns the result. Raw provider error details are only included when the developer
     * API-debug filter is enabled.
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying the connection `id` route parameter.
     * @return JsonResponse `success`, and on failure a safe `error` message plus, when API-debug
     *                       is enabled, `api_debug`/`resolved_mailer`/`debug` detail.
     */
    public function test(Request $request): JsonResponse {
        $id         = (int) $request->param('id');
        $connection = $this->connections->findOrFail($id);
        $apiDebugEnabled = WordPressDebugLogger::canExposeApiDebugResponse();

        if ($connection->driver === 'php') {
            return $this->testWordPressMailConnection($connection, $id, $apiDebugEnabled);
        }

        $settings  = $connection->settings ?? [];
        $decrypted = $this->encryptor->decryptArray(
            is_array($settings) ? $settings : []
        );

        /** @var ConnectionHealthProbe $probe */
        $probe  = $this->make(ConnectionHealthProbe::class);
        $result = $probe->probe($connection, $decrypted, true, true);

        if (!$result['healthy']) {
            $rawError = $result['error'] ?? 'Connection test failed';
            $this->connections->updateHealthStatus($id, 'error', $rawError);

            $payload = [
                'success'         => false,
                'error'           => $apiDebugEnabled ? $rawError : self::safeConnectionTestError()
            ];
            if ($apiDebugEnabled) {
                $payload['api_debug']       = $result['api_debug'] ?? null;
                $payload['resolved_mailer'] = null;
                WordPressDebugLogger::mergeApiDebugIntoDataArray($payload);
            }

            return $this->ok($payload, 'Connection test failed.');
        }

        $this->connections->updateHealthStatus($id, 'healthy');

        $payload = ['success' => true];
        if ($apiDebugEnabled) {
            $payload['debug']           = $result['debug'] ?? '';
            $payload['api_debug']       = $result['api_debug'] ?? null;
            $payload['resolved_mailer'] = $result['resolved_mailer'] ?? null;
            WordPressDebugLogger::mergeApiDebugIntoDataArray($payload);
        }

        return $this->ok($payload, 'Connection test passed.');
    }

    /**
     * Handle `POST /booleansmtp/v1/connections/{id}/verify-credentials`.
     *
     * Reads `client_id`, `client_secret`, and, per driver, `tenant_id` or `region`. Saves the
     * OAuth client credentials for a Google, Outlook, or Zoho connection and validates them
     * without requiring a full delegated-consent round trip: Outlook credentials are checked
     * against the Microsoft token endpoint directly; Google and Zoho are validated by schema only
     * and confirmed on the next OAuth authorize/callback.
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying the connection `id` route parameter and
     *                          `client_id`, `client_secret`, `tenant_id`, `region`.
     * @return JsonResponse `verified: true` (plus an optional `verification_hint`) on success, or
     *                       a 422 error when the driver is unsupported or verification fails.
     */
    public function verifyCredentials(Request $request): JsonResponse {
        $id = (int) $request->param('id');
        /** @var Connection $connection */
        $connection = $this->connections->findOrFail($id);
        $driver     = (string) ($connection->driver ?? '');
        $oauthDriver = $this->normalizeOAuthDriver($driver);
        $apiDebugEnabled = WordPressDebugLogger::canExposeApiDebugResponse();

        if (!$this->isOAuthDriver($driver)) {
            return $this->error('Verify credentials is only supported for Google, Outlook, and Zoho connections.', 422);
        }

        $this->validate($request, [
            'client_id'     => 'required|string|max:2048',
            'client_secret' => 'required|string|max:2048',
            'tenant_id'     => 'nullable|string|max:255',
            'region'        => 'nullable|string|max:32'
        ]);

        $settings = MailerManager::prepareDecryptedConnectionSettings(
            $this->make(ConstantSettingsResolver::class),
            $oauthDriver,
            $this->decryptedConnectionSettings($connection)
        );
        $settings['delivery_mode'] = 'api';
        $settings['client_id']     = trim((string) $request->input('client_id'));

        // In edit flows the UI may send a masked placeholder like "****abcd".
        // Preserve the real stored secret in that case so OAuth code exchange keeps working.
        $incomingClientSecret = trim((string) $request->input('client_secret'));
        $storedClientSecret   = trim((string) ($settings['client_secret'] ?? ''));
        if ($this->isLikelyMaskedSecretPlaceholder($incomingClientSecret) && $storedClientSecret !== '') {
            if ($this->isLikelyMaskedSecretPlaceholder($storedClientSecret)) {
                return $this->error('Client secret is masked in storage. Paste the real Microsoft Client Secret, verify credentials, then reconnect.', 422);
            }
            $settings['client_secret'] = $storedClientSecret;
        } else {
            // Hardening: users often paste client secrets with trailing whitespace/newlines.
            // That breaks refresh/verification with AADSTS7000215.
            $settings['client_secret'] = $incomingClientSecret;
        }

        if ($oauthDriver === 'outlook') {
            $tenant = $request->input('tenant_id');
            if ($tenant !== null && trim((string) $tenant) !== '') {
                $settings['tenant_id'] = trim((string) $tenant);
            } elseif (empty($settings['tenant_id'])) {
                $settings['tenant_id'] = 'common';
            }
        }

        if ($oauthDriver === 'zoho') {
            $region = $request->input('region');
            if ($region !== null && trim((string) $region) !== '') {
                $settings['region'] = trim((string) $region);
            }
        }

        $mailer    = $this->make(MailerManager::class);
        $transport = $mailer->resolveTransportByDriver($oauthDriver);
        $errors    = $transport->validateSettings($settings);
        if (!empty($errors)) {
            return $this->validationError($errors);
        }

        // Real Microsoft preflight: validate tenant + client_id + client_secret at the token endpoint.
        // We intentionally use a dummy authorization_code exchange: wrong credentials fail as invalid_client,
        // while valid delegated apps commonly return invalid_grant for the fake code.
        $verificationHint = null;
        if ($oauthDriver === 'outlook') {
            $verification = $this->verifyOutlookCredentials($settings);
            if (!($verification['ok'] ?? false)) {
                $error = (string) ($verification['error'] ?? 'Microsoft credential verification failed.');

                return $this->error(
                    $apiDebugEnabled ? $error : self::safeCredentialVerificationError(),
                    422
                );
            }

            $verificationHint = isset($verification['hint']) ? (string) $verification['hint'] : null;
        }

        $this->connections->update($id, [
            'settings' => $this->encryptor->encryptArray($settings)
        ]);

        $payload = ['verified' => true];
        if ($verificationHint !== null && $verificationHint !== '') {
            $payload['verification_hint'] = $apiDebugEnabled
                ? $verificationHint
                : self::safeCredentialVerificationHint();
        }

        return $this->ok($payload, 'Credentials saved.');
    }

    /**
     * Handle `POST /booleansmtp/v1/connections/{id}/verify-api-credentials`.
     *
     * Reads `access_key`, `secret`, `region`, `delivery_mode` and an optional `from_email`.
     * Static-credential connectivity check for drivers that do not use OAuth (currently Amazon
     * SES) — the equivalent of {@see verifyCredentials()} for an access-key/secret pair instead
     * of an OAuth client id/secret. Makes one real, side-effect-free AWS call (`ses:GetSendQuota`)
     * so a bad key, bad secret, wrong region, or an IAM policy with no SES access is caught before
     * a real send is attempted. With `from_email`, a second read-only call
     * (`ses:GetIdentityVerificationAttributes`) reports whether that address or its domain is a
     * verified SES identity; a failure of that call never fails the verification, it is reported
     * as `identity: null`.
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying the connection `id` route parameter and
     *                          `access_key`, `secret`, `region`, `delivery_mode`, `from_email`.
     * @return JsonResponse Verified send-quota details (and the sender identity's status when
     *                       `from_email` was given) on success, or a 422 error when AWS rejects
     *                       the credentials.
     */
    public function verifyApiCredentials(Request $request): JsonResponse {
        $id = (int) $request->param('id');
        /** @var Connection $connection */
        $connection = $this->connections->findOrFail($id);
        $driver     = (string) ($connection->driver ?? '');
        $apiDebugEnabled = WordPressDebugLogger::canExposeApiDebugResponse();

        if ($driver !== 'ses') {
            return $this->error('Credential validation is only available for Amazon SES connections right now.', 422);
        }

        $this->validate($request, [
            'access_key'    => 'required|string|max:255',
            'secret'        => 'required|string|max:255',
            'region'        => 'nullable|string|max:32',
            'delivery_mode' => 'nullable|string',
            'from_email'    => 'nullable|email'
        ]);

        $deliveryMode = (string) $request->input('delivery_mode', 'api');
        $region       = trim((string) $request->input('region')) ?: 'us-east-1';

        $stored             = $this->decryptedConnectionSettings($connection);
        $storedAccessKeyKey = $deliveryMode === 'smtp' ? 'smtp_username' : 'api_access_key';
        $storedSecretKey    = $deliveryMode === 'smtp' ? 'smtp_password' : 'api_secret';

        $incomingAccessKey = trim((string) $request->input('access_key'));
        $incomingSecret    = trim((string) $request->input('secret'));

        // Edit-mode UX: the form shows a masked placeholder for an already-saved secret. Fall
        // back to the real stored value rather than trying to validate "****abcd" against AWS.
        $accessKey = $this->isLikelyMaskedSecretPlaceholder($incomingAccessKey)
            ? (string) ($stored[$storedAccessKeyKey] ?? $stored['access_key'] ?? '')
            : $incomingAccessKey;
        $secret = $this->isLikelyMaskedSecretPlaceholder($incomingSecret)
            ? (string) ($stored[$storedSecretKey] ?? $stored['secret'] ?? '')
            : $incomingSecret;

        if ($accessKey === '' || $secret === '') {
            return $this->error('Access Key ID and Secret Access Key are required to validate.', 422);
        }

        $sender = new SesApiSender();
        $quota  = $sender->getSendQuota([
            'access_key' => $accessKey,
            'secret'     => $secret,
            'region'     => $region
        ]);

        if (\is_wp_error($quota)) {
            $rawError = $quota->get_error_message();

            return $this->error(
                $apiDebugEnabled
                    ? 'AWS rejected these credentials: ' . $rawError
                    : self::safeCredentialVerificationError($quota),
                422,
                $apiDebugEnabled ? ['code' => $quota->get_error_code()] : null
            );
        }

        $identity  = null;
        $fromEmail = trim((string) $request->input('from_email', ''));
        if ($fromEmail !== '') {
            $status = $sender->getIdentityVerification([
                'access_key' => $accessKey,
                'secret'     => $secret,
                'region'     => $region
            ], $fromEmail);
            $identity = \is_wp_error($status) ? null : $status;
        }

        return $this->ok([
            'verified'           => true,
            'region'             => $region,
            'max_24_hour_send'   => $quota['Max24HourSend'] ?? null,
            'max_send_rate'      => $quota['MaxSendRate'] ?? null,
            'sent_last_24_hours' => $quota['SentLast24Hours'] ?? null,
            'identity'           => $identity,
        ], 'AWS accepted these credentials and confirmed SES API access.');
    }

    /**
     * Handle `POST /booleansmtp/v1/connections/{id}/oauth-token`.
     *
     * Reads `token` (a pasted authorization code, refresh token, or One Click bearer token) and
     * an optional `delivery_mode`, exchanges or applies it as needed for the connection's driver,
     * validates the resulting settings, and stores them encrypted. The connection's stored delivery
     * mode must still be one its transport offers.
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying the connection `id` route parameter, `token`, and
     *                          `delivery_mode`.
     * @return JsonResponse `{ success: true }` on success, or a 422 error when the driver does not
     *                       support OAuth, the provider refused the exchange (its reason in the
     *                       message), the pasted value cannot be processed, or validation fails.
     */
    public function saveOAuthToken(Request $request): JsonResponse {
        $id = (int) $request->param('id');
        /** @var Connection $connection */
        $connection = $this->connections->findOrFail($id);
        $driver     = (string) ($connection->driver ?? '');
        $oauthDriver = $this->normalizeOAuthDriver($driver);

        if (!$this->isOAuthDriver($driver)) {
            return $this->error('OAuth token is only supported for Google, Outlook, and Zoho connections.', 422);
        }

        $this->validate($request, [
            'token' => 'required|string|min:4|max:8192'
        ]);

        $settings = MailerManager::prepareDecryptedConnectionSettings(
            $this->make(ConstantSettingsResolver::class),
            $oauthDriver,
            $this->decryptedConnectionSettings($connection)
        );

        // This endpoint saves directly, so it checks the connection's stored delivery mode the
        // way update() does: a mode the transport no longer offers cannot keep receiving tokens.
        $modeError = $this->assertDeliveryModeOffered($driver, ['delivery_mode' => (string) ($settings['delivery_mode'] ?? '')]);
        if ($modeError !== null) {
            return $modeError;
        }

        $deliveryMode = (string) ($request->input('delivery_mode') ?? ($settings['delivery_mode'] ?? 'api'));
        $pasted = (string) $request->input('token');

        try {
            $merged = OAuthManualTokenExchange::applyPastedToken($oauthDriver, $pasted, $settings, $deliveryMode);
        } catch (OAuthExchangeException $e) {
            return $this->error(sprintf('The provider refused the authorization: %s', $e->getMessage()), 422);
        }
        if ($merged === null) {
            if ($oauthDriver === 'outlook' && $deliveryMode === 'one_click') {
                return $this->error('Could not process the pasted One Click token. Paste the full bearer token from the proxy callback flow.', 422);
            }

            return $this->error('Could not process the pasted value. Paste the authorization code or OAuth refresh token from the provider.', 422);
        }

        foreach ($merged as $key => $value) {
            $settings[$key] = $value;
        }

        $mailer    = $this->make(MailerManager::class);
        $transport = $mailer->resolveTransportByDriver($driver);
        $errors    = $transport->validateSettings($settings);
        if (!empty($errors)) {
            return $this->validationError($errors);
        }

        $this->connections->update($id, [
            'settings' => $this->encryptor->encryptArray($settings)
        ]);

        return $this->ok(['success' => true], 'OAuth token saved.');
    }

    /**
     * Decrypt a connection's stored settings.
     *
     * @since 1.0.0
     *
     * @param Connection $connection Connection to read.
     * @return array<string, mixed> Decrypted settings.
     */
    private function decryptedConnectionSettings(Connection $connection): array {
        $raw = $connection->settings ?? [];

        return \is_array($raw) ? $this->encryptor->decryptArray($raw) : [];
    }

    /**
     * Test a "WordPress mail" (`php`) driver connection by sending through `wp_mail()`.
     *
     * WordPress mail connections must be validated through `wp_mail()` itself, not a raw
     * PHPMailer/SMTP connection check, since `wp_mail()` is the only thing that determines how the
     * message is actually delivered for this driver.
     *
     * @since 1.0.0
     *
     * @param Connection $connection Connection being tested.
     * @param int        $id         Connection id, used to force this connection for the send.
     * @param bool       $apiDebugEnabled Whether to include raw debug detail in the response.
     * @return JsonResponse `success`, and on failure a safe or raw `error` depending on
     *                       `$apiDebugEnabled`.
     */
    private function testWordPressMailConnection(Connection $connection, int $id, bool $apiDebugEnabled): JsonResponse {
        WordPressMailerLoader::ensureLoaded();

        $mailer = $this->make(MailerManager::class);
        $renderer = $this->make(TestEmailRenderer::class);
        $mailer->forceConnectionForNextSend($id);

        $debugger = $apiDebugEnabled ? $this->make(SmtpActivityCapture::class) : null;
        if ($debugger !== null) {
            $debugger->startCapture();
        }

        // One user-initiated send testing THIS connection: fallback retry off, diagnostics captured.
        try {
            $outcome = $this->make(SupervisedSend::class)->run(function () use ($connection, $id, $renderer): bool {
                $adminEmail = (string) \get_option('admin_email', '');
                if ($adminEmail === '') {
                    throw new \RuntimeException('No admin email configured in WordPress.');
                }

                $subject  = sprintf('BooleanSMTP: Connection test (%s)', $connection->name);
                $rendered = $renderer->render($id);

                $sent = \wp_mail(
                    $adminEmail,
                    $subject,
                    $rendered['plain'],
                    ['Content-Type: text/plain; charset=UTF-8']
                );

                if (!$sent) {
                    $err = '';
                    if (isset($GLOBALS['phpmailer']) && \is_object($GLOBALS['phpmailer']) && isset($GLOBALS['phpmailer']->ErrorInfo)) {
                        $err = trim((string) $GLOBALS['phpmailer']->ErrorInfo);
                    }
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message is returned as JSON text or logged, never printed as HTML.
                    throw new \RuntimeException($err !== '' ? $err : 'wp_mail returned false.');
                }

                return true;
            }, $apiDebugEnabled);
        } finally {
            $mailer->clearForcedConnectionForSend();
            $debugLog = $debugger !== null ? $debugger->stopCapture() : [];
        }

        $resolvedMailer = $outcome->resolvedMailer;
        $success        = $outcome->sent && $outcome->exception === null;
        $error          = $success ? '' : $outcome->failureDetail('wp_mail returned false.');

        if ($success) {
            $this->connections->updateHealthStatus($id, 'healthy');
        } else {
            $this->connections->updateHealthStatus($id, 'error', $error);
        }

        if ($success) {
            $payload = ['success' => true];
            if ($apiDebugEnabled) {
                $payload['debug']           = $debugLog;
                $payload['resolved_mailer'] = $resolvedMailer;
            }

            return $this->ok($payload, 'Connection test passed.');
        }

        $payload = [
            'success'         => false,
            'error'           => $apiDebugEnabled ? $error : self::safeConnectionTestError()
        ];
        if ($apiDebugEnabled) {
            $payload['debug']           = $debugLog;
            $payload['resolved_mailer'] = $resolvedMailer;
        }

        return $this->ok($payload, 'Connection test failed.');
    }

    /**
     * The generic connection-test failure message shown to the browser in place of a raw provider error.
     *
     * @since 1.0.0
     *
     * @return string The generic error message.
     */
    private static function safeConnectionTestError(): string {
        return 'Connection test failed. Check the connection settings and try again.';
    }

    /**
     * The credential-verification failure message shown to the browser in place of a raw provider error.
     *
     * The raw exchange stays behind the diagnostics switch, but AWS's error code and the plugin's own
     * hint for it are not diagnostic data — they tell the person which of the three inputs to fix.
     *
     * @since 1.0.0
     *
     * @param \WP_Error|null $error The provider error, when the SES sender produced one.
     * @return string The generic message, followed by the error code and hint when known.
     */
    private static function safeCredentialVerificationError(?\WP_Error $error = null): string {
        $message = 'Credential verification failed. Review the connection settings and try again.';
        if ($error === null) {
            return $message;
        }

        $data = $error->get_error_data();
        $code = \is_array($data) ? trim((string) ($data['ses_error_code'] ?? '')) : '';
        $hint = \is_array($data) ? trim((string) ($data['ses_hint'] ?? '')) : '';
        if ($code !== '') {
            $message .= ' AWS answered ' . $code . '.';
        }
        if ($hint !== '') {
            $message .= ' ' . $hint;
        }

        return $message;
    }

    /**
     * The generic credential-verification hint shown to the browser in place of a raw provider warning.
     *
     * @since 1.0.0
     *
     * @return string The generic hint message.
     */
    private static function safeCredentialVerificationHint(): string {
        return 'Credential verification completed with a provider policy or permissions warning. Complete OAuth authorization to validate delivery.';
    }

    /**
     * Build a connection's settings for display: constant-defined and encrypted values are masked.
     *
     * @since 1.0.0
     *
     * @param Connection $connection Connection to read.
     * @return array<string, mixed> Settings safe to return to the browser.
     */
    private function getMaskedSettings($connection): array {
        $settings = $connection->settings ?? [];
        if (!is_array($settings)) {
            return [];
        }

        $decrypted  = $this->encryptor->decryptArray($settings);
        $resolver   = $this->make(\BooleanSmtp\Services\Settings\ConstantSettingsResolver::class);
        $masked     = [];
        $hiddenKeys = ['access_token', 'refresh_token', 'token_expires_at'];

        foreach ($decrypted as $key => $value) {
            if ($key === \BooleanSmtp\Services\Encryption\AesEncryptor::DECRYPT_FAILED_KEY) {
                continue;
            }

            if ($resolver->isDefined((string) $connection->driver, $key)) {
                $masked[$key] = '[defined in wp-config.php / env]';
                continue;
            }

            if (\in_array(strtolower($key), $hiddenKeys, true)) {
                continue;
            }

            // Same sensitivity check AesEncryptor used to decide this field is worth encrypting
            // at rest -- see its isSensitiveKey() docblock for why this can't be its own
            // independently-maintained list without drifting out of sync again.
            if ($this->encryptor->isSensitiveKey((string) $key) && is_string($value) && strlen($value) > 4) {
                $masked[$key] = str_repeat('*', strlen($value) - 4) . substr($value, -4);
            } else {
                $masked[$key] = $value;
            }
        }

        return $masked;
    }

    /**
     * Build OAuth token status metadata for a connection.
     *
     * @since 1.0.0
     *
     * @param Connection $connection Connection to read.
     * @return array<string, mixed> `oauth_token_expires_at`, `oauth_token_seconds_remaining`,
     *                               `oauth_refresh_available` and `oauth_account_email` (the
     *                               authorized account, `null` until a callback recorded it), or an
     *                               empty array for non-OAuth drivers.
     */
    private function getOauthStatusMeta($connection): array {
        $driver = (string) ($connection->driver ?? '');
        if (!$this->isOAuthDriver($driver)) {
            return [];
        }

        $rawSettings = $connection->settings ?? [];
        $decrypted   = \is_array($rawSettings) ? $this->encryptor->decryptArray($rawSettings) : [];

        $expiresAt        = isset($decrypted['token_expires_at']) ? (int) $decrypted['token_expires_at'] : 0;
        $secondsRemaining = $expiresAt > 0 ? ($expiresAt - time()) : null;
        $refresh          = trim((string) ($decrypted['refresh_token'] ?? ''));

        $account = trim((string) ($decrypted['oauth_account_email'] ?? ''));

        return [
            'oauth_token_expires_at'        => $expiresAt > 0 ? $expiresAt : null,
            'oauth_token_seconds_remaining' => $secondsRemaining,
            'oauth_refresh_available'       => $refresh !== '',
            'oauth_account_email'           => $account !== '' ? $account : null,
        ];
    }

    /**
     * Build the full REST representation of a connection: masked settings, schema, and metadata.
     *
     * @since 1.0.0
     *
     * @param Connection $connection Connection to represent.
     * @return array<string, mixed> The connection record merged with masked settings, its
     *                               settings schema, and OAuth/credential-source metadata.
     */
    private function buildConnectionResponse($connection): array {
        $data                    = $connection->toArray();
        $data['settings']        = $this->getMaskedSettings($connection);
        $data['settings_schema'] = $this->getSchemaForDriver((string) ($connection->driver ?? ''));
        // Secrets that no longer decrypt (the key changed, the row came from another site) are
        // blanked in `settings`; their names are listed so the form can ask for them again.
        $data['decrypt_failed']  = array_values((array) ($this->decryptedConnectionSettings($connection)[\BooleanSmtp\Services\Encryption\AesEncryptor::DECRYPT_FAILED_KEY] ?? []));
        if (($data['health_status'] ?? '') === 'error' && !WordPressDebugLogger::canExposeApiDebugResponse()) {
            // last_error may contain a provider raw HTTP response from an earlier
            // health or connection test. Do not reintroduce it through the normal
            // connection-list response after the diagnostic endpoint omits it.
            $data['last_error'] = self::safeConnectionTestError();
        }

        return array_merge(
            $data,
            $this->getOauthStatusMeta($connection),
            $this->getCredentialSourceMeta($connection)
        );
    }

    /**
     * Build credential-source metadata (database, wp-config.php constant, or environment
     * variable) for an SES connection.
     *
     * @since 1.0.0
     *
     * @param Connection $connection Connection to read.
     * @return array<string, mixed> `credential_sources`, or an empty array for non-SES drivers.
     */
    private function getCredentialSourceMeta($connection): array {
        if ((string) ($connection->driver ?? '') !== 'ses') {
            return [];
        }

        $rawSettings = $connection->settings ?? [];
        $decrypted   = \is_array($rawSettings) ? $this->encryptor->decryptArray($rawSettings) : [];

        try {
            return [
                'credential_sources' => CredentialSourceDetector::detectAll($decrypted)
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Resolve the settings schema for a transport driver, augmented with the `key_store` field.
     *
     * @since 1.0.0
     *
     * @param string $driver Transport driver key.
     * @return array<string, mixed> The settings schema, or an empty array when the driver is
     *                               unknown or resolution fails.
     */
    private function getSchemaForDriver(string $driver): array {
        try {
            $mailer     = $this->make(MailerManager::class);
            $transports = $mailer->getTransports();

            if (isset($transports[$driver]) && class_exists($transports[$driver])) {
                $transport = new $transports[$driver]();
                $schema    = $transport->getSettingsSchema();
                $rules     = $transport->getValidationRules();

                $this->augmentKeyStoreSchemaAndRules($schema, $rules, $driver);

                /** This filter is documented in app/Http/Controllers/TransportController.php */
                return \apply_filters('boolean_smtp_transport_settings_schema', $schema, $driver);
            }
        } catch (\Throwable) {
            // fallback
        }

        return [];
    }

    /**
     * Preserve persisted OAuth token fields when the edit payload omits them.
     *
     * @since 1.0.0
     *
     * @param Connection            $existing Connection as currently stored.
     * @param array<string, mixed>  $incoming Incoming settings from the update request.
     * @return array<string, mixed> The incoming settings with missing token fields restored from
     *                               the existing connection.
     */
    private function preserveOAuthTokenFields(Connection $existing, array $incoming): array {
        $driver = (string) ($existing->driver ?? '');
        if (!$this->isOAuthDriver($driver)) {
            return $incoming;
        }

        $existingSettings = $this->decryptedConnectionSettings($existing);
        foreach (['access_token', 'refresh_token', 'token_expires_at', 'oauth_account_email', 'one_click_bearer_token', 'one_click_status'] as $tokenKey) {
            if (!\array_key_exists($tokenKey, $incoming) && \array_key_exists($tokenKey, $existingSettings)) {
                $incoming[$tokenKey] = $existingSettings[$tokenKey];
            }
        }

        if (\array_key_exists('one_click_bearer_token', $incoming)) {
            $incomingToken = trim((string) $incoming['one_click_bearer_token']);
            if ($this->isLikelyMaskedSecretPlaceholder($incomingToken)) {
                $existingToken = trim((string) ($existingSettings['one_click_bearer_token'] ?? ''));
                if ($existingToken !== '' && !$this->isLikelyMaskedSecretPlaceholder($existingToken)) {
                    $incoming['one_click_bearer_token'] = $existingToken;
                } else {
                    unset($incoming['one_click_bearer_token']);
                }
            }
        }

        return $incoming;
    }

    /**
     * Preserve a persisted OAuth client secret when the edit payload sends a masked placeholder.
     *
     * @since 1.0.0
     *
     * @param Connection            $existing Connection as currently stored.
     * @param array<string, mixed>  $incoming Incoming settings from the update request.
     * @return array<string, mixed> The incoming settings with a masked secret replaced by the
     *                               real stored value, or unset when no real value can be recovered.
     */
    private function preserveOAuthClientSecretField(Connection $existing, array $incoming): array {
        $driver = (string) ($existing->driver ?? '');
        if (!$this->isOAuthDriver($driver)) {
            return $incoming;
        }

        $existingSettings = $this->decryptedConnectionSettings($existing);

        // 'client_secret' (delegated api mode) and 'app_client_secret' (Outlook's Pro-only
        // Application Permission mode -- a deliberately separate field/credential from the
        // delegated mode's, see MicrosoftSchemaExtender's docblock) both need this same
        // masked-placeholder handling, or editing any other field on an existing app_permission
        // connection without retyping the secret would silently overwrite the real stored secret
        // with the UI's masked placeholder string.
        foreach (['client_secret', 'app_client_secret'] as $secretKey) {
            if (!array_key_exists($secretKey, $incoming)) {
                continue;
            }

            $incomingSecret = trim((string) $incoming[$secretKey]);
            if (!$this->isLikelyMaskedSecretPlaceholder($incomingSecret)) {
                continue;
            }

            $existingSecret = trim((string) ($existingSettings[$secretKey] ?? ''));

            // Keep an existing real secret when UI sends masked placeholder.
            if ($existingSecret !== '' && !$this->isLikelyMaskedSecretPlaceholder($existingSecret)) {
                $incoming[$secretKey] = $existingSecret;
                continue;
            }

            // Avoid persisting masked placeholders when we cannot recover.
            unset($incoming[$secretKey]);
        }

        return $incoming;
    }

    /**
     * Keep every stored credential whose incoming value is the masked placeholder the API returned.
     *
     * The list, edit and wizard screens receive secrets as `****abcd`; a save that sends that value
     * back unchanged means "keep what is stored", not "store the mask". The OAuth-specific
     * preservers above handle the OAuth fields; this covers every other sensitive key the encryptor
     * recognises (SMTP password, API secret, API key).
     *
     * @since 1.0.0
     *
     * @param Connection            $existing Connection as currently stored.
     * @param array<string, mixed>  $incoming Incoming settings from the update request.
     * @return array<string, mixed> The incoming settings with each masked secret replaced by the stored value.
     */
    private function preserveMaskedSecrets(Connection $existing, array $incoming): array {
        $existingSettings = null;

        foreach ($incoming as $key => $value) {
            $key = (string) $key;
            if (!\is_string($value) || !$this->encryptor->isSensitiveKey($key) || !$this->isLikelyMaskedSecretPlaceholder(trim($value))) {
                continue;
            }

            $existingSettings ??= $this->decryptedConnectionSettings($existing);
            $stored = (string) ($existingSettings[$key] ?? '');

            if ($stored !== '' && !$this->isLikelyMaskedSecretPlaceholder($stored)) {
                $incoming[$key] = $stored;
            }
        }

        return $incoming;
    }

    /**
     * Preserve persisted OAuth identity fields (client id, tenant, region) left blank in the
     * edit payload.
     *
     * @since 1.0.0
     *
     * @param Connection            $existing Connection as currently stored.
     * @param array<string, mixed>  $incoming Incoming settings from the update request.
     * @return array<string, mixed> The incoming settings with blank identity fields restored
     *                               from the existing connection.
     */
    private function preserveOAuthIdentityFields(Connection $existing, array $incoming): array {
        $driver = (string) ($existing->driver ?? '');
        if (!$this->isOAuthDriver($driver)) {
            return $incoming;
        }

        $existingSettings = $this->decryptedConnectionSettings($existing);

        foreach (['client_id', 'tenant_id', 'region', 'app_client_id', 'app_tenant_id'] as $key) {
            if (!array_key_exists($key, $incoming)) {
                if (array_key_exists($key, $existingSettings)) {
                    $incoming[$key] = $existingSettings[$key];
                }
                continue;
            }

            $incomingValue = trim((string) $incoming[$key]);
            if ($incomingValue === '' && array_key_exists($key, $existingSettings)) {
                $incoming[$key] = $existingSettings[$key];
            }
        }

        return $incoming;
    }

    /**
     * Guard against silently clearing or omitting a required OAuth refresh token in API mode.
     *
     * @since 1.0.0
     *
     * @param Connection            $existing Connection as currently stored.
     * @param array<string, mixed>  $incoming Incoming settings from the update request.
     * @return JsonResponse|null A 422 validation error when the update would leave API mode
     *                            without a refresh token, otherwise null.
     */
    private function validateOAuthTokenGuard(Connection $existing, array $incoming): ?JsonResponse {
        $driver = (string) ($existing->driver ?? '');
        if (!$this->isOAuthDriver($driver)) {
            return null;
        }

        $existingSettings = $this->decryptedConnectionSettings($existing);
        $deliveryMode     = (string) ($incoming['delivery_mode'] ?? $existingSettings['delivery_mode'] ?? '');
        if ($deliveryMode !== 'api') {
            return null;
        }

        $incomingRefresh = isset($incoming['refresh_token']) ? trim((string) $incoming['refresh_token']) : null;
        $existingRefresh = trim((string) ($existingSettings['refresh_token'] ?? ''));
        if ($incomingRefresh !== null && $incomingRefresh === '' && $existingRefresh !== '') {
            return $this->validationError([
                'refresh_token' => 'Refusing to clear refresh token for API mode connection.'
            ]);
        }

        $effectiveRefresh = $incomingRefresh !== null ? $incomingRefresh : $existingRefresh;
        if ($effectiveRefresh === '') {
            return $this->validationError([
                'refresh_token' => 'API mode requires a valid refresh token. Verify credentials and save OAuth token first.'
            ]);
        }

        return null;
    }

    /**
     * Validate a connection's transport-specific settings before create or update.
     *
     * Delegates to the resolved transport's own `validateSettings()` rather than the framework's
     * `Request::validate()`, which does not support dot-notation keys like `settings.client_id`.
     * Also enforces the delivery mode and credential-source (`key_store`) values, adds SES
     * access-key/secret sanity checks, and, on an OAuth driver's update flow, relaxes identity
     * field errors that are only masked-placeholder artifacts of the edit UI.
     *
     * @since 1.0.0
     *
     * @param Request               $request Current request, used to detect update vs. create.
     * @param string                $driver  Transport driver key.
     * @param array<string, mixed>  $settings Settings to validate.
     * @return JsonResponse|null A 422 validation error response, or null when settings are valid.
     */
    private function validateTransportSettings(Request $request, string $driver, array $settings): ?JsonResponse {
        try {
            $mailer    = $this->make(MailerManager::class);
            $transport = $mailer->resolveTransportByDriver($driver);
            $resolver  = $this->make(\BooleanSmtp\Services\Settings\ConstantSettingsResolver::class);
            $keyStore  = (string) ($settings['key_store'] ?? 'db');

            // This project uses a simplified Request::validate() implementation that does not
            // support dot-notation (e.g. settings.client_id). We rely on transport-level
            // validateSettings() instead of framework validation for settings fields.

            // Basic delivery mode guard (prevents accidental SMTP/API mismatch).
            $deliveryMode = (string) ($settings['delivery_mode'] ?? 'smtp');
            if (!in_array($deliveryMode, ['smtp', 'api', 'one_click', 'app_permission'], true)) {
                return $this->validationError(['delivery_mode' => 'Invalid delivery mode.']);
            }

            // Key store resolution (db / constants / env).
            if ($keyStore !== 'db') {
                if (!in_array($keyStore, ['wp_config', 'env'], true)) {
                    return $this->validationError(['key_store' => 'Invalid credential source.']);
                }

                $settings = $resolver->resolve($driver, $settings, array_keys($transport->getValidationRules()));
            }

            $customErrors = $transport->validateSettings($settings);

            if ($driver === 'ses' && $deliveryMode === 'api') {
                $apiAccessKey = trim((string) ($settings['api_access_key'] ?? $settings['access_key'] ?? ''));
                $apiSecret    = trim((string) ($settings['api_secret'] ?? $settings['secret'] ?? $settings['secret_key'] ?? ''));

                if ($apiAccessKey !== '') {
                    if ($this->isLikelyMaskedSecretPlaceholder($apiAccessKey)) {
                        $customErrors['api_access_key'] = 'Access Key appears masked. Paste the full Access Key ID before saving.';
                    } elseif (preg_match('/\s/', $apiAccessKey) === 1) {
                        $customErrors['api_access_key'] = 'Access Key ID must not contain spaces or line breaks.';
                    }
                }

                if ($apiSecret !== '') {
                    if ($this->isLikelyMaskedSecretPlaceholder($apiSecret)) {
                        $customErrors['api_secret'] = 'Secret Access Key appears masked. Paste the full Secret Access Key before saving.';
                    } elseif (preg_match('/\s/', $apiSecret) === 1) {
                        $customErrors['api_secret'] = 'Secret Access Key must not contain spaces or line breaks.';
                    }
                }
            }

            if ($keyStore !== 'db') {
                $externalErrors = $this->collectMissingExternalCredentialErrors(
                    $driver,
                    $settings,
                    $transport->getValidationRules(),
                    $resolver
                );
                if (!empty($externalErrors)) {
                    $customErrors = array_merge($customErrors, $externalErrors);
                }
            }

            // Edit-mode OAuth UX: do not block saving existing connections when
            // credential identity fields are unchanged/masked in UI.
            $isOAuthDriver = $this->isOAuthDriver($driver);
            $isUpdateFlow  = (int) $request->param('id', 0) > 0;
            $isApiMode     = \in_array((string) ($settings['delivery_mode'] ?? 'smtp'), ['api', 'app_permission'], true);
            if ($isOAuthDriver && $isUpdateFlow && $isApiMode && is_array($customErrors)) {
                foreach (['client_id', 'client_secret', 'tenant_id', 'region', 'app_client_id', 'app_client_secret', 'app_tenant_id'] as $key) {
                    unset($customErrors[$key]);
                }
            }

            if (!empty($customErrors)) {
                return $this->validationError($customErrors);
            }

            return null;
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * Build validation errors for required fields left blank when credentials come from
     * wp-config.php constants or environment variables instead of the database.
     *
     * @since 1.0.0
     *
     * @param string                                    $driver   Transport driver key.
     * @param array<string, mixed>                       $settings Settings being validated.
     * @param array<string, string>                      $rules    Transport validation rules.
     * @param \BooleanSmtp\Services\Settings\ConstantSettingsResolver $resolver Resolves the
     *                                                    expected constant name for a field.
     * @return array<string, string> Validation errors keyed by field name.
     */
    private function collectMissingExternalCredentialErrors(
        string $driver,
        array $settings,
        array $rules,
        \BooleanSmtp\Services\Settings\ConstantSettingsResolver $resolver
    ): array {
        $errors = [];

        foreach ($rules as $key => $rule) {
            if (strpos((string) $rule, 'required') === false) {
                continue;
            }

            $value = trim((string) ($settings[$key] ?? ''));
            if ($value !== '') {
                continue;
            }

            $constName    = $resolver->constantName($driver, (string) $key);
            $errors[$key] = sprintf(
                'Missing required value for %s. Define %s in wp-config.php or environment, or switch Credential Source to Database.',
                str_replace('_', ' ', (string) $key),
                $constName
            );
        }

        return $errors;
    }

    /**
     * Blank or drop sensitive settings that are sourced externally instead of from the database.
     *
     * When `key_store` is not `db`, a sensitive field either resolves from a wp-config.php
     * constant (in which case it is removed from storage entirely) or is left blank in storage
     * (its runtime value comes from the resolver, not the database).
     *
     * @since 1.0.0
     *
     * @param string                $driver   Transport driver key.
     * @param array<string, mixed>  $settings Settings to prepare.
     * @return array<string, mixed> Settings ready to persist.
     */
    private function prepareSettingsForStorage(string $driver, array $settings): array {
        $keyStore = (string) ($settings['key_store'] ?? 'db');
        if ($keyStore === 'db') {
            return $settings;
        }

        $resolver = $this->make(\BooleanSmtp\Services\Settings\ConstantSettingsResolver::class);

        // Same sensitivity check AesEncryptor/getMaskedSettings() use -- was previously its own
        // hand-maintained exact-match list here, the same drift pattern that already caused two
        // real bugs this session (a credential field's exact name missing from one of the other
        // two copies of this list). Only settings actually present are ever touched (unset()/blank
        // has no effect on an absent key either way), so scoping to the field names actually in
        // $settings is equivalent to enumerating every known field name up front.
        foreach (array_keys($settings) as $key) {
            $key = (string) $key;
            if (!$this->encryptor->isSensitiveKey($key)) {
                continue;
            }

            if ($resolver->isDefined($driver, $key)) {
                unset($settings[$key]);
                continue;
            }

            $settings[$key] = '';
        }

        return $settings;
    }

    /**
     * Add the `key_store` (credential source) field to a transport's schema and rules when the
     * transport did not already declare one. OAuth drivers manage credentials through the OAuth
     * flow instead, so they are skipped.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed>  $schema  Transport settings schema, modified in place.
     * @param array<string, string> $rules   Transport validation rules, modified in place.
     * @param string|null           $driver  Driver key, used to skip OAuth drivers.
     */
    private function augmentKeyStoreSchemaAndRules(array &$schema, array &$rules, ?string $driver = null): void {
        if ($driver !== null && $this->isOAuthDriver($driver)) {
            return;
        }

        if (!isset($schema['key_store'])) {
            $schema['key_store'] = [
                'type'     => 'select',
                'label'    => 'Credential Source',
                'required' => false,
                'default'  => 'db',
                'options'  => [
                    'db'        => 'Database (encrypted)',
                    'wp_config' => 'wp-config.php constant',
                    'env'       => 'Environment variable'
                ]
            ];
        }

        if (!isset($rules['key_store'])) {
            $rules['key_store'] = 'nullable|in:db,wp_config,env';
        }
    }

    /**
     * Apply the sender rule to a connection that is being created, given a new sender or
     * switched on.
     *
     * @since 1.0.0
     *
     * @param  string               $driver       Transport driver key.
     * @param  array<string, mixed> $settings     Plain connection settings.
     * @param  string               $name         Connection name.
     * @param  int|null             $connectionId Id of the connection being changed; null for a new one.
     * @param  string               $reason       One of the `SenderCandidate::REASON_*` constants.
     * @return JsonResponse|null A 422 naming the connection that already uses the sender, or null.
     */
    private function checkSender(string $driver, array $settings, string $name, ?int $connectionId, string $reason): ?JsonResponse {
        $guard    = $this->make(SenderGuard::class);
        $conflict = $guard->check($guard->candidate($driver, $settings, $name, $connectionId, $reason));
        if ($conflict === null) {
            return null;
        }

        $field    = $reason === SenderCandidate::REASON_ACTIVATION ? 'is_active' : 'settings.from_email';
        $response = $this->validationError([$field => $conflict->message], 'The given data was invalid.');
        $body     = (array) $response->getData();

        $body['conflict'] = ['id' => $conflict->with->id, 'name' => $conflict->with->name];

        return $response->setData($body);
    }

    /**
     * Apply the sender rule to an update: when the From address changes, or when an inactive
     * connection is switched on. Any other edit of a connection is never refused for its sender.
     *
     * @since 1.0.0
     *
     * @param  Connection           $existing The connection as saved.
     * @param  array<string, mixed> $data     Fields from the request (`name`, `driver`, `settings`, `is_active`).
     * @return JsonResponse|null
     */
    private function checkSenderOnUpdate(Connection $existing, array $data): ?JsonResponse {
        $saved       = $this->decryptedConnectionSettings($existing);
        $settings    = is_array($data['settings'] ?? null) ? $data['settings'] : $saved;
        $driver      = (string) ($data['driver'] ?? $existing->driver ?? '');
        $name        = (string) ($data['name'] ?? $existing->name ?? '');
        $newSender   = SenderGuard::senderOf($settings);
        $activating  = array_key_exists('is_active', $data)
            && filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN)
            && !(bool) $existing->is_active;

        if ($newSender !== SenderGuard::senderOf($saved)) {
            return $this->checkSender($driver, $settings, $name, (int) $existing->id, SenderCandidate::REASON_SENDER_CHANGE);
        }

        if ($activating) {
            return $this->checkSender($driver, $settings, $name, (int) $existing->id, SenderCandidate::REASON_ACTIVATION);
        }

        return null;
    }

    /**
     * Check that a connection's delivery mode is one its transport offers.
     *
     * `api` and `smtp` are the two built-in modes and are always accepted here (the transport's
     * own validation decides whether it supports them); any other mode exists only while the
     * transport offers it, for example a mode an extension adds.
     *
     * @since 1.0.0
     *
     * @param  string               $driver   Transport driver key.
     * @param  array<string, mixed> $settings Connection settings carrying `delivery_mode`.
     * @return JsonResponse|null A 422 when the mode is not offered, null otherwise.
     */
    private function assertDeliveryModeOffered(string $driver, array $settings): ?JsonResponse {
        $mode = (string) ($settings['delivery_mode'] ?? '');
        if ($mode === '' || \in_array($mode, ['api', 'smtp'], true)) {
            return null;
        }

        try {
            $offered = $this->make(MailerManager::class)->resolveTransportByDriver($driver)->getDeliveryModes();
        } catch (\Throwable) {
            // intentionally silent: a transport that cannot list its modes offers none.
            $offered = [];
        }

        if (\array_key_exists($mode, $offered)) {
            return null;
        }

        return $this->validationError(['settings.delivery_mode' => 'This delivery mode is not available.'], 'The given data was invalid.');
    }

    /**
     * Check whether a value looks like the UI's masked placeholder for an already-saved secret
     * (for example `****abcd`), rather than a real credential value.
     *
     * @since 1.0.0
     *
     * @param string $value Value to check.
     * @return bool True when the value matches the masked-placeholder pattern.
     */
    private function isLikelyMaskedSecretPlaceholder(string $value): bool {
        if ($value === '') {
            return false;
        }

        return \preg_match('/^\*{4,}\S{0,8}$/', $value) === 1;
    }

    /**
     * Preflight-check Microsoft client credentials against the token endpoint.
     *
     * Uses a `client_credentials` grant purely to confirm the tenant, client id, and client
     * secret are accepted; it does not itself grant delegated mail-send access. A hard failure
     * (invalid client, unknown tenant) is reported as an error. A permission- or policy-related
     * response is treated as valid with a hint, since delegated OAuth consent is the final
     * authority on whether sending will actually work.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $settings Decrypted connection settings (`tenant_id`,
     *                                        `client_id`, `client_secret`).
     * @return array{ok: bool, error?: string, hint?: string}
     */
    private function verifyOutlookCredentials(array $settings): array {
        $tenant       = trim((string) ($settings['tenant_id'] ?? 'common'));
        $clientId     = trim((string) ($settings['client_id'] ?? ''));
        $clientSecret = trim((string) ($settings['client_secret'] ?? ''));

        if ($tenant === '' || $clientId === '' || $clientSecret === '') {
            return [
                'ok'    => false,
                'error' => 'Microsoft credential verification requires Tenant ID, Client ID, and Client Secret.'
            ];
        }

        /** This filter is documented in app/Services/Mailer/OAuth/OAuthManualTokenExchange.php */
        $tokenUrl = (string) \apply_filters(
            'boolean_smtp_microsoft_token_url',
            "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token",
            $tenant
        );

        // Strong preflight: client_credentials validates tenant + client_id + client_secret
        // without depending on a real authorization code. This is more reliable than
        // the dummy auth-code exchange for detecting wrong secrets.
        $response = \wp_remote_post($tokenUrl, [
            'timeout' => 20,
            'body'    => [
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'grant_type'    => 'client_credentials',
                'scope'         => 'https://graph.microsoft.com/.default'
            ]
        ]);

        if (\is_wp_error($response)) {
            return [
                'ok'    => false,
                'error' => 'Could not reach Microsoft token endpoint: ' . $response->get_error_message()
            ];
        }

        $status = (int) \wp_remote_retrieve_response_code($response);
        $body   = json_decode((string) \wp_remote_retrieve_body($response), true);
        $error  = \is_array($body) ? strtolower((string) ($body['error'] ?? '')) : '';
        $desc   = \is_array($body) ? trim((string) ($body['error_description'] ?? '')) : '';

        if ($status >= 200 && $status < 300 && \is_array($body) && !empty($body['access_token'])) {
            return ['ok' => true];
        }

        // Explicit hard failures for wrong tenant/client/secret.
        $isHardInvalid = $error === 'invalid_client'
        || str_contains($desc, 'AADSTS7000215') // Invalid client secret.
        || str_contains($desc, 'AADSTS700016') // Application not found.
        || str_contains($desc, 'AADSTS7000222') // Invalid/expired client secret.
        || str_contains($desc, 'AADSTS90002'); // Tenant not found.

        if ($isHardInvalid) {
            return [
                'ok'    => false,
                'error' => $desc !== ''
                ? 'Microsoft rejected the credentials: ' . $desc
                : 'Microsoft rejected the credentials (invalid client or tenant).'
            ];
        }

        // Credentials can still be valid even if app-permission consent for /.default
        // is not configured in the tenant. In that case, proceed with a clear hint and
        // let delegated OAuth callback be the final authority.
        $isLikelyPermissionOrPolicyIssue = $error === 'unauthorized_client'
        || $error === 'invalid_scope'
        || str_contains($desc, 'AADSTS65001')
        || str_contains($desc, 'AADSTS650052')
        || str_contains($desc, 'AADSTS70011');

        if ($isLikelyPermissionOrPolicyIssue) {
            return [
                'ok'   => true,
                'hint' => $desc !== ''
                ? 'Microsoft credential probe reached the token endpoint, but app/.default consent is limited: ' . $desc
                : 'Microsoft credential probe reached the token endpoint, but app/.default consent is limited. Proceed with OAuth authorize to validate delegated send flow.'
            ];
        }

        // Other failures are likely app permission/policy issues; keep flow but surface hint.
        if ($error !== '' || $desc !== '') {
            return [
                'ok'   => true,
                'hint' => $desc !== ''
                ? 'Microsoft verification returned: ' . $desc
                : 'Microsoft verification returned: ' . $error
            ];
        }

        return [
            'ok'    => false,
            'error' => 'Microsoft credential verification failed with an unexpected response.'
        ];
    }

    /**
     * Handle `POST /booleansmtp/v1/connections/{id}/oauth-refresh-now`.
     *
     * Forces an OAuth token exchange for an API-mode connection even when the current token has
     * not yet crossed its staleness threshold, persists the refreshed token fields, and records
     * the attempt in the OAuth refresh history log.
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying the connection `id` route parameter.
     * @return JsonResponse New token expiry details on success, or a 400/422 error when the
     *                       connection is not eligible or the refresh fails.
     */
    public function refreshOAuthNow(Request $request): JsonResponse {
        $id         = (int) $request->param('id');
        $connection = $this->connections->findOrFail($id);
        $driver     = (string) ($connection->driver ?? '');
        $oauthDriver = $this->normalizeOAuthDriver($driver);
        $historyWarning = $this->getOAuthRefreshHistoryStorageWarning();

        if (!$this->isOAuthDriver($driver)) {
            return $this->error('Connection does not support OAuth refresh.', 400);
        }

        $decrypted = MailerManager::prepareDecryptedConnectionSettings(
            $this->make(ConstantSettingsResolver::class),
            $oauthDriver,
            $this->decryptedConnectionSettings($connection)
        );

        if ((string) ($decrypted['delivery_mode'] ?? '') !== 'api') {
            return $this->error('OAuth refresh is only available for API delivery mode.', 400);
        }

        // Force exchange even when token has not yet crossed stale threshold.
        $decrypted['force_refresh'] = true;

        $startTime = \microtime(true);
        /** @var ConnectionHealthProbe $probe */
        $probe     = $this->make(ConnectionHealthProbe::class);
        $result    = $probe->probe($connection, $decrypted, false, false);
        $elapsedMs = (int) ((\microtime(true) - $startTime) * 1000);

        if (!($result['healthy'] ?? false)) {
            $rawErrorCode = (string) ($result['api_debug']['error_code'] ?? 'oauth_refresh_manual_failed');
            $rawErrorMsg  = (string) ($result['error'] ?? 'OAuth refresh failed.');
            $classified   = \BooleanSmtp\Services\OAuth\OAuthErrorClassifier::classify($rawErrorCode, $oauthDriver);

            if ($historyWarning === null) {
                $logged = $this->refreshLogs->log(
                    (int) $connection->id,
                    $oauthDriver,
                    'failed',
                    (string) ($classified['code'] ?? $rawErrorCode),
                    $rawErrorMsg,
                    $elapsedMs,
                    1
                );

                if (!$logged) {
                    $historyWarning = 'OAuth refresh log could not be persisted. Check database migration and write permissions.';
                }
            }

            // The frontend's shared API client appends whatever's in `errors` onto the displayed
            // message verbatim (JSON.stringify'd) -- fine for real field-validation errors
            // elsewhere in the app, but wrong here: `errorData` is diagnostic metadata nothing in
            // OAuthRefreshHistoryPanel.jsx actually reads, and api_debug in particular can be a
            // large, nested raw provider error body. Only send it (as `errors`, so it lands in the
            // displayed message) when a developer has opted in via the filter, or there's a real,
            // user-actionable history-storage warning -- otherwise `errors` stays null and the
            // classified message above is all that's shown.
            /**
             * Filters whether OAuth refresh diagnostic details are exposed to the browser.
             *
             * @since 1.0.0
             *
             * @param bool $enabled Whether to include raw diagnostic details (API debug data,
             *                      stored provider error messages) in OAuth refresh responses.
             *                      Return true to enable; default false.
             */
            $debugEnabled = \apply_filters('boolean_smtp_oauth_debug_ui', false);
            $errorData    = null;

            if ($debugEnabled || $historyWarning !== null) {
                $errorData = [
                    'connection_id'    => (int) $connection->id,
                    'driver'           => $oauthDriver,
                    'response_time_ms' => $elapsedMs
                ];

                if ($debugEnabled) {
                    $errorData['api_debug'] = $result['api_debug'] ?? null;
                }

                if ($historyWarning !== null) {
                    $errorData['history_warning'] = $historyWarning;
                }
            }

            return $this->error((string) ($classified['message'] ?? $rawErrorMsg), 422, $errorData);
        }

        $reloaded      = $this->connections->findOrFail((int) $connection->id);
        $freshSettings = $this->decryptedConnectionSettings($reloaded);
        $expiresAt     = isset($freshSettings['token_expires_at']) ? (int) $freshSettings['token_expires_at'] : 0;
        $remaining     = $expiresAt > 0 ? ($expiresAt-\time()) : null;

        if ($historyWarning === null) {
            $logged = $this->refreshLogs->log(
                (int) $reloaded->id,
                $oauthDriver,
                'success',
                'refresh_successful',
                '',
                $elapsedMs,
                1
            );

            if (!$logged) {
                $historyWarning = 'OAuth refresh succeeded but history log could not be persisted.';
            }
        }

        /** This action is documented in app/Jobs/OAuthRefreshJob.php */
        \do_action('boolean_smtp_oauth_refresh_success', [
            'connection_id'           => (int) $reloaded->id,
            'driver'                  => $oauthDriver,
            'attempts'                => 1,
            'response_time_ms'        => $elapsedMs,
            'token_expires_at'        => $expiresAt > 0 ? $expiresAt : null,
            'token_seconds_remaining' => $remaining
        ]);

        $payload = [
            'connection_id'           => (int) $reloaded->id,
            'driver'                  => $oauthDriver,
            'token_expires_at'        => $expiresAt > 0 ? $expiresAt : null,
            'token_seconds_remaining' => $remaining,
            'response_time_ms'        => $elapsedMs
        ];

        if ($historyWarning !== null) {
            $payload['history_warning'] = $historyWarning;
        }

        return $this->ok($payload, 'OAuth token refreshed successfully.');
    }

    /**
     * Handle `GET /booleansmtp/v1/connections/{id}/oauth-refresh-history`.
     *
     * Reads the `limit` query parameter (capped at 100) and returns recent OAuth refresh attempts
     * for a connection along with summary statistics. Raw provider error messages are cleared
     * unless the developer debug filter is enabled.
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying the connection `id` route parameter and the
     *                          `limit` query parameter.
     * @return JsonResponse `attempts`, `statistics`, and `total_count`, or a 400/500 error when
     *                       the connection is not OAuth-based or history storage is unavailable.
     */
    public function getOAuthRefreshHistory(Request $request): JsonResponse {
        $id         = (int) $request->param('id');
        $connection = $this->connections->findOrFail($id);
        $driver     = $this->normalizeOAuthDriver((string) $connection->driver);
        $historyWarning = $this->getOAuthRefreshHistoryStorageWarning();

        // Verify connection is OAuth-based
        if (!$this->isOAuthDriver((string) $connection->driver)) {
            return $this->error('Connection does not support OAuth refresh history.', 400);
        }

        if ($historyWarning !== null) {
            return $this->error($historyWarning, 500);
        }

        try {
            $limit = $this->resolvePagination($request, 20, 'limit')['per_page'];

            $connectionId = (int) $connection->id;
            $attempts     = $this->refreshLogs->getRecentAttempts($connectionId, $limit);
            $statistics   = $this->buildOAuthRefreshStatsFromAttempts($attempts, '7d');

            // The stored error_message is the raw provider error body (can be large, nested JSON)
            // -- kept in the database for support/debugging, but not served to the browser unless
            // explicitly enabled. The classified error_code (already returned) drives the friendly
            // label the UI shows by default.
            /** This filter is documented in {@see ConnectionController::refreshOAuthNow()}. */
            if (!\apply_filters('boolean_smtp_oauth_debug_ui', false)) {
                $attempts = \array_map(static function (array $attempt): array {
                    if (($attempt['status'] ?? '') !== 'success') {
                        $attempt['error_message'] = '';
                    }
                    return $attempt;
                }, $attempts);
            }

            return $this->ok([
                'connection_id' => (int) $connection->id,
                'driver'        => $driver,
                'attempts'      => $attempts,
                'statistics'    => $statistics,
                'total_count'   => \count($attempts)
            ]);
        } catch (\Throwable $e) {
            return $this->error('Failed to retrieve refresh history: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Build lightweight statistics from fetched attempt rows.
     *
     * @since 1.0.0
     *
     * @param array<int, array<string, mixed>> $attempts Attempt rows to summarize.
     * @param string                            $period  Label for the period the attempts cover,
     *                                                    echoed back in the result.
     * @return array<string, mixed> `period`, `total_attempts`, `successful`, `failed`,
     *                               `success_rate`, `avg_response_time_ms`, and `top_errors`.
     */
    private function buildOAuthRefreshStatsFromAttempts(array $attempts, string $period): array {
        $total      = \count($attempts);
        $successful = 0;
        $errorMap   = [];
        $responseTotal = 0;
        $responseCount = 0;

        foreach ($attempts as $attempt) {
            if (($attempt['status'] ?? '') === 'success') {
                ++$successful;
            }

            if (isset($attempt['response_time_ms']) && \is_numeric($attempt['response_time_ms'])) {
                $responseTotal += (int) $attempt['response_time_ms'];
                ++$responseCount;
            }

            $errorCode = (string) ($attempt['error_code'] ?? '');
            if ($errorCode !== '') {
                $errorMap[$errorCode] = ($errorMap[$errorCode] ?? 0) + 1;
            }
        }

        \arsort($errorMap);
        $topErrors = [];
        foreach (\array_slice($errorMap, 0, 5, true) as $code => $count) {
            $topErrors[] = [
                'error_code'  => $code,
                'error_count' => $count
            ];
        }

        return [
            'period'               => $period,
            'total_attempts'       => $total,
            'successful'           => $successful,
            'failed'               => $total - $successful,
            'success_rate'         => $total > 0 ? (int) (($successful / $total) * 100) : 0,
            'avg_response_time_ms' => $responseCount > 0 ? (int) \round($responseTotal / $responseCount) : 0,
            'top_errors'           => $topErrors
        ];
    }

    /**
     * Normalize a driver key to its canonical OAuth driver name.
     *
     * @since 1.0.0
     *
     * @param string $driver Raw driver key.
     * @return string `google` for the legacy `gmail` alias, otherwise the driver unchanged.
     */
    private function normalizeOAuthDriver(string $driver): string {
        return $driver === 'gmail' ? 'google' : $driver;
    }

    /**
     * Check whether a driver uses the delegated OAuth flow.
     *
     * @since 1.0.0
     *
     * @param string $driver Driver key to check.
     * @return bool True for `google`, `outlook`, or `zoho` (including the `gmail` alias).
     */
    private function isOAuthDriver(string $driver): bool {
        return \in_array($this->normalizeOAuthDriver($driver), ['google', 'outlook', 'zoho'], true);
    }

    /**
     * Check whether the OAuth refresh history log's storage backend is available.
     *
     * @since 1.0.0
     *
     * @return string|null A warning message when history storage is unavailable, otherwise null.
     */
    private function getOAuthRefreshHistoryStorageWarning(): ?string {
        try {
            if (!Application::hasInstance()) {
                return 'OAuth refresh history storage is unavailable: application not initialised.';
            }
            return null;
        } catch (\Throwable $e) {
            return 'OAuth refresh history storage check failed: ' . $e->getMessage();
        }
    }

    /**
     * A 422 response carrying transport-level field errors in the same shape as a failed
     * FormRequest: `errors.<field>` is always a list of messages.
     *
     * @since 1.0.0
     *
     * @param  array<string, string|list<string>> $errors  Messages keyed by field name.
     * @param  string                             $message Response message.
     * @return JsonResponse
     */
    protected function validationError(array $errors, string $message = 'Validation failed.'): JsonResponse {
        return parent::validationError(
            \array_map(static fn (string|array $messages): array => (array) $messages, $errors),
            $message
        );
    }
}
