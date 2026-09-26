<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Http\Middleware;

use BooleanSmtp\Core\Http\JsonResponse;
use BooleanSmtp\Core\Http\Request;
use Closure;

/**
 * Authentication Middleware
 *
 * Ensures the user is authenticated via WordPress and optionally
 * checks for specific capabilities.
 */
class AuthMiddleware extends Middleware
{
    /**
     * Required capability (null = just check auth).
     */
    protected ?string $capability = null;

    /**
     * Whether to log authentication failures.
     */
    protected bool $logFailures = true;

    /**
     * Create a new auth middleware instance.
     *
     * @param string|null $capability Optional capability to check (e.g., 'edit_posts')
     */
    public function __construct(?string $capability = null)
    {
        $this->capability = $capability;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        // Check if user is authenticated
        if (!$request->isAuthenticated()) {
            $this->logAuthFailure($request, 'unauthenticated');
            return JsonResponse::unauthorized('You must be logged in to access this resource.');
        }

        // Check capability if specified
        if ($this->capability !== null) {
            if (!$this->userHasCapability($this->capability)) {
                $this->logAuthFailure($request, 'insufficient_capability', $this->capability);
                return JsonResponse::forbidden('You do not have permission to perform this action.');
            }
        }

        return $next($request);
    }

    /**
     * Check if the current user has the specified capability.
     */
    protected function userHasCapability(string $capability): bool
    {
        if (function_exists('current_user_can')) {
            return current_user_can($capability);
        }

        return false;
    }

    /**
     * Log authentication failure for security monitoring.
     *
     * @since 0.2.11 Writes to the `core` log channel (when a site has switched it on) instead of PHP's
     *               error log; `timestamp` is UTC.
     */
    protected function logAuthFailure(Request $request, string $reason, ?string $capability = null): void
    {
        if (!$this->logFailures) {
            return;
        }

        $data = [
            'type' => 'auth_failure',
            'reason' => $reason,
            'ip' => $request->ip(),
            'path' => $request->path(),
            'method' => $request->method(),
            'user_agent' => $request->header('User-Agent'),
            'timestamp' => gmdate('Y-m-d H:i:s'),
        ];

        if ($capability !== null) {
            $data['required_capability'] = $capability;
        }

        // Get current user if authenticated
        if (function_exists('get_current_user_id')) {
            $userId = get_current_user_id();
            if ($userId > 0) {
                $data['user_id'] = $userId;
            }
        }

        if (\BooleanSmtp\Core\Foundation\Application::hasInstance()) {
            try {
                $logger = \BooleanSmtp\Core\Foundation\Application::getInstance()->make(\BooleanSmtp\Core\Log\Logger::class);
                if ($logger->isEnabled()) {
                    $logger->warning('Request rejected by the authentication middleware.', $data);
                }
            } catch (\Throwable) {
                // intentionally silent: a failed log write must not change the response to the caller.
            }
        }

        if (function_exists('do_action')) {
            /**
             * Fires when a request is rejected by the authentication middleware.
             *
             * Runs after the failure has been written to the `core` log channel (when a site has
             * switched it on), so a plugin can forward it to its own security monitoring.
             *
             * @since 0.2.3
             *
             * @param array<string, mixed> $data Failure details: `type` (`auth_failure`), `reason`,
             *        `ip`, `path`, `method`, `user_agent`, `timestamp`, `required_capability`
             *        when a capability was checked and `user_id` when a user was logged in.
             */
            do_action('booleanpress_auth_failure', $data);
        }
    }

    /**
     * Set the required capability.
     */
    public function requireCapability(string $capability): static
    {
        $this->capability = $capability;
        return $this;
    }

    /**
     * Disable failure logging.
     */
    public function withoutLogging(): static
    {
        $this->logFailures = false;
        return $this;
    }

    /**
     * Static factory for common capability checks.
     */
    public static function can(string $capability): static
    {
        return new static($capability);
    }
}
