<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Http\Middleware;

use BooleanSmtp\Core\Http\JsonResponse;
use BooleanSmtp\Core\Http\Request;
use Closure;

/**
 * Capability Middleware
 *
 * Ensures the user has a specific WordPress capability.
 */
class CapabilityMiddleware extends Middleware
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param string $capability The required capability
     */
    public function handle(Request $request, Closure $next, string $capability = 'manage_options'): mixed
    {
        if (function_exists('current_user_can') && !current_user_can($capability)) {
            return JsonResponse::forbidden("You do not have the required capability: {$capability}");
        }

        return $next($request);
    }
}
