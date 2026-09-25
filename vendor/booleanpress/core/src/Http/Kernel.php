<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Http;

use BooleanSmtp\Core\Foundation\Application;

class Kernel
{
    /**
     * The application implementation.
     */
    protected Application $app;

    /**
     * The router instance.
     */
    protected Router $router;

    /**
     * The application's global HTTP middleware stack.
     */
    protected array $middleware = [];

    /**
     * The application's route middleware groups.
     */
    protected array $middlewareGroups = [
        'web' => [],
        'api' => [],
    ];

    /**
     * The application's route middleware aliases.
     */
    protected array $routeMiddleware = [];

    /**
     * Create a new HTTP kernel instance.
     */
    public function __construct(Application $app, Router $router)
    {
        $this->app = $app;
        $this->router = $router;

        $this->syncMiddlewareToRouter();
    }

    /**
     * Sync middleware to the router.
     */
    protected function syncMiddlewareToRouter(): void
    {
        foreach ($this->routeMiddleware as $key => $middleware) {
            $this->router->aliasMiddleware($key, $middleware);
        }

        foreach ($this->middlewareGroups as $key => $middleware) {
            $this->router->middlewareGroup($key, $middleware);
        }
    }

    /**
     * Handle an incoming HTTP request.
     *
     * Note: In WordPress, this is often handled via hooks,
     * but we can provide this for custom entry points.
     */
    public function handle(Request $request): Response
    {
        // 1. Run global middleware
        // 2. Dispatch to router

        return $this->router->dispatch($request);
    }
}
