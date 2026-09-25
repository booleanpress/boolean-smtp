<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Http\Middleware;

use BooleanSmtp\Core\Http\Request;
use Closure;

/**
 * Base Middleware
 *
 * Base class for HTTP middleware.
 */
abstract class Middleware
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request The incoming request
     * @param Closure $next The next middleware in the pipeline
     * @return mixed
     */
    abstract public function handle(Request $request, Closure $next): mixed;
}
