<?php

/**
 * Developer debug logging for BooleanSMTP: outgoing HTTP, database queries, incoming requests,
 * and mailer diagnostics.
 *
 * All logging in this file is opt-in and intended for developers troubleshooting a site, not for
 * normal operation. See the class docblock below for the full list of constants that control it
 * and the security implications of enabling them.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Support\Debug;

use BooleanSmtp\Core\Foundation\Application;
use BooleanSmtp\Core\Log\LogManager;
use BooleanSmtp\Support\Logging\LogChannels;
use function BooleanSmtp\Core\app;

/**
 * Centralized developer debug logging: outgoing HTTP (`http_api_debug`), database queries
 * (`SAVEQUERIES`), an incoming request/response summary, and mailer diagnostics.
 *
 * This is a diagnostic tool for developers, disabled by default. Every channel below is off
 * unless its constant is defined and truthy or the `boolean_smtp_log_file_enabled` filter switches its
 * log channel on. Output goes to the framework's log channels — `requests`, `queries` and `mail`,
 * files under `wp-content/uploads/booleanpress/boolean-smtp/logs/` that are rotated, pruned and
 * capped (see {@see LogChannels}); the API debug channel instead attaches the captured data to a
 * REST response for a developer console or XHR inspector to read.
 *
 * Security: when enabled, these channels can write request parameters, POST bodies, outgoing
 * HTTP request/response bodies and headers, and logged SQL statements to disk or to an API response.
 * That data may include credentials, tokens, and other values a site operator considers
 * sensitive. Secret-shaped values are masked by default; `BOOLEAN_SMTP_DEBUG_SECRET_VISIBILITY`
 * (`show`, `mask`, or `full`; see {@see secretVisibility()}) or the legacy
 * `BOOLEAN_SMTP_DEBUG_REDACT` (`full`) change that. Only set `show` on a site without real
 * credentials. The API debug channel additionally
 * requires the `manage_options` capability regardless of visibility settings.
 *
 * Constants read by this class, all opt-in and false/unset by default:
 * - `BOOLEAN_SMTP_DEBUG_HTTP` — enables the outgoing-HTTP channel (log channel `requests`).
 * - `BOOLEAN_SMTP_DEBUG_QUERIES` — enables the database-query channel (log channel `queries`;
 *   requires WordPress core's `SAVEQUERIES` to also be enabled).
 * - `BOOLEAN_SMTP_DEBUG_REQUEST` — enables the incoming-request/REST channel (log channel `requests`).
 * - `BOOLEAN_SMTP_DEBUG_MAILER` — enables the mailer-diagnostics channel (log channel `mail`).
 * - `BOOLEAN_SMTP_DEBUG_ATTACH_API_DEBUG` — legacy name for enabling the API debug response
 *   channel; see {@see isApiDebugResponseEnabled()}.
 * - `BOOLEAN_SMTP_DEBUG_REDACT` — when truthy, sets the default secret visibility to `full`
 *   (values are replaced with `[REDACTED]`) instead of the default `mask`.
 * - `BOOLEAN_SMTP_DEBUG_SECRET_VISIBILITY` — explicit secret visibility (`show`, `mask`, or
 *   `full`); see {@see secretVisibility()}.
 * - `BOOLEAN_SMTP_DEBUG_REQUEST_SECRET_VISIBILITY` — secret visibility override for the REST/POST
 *   request payload block only; see {@see requestPayloadSecretVisibility()}.
 * - `BOOLEAN_SMTP_DEBUG_LOG_DIR` — a parent folder for the log directory: every channel and the SMTP
 *   debug sessions go into its `boolean-smtp-logs/` subfolder; defaults to
 *   `wp-content/uploads/booleanpress/boolean-smtp/logs`.
 *
 * @since 1.0.0
 */
final class WordPressDebugLogger {
    /**
     * Maximum number of bytes of a single log value (body, response JSON, dump) written to a log
     * file before it is truncated.
     *
     * @since 1.0.0
     */
    private const LOG_TRUNCATE_BYTES = 50000;

    /**
     * The bootstrapped logger instance, or null before {@see bootstrap()} runs.
     *
     * @since 1.0.0
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Whether WordPress hook callbacks have already been registered for this instance.
     *
     * @since 1.0.0
     * @var bool
     */
    private bool $registered = false;

    /**
     * Summary of the current incoming request, captured once per request.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    private array $incomingRequest = [];

    /**
     * Outgoing HTTP request/response entries buffered for the API debug response channel.
     *
     * @since 1.0.0
     * @var list<array<string, mixed>>
     */
    private array $outgoingHttp = [];

    /**
     * Mailer diagnostic payloads buffered for the API debug response channel.
     *
     * @since 1.0.0
     * @var list<array<string, mixed>>
     */
    private array $mailerDebug = [];

    /**
     * First meaningful file:line for each SQL execution (parallel to {@see \wpdb::$queries} indices).
     *
     * @since 1.0.0
     * @var list<string>
     */
    private array $queryCallSites = [];


    /**
     * Whether a REST request has been seen dispatching during the current request.
     *
     * @since 1.0.0
     * @var bool
     */
    private bool $restDispatchSeen = false;

    /**
     * Formatted REST request payload block for the current request, if any.
     *
     * @since 1.0.0
     * @var string|null
     */
    private $restRequestPayloadLog;

    /**
     * Formatted REST response block for the current request, if any.
     *
     * @since 1.0.0
     * @var string|null
     */
    private $restResponseLog;

    /**
     * Normalized absolute path to the plugin directory, used to scope debug output to
     * BooleanSMTP's own code when {@see scope()} is `boolean_smtp_only`.
     *
     * @since 1.0.0
     * @var string
     */
    private string $pluginPath;

    /**
     * @since 1.0.0
     *
     * @param string $pluginPath Absolute path to the plugin directory.
     */
    private function __construct(string $pluginPath) {
        $this->pluginPath = \wp_normalize_path($pluginPath);
    }

    /**
     * Get the bootstrapped logger instance.
     *
     * @since 1.0.0
     *
     * @return self|null Null before {@see bootstrap()} has run.
     */
    public static function getInstance(): ?self {
        return self::$instance;
    }

    /**
     * Create the logger instance and register its WordPress hooks if any channel is enabled.
     *
     * Safe to call more than once; only the first call has an effect.
     *
     * @since 1.0.0
     *
     * @param string $pluginPath Absolute path to the plugin directory.
     */
    public static function bootstrap(string $pluginPath): void {
        if (self::$instance !== null) {
            return;
        }
        self::$instance = new self($pluginPath);
        self::$instance->maybeRegister();
    }

    /**
     * Register WordPress hooks once, if at least one debug channel is enabled.
     *
     * @since 1.0.0
     */
    private function maybeRegister(): void {
        if ($this->registered) {
            return;
        }
        if (!$this->shouldRegister()) {
            return;
        }
        $this->register();
    }

