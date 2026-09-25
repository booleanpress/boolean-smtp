<?php

declare (strict_types = 1);

namespace BooleanSmtp\Core\Http;

/**
 * Route
 *
 * Represents a single route with its configuration.
 */
class Route {
    /**
     * The HTTP method.
     */
    protected string $method;

    /**
     * The route URI.
     */
    protected string $uri;

    /**
     * The route action.
     *
     * @var array<string, mixed>
     */
    protected array $action;

    /**
     * The route middleware.
     *
     * @var array<string>
     */
    protected array $middlewareList = [];

    /**
     * The route name.
     */
    protected ?string $name = null;

    /**
     * The route permission.
     */
    protected mixed $permission = null;

    /**
     * Callback invoked when the route mutates.
     */
    protected ?\Closure $onChange = null;

    /**
     * Create a new route instance.
     *
     * @param array<string, mixed> $action
     */
    public function __construct(string $method, string $uri, array $action,  ? \Closure $onChange = null) {
        $this->method   = $method;
        $this->uri      = $uri;
        $this->action   = $action;
        $this->onChange = $onChange;
    }

    /**
     * Set the route name.
     */
    public function name(string $name): static
    {
        $this->name = $name;
        $this->sync();
        return $this;
    }

    /**
     * Add middleware to the route.
     */
    public function middleware(string | array $middleware): static
    {
        $this->middlewareList = array_merge(
            $this->middlewareList,
            (array) $middleware
        );
        $this->sync();
        return $this;
    }

    /**
     * Remove middleware from the route — including middleware a wrapping
     * group() already applied. A route registered inside a group has no
     * other way to opt out of that group's middleware (e.g. a public
     * provider-webhook endpoint declared inside a group that requires 'auth'
     * for everything else).
     */
    public function withoutMiddleware(string | array $middleware): static
    {
        $remove = (array) $middleware;
        $this->middlewareList = array_values(array_diff($this->middlewareList, $remove));
        $this->sync();
        return $this;
    }

    /**
     * Set the permission callback or capability.
     */
    public function permission(string | \Closure $permission): static
    {
        $this->permission = $permission;
        $this->sync();
        return $this;
    }

    /**
     * Require authentication.
     */
    public function requireAuth(): static
    {
        return $this->middleware('auth');
    }

    /**
     * Require a specific capability.
     */
    public function can(string $capability): static
    {
        $this->permission = $capability;
        $this->sync();
        return $this;
    }

    /**
     * Sync route updates with router storage.
     */
    protected function sync(): void {
        if ($this->onChange !== null) {
            ($this->onChange)($this);
        }
    }

    /**
     * Get the route method.
     */
    public function getMethod(): string {
        return $this->method;
    }

    /**
     * Get the route URI.
     */
    public function getUri(): string {
        return $this->uri;
    }

    /**
     * Get the route action.
     *
     * @return array<string, mixed>
     */
    public function getAction(): array {
        return $this->action;
    }

    /**
     * Get the route name.
     */
    public function getName(): ?string {
        return $this->name;
    }

    /**
     * Get the route middleware.
     *
     * @return array<string>
     */
    public function getMiddleware(): array {
        return $this->middlewareList;
    }

    /**
     * Convert route to array for storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array {
        return [
            'method'     => $this->method,
            'uri'        => $this->uri,
            'uses'       => $this->action['uses'] ?? null,
            'middleware' => $this->middlewareList,
            'name'       => $this->name,
            'permission' => $this->permission
        ];
    }
}
