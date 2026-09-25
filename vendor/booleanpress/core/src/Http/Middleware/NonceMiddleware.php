<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Http\Middleware;

use BooleanSmtp\Core\Http\JsonResponse;
use BooleanSmtp\Core\Http\Request;
use Closure;

/**
 * Nonce Verification Middleware
 *
 * Verifies WordPress nonces on state-changing requests (POST, PUT, PATCH, DELETE).
 * This provides CSRF protection for REST API endpoints using the standard
 * X-WP-Nonce header with configurable action and path exclusions.
 */
class NonceMiddleware extends Middleware
{
    /**
     * The nonce action name.
     */
    protected string $action;

    /**
     * URIs that should be excluded from nonce verification.
     *
     * @var array<string>
     */
    protected array $except = [];

    /**
     * Create a new nonce middleware instance.
     */
    public function __construct(string $action = 'wp_rest')
    {
        $this->action = $action;
    }

    /**
     * Add paths to exclude from nonce verification.
     *
     * @param array<string> $paths
     */
    public function except(array $paths): static
    {
        $this->except = array_merge($this->except, $paths);
        return $this;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        // Only verify nonces on state-changing requests
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        // Check path exclusions
        if ($this->isExcluded($request)) {
            return $next($request);
        }

        // Check X-WP-Nonce header first (standard for WP REST API)
        $nonce = $request->header('x-wp-nonce');

        // Fallback to _wpnonce in request body
        if (!$nonce) {
            $nonce = $request->input('_wpnonce');
        }

        // Fallback to query parameter
        if (!$nonce) {
            $nonce = $request->query('_wpnonce');
        }

        if (!$nonce) {
            return JsonResponse::forbidden('Missing security token.');
        }

        if (!$this->verifyNonce($nonce)) {
            return JsonResponse::forbidden('Invalid or expired security token.');
        }

        return $next($request);
    }

    /**
     * Check if the request path is excluded from verification.
     */
    protected function isExcluded(Request $request): bool
    {
        $path = $request->path();

        foreach ($this->except as $pattern) {
            if ($this->matchesPattern($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a path matches a wildcard pattern.
     */
    protected function matchesPattern(string $path, string $pattern): bool
    {
        $pattern = preg_quote($pattern, '#');
        $pattern = str_replace('\*', '.*', $pattern);
        return (bool) preg_match('#^' . $pattern . '$#', $path);
    }

    /**
     * Verify the nonce.
     */
    protected function verifyNonce(string $nonce): bool
    {
        if (function_exists('wp_verify_nonce')) {
            return wp_verify_nonce($nonce, $this->action) !== false;
        }

        // Can't verify without WordPress - fail closed for security
        return false;
    }
}
