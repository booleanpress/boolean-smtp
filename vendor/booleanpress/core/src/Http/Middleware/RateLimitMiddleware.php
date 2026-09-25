<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Http\Middleware;

use BooleanSmtp\Core\Database\Drivers\DriverInterface;
use BooleanSmtp\Core\Http\JsonResponse;
use BooleanSmtp\Core\Http\Request;
use Closure;
use function BooleanSmtp\Core\app;

/**
 * Rate Limiting Middleware
 *
 * Limits the number of requests a user can make within a time window.
 * Uses the custom `cache` table via DriverInterface for storage — the table the Cache component's
 * migration creates. A plugin that does not register the `Cache` component must not route
 * through this middleware; the counter has nowhere to live and every request fails.
 */
class RateLimitMiddleware extends Middleware
{
    /**
     * Maximum requests per window.
     */
    protected int $maxRequests;

    /**
     * Time window in seconds.
     */
    protected int $windowSeconds;

    /**
     * Rate limit key prefix.
     */
    protected string $prefix;

    /**
     * Create a new rate limit middleware instance.
     */
    public function __construct(int $maxRequests = 60, int $windowSeconds = 60, string $prefix = 'rate_limit')
    {
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;
        $this->prefix = $prefix;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $key = $this->resolveKey($request);
        $current = $this->getCurrentCount($key);

        if ($current >= $this->maxRequests) {
            return $this->rateLimitExceeded();
        }

        $this->incrementCount($key);

        $response = $next($request);

        // Add rate limit headers if response supports it
        if ($response instanceof JsonResponse) {
            $response->header('X-RateLimit-Limit', (string) $this->maxRequests);
            $response->header('X-RateLimit-Remaining', (string) max(0, $this->maxRequests - $current - 1));
        }

        return $response;
    }

    /**
     * Resolve the rate limit key for the request.
     */
    protected function resolveKey(Request $request): string
    {
        $identifier = $request->user()?->ID ?? $request->ip() ?? 'unknown';
        return $this->prefix . '_' . md5((string) $identifier);
    }

    /**
     * Get the database driver.
     */
    protected function driver(): DriverInterface
    {
        return app(DriverInterface::class);
    }

    /**
     * Get the full cache table name.
     */
    protected function getTable(): string
    {
        return $this->driver()->getTable('cache');
    }

    /**
     * Get the current request count.
     */
    protected function getCurrentCount(string $key): int
    {
        $row = $this->driver()->selectRow(
            "SELECT `value` FROM `{$this->getTable()}` WHERE `key` = %s AND (`expiration` = 0 OR `expiration` > %s)",
            [$key, time()]
        );

        if ($row === null) {
            return 0;
        }

        return (int) unserialize($row['value'], ['allowed_classes' => false]);
    }

    /**
     * Increment the request count.
     */
    protected function incrementCount(string $key): void
    {
        $driver = $this->driver();
        $table = $this->getTable();
        $expiration = time() + $this->windowSeconds;

        $existing = $driver->selectRow(
            "SELECT `value` FROM `{$table}` WHERE `key` = %s AND (`expiration` = 0 OR `expiration` > %s)",
            [$key, time()]
        );

        if ($existing !== null) {
            $newCount = (int) unserialize($existing['value'], ['allowed_classes' => false]) + 1;
            $driver->statement(
                "UPDATE `{$table}` SET `value` = %s, `expiration` = %s WHERE `key` = %s",
                [serialize($newCount), $expiration, $key]
            );
        } else {
            // Clean up any expired entry first
            $driver->statement(
                "DELETE FROM `{$table}` WHERE `key` = %s",
                [$key]
            );

            $driver->insert('cache', [
                'key' => $key,
                'value' => serialize(1),
                'expiration' => $expiration,
            ]);
        }
    }

    /**
     * Return a rate limit exceeded response.
     */
    protected function rateLimitExceeded(): JsonResponse
    {
        return (new JsonResponse([
            'success' => false,
            'message' => 'Rate limit exceeded. Please try again later.',
            'retry_after' => $this->windowSeconds,
        ], 429))
            ->header('Retry-After', (string) $this->windowSeconds)
            ->header('X-RateLimit-Limit', (string) $this->maxRequests)
            ->header('X-RateLimit-Remaining', '0');
    }
}
