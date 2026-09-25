<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Pagination;

use BooleanSmtp\Core\Support\Collection;

/**
 * Length Aware Paginator
 *
 * A paginator that knows the total number of items and pages.
 * Provides navigation helpers for building pagination UI.
 */
class LengthAwarePaginator
{
    /**
     * @var Collection<int, mixed>
     */
    protected Collection $items;
    protected int $total;
    protected int $perPage;
    protected int $currentPage;
    protected int $lastPage;

    /**
     * @param Collection<int, mixed>|array<mixed> $items
     */
    public function __construct(Collection|array $items, int $total, int $perPage, int $currentPage = 1)
    {
        $this->items = $items instanceof Collection ? $items : new Collection($items);
        $this->total = $total;
        $this->perPage = $perPage;
        $this->currentPage = max(1, $currentPage);
        $this->lastPage = max(1, (int) ceil($total / $perPage));
    }

    /**
     * Get the items for the current page.
     *
     * @return Collection<int, mixed>
     */
    public function items(): Collection
    {
        return $this->items;
    }

    /**
     * Get the total number of items.
     */
    public function total(): int
    {
        return $this->total;
    }

    /**
     * Get the number of items per page.
     */
    public function perPage(): int
    {
        return $this->perPage;
    }

    /**
     * Get the current page number.
     */
    public function currentPage(): int
    {
        return $this->currentPage;
    }

    /**
     * Get the last page number.
     */
    public function lastPage(): int
    {
        return $this->lastPage;
    }

    /**
     * Determine if there are more pages.
     */
    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage;
    }

    /**
     * Determine if there are previous pages.
     */
    public function hasPreviousPage(): bool
    {
        return $this->currentPage > 1;
    }

    /**
     * Get the next page number.
     */
    public function nextPage(): ?int
    {
        return $this->hasMorePages() ? $this->currentPage + 1 : null;
    }

    /**
     * Get the previous page number.
     */
    public function previousPage(): ?int
    {
        return $this->hasPreviousPage() ? $this->currentPage - 1 : null;
    }

    /**
     * Get the item count on the current page.
     *
     * @return int
     */
    public function count(): int
    {
        return $this->items->count();
    }

    /**
     * Determine if we are on the first page.
     *
     * @return bool
     */
    public function onFirstPage(): bool
    {
        return $this->currentPage === 1;
    }

    /**
     * Determine if we are on the last page.
     *
     * @return bool
     */
    public function onLastPage(): bool
    {
        return $this->currentPage === $this->lastPage;
    }

    /**
     * Get the starting item number for the current page.
     *
     * @return int
     */
    public function firstItem(): int
    {
        return $this->total > 0 ? ($this->currentPage - 1) * $this->perPage + 1 : 0;
    }

    /**
     * Get the ending item number for the current page.
     *
     * @return int
     */
    public function lastItem(): int
    {
        return min($this->currentPage * $this->perPage, $this->total);
    }

    /**
     * Convert to array for JSON responses.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'data' => $this->items->toArray(),
            'meta' => [
                'current_page' => $this->currentPage,
                'last_page' => $this->lastPage,
                'per_page' => $this->perPage,
                'total' => $this->total,
                'from' => $this->firstItem(),
                'to' => $this->lastItem(),
            ],
        ];
    }
}