    /**
     * Determine whether any debug channel is enabled.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    private function shouldRegister(): bool {
        if (
            $this->channelHttp()
            || $this->channelQueries()
            || $this->channelRequest()
            || $this->channelMailer()
            || $this->attachApiDebug()
        ) {
            return true;
        }

        return false;
    }

    /**
     * Register the WordPress hook callbacks for each enabled debug channel.
     *
     * @since 1.0.0
     */
    private function register(): void {
        if ($this->registered) {
            return;
        }
        $this->registered = true;

        if ($this->channelRequest() || $this->attachApiDebug()) {
            add_action('init', [$this, 'captureIncomingRequest'], 0);
            add_filter('rest_pre_dispatch', [$this, 'onRestPreDispatch'], 10, 3);
            add_filter('rest_post_dispatch', [$this, 'onRestPostDispatch'], 10, 3);
        }

        if ($this->channelHttp() || $this->attachApiDebug()) {
            add_action('http_api_debug', [$this, 'onHttpApiDebug'], 10, 5);
        }

        if ($this->channelQueries() || $this->attachApiDebug()) {
            add_action('shutdown', [$this, 'onShutdownQueriesAndRequest'], 999);
        } elseif ($this->channelRequest()) {
            add_action('shutdown', [$this, 'onShutdownRequestFileOnly'], 999);
        }

        if ($this->channelMailer() || $this->attachApiDebug()) {
            add_action('boolean_smtp_api_debug', [$this, 'onMailerApiDebug'], 10, 1);
        }

        if ($this->channelQueries() || $this->attachApiDebug()) {
            add_filter('query', [$this, 'onWpdbQueryFilter'], 999, 1);
        }
    }

    /**
     * Record the call site for each SQL execution as it happens.
     *
     * Runs inside {@see \wpdb::query()} before each SQL execution via the `query` filter; records
     * the first caller outside wpdb and this class. Only records when {@see \wpdb::$save_queries}
     * is true, so indices stay aligned with {@see \wpdb::$queries}.
     *
     * @since 1.0.0
     *
     * @param  string $query The SQL about to run.
     * @return string The SQL unchanged; this is a query filter used only for its side effect.
     */
    public function onWpdbQueryFilter(string $query): string {
        global $wpdb;
        if (!isset($wpdb->save_queries) || !$wpdb->save_queries) {
            return $query;
        }
        $this->queryCallSites[] = $this->resolveQueryCallSiteFromBacktrace();

        return $query;
    }

    /**
     * Find the first stack frame with a file path outside `wp-db.php`, `class-wpdb.php`, and this
     * logger, to identify which plugin/theme code issued a query.
     *
     * @since 1.0.0
     *
     * @return string `file:line`, or an empty string when no matching frame is found.
     */
    private function resolveQueryCallSiteFromBacktrace(): string {
        $bt = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 50); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- developer query channel (off unless a constant or filter turns it on): finds the file and line that ran each query.
        foreach ($bt as $frame) {
            $file = isset($frame['file']) ? (string) $frame['file'] : '';
            if ($file === '') {
                continue;
            }
            $norm = \wp_normalize_path($file);
            if (\str_contains($norm, 'wp-db.php') || \str_contains($norm, 'class-wpdb.php')) {
                continue;
            }
            if (\str_contains($norm, 'WordPressDebugLogger.php')) {
                continue;
            }
            /**
             * Filters whether a backtrace frame may be used as a query's recorded call site.
             *
             * @since 1.0.0
             *
             * @param bool                 $include Whether to accept this frame. Default true.
             * @param array<string, mixed> $frame   The raw backtrace frame.
             * @param string               $norm    The frame's normalized file path.
             * @return bool The filtered value.
             */
            if (!\apply_filters('boolean_smtp_debug_query_call_site_include_frame', true, $frame, $norm)) {
                continue;
            }
            $line = (int) ($frame['line'] ?? 0);

            return $file . ':' . $line;
        }

