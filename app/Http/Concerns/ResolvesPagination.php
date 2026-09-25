<?php
/**
 * Shared page/per-page resolution for list endpoints.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Http\Concerns;

use BooleanSmtp\Core\Http\Request;

/**
 * Reads `page` and `per_page` from a request and clamps them to safe bounds, so no list
 * endpoint can be asked for an unbounded page.
 *
 * @since 1.0.0
 */
trait ResolvesPagination {
    /**
     * Largest page size any list endpoint serves.
     *
     * A method rather than a constant: PHP allows constants in traits only from 8.2, and the
     * plugin runs on 8.1.
     *
     * @since 1.0.0
     *
     * @return int
     */
    public static function maxPerPage(): int {
        return 100;
    }

    /**
     * Resolve the requested page and page size.
     *
     * @since 1.0.0
     *
     * @param  Request $request        The incoming request (`page`, `per_page` query parameters).
     * @param  int     $defaultPerPage Page size used when `per_page` is absent.
     * @param  string  $perPageKey     Query parameter carrying the page size (`per_page` or `limit`).
     * @return array{page: int, per_page: int} Page (>= 1) and page size (1 … {@see maxPerPage()}).
     */
    protected function resolvePagination(Request $request, int $defaultPerPage = 25, string $perPageKey = 'per_page'): array {
        $page    = (int) $request->get('page', 1);
        $perPage = (int) $request->get($perPageKey, $defaultPerPage);

        return [
            'page'     => \max(1, $page),
            'per_page' => \min(\max(1, $perPage), self::maxPerPage()),
        ];
    }
}
