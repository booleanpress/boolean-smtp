<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Http\Resources;

use BooleanSmtp\Core\Database\Orm\Model;
use BooleanSmtp\Core\Support\Collection;
use BooleanSmtp\Core\Pagination\LengthAwarePaginator;

/**
 * Resource Collection
 *
 * Handles collections of resources for API responses.
 * Supports pagination metadata.
 */
class ResourceCollection
{
    /**
     * The collection of resources.
     *
     * @var Collection<int, Model>|array<Model>
     */
    protected Collection|array $resources;

    /**
     * The resource class to use for transformation.
     */
    protected string $resourceClass;

    /**
     * Pagination metadata if available.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $pagination = null;

    /**
     * @param Collection<int, Model>|array<Model> $resources
     */
    public function __construct(Collection|array $resources, string $resourceClass = JsonResource::class)
    {
        $this->resources = $resources;
        $this->resourceClass = $resourceClass;
    }

    /**
     * Create from a paginator.
     */
    public static function fromPaginator(LengthAwarePaginator $paginator, string $resourceClass = JsonResource::class): static
    {
        $instance = new static($paginator->items(), $resourceClass);
        $instance->pagination = [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
        return $instance;
    }

    /**
     * Transform the collection to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $collection = $this->resources instanceof Collection
            ? $this->resources
            : new Collection($this->resources);

        $data = $collection->map(function (Model $model) {
            $resource = new $this->resourceClass($model);
            return $resource->toArray();
        })->all();

        $result = ['data' => $data];

        if ($this->pagination !== null) {
            $result['meta'] = $this->pagination;
        }

        return $result;
    }

    /**
     * Convert to JSON.
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }
}