        return '';
    }

    /**
     * Capture a summary of the current incoming request, once per request.
     *
     * @since 1.0.0
     */
    public function captureIncomingRequest(): void {
        if ($this->incomingRequest !== []) {
            return;
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? \sanitize_text_field(\wp_unslash((string) $_SERVER['REQUEST_URI'])) : '';
        $this->incomingRequest = [
            'method'       => isset($_SERVER['REQUEST_METHOD']) ? \sanitize_text_field(\wp_unslash((string) $_SERVER['REQUEST_METHOD'])) : '',
            'uri'          => $uri,
            'query_string' => isset($_SERVER['QUERY_STRING']) ? \sanitize_text_field(\wp_unslash((string) $_SERVER['QUERY_STRING'])) : '',
            'is_admin'     => \is_admin(),
            'doing_ajax'   => \defined('DOING_AJAX') && \constant('DOING_AJAX'),
            'user_id'      => \function_exists('get_current_user_id') ? \get_current_user_id() : 0,
        ];
    }

    /**
     * Capture the REST request payload before dispatch, for the `rest_pre_dispatch` filter.
     *
     * @since 1.0.0
     *
     * @param mixed $result
     * @param \WP_REST_Server $server
     * @param \WP_REST_Request $request
     *
     * @return mixed The `$result` argument, unchanged.
     */
    public function onRestPreDispatch($result, $server, $request) {
        if ($request instanceof \WP_REST_Request) {
            $this->restDispatchSeen = true;
            $this->restRequestPayloadLog = $this->formatRestRequestPayload($request);
        }

        return $result;
    }

    /**
     * Capture the REST response after dispatch, for the `rest_post_dispatch` filter.
     *
     * @since 1.0.0
     *
     * @param mixed $result
     * @param \WP_REST_Server $server
     * @param \WP_REST_Request $request
     *
     * @return mixed The `$result` argument, unchanged.
     */
    public function onRestPostDispatch($result, $server, $request) {
        if ($result instanceof \WP_REST_Response) {
            $this->restResponseLog = $this->formatRestResponse($result);
        } elseif (\is_wp_error($result)) {
            /** @var \WP_Error $result */
            $this->restResponseLog = $this->dumpForLog([
                'wp_error' => true,
                'code'     => $result->get_error_code(),
                'message'  => $result->get_error_message(),
                'data'     => $result->get_error_data(),
            ]);
        }

        return $result;
    }

    /**
     * Format a REST request's route, params, and body into a loggable block.
     *
     * @since 1.0.0
     *
     * @param  \WP_REST_Request $request
     * @return string
     */
    private function formatRestRequestPayload(\WP_REST_Request $request): string {
        $route = $request->get_route();
        $method = $request->get_method();
        $reqVis = $this->requestPayloadSecretVisibility();
        $params = $this->maybeRedactSensitiveArray($request->get_params(), $reqVis);
        $jsonBody = $request->get_json_params();
        if (!\is_array($jsonBody)) {
            $jsonBody = [];
        }
        $jsonBody = $this->maybeRedactSensitiveArray($jsonBody, $reqVis);
        $rawBody = (string) $request->get_body();
        if ($rawBody !== '' && $reqVis !== 'show') {
            $rawBody = $this->redactRawBodyString($rawBody, $reqVis);
        }

        $block = [
            'route'          => $route,
            'method'         => $method,
            'merged_params'  => $params,
            'json_params'    => $jsonBody,
        ];
        if ($rawBody !== '' && $jsonBody === []) {
            $block['raw_body'] = \strlen($rawBody) > 8000
                ? \substr($rawBody, 0, 8000) . "\n... [truncated]"
                : $rawBody;
        }

        return $this->dumpForLog($block);
    }

    /**
     * Resolve how sensitive values appear in debug logs and API debug responses.
     *
     * Reads, in order: the `BOOLEAN_SMTP_DEBUG_SECRET_VISIBILITY` constant (`show`, `mask`, or
     * `full`); then the `BOOLEAN_SMTP_DEBUG_REDACT` constant, which maps truthy to `full`;
     * otherwise defaults to `show`. `show` logs values as-is, `mask` keeps a short visible suffix
     * (see {@see maskString()}), and `full` replaces the value with `[REDACTED]`.
     *
     * @since 1.0.0
     *
     * @return 'show'|'mask'|'full'
     */
    private function secretVisibility(): string {
        $explicit = '';
        if (\defined('BOOLEAN_SMTP_DEBUG_SECRET_VISIBILITY')) {
            $explicit = \strtolower(\trim((string) \constant('BOOLEAN_SMTP_DEBUG_SECRET_VISIBILITY')));
        }
        if ($explicit !== '' && \in_array($explicit, ['show', 'mask', 'full'], true)) {
            /**
             * Filters how sensitive values appear in debug logs and API debug responses.
             *
             * @since 1.0.0
             *
             * @param string $visibility One of `show`, `mask`, or `full`.
             * @return string The filtered visibility.
             */
            return (string) \apply_filters('boolean_smtp_debug_secret_visibility', $explicit);
        }
        if (\defined('BOOLEAN_SMTP_DEBUG_REDACT') && \constant('BOOLEAN_SMTP_DEBUG_REDACT')) {
            /** This filter is documented above. */
            return (string) \apply_filters('boolean_smtp_debug_secret_visibility', 'full');
        }

        /** This filter is documented above. */
        return (string) \apply_filters('boolean_smtp_debug_secret_visibility', 'mask');
    }

    /**
     * Resolve the secret visibility for REST/POST request payload blocks in the `requests` log channel (not
     * HTTP OUT requests/responses, which always use {@see secretVisibility()}).
     *
     * Follows the global visibility unless `BOOLEAN_SMTP_DEBUG_REQUEST_SECRET_VISIBILITY` names
     * another mode; `show` there logs the connection settings submitted from the admin UI in
     * full while outgoing HTTP keeps the global mode.
     *
     * @since 1.0.0
     *
     * @return 'show'|'mask'|'full'
     */
    private function requestPayloadSecretVisibility(): string {
        $mode = $this->secretVisibility();
        if (\defined('BOOLEAN_SMTP_DEBUG_REQUEST_SECRET_VISIBILITY')) {
            $raw = \strtolower(\trim((string) \constant('BOOLEAN_SMTP_DEBUG_REQUEST_SECRET_VISIBILITY')));
            if ($raw !== '' && \in_array($raw, ['show', 'mask', 'full'], true)) {
                /**
                 * Filters the secret visibility used for REST/POST request payload blocks in
                 * the `requests` log channel.
                 *
                 * @since 1.0.0
                 *
                 * @param string $visibility One of `show`, `mask`, or `full`.
                 * @return string The filtered visibility.
                 */
                return (string) \apply_filters('boolean_smtp_debug_request_secret_visibility', $raw);
            }
        }
        /** This filter is documented above. */
        return (string) \apply_filters('boolean_smtp_debug_request_secret_visibility', $mode);
    }

    /**
     * Recursively redact sensitive values in an array for logging.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $data
     * @param 'show'|'mask'|'full'|null $mode null = {@see secretVisibility()}
     *
     * @return array<string, mixed>
     */
    private function maybeRedactSensitiveArray(array $data, ?string $mode = null): array {
        $mode ??= $this->secretVisibility();
        if ($mode === 'show') {
            return $data;
        }
        $out = [];
        foreach ($data as $k => $v) {
            if (\is_array($v)) {
                $out[$k] = $this->maybeRedactSensitiveArray($v, $mode);

                continue;
            }
            if (!$this->isSensitiveKey((string) $k)) {
                $out[$k] = $v;

                continue;
            }
            if (!\is_string($v) && !\is_scalar($v)) {
                $out[$k] = $mode === 'full' ? '[REDACTED]' : $v;

                continue;
            }
            $str = (string) $v;
            if ($mode === 'full') {
                $out[$k] = '[REDACTED]';
            } else {
                $out[$k] = $this->maskString($str);
            }
        }

        return $out;
    }

    /**
     * Determine whether a data key looks like it holds a secret, by substring match against a
     * fixed list of fragments.
     *
     * @since 1.0.0
     *
     * @param  string $key Key to check, as given by the caller.
     * @return bool
     */
    private function isSensitiveKey(string $key): bool {
        $lk = \strtolower($key);
        $needles = [
            'password', 'pass', 'password_confirmation', 'client_secret', 'secret',
            'authorization', 'access_token', 'refresh_token', 'api_key', 'apikey',
            'api_secret', 'api_access', 'private_key',
        ];
        foreach ($needles as $needle) {
            if (\str_contains($lk, $needle)) {
                return true;
            }
        }

        /**
         * Filters whether a data key is treated as sensitive for debug-log redaction.
         *
         * @since 1.0.0
         *
         * @param bool   $sensitive Whether the built-in fragment match considered this key
         *                          sensitive. Default false when no fragment matched.
         * @param string $key       The key as given by the caller.
         * @param string $lowercase The lowercased key.
         * @return bool Whether the key is sensitive.
         */
        return (bool) \apply_filters('boolean_smtp_debug_sensitive_key', false, $key, $lk);
    }

    /**
     * Mask a string, keeping a short visible suffix for identification.
     *
     * @since 1.0.0
     *
     * @param  string $value Value to mask.
     * @return string The masked value, all asterisks except for the visible suffix.
     */
    private function maskString(string $value): string {
        $len = \strlen($value);
        /**
         * Filters the number of trailing characters left visible when a value is masked.
         *
         * @since 1.0.0
         *
         * @param int $length Number of visible trailing characters. Default 4.
         * @return int The filtered length.
         */
        $suffix = (int) \apply_filters('boolean_smtp_debug_mask_visible_suffix_length', 4);
        if ($suffix < 1) {
            $suffix = 4;
        }
        if ($len <= $suffix) {
            return \str_repeat('*', $len);
        }

        return \str_repeat('*', $len - $suffix) . \substr($value, -$suffix);
    }

    /**
     * Redact a raw request body string if it looks like it contains a secret.
     *
     * @since 1.0.0
     *
     * @param  string $raw
     * @param 'show'|'mask'|'full'|null $mode null = {@see secretVisibility()}
     * @return string
     */
    private function redactRawBodyString(string $raw, ?string $mode = null): string {
        $mode ??= $this->secretVisibility();
        if ($mode === 'show') {
            return $raw;
        }
        $looksSensitive = \stripos($raw, 'password') !== false
            || \stripos($raw, 'secret') !== false
            || \stripos($raw, 'access_token') !== false
            || \stripos($raw, 'refresh_token') !== false
            || \stripos($raw, 'api_key') !== false;
        if (!$looksSensitive) {
            return $raw;
        }
        if ($mode === 'full') {
            return '[REDACTED BODY]';
        }

        return $this->maskString($raw);
    }

    /**
     * Format a REST response's status, headers, and body into a loggable block.
     *
     * @since 1.0.0
     *
     * @param  \WP_REST_Response $response
     * @return string
     */
    private function formatRestResponse(\WP_REST_Response $response): string {
        $status = $response->get_status();
        $data   = $response->get_data();
        if ($this->secretVisibility() !== 'show' && \is_array($data)) {
            $data = $this->maybeRedactSensitiveArray($data);
        }
        $headers = $response->get_headers();
        if (!\is_array($headers)) {
            $headers = [];
        }
        if ($this->secretVisibility() !== 'show') {
            $headers = $this->redactHeaders($headers);
        }

        $jsonFlags = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT;
        $encoded = \function_exists('wp_json_encode')
            ? \wp_json_encode($data, $jsonFlags)
            : \json_encode($data, $jsonFlags);
        $responseJson = $encoded === false ? '(json encode failed)' : (string) $encoded;
        if ($responseJson !== '' && \strlen($responseJson) > self::LOG_TRUNCATE_BYTES) {
            $responseJson = \substr($responseJson, 0, self::LOG_TRUNCATE_BYTES) . "\n... [truncated]";
        }

        $block = [
            'note'           => 'WP_REST_Response headers are often empty here (applied later when the response is sent). Use response_json for the payload.',
            'status'         => $status,
            'headers'      => $headers,
            'data'         => $data,
            'response_json' => $responseJson,
        ];

        return $this->dumpForLog($block);
    }

    /**
     * Capture an outgoing HTTP request/response for the `http_api_debug` action.
     *
     * @since 1.0.0
     *
     * @param mixed $response
     * @param string $context
     * @param string $class
     * @param array<string, mixed> $parsedArgs
     * @param string $url
     */
    public function onHttpApiDebug($response, string $context, string $class, array $parsedArgs, string $url): void {
        if (!$this->passesScopeHttp()) {
            return;
        }
        if ($this->httpMailerOnly() && !$this->urlLooksLikeMailRelated($url)) {
            return;
        }
        /**
         * Filters whether an outgoing HTTP request/response is recorded to the debug log.
         *
         * @since 1.0.0
         *
         * @param bool                 $shouldLog Whether to log this exchange. Default true.
         * @param string               $url       The request URL.
         * @param array<string, mixed> $parsedArgs The request arguments passed to wp_remote_request().
         * @param mixed                $response  The response array or a WP_Error.
         * @return bool Whether to log the exchange.
         */
        if (!apply_filters('boolean_smtp_debug_should_log_http', true, $url, $parsedArgs, $response)) {
            return;
        }

        $entry = [
            'channel'  => 'http',
            'url'      => $url,
            'context'  => $context,
            'class'    => $class,
            'args'     => $this->maybeRedactHttpArgs($parsedArgs),
            'response' => $this->maybeRedactHttpResponse($response),
        ];

        if ($this->channelHttp()) {
            $this->appendHttpBlockToRequestsLog($entry, $url, $parsedArgs, $response);
        }
        if ($this->attachApiDebug()) {
            $this->outgoingHttp[] = $entry;
        }
    }

    /**
     * Capture a mailer diagnostic payload for the `boolean_smtp_api_debug` action.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $payload
     */
    public function onMailerApiDebug(array $payload): void {
        if (!$this->passesScopeMailer()) {
            return;
        }
        if ($this->channelMailer()) {
            $this->appendMailerBlock($payload);
        }
        if ($this->attachApiDebug()) {
            $this->mailerDebug[] = $payload;
        }
    }

    /**
     * Write the queries and/or request log files at the end of the request, for the `shutdown`
     * action, when both the query and request channels may be active.
     *
     * @since 1.0.0
     */
    public function onShutdownQueriesAndRequest(): void {
        if ($this->channelQueries()) {
            $this->writeQueriesFile();
        }
        if ($this->channelRequest()) {
            $this->writeRequestFile();
        }
    }

    /**
     * Write the request log file at the end of the request, for the `shutdown` action, when only
     * the request channel is active.
     *
     * @since 1.0.0
     */
    public function onShutdownRequestFileOnly(): void {
        $this->writeRequestFile();
    }

    /**
     * Write the accumulated SQL query log to the `queries` log channel.
     *
     * Requires WordPress core's `SAVEQUERIES` constant to be enabled; without it, wpdb records no
     * query text or timing and this writes a note explaining why instead.
     *
     * @since 1.0.0
     */
    private function writeQueriesFile(): void {
        if (!\defined('SAVEQUERIES') || !\constant('SAVEQUERIES')) {
            $this->appendQueriesLog(
                "====================================================================\n"
                . "BooleanSMTP queries log\n"
                . "Date: " . $this->formatLocalDateTime() . "\n"
                . "Note: SAVEQUERIES is not enabled in wp-config.php — no SQL was recorded.\n"
                . "====================================================================\n\n"
            );

            return;
        }
        global $wpdb;
        if (!isset($wpdb->queries) || !\is_array($wpdb->queries) || $wpdb->queries === []) {
            $this->appendQueriesLog(
                "====================================================================\n"
                . "BooleanSMTP queries log\n"
                . "Date: " . $this->formatLocalDateTime() . "\n"
                . "URL: " . (\function_exists('home_url') ? \home_url(\add_query_arg([])) : '') . "\n"
                . "Total Queries: 0 (empty list)\n"
                . "====================================================================\n\n"
            );

            return;
        }

        $log = "====================================================================\n";
        $log .= 'Date: ' . $this->formatLocalDateTime() . "\n";
        $log .= 'URL: ' . (\function_exists('home_url') ? \home_url(\add_query_arg([])) : '') . "\n";

        $included = 0;
        $totalTime = 0.0;
        $body      = '';

        foreach ($wpdb->queries as $k => $query) {
            if (!\is_array($query) || count($query) < 2) {
                continue;
            }
            $sql   = (string) ($query[0] ?? '');
            $time  = (float) ($query[1] ?? 0);
            $stack = isset($query[2]) ? (string) $query[2] : '';
            if (!$this->passesScopeQuery($stack)) {
                continue;
            }
            $included++;
            $totalTime += $time;
            $body .= 'Query #' . ($k + 1) . ' | Time: ' . \number_format($time * 1000, 4) . " ms\n";
            $body .= "----------------------------------------\n";
            $body .= \trim($sql) . "\n\n";
            $body .= 'Called from: ' . $stack . "\n";
            $origin = $this->queryCallSites[$k] ?? '';
            if ($origin !== '') {
                $body .= 'Origin (file:line): ' . $origin . "\n";
            }
            $body .= "----------------------------------------\n\n";
        }

        $log .= 'Total Queries (after scope filter): ' . $included . "\n";
        $log .= "====================================================================\n\n";
        $log .= $body;
        $log .= "====================================================================\n";
        $log .= 'Total Query Time: ' . \number_format($totalTime * 1000, 2) . " ms\n";
        $log .= "====================================================================\n\n\n";

        $this->appendQueriesLog($log);
    }

    /**
     * Write the accumulated request/response log to the `requests` log channel.
     *
     * @since 1.0.0
     */
    private function writeRequestFile(): void {
        $req = $this->incomingRequest;
        if ($req === []) {
            $this->captureIncomingRequest();
            $req = $this->incomingRequest;
        }

        $log = "====================================================================\n";
        $log .= "INCOMING REQUEST\n";
        $log .= 'Date: ' . $this->formatLocalDateTime() . "\n";
        $log .= 'URL: ' . (\function_exists('home_url') ? \home_url(\add_query_arg([])) : '') . "\n";
        $log .= "====================================================================\n";
        $log .= 'Method: ' . ($req['method'] ?? '') . "\n";
        $log .= 'URI: ' . ($req['uri'] ?? '') . "\n";
        $log .= 'Query string: ' . ($req['query_string'] ?? '') . "\n";
        $log .= 'is_admin: ' . (($req['is_admin'] ?? false) ? 'yes' : 'no') . "\n";
        $log .= 'doing_ajax: ' . (($req['doing_ajax'] ?? false) ? 'yes' : 'no') . "\n";
        $log .= 'user_id: ' . (string) ($req['user_id'] ?? '0') . "\n";
        $log .= "====================================================================\n";

        if ($this->restRequestPayloadLog !== null && $this->restRequestPayloadLog !== '') {
            $log .= "--------------------------------------------------------------------\n";
            $log .= "REQUEST PAYLOAD (REST)\n";
            $log .= "--------------------------------------------------------------------\n";
            $log .= $this->restRequestPayloadLog . "\n\n";
        } elseif (!$this->restDispatchSeen) {
            $postDump = $this->formatNonRestPostPayload();
            if ($postDump !== '') {
                $log .= "--------------------------------------------------------------------\n";
                $log .= "REQUEST PAYLOAD (non-REST POST)\n";
                $log .= "--------------------------------------------------------------------\n";
                $log .= $postDump . "\n\n";
            }
        }

        if ($this->restResponseLog !== null && $this->restResponseLog !== '') {
            $log .= "--------------------------------------------------------------------\n";
            $log .= "RESPONSE (REST)\n";
            $log .= "--------------------------------------------------------------------\n";
            $log .= $this->restResponseLog . "\n\n";
        }

        $log .= "\n";

        $this->appendRequestsLog($log);
    }

    /**
     * Format a non-REST POST body (`$_POST`) into a loggable block.
     *
     * @since 1.0.0
     *
     * @return string Empty when the request is not a POST or has no body.
     */
    private function formatNonRestPostPayload(): string {
        $method = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper(\sanitize_text_field(\wp_unslash((string) $_SERVER['REQUEST_METHOD']))) : '';
        if ($method !== 'POST' || empty($_POST) || !\is_array($_POST)) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only: the developer request log records the POST body; nothing changes state.
            return '';
        }

        return $this->dumpForLog($this->maybeRedactSensitiveArray($_POST, $this->requestPayloadSecretVisibility())); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only logging, secrets masked by maybeRedactSensitiveArray().
    }

    /**
     * Get the current date and time in the site's local timezone, for log timestamps.
     *
     * @since 1.0.0
     *
     * @return string `Y-m-d H:i:s`.
     */
    private function formatLocalDateTime(): string {
        if (\function_exists('current_time')) {
            return (string) \current_time('mysql');
        }

        return \gmdate('Y-m-d H:i:s');
    }

    /**
     * Append a formatted "HTTP OUT" block to the `requests` log channel.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $entry
     * @param string               $requestUrl
     * @param array<string, mixed> $rawArgs Unredacted {@see wp_remote_request()} args (for exchange formatting).
     * @param mixed                $rawResponse Unredacted response array or {@see \WP_Error}.
     */
    private function appendHttpBlockToRequestsLog(array $entry, string $requestUrl, array $rawArgs, $rawResponse): void {
        $ts = \gmdate('Y-m-d H:i:s') . ' UTC';
        $log = "--------------------------------------------------------------------\n";
        $log .= "HTTP OUT\n";
        $log .= 'Time: ' . $ts . "\n";
        $log .= 'URL: ' . (string) ($entry['url'] ?? '') . "\n";
        $log .= 'Context: ' . (string) ($entry['context'] ?? '') . "\n";
        $log .= 'Class: ' . (string) ($entry['class'] ?? '') . "\n";
        $log .= "--------------------------------------------------------------------\n";

        /**
         * Filters how outgoing HTTP exchanges are formatted in the `requests` log channel.
         *
         * @since 1.0.0
         *
         * @param string $style One of `exchange` (a fluent request/response transcript, the
         *                      default), `array` (a raw `print_r()` dump), or `both`.
         * @return string The filtered style.
         */
        $style = (string) \apply_filters('boolean_smtp_debug_http_log_style', 'exchange');
        if ($style === 'exchange' || $style === 'both') {
            $log .= $this->formatHttpExchangeForLog($requestUrl, $rawArgs, $rawResponse) . "\n";
        }
        if ($style === 'array' || $style === 'both') {
            $log .= "Args (redacted view):\n" . $this->dumpForLog($entry['args'] ?? []) . "\n";
            $log .= "Response (redacted view):\n" . $this->dumpForLog($entry['response'] ?? null) . "\n";
        }

        $log .= "====================================================================\n\n\n";

        $this->appendRequestsLog($log);
    }

    /**
     * Build a fluent-style request/response transcript (status line, headers, body preview) for
     * the `requests` log channel.
     *
     * @since 1.0.0
     *
     * @param  string $url
     * @param array<string, mixed> $args
     * @param mixed                $response
     * @return string
     */
    private function formatHttpExchangeForLog(string $url, array $args, $response): string {
        $method = \strtoupper((string) ($args['method'] ?? 'POST'));
        $reqHeaders = isset($args['headers']) && \is_array($args['headers']) ? $args['headers'] : [];
        $reqHeaders = $this->headersToArray($reqHeaders);
        if ($this->secretVisibility() !== 'show') {
            $reqHeaders = $this->redactHeaders($reqHeaders);
        }

        $reqBody = $args['body'] ?? '';
        if (\is_array($reqBody)) {
            $enc = \function_exists('wp_json_encode') ? \wp_json_encode($reqBody) : \json_encode($reqBody);
            $reqBody = $enc === false ? '' : (string) $enc;
        } else {
            $reqBody = (string) $reqBody;
        }
        if ($this->secretVisibility() !== 'show') {
            $reqBody = (string) $this->redactBody($reqBody);
        }

        $lines   = [];
        $lines[] = 'API REQUEST: ' . $method . ' ' . $url;
        foreach ($reqHeaders as $hk => $hv) {
            $lines[] = '  ' . (string) $hk . ': ' . $this->httpHeaderValueToString($hv);
        }
        $lines[] = '  [body length: ' . \strlen($reqBody) . ' bytes]';
        $lines[] = $this->truncateForLogFile($reqBody);

        if (\is_wp_error($response)) {
            $lines[] = 'API RESPONSE: (transport error)';
            $lines[] = '  ' . $response->get_error_code() . ': ' . $response->get_error_message();

            return \implode("\n", $lines);
        }

        $code = \wp_remote_retrieve_response_code($response);
        $body = \wp_remote_retrieve_body($response);
        $hRaw = \wp_remote_retrieve_headers($response);
        $hArr = $this->headersToArray($hRaw);
        if ($this->secretVisibility() !== 'show') {
            $hArr = $this->redactHeaders($hArr);
            $body = (string) $this->redactBody($body);
        }

        $lines[] = 'API RESPONSE: ' . (string) $code . ' ' . $url;
        foreach ($hArr as $hk => $hv) {
            $lines[] = '  ' . (string) $hk . ': ' . $this->httpHeaderValueToString($hv);
        }
        $lines[] = '  [body length: ' . \strlen($body) . ' bytes]';
        $lines[] = $this->truncateForLogFile($body);

        return \implode("\n", $lines);
    }

    /**
     * Convert a header value (string or array of strings) into a single display string.
     *
     * @since 1.0.0
     *
     * @param  mixed $value
     * @return string
     */
    private function httpHeaderValueToString(mixed $value): string {
        if (\is_array($value)) {
            $parts = [];
            foreach ($value as $one) {
                $parts[] = (string) $one;
            }

            return \implode(', ', $parts);
        }

        return (string) $value;
    }

    /**
     * Truncate a string to {@see LOG_TRUNCATE_BYTES} for a log file, appending a marker when cut.
     *
     * @since 1.0.0
     *
     * @param  string $s
     * @return string
     */
    private function truncateForLogFile(string $s): string {
        if (\strlen($s) > self::LOG_TRUNCATE_BYTES) {
            return \substr($s, 0, self::LOG_TRUNCATE_BYTES) . "\n... [truncated]";
        }

        return $s;
    }

    /**
     * Append a formatted "MAILER" block to the `mail` log channel for a `boolean_smtp_api_debug` payload.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $payload
     */
    private function appendMailerBlock(array $payload): void {
        $ts = \gmdate('Y-m-d H:i:s') . ' UTC';
        $log = "--------------------------------------------------------------------\n";
        $log .= "MAILER (boolean_smtp_api_debug)\n";
        $log .= 'Time: ' . $ts . "\n";
        $log .= "--------------------------------------------------------------------\n";
        $log .= $this->dumpForLog($payload) . "\n";
        $log .= "====================================================================\n\n\n";

        $this->appendToChannel('mail', $log);
    }

    /**
     * Render a value with `print_r()` for a log file, truncating long output.
     *
     * @since 1.0.0
     *
     * @param mixed $data
     * @return string
     */
    private function dumpForLog($data): string {
        $s = \print_r($data, true); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- formats a value for the developer log files; nothing is printed to the page.
        if ($s === false) {
            return "(unprintable)\n";
        }
        if (\strlen($s) > self::LOG_TRUNCATE_BYTES) {
            return \substr($s, 0, self::LOG_TRUNCATE_BYTES) . "\n... [truncated]\n";
        }

        return $s;
    }

    /**
     * Append a block to the `queries` log channel.
     *
     * @since 1.0.0
     *
     * @param string $text Preformatted block.
     */
    private function appendQueriesLog(string $text): void {
        $this->appendToChannel('queries', $text);
    }

    /**
     * Append a block to the `requests` log channel.
     *
     * @since 1.0.0
     *
     * @param string $text Preformatted block.
     */
    private function appendRequestsLog(string $text): void {
        $this->appendToChannel('requests', $text);
    }

    /**
     * Append a block to one of the plugin's log channels, which writes only while it is switched on.
     *
     * Before the plugin's container exists there is no log manager, and the block is dropped.
     *
     * @since 1.0.0
     *
     * @param string $channel Log channel name.
     * @param string $text    Preformatted block.
     */
    private function appendToChannel(string $channel, string $text): void {
        if (!Application::hasInstance()) {
            return;
        }

        try {
            app(LogManager::class)->channel($channel)->append($text);
        } catch (\Throwable) {
            // intentionally silent: a diagnostic write must never break the request it is diagnosing.
        }
    }

    /**
     * Merge buffered `request` and `sql` into a response data array's `api_debug` key (admin only).
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $data
     */
    public static function mergeApiDebugIntoDataArray(array &$data): void {
        $logger = self::getInstance();
        if ($logger === null) {
            return;
        }
        $extra = $logger->getBufferedForApiDebug();
        if ($extra === null) {
            return;
        }
        if (!isset($data['api_debug']) || !is_array($data['api_debug'])) {
            if (isset($data['api_debug'])) {
                $data['api_debug'] = ['mailer_payload' => $data['api_debug']];
            } else {
                $data['api_debug'] = [];
            }
        }
        /** @var array<string, mixed> $api */
        $api            = $data['api_debug'];
        $api['request'] = $extra['request'];
        $api['sql']     = $extra['sql'];
        $data['api_debug'] = $api;
    }

    /**
     * Build the buffered request and SQL data for the API debug response channel.
     *
     * @since 1.0.0
     *
     * @return array{request: mixed, sql: mixed}|null Null when the API debug response channel is
     *                                                  disabled or the current user cannot see it.
     */
    public function getBufferedForApiDebug(): ?array {
        if (!self::canExposeApiDebugResponse()) {
            return null;
        }

        if ($this->incomingRequest === []) {
            $this->captureIncomingRequest();
        }

        $request = [
            'incoming' => $this->incomingRequest,
            'outgoing_http' => $this->maybeRedactOutgoingBufferForApi($this->outgoingHttp),
            'mailer_events' => $this->mailerDebug,
        ];

        $sql = $this->buildSqlSnapshotForApi();

        return [
            'request' => $request,
            'sql'     => $sql,
        ];
    }

    /**
     * Redact the buffered outgoing-HTTP entries before including them in an API debug response.
     *
     * @since 1.0.0
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function maybeRedactOutgoingBufferForApi(array $rows): array {
        if ($this->secretVisibility() === 'show') {
            return $rows;
        }
        $out = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                $out[] = $row;

                continue;
            }
            $r = $row;
            if (isset($r['args']) && \is_array($r['args'])) {
                $r['args'] = $this->maybeRedactHttpArgs($r['args']);
            }
            if (\array_key_exists('response', $r)) {
                $r['response'] = $this->maybeRedactHttpResponse($r['response']);
            }
            $out[] = $r;
        }

        return $out;
    }

    /**
     * Build the SQL query snapshot included in an API debug response.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>|list<array<string, mixed>>|string
     */
    private function buildSqlSnapshotForApi() {
        if (!$this->channelQueries() && !$this->attachApiDebug()) {
            return ['message' => 'Query channel disabled.'];
        }
        if (!\defined('SAVEQUERIES') || !\constant('SAVEQUERIES')) {
            return ['error' => 'SAVEQUERIES is not enabled in wp-config.php.'];
        }
        global $wpdb;
        if (!isset($wpdb->queries) || !is_array($wpdb->queries)) {
            return ['message' => 'No queries recorded yet.'];
        }

        $lines = [];
        $total = 0.0;
        foreach ($wpdb->queries as $k => $row) {
            if (!is_array($row) || count($row) < 2) {
                continue;
            }
            $sql   = (string) ($row[0] ?? '');
            $time  = (float) ($row[1] ?? 0);
            $stack = isset($row[2]) ? (string) $row[2] : '';
            $total += $time;
            if (!$this->passesScopeQuery($stack)) {
                continue;
            }
            $row = [
                'n'     => $k + 1,
                'ms'    => round($time * 1000, 4),
                'sql'   => $this->secretVisibility() === 'full' ? $this->redactSqlSnippet($sql) : $sql,
                'stack' => $stack,
            ];
            $origin = $this->queryCallSites[$k] ?? '';
            if ($origin !== '') {
                $row['origin'] = $origin;
            }
            $lines[] = $row;
        }

        return [
            'total_queries' => count($lines),
            'total_ms'      => round($total * 1000, 2),
            'queries'       => $lines,
        ];
    }

    /**
     * Redact a SQL snippet for the API debug response's `full` secret visibility mode.
     *
     * @since 1.0.0
     *
     * @param  string $sql
     * @return string
     */
    private function redactSqlSnippet(string $sql): string {
        // Every quoted literal becomes `[REDACTED]`: bound values are the only place a query can
        // carry a credential or a token, and the query shape stays readable without them.
        return (string) \preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", "'[REDACTED]'", $sql);
    }

    /**
     * Determine whether the outgoing-HTTP debug channel (log channel `requests`) is enabled.
     *
     * Reads the `BOOLEAN_SMTP_DEBUG_HTTP` constant, false by default.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    private function channelHttp(): bool {
        /**
         * Filters whether the outgoing-HTTP debug channel is enabled.
         *
         * @since 1.0.0
         *
         * @param bool $enabled Default false; true when the `BOOLEAN_SMTP_DEBUG_HTTP` constant is set or the
         *                     `boolean_smtp_log_file_enabled` filter switches the `requests` log channel on.
         * @return bool The filtered value.
         */
        return (bool) apply_filters(
            'boolean_smtp_debug_http_enabled',
            (defined('BOOLEAN_SMTP_DEBUG_HTTP') && constant('BOOLEAN_SMTP_DEBUG_HTTP')) || LogChannels::enabledByHook('requests')
        );
    }

    /**
     * Determine whether the database-query debug channel (log channel `queries`) is enabled.
     *
     * Reads the `BOOLEAN_SMTP_DEBUG_QUERIES` constant, false by default. Also requires
     * WordPress core's `SAVEQUERIES` constant to produce any recorded query text.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    private function channelQueries(): bool {
        /**
         * Filters whether the database-query debug channel is enabled.
         *
         * @since 1.0.0
         *
         * @param bool $enabled Default false; true when the `BOOLEAN_SMTP_DEBUG_QUERIES` constant is set or the
         *                     `boolean_smtp_log_file_enabled` filter switches the `queries` log channel on.
         * @return bool The filtered value.
         */
        return (bool) apply_filters(
            'boolean_smtp_debug_queries_enabled',
            (defined('BOOLEAN_SMTP_DEBUG_QUERIES') && constant('BOOLEAN_SMTP_DEBUG_QUERIES')) || LogChannels::enabledByHook('queries')
        );
    }

    /**
     * Determine whether the incoming-request/REST debug channel (log channel `requests`) is enabled.
     *
     * Reads the `BOOLEAN_SMTP_DEBUG_REQUEST` constant, false by default.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    private function channelRequest(): bool {
        /**
         * Filters whether the incoming-request/REST debug channel is enabled.
         *
         * @since 1.0.0
         *
         * @param bool $enabled Default false; true when the `BOOLEAN_SMTP_DEBUG_REQUEST` constant is set or the
         *                     `boolean_smtp_log_file_enabled` filter switches the `requests` log channel on.
         * @return bool The filtered value.
         */
        return (bool) apply_filters(
            'boolean_smtp_debug_request_enabled',
            (defined('BOOLEAN_SMTP_DEBUG_REQUEST') && constant('BOOLEAN_SMTP_DEBUG_REQUEST')) || LogChannels::enabledByHook('requests')
        );
    }

    /**
     * Determine whether the mailer-diagnostics debug channel (log channel `mail`) is enabled.
     *
     * Reads the `BOOLEAN_SMTP_DEBUG_MAILER` constant, false by default.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    private function channelMailer(): bool {
        /**
         * Filters whether the mailer-diagnostics debug channel is enabled.
         *
         * @since 1.0.0
         *
         * @param bool $enabled Default false; true when the `BOOLEAN_SMTP_DEBUG_MAILER` constant is set or the
         *                     `boolean_smtp_log_file_enabled` filter switches the `mail` log channel on.
         * @return bool The filtered value.
         */
        return (bool) apply_filters(
            'boolean_smtp_debug_mailer_action_enabled',
            (defined('BOOLEAN_SMTP_DEBUG_MAILER') && constant('BOOLEAN_SMTP_DEBUG_MAILER')) || LogChannels::enabledByHook('mail')
        );
    }

    /**
     * Determine whether buffered debug data should be attached to an API response.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    private function attachApiDebug(): bool {
        return self::isApiDebugResponseEnabled();
    }

    /**
     * Determine whether raw debug data may be included in an admin REST response.
     *
     * This is intentionally opt-in. Reads the `BOOLEAN_SMTP_DEBUG_ATTACH_API_DEBUG` constant,
     * false by default. The legacy filters remain part of the effective default so a site already
     * using them retains its behavior; `boolean_smtp_api_debug_response_enabled` is the canonical
     * filter for new integrations.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public static function isApiDebugResponseEnabled(): bool {
        /**
         * Filters the legacy API debug response toggle.
         *
         * @since 1.0.0
         *
         * @param bool $enabled Default false, or the value of the
         *                      `BOOLEAN_SMTP_DEBUG_ATTACH_API_DEBUG` constant.
         * @return bool The filtered value.
         */
        $legacy = (bool) apply_filters(
            'boolean_smtp_debug_attach_api_debug',
            defined('BOOLEAN_SMTP_DEBUG_ATTACH_API_DEBUG') && constant('BOOLEAN_SMTP_DEBUG_ATTACH_API_DEBUG')
        );

        /**
         * Filters the legacy API debug response toggle (alias of `boolean_smtp_debug_attach_api_debug`).
         *
         * @since 1.0.0
         *
         * @param bool $enabled The value resolved by `boolean_smtp_debug_attach_api_debug`.
         * @return bool The filtered value.
         */
        $legacy = (bool) apply_filters('boolean_smtp_debug_output_xhr', $legacy);

        /**
         * Filters whether raw debug data may be attached to an admin REST response.
         *
         * This is the canonical filter for the API debug response channel; the legacy
         * `boolean_smtp_debug_attach_api_debug` and `boolean_smtp_debug_output_xhr` filters feed
         * into its default for backward compatibility.
         *
         * @since 1.0.0
         *
         * @param bool $enabled The legacy-filtered default.
         * @return bool The filtered value.
         */
        return (bool) apply_filters('boolean_smtp_api_debug_response_enabled', $legacy);
    }

    /**
     * Determine whether the current user may see an API debug response.
     *
     * Raw provider exchanges are diagnostic data, not normal admin response data. Even when a
     * developer enables the response channel via {@see isApiDebugResponseEnabled()}, it is
     * exposed only to a user who can manage site settings.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public static function canExposeApiDebugResponse(): bool {
        if (!self::isApiDebugResponseEnabled()) {
            return false;
        }

        return !\function_exists('current_user_can') || \current_user_can('manage_options');
    }

    /**
     * Resolve the debug scope: whether to log everything or only BooleanSMTP's own code.
     *
     * @since 1.0.0
     *
     * @return 'all'|'boolean_smtp_only'
     */
    private function scope(): string {
        /**
         * Filters the debug logging scope.
         *
         * @since 1.0.0
         *
         * @param string $scope `all` (default) logs every matching event; `boolean_smtp_only`
         *                      restricts logging to code running from within this plugin.
         * @return string The filtered scope.
         */
        $s = apply_filters('boolean_smtp_debug_scope', 'all');
        if ($s === 'boolean_smtp_only') {
            return 'boolean_smtp_only';
        }

        return 'all';
    }

    /**
     * Determine whether an HTTP debug event passes the current scope, by checking the call stack
     * for a frame inside the plugin directory.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    private function passesScopeHttp(): bool {
        if ($this->scope() !== 'boolean_smtp_only') {
            return true;
        }
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- developer HTTP channel scope: keeps only requests the plugin itself made.
        foreach ($trace as $frame) {
            $file = isset($frame['file']) ? (string) $frame['file'] : '';
            if ($file !== '' && str_starts_with(\wp_normalize_path($file), $this->pluginPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether a mailer debug event passes the current scope.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    private function passesScopeMailer(): bool {
        return $this->passesScopeHttp();
    }

    /**
     * Determine whether a query's call stack passes the current scope.
     *
     * @since 1.0.0
     *
     * @param  string $stack The query's recorded call stack, as provided by {@see \wpdb::$queries}.
     * @return bool
     */
    private function passesScopeQuery(string $stack): bool {
        if ($this->scope() !== 'boolean_smtp_only') {
            return true;
        }
        if ($stack === '') {
            return false;
        }
        if (stripos($stack, str_replace('\\', '/', $this->pluginPath)) !== false) {
            return true;
        }
        if (stripos($stack, 'boolean-smtp') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the outgoing-HTTP channel logs only mail-provider-related requests.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    private function httpMailerOnly(): bool {
        /**
         * Filters whether the outgoing-HTTP debug channel is restricted to mail-provider URLs.
         *
         * @since 1.0.0
         *
         * @param bool $mailerOnly Default true; set to false to log all outgoing HTTP requests.
         * @return bool Whether to log mailer requests only.
         */
        return (bool) apply_filters('boolean_smtp_debug_http_mailer_only', true);
    }

    /**
     * Determine whether a URL looks like a call to a known mail provider or auth endpoint.
     *
     * @since 1.0.0
     *
     * @param  string $url
     * @return bool
     */
    private function urlLooksLikeMailRelated(string $url): bool {
        $u = strtolower($url);
        $patterns = [
            'googleapis.com',
            'gmail.googleapis.com',
            'graph.microsoft.com',
            'login.microsoftonline.com',
            'microsoftonline.com',
            'amazonaws.com', // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- a host suffix matched against outgoing request URLs; nothing is loaded from it.
            'email.',
            'mail.',
            'smtp.',
            'oauth2.googleapis.com',
            'api.sendgrid.com',
            'api.mailgun.net',
            'api.postmarkapp.com',
            'api.sparkpost.com',
            'mandrillapp.com',
            'api.eu.mailgun.net',
        ];
        foreach ($patterns as $p) {
            if (str_contains($u, $p)) {
                return true;
            }
        }

        /**
         * Filters whether a URL not matched by the built-in provider list counts as mail-related.
         *
         * @since 1.0.0
         *
         * @param bool   $matches Whether the URL should be treated as mail-related. Default false.
         * @param string $url     The URL being checked.
         * @return bool Whether the URL belongs to a mailer.
         */
        return (bool) apply_filters('boolean_smtp_debug_http_mailer_url_match', false, $url);
    }

    /**
     * Redact sensitive headers and body values in a set of outgoing HTTP request arguments.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function maybeRedactHttpArgs(array $args): array {
        if ($this->secretVisibility() === 'show') {
            return $args;
        }
        $out = $args;
        if (isset($out['headers']) && \is_array($out['headers'])) {
            $out['headers'] = $this->redactHeaders($out['headers']);
        }
        if (isset($out['body'])) {
            $out['body'] = $this->redactBody($out['body']);
        }

        return $out;
    }

    /**
     * Redact sensitive headers and body values in an outgoing HTTP response, or summarize a
     * {@see \WP_Error}.
     *
     * @since 1.0.0
     *
     * @param mixed $response
     *
     * @return mixed
     */
    private function maybeRedactHttpResponse($response) {
        if ($this->secretVisibility() === 'show') {
            return $response;
        }
        if (\is_wp_error($response)) {
            return [
                'error' => true,
                'code'  => $response->get_error_code(),
                'msg'   => $response->get_error_message(),
            ];
        }
        if (!is_array($response)) {
            return $response;
        }
        $code = \wp_remote_retrieve_response_code($response);
        $body = \wp_remote_retrieve_body($response);
        $headers = \wp_remote_retrieve_headers($response);
        $h = \is_array($headers) ? $this->redactHeaders($headers) : $this->headersToArray($headers);

        return [
            'code'    => $code,
            'headers' => $h,
            'body'    => $this->redactBody($body),
        ];
    }

    /**
     * Redact `Authorization` and token-like header values.
     *
     * @since 1.0.0
     *
     * @param array<string|int, mixed>|\WpOrg\Requests\Utility\CaseInsensitiveDictionary $headers
     *
     * @return array<string|int, mixed>
     */
    private function redactHeaders(array $headers): array {
        $mode = $this->secretVisibility();
        $out  = [];
        foreach ($headers as $key => $value) {
            $k = \is_string($key) ? $key : (string) $key;
            $sensitive = \stripos($k, 'authorization') !== false || \stripos($k, 'token') !== false;
            if (!$sensitive) {
                $out[$k] = $value;

                continue;
            }
            if ($mode === 'full') {
                $out[$k] = '[REDACTED]';

                continue;
            }
            $vals = \is_array($value) ? $value : [$value];
            $masked = [];
            foreach ($vals as $one) {
                $masked[] = \is_string($one) ? $this->maskString($one) : $one;
            }
            $out[$k] = \is_array($value) ? $masked : $masked[0];
        }

        return $out;
    }

    /**
     * Normalize a header collection (array or iterable object) into a plain array.
     *
     * @since 1.0.0
     *
     * @param mixed $headers
     *
     * @return array<string|int, mixed>
     */
    private function headersToArray($headers): array {
        if (\is_array($headers)) {
            return $headers;
        }
        if (\is_object($headers)) {
            $out = [];
            foreach ($headers as $k => $v) {
                $out[$k] = $v;
            }

            return $out;
        }

        return [];
    }

    /**
     * Redact an HTTP body string if it looks like it contains an OAuth token or client secret.
     *
     * @since 1.0.0
     *
     * @param mixed $body
     *
     * @return mixed
     */
    private function redactBody($body) {
        $mode = $this->secretVisibility();
        if ($mode === 'show' || !\is_string($body)) {
            return $body;
        }
        $looksSensitive = \stripos($body, 'access_token') !== false
            || \stripos($body, 'refresh_token') !== false
            || \stripos($body, 'client_secret') !== false;
        if (!$looksSensitive) {
            return $body;
        }
        if ($mode === 'full') {
            return '[REDACTED BODY]';
        }

        return $this->maskString($body);
    }
}
