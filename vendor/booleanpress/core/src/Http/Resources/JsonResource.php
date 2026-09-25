<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Http\Resources;

use BooleanSmtp\Core\Database\Orm\Model;
use BooleanSmtp\Core\Support\Collection;

/**
 * JSON Resource
 *
 * Transform models into JSON-serializable arrays for API responses.
 * Provides a consistent transformation layer.
 */
class JsonResource
{
    /**
     * The resource instance.
     */
    public Model $resource;

    /**
     * Additional data to be added to the resource.
     *
     * @var array<string, mixed>
     */
    protected array $with = [];

    public function __construct(Model $resource)
    {
        $this->resource = $resource;
    }

    /**
     * Create a new resource instance.
     */
    public static function make(Model $resource): static
    {
        return new static($resource);
    }

    /**
     * Create a collection of resources.
     *
     * @param Collection<int, Model>|array<Model> $resources
     */
    public static function collection(Collection|array $resources): ResourceCollection
    {
        return new ResourceCollection($resources, static::class);
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        // Override in child class for custom transformation
        return $this->resource->toArray();
    }

    /**
     * Add additional data to the resource response.
     *
     * @param array<string, mixed> $data
     */
    public function additional(array $data): static
    {
        $this->with = array_merge($this->with, $data);
        return $this;
    }

    /**
     * Resolve the resource to an array.
     *
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $data = $this->toArray();

        if (!empty($this->with)) {
            $data = array_merge($data, $this->with);
        }

        return $data;
    }

    /**
     * Convert to JSON.
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->resolve(), $options);
    }

    /**
     * Get a value from the underlying resource.
     */
    public function __get(string $key): mixed
    {
        return $this->resource->$key;
    }
}
