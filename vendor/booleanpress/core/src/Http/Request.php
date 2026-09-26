<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Http;

use BooleanSmtp\Core\Exceptions\ValidationException;

/**
 * HTTP Request
 *
 * Wraps the WordPress request and provides a clean interface for accessing
 * request data, headers, and files.
 */
class Request
{
    /**
     * The request GET parameters.
     *
     * @var array<string, mixed>
     */
    protected array $query;

    /**
     * The request POST parameters.
     *
     * @var array<string, mixed>
     */
    protected array $request;

    /**
     * The request headers.
     *
     * @var array<string, string>
     */
    protected array $headers;

    /**
     * The request cookies.
     *
     * @var array<string, string>
     */
    protected array $cookies;

    /**
     * The request files.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $files;

    /**
     * The server parameters.
     *
     * @var array<string, mixed>
     */
    protected array $server;

    /**
     * The raw request body.
     */
    protected ?string $content = null;

    /**
     * The JSON decoded content.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $json = null;

    /**
     * Custom attributes.
     *
     * @var array<string, mixed>
     */
    protected array $attributes = [];

    /**
     * The route parameters.
     *
     * @var array<string, mixed>
     */
    protected array $routeParams = [];

    /**
     * Create a new request instance.
     */
    public function __construct(
        array $query = [],
        array $request = [],
        array $cookies = [],
        array $files = [],
        array $server = [],
        ?string $content = null
    ) {
        $this->query = $query;
        $this->request = $request;
        $this->cookies = $cookies;
        $this->files = $files;
        $this->server = $server;
        $this->content = $content;
        $this->headers = $this->parseHeaders($server);
    }

    /**
     * Create an instance of this class carrying another request's state.
     *
     * Used to build a FormRequest subclass from the request the router is dispatching, so
     * the subclass validates the same query, body, JSON, route parameters and headers.
     */
    public static function createFrom(Request $from): static
    {
        $request = new static(
            $from->query,
            $from->request,
            $from->cookies,
            $from->files,
            $from->server,
            $from->content
        );

        $request->headers     = $from->headers;
        $request->json        = $from->json;
        $request->attributes  = $from->attributes;
        $request->routeParams = $from->routeParams;

        return $request;
    }

    /**
     * Create a request from the one WordPress's REST server built for this call.
     *
     * Everything comes from `WP_REST_Request`, which WordPress has already parsed and unslashed:
     * the query, the form and JSON bodies, the raw body (a provider webhook signs exactly those
     * bytes), uploaded files, the headers and the route's URL parameters. The same values arrive
     * whether the call is a real HTTP request or an internal dispatch (`rest_do_request()`, the
     * batch endpoint, WP-CLI), and no superglobal is read, apart from the client address, which
     * `WP_REST_Request` does not carry and which is kept only when it is a valid IP address.
     *
     * @since 0.2.11
     *
     * @param \WP_REST_Request $wpRequest The request WordPress is dispatching.
     * @return static
     */
    public static function fromWpRest(\WP_REST_Request $wpRequest): static
    {
        $route  = (string) $wpRequest->get_route();
        $prefix = \function_exists('rest_get_url_prefix') ? trim((string) rest_get_url_prefix(), '/') : 'wp-json';
        $query  = $wpRequest->get_query_params();

        $server = [
            'REQUEST_METHOD' => strtoupper((string) $wpRequest->get_method()),
            'REQUEST_URI'    => '/' . $prefix . $route . ($query !== [] ? '?' . http_build_query($query) : ''),
        ];

        // WordPress stores header names lower-cased with underscores (`content_type`, `user_agent`).
        foreach ($wpRequest->get_headers() as $name => $values) {
            $key   = strtoupper((string) $name);
            $value = implode(', ', array_map('strval', (array) $values));
            $server[\in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true) ? $key : 'HTTP_' . $key] = $value;
        }

        if (\function_exists('is_ssl') && is_ssl()) {
            $server['HTTPS'] = 'on';
        }

