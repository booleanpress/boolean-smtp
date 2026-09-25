<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Http;

use Closure;

/**
 * Route Group
 *
 * Fluent interface for creating route groups.
 */
class RouteGroup
{
    /**
     * The router instance.
     */
    protected Router $router;

    /**
     * The group attributes.
     *
     * @var array<string, mixed>
     */
    protected array $attributes;

    /**
     * Create a new route group instance.
     *
     * @param array<string, mixed> $attributes
     */
    public function __construct(Router $router, array $attributes = [])
    {
        $this->router = $router;
        $this->attributes = $attributes;
    }

    /**
     * Add a prefix to the group.
     */
    public function prefix(string $prefix): static
    {
        $this->attributes['prefix'] = $prefix;
        return $this;
    }

    /**
     * Add middleware to the group.
     */
    public function middleware(string|array $middleware): static
    {
        $existing = $this->attributes['middleware'] ?? [];
        $this->attributes['middleware'] = array_merge($existing, (array) $middleware);
        return $this;
    }

    /**
     * Require a capability on every route in the group. A route's own
     * ->can()/->permission() call takes precedence.
     */
    public function can(string $capability): static
    {
        $this->attributes['permission'] = $capability;
        return $this;
    }

    /**
     * Set the namespace for the group.
     */
    public function namespace(string $namespace): static
    {
        $this->attributes['namespace'] = $namespace;
        return $this;
    }

    /**
     * Register the group routes.
     */
    public function group(Closure $callback): void
    {
        $this->router->group($this->attributes, $callback);
    }
}
