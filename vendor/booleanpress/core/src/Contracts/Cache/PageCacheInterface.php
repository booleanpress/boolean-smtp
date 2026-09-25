<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Contracts\Cache;

/**
 * Full HTML response cache interface.
 */
interface PageCacheInterface extends CacheInterface
{
    /**
     * Cache a response with headers.
     *
     * @param string $key
     * @param string $content
     * @param array $headers
     * @param int|\DateInterval|null $ttl
     * @return bool
     */
    public function setPage(string $key, string $content, array $headers = [], $ttl = null): bool;

    /**
     * Get a cached page.
     *
     * @param string $key
     * @return array|null [content, headers]
     */
    public function getPage(string $key): ?array;

    /**
     * Normalize query parameters for a consistent cache key.
     *
     * @param array $queryParams
     * @return array
     */
    public function normalizeQueryParams(array $queryParams): array;

    /**
     * Determine if a request should be cached based on user logic.
     *
     * @return bool
     */
    public function shouldCacheForCurrentUser(): bool;
}