        if (isset($_SERVER['REMOTE_ADDR']) && \is_string($_SERVER['REMOTE_ADDR']) && \function_exists('wp_unslash')) {
            $address = filter_var(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])), FILTER_VALIDATE_IP);
            if (\is_string($address)) {
                $server['REMOTE_ADDR'] = $address;
            }
        }

        $content = (string) $wpRequest->get_body();
        $body    = $wpRequest->get_body_params();
        $files   = $wpRequest->get_file_params();

        $request = new static(
            \is_array($query) ? $query : [],
            \is_array($body) ? $body : [],
            [],
            \is_array($files) ? $files : [],
            $server,
            $content !== '' ? $content : null
        );

        $json = $wpRequest->get_json_params();
        if (\is_array($json) && $json !== []) {
            $request->setJsonParams($json);
        }

        $request->setRouteParams($wpRequest->get_url_params());

        return $request;
    }

    /**
     * Parse headers from server array.
     *
     * @return array<string, string>
     */
    protected function parseHeaders(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[strtolower($name)] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'])) {
                $name = str_replace('_', '-', $key);
                $headers[strtolower($name)] = $value;
            }
        }

        return $headers;
    }

    /**
     * Get the request method.
     */
    public function method(): string
    {
        $method = strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');

        // Check for method override
        if ($method === 'POST') {
            if ($override = $this->headers['x-http-method-override'] ?? null) {
                $method = strtoupper($override);
            } elseif ($override = $this->input('_method')) {
                $method = strtoupper($override);
            }
        }

        return $method;
    }

    /**
     * Check if the request matches a method.
     */
    public function isMethod(string $method): bool
    {
        return $this->method() === strtoupper($method);
    }

    /**
     * Get the request path.
     */
    public function path(): string
    {
        $path = parse_url($this->server['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        return $path ?: '/';
    }

    /**
     * Get the full URL.
     */
    public function fullUrl(): string
    {
        $scheme = $this->isSecure() ? 'https' : 'http';
        $host = $this->header('host', 'localhost');
        $uri = $this->server['REQUEST_URI'] ?? '/';

        return "{$scheme}://{$host}{$uri}";
    }

    /**
     * Get the URL without query string.
     */
    public function url(): string
    {
        return strtok($this->fullUrl(), '?') ?: $this->fullUrl();
    }

    /**
     * Check if request is HTTPS.
     */
    public function isSecure(): bool
    {
        return ($this->server['HTTPS'] ?? '') === 'on'
            || ($this->header('x-forwarded-proto') === 'https');
    }

    /**
     * Check if request is AJAX.
     */
    public function isAjax(): bool
    {
        return $this->header('x-requested-with') === 'XMLHttpRequest';
    }

    /**
     * Check if request expects JSON response.
     */
    public function expectsJson(): bool
    {
        return str_contains($this->header('accept', ''), 'application/json')
            || $this->isAjax();
    }

    /**
     * Alias for expectsJson() (Laravel compatibility).
     */
    public function wantsJson(): bool
    {
        return $this->expectsJson();
    }

    /**
     * Determine if the request path matches a given pattern.
     */
    public function is(string ...$patterns): bool
    {
        $path = trim($this->path(), '/');

        foreach ($patterns as $pattern) {
            $pattern = trim($pattern, '/');
            $regex = str_replace(['\*', '\{[^}]+\}'], ['.*', '[^/]+'], preg_quote($pattern, '#'));
            if (preg_match('#^' . $regex . '$#i', $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if request has JSON content type.
     */
    public function isJson(): bool
    {
        return str_contains($this->header('content-type', ''), 'application/json');
    }

    /**
     * Get a header value.
     */
    public function header(string $key, ?string $default = null): ?string
    {
        return $this->headers[strtolower($key)] ?? $default;
    }

    /**
     * Get all headers.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Get an input value (POST > GET > default).
     */
    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->request)) {
            return $this->request[$key];
        }

        if (array_key_exists($key, $this->query)) {
            return $this->query[$key];
        }

        if ($this->json !== null && array_key_exists($key, $this->json)) {
            return $this->json[$key];
        }

        if ($this->json === null && $this->isJson() && $this->content) {
            $this->json = json_decode($this->content, true) ?: [];
            if (array_key_exists($key, $this->json)) {
                return $this->json[$key];
            }
        }

        return $default;
    }

    /**
     * Get an input value (alias for {@see input()}, Laravel-style).
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->input($key, $default);
    }

    /**
     * Get a query string value.
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Get a POST value.
     */
    public function post(string $key, mixed $default = null): mixed
    {
        return $this->request[$key] ?? $default;
    }

    /**
     * Get all input data.
     *
     * @param array<string>|null $keys Keys to include
     * @return array<string, mixed>
     */
    public function all(?array $keys = null): array
    {
        $all = array_merge($this->query, $this->request, $this->json());

        if ($keys === null) {
            return $all;
        }

        return array_intersect_key($all, array_flip($keys));
    }

    /**
     * Get only specific keys.
     *
     * @param array<string> $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        return $this->all($keys);
    }

    /**
     * Get all except specific keys.
     *
     * @param array<string> $keys
     * @return array<string, mixed>
     */
    public function except(array $keys): array
    {
        return array_diff_key($this->all(), array_flip($keys));
    }

    /**
     * Check if a key exists in the input.
     */
    public function has(string|array $key): bool
    {
        $keys = (array) $key;
        $input = $this->all();

        foreach ($keys as $k) {
            if (!array_key_exists($k, $input)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a key is present and not empty.
     */
    public function filled(string|array $key): bool
    {
        $keys = (array) $key;

        foreach ($keys as $k) {
            $value = $this->input($k);
            if ($value === null || $value === '' || $value === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get JSON body data.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if ($this->json === null && $this->content) {
            $this->json = json_decode($this->content, true) ?: [];
        }

        return $this->json ?? [];
    }

    /**
     * Set decoded JSON body (used when building from WP_REST_Request; php://input may already be consumed).
     *
     * @param array<string, mixed> $json
     */
    public function setJsonParams(array $json): static
    {
        $this->json = $json;

        return $this;
    }

    /**
     * Set the raw request body (used when building from WP_REST_Request, which already holds it; a
     * webhook signature is computed over these exact bytes).
     */
    public function setContent(?string $content): static
    {
        $this->content = $content;
        $this->json    = null;

        return $this;
    }

    /**
     * Merge into POST parameters (form body).
     *
     * @param array<string, mixed> $data
     */
    public function mergeRequest(array $data): static
    {
        $this->request = array_merge($this->request, $data);

        return $this;
    }

    /**
     * Merge into the query parameters; a key given here replaces the current one.
     *
     * @since 0.2.6
     *
     * @param array<string, mixed> $data
     */
    public function mergeQuery(array $data): static
    {
        $this->query = array_merge($this->query, $data);

        return $this;
    }

    /**
     * Get the raw request body.
     */
    public function getContent(): ?string
    {
        return $this->content;
    }

    /**
     * Get a cookie value.
     */
    public function cookie(string $key, ?string $default = null): ?string
    {
        return $this->cookies[$key] ?? $default;
    }

    /**
     * Get an uploaded file.
     *
     * @return array<string, mixed>|null
     */
    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    /**
     * Check if a file was uploaded.
     */
    public function hasFile(string $key): bool
    {
        $file = $this->file($key);
        return $file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * Get the client IP address.
     */
    public function ip(): ?string
    {
        $keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];

        foreach ($keys as $key) {
            if (!empty($this->server[$key])) {
                $ips = explode(',', $this->server[$key]);
                return trim($ips[0]);
            }
        }

        return null;
    }

    /**
     * Get the user agent.
     */
    public function userAgent(): ?string
    {
        return $this->server['HTTP_USER_AGENT'] ?? null;
    }

    /**
     * Get the bearer token from Authorization header.
     */
    public function bearerToken(): ?string
    {
        $header = $this->header('authorization', '');
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }
        return null;
    }

    /**
     * Set a route parameter.
     */
    public function setRouteParam(string $key, mixed $value): static
    {
        $this->routeParams[$key] = $value;
        return $this;
    }

    /**
     * Get a route parameter.
     */
    public function route(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }

    /**
     * Get a route parameter (Laravel-style alias for {@see route()}).
     */
    public function param(string $key, mixed $default = null): mixed
    {
        return $this->route($key, $default);
    }

    /**
     * Set all route parameters.
     *
     * @param array<string, mixed> $params
     */
    public function setRouteParams(array $params): static
    {
        $this->routeParams = $params;
        return $this;
    }

    /**
     * Get all route parameters.
     *
     * @return array<string, mixed>
     */
    public function routeParams(): array
    {
        return $this->routeParams;
    }

    /**
     * Set a custom attribute.
     */
    public function setAttribute(string $key, mixed $value): static
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    /**
     * Get a custom attribute.
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Get the current WordPress user.
     */
    public function user(): ?\WP_User
    {
        if (function_exists('wp_get_current_user')) {
            $user = wp_get_current_user();
            return ($user && $user->ID > 0) ? $user : null;
        }
        return null;
    }

    /**
     * Check if user is authenticated.
     */
    public function isAuthenticated(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Validate the request data.
     *
     * @param array<string, string|array<string>> $rules
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function validate(array $rules): array
    {
        // Basic implementation - full validation is in Validator component
        $validated = [];
        $errors = [];

        foreach ($rules as $field => $fieldRules) {
            $value = $this->input($field);
            $ruleList = is_array($fieldRules) ? $fieldRules : explode('|', $fieldRules);

            foreach ($ruleList as $rule) {
                if ($rule === 'required' && ($value === null || $value === '')) {
                    $errors[$field][] = "The {$field} field is required.";
                }
            }

            if (!isset($errors[$field])) {
                $validated[$field] = $value;
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        return $validated;
    }

    // =========================================================================
    // SECURITY: Sanitization Helpers
    // =========================================================================

    /**
     * Get a sanitized string input (strips tags and encodes special chars).
     */
    public function sanitize(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        if (!is_string($value)) {
            return $default;
        }

        if (function_exists('sanitize_text_field')) {
            return sanitize_text_field($value);
        }

        return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Get HTML-sanitized input (allows safe HTML tags).
     */
    public function sanitizeHtml(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        if (!is_string($value)) {
            return $default;
        }

        if (function_exists('wp_kses_post')) {
            return wp_kses_post($value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Get a sanitized email input.
     */
    public function sanitizeEmail(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        if (!is_string($value)) {
            return $default;
        }

        if (function_exists('sanitize_email')) {
            return sanitize_email($value);
        }

        return filter_var($value, FILTER_SANITIZE_EMAIL) ?: $default;
    }

    /**
     * Get a sanitized URL input.
     */
    public function sanitizeUrl(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        if (!is_string($value)) {
            return $default;
        }

        if (function_exists('esc_url_raw')) {
            return esc_url_raw($value);
        }

        return filter_var($value, FILTER_SANITIZE_URL) ?: $default;
    }

    /**
     * Get a sanitized integer input.
     */
    public function sanitizeInt(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);
        return (int) filter_var($value, FILTER_SANITIZE_NUMBER_INT);
    }

    /**
     * Verify a WordPress nonce.
     */
    public function verifyNonce(string $action, string $nonceKey = '_wpnonce'): bool
    {
        $nonce = $this->input($nonceKey) ?? $this->header('x-wp-nonce');

        if (!$nonce) {
            return false;
        }

        if (function_exists('wp_verify_nonce')) {
            return wp_verify_nonce($nonce, $action) !== false;
        }

        return false;
    }
}
