<?php

declare (strict_types = 1);

namespace BooleanSmtp\Core\Http;

use BooleanSmtp\Core\Container\Container;
use Closure;

/**
 * Router
 *
 * A lightweight router for WordPress REST API and admin AJAX integration.
 * Supports route groups, middleware, and parameter binding.
 */
class Router {
    /**
     * The container instance.
     */
    protected Container $container;

    /**
     * The registered routes.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    protected array $routes = [];

    /**
     * The route group stack.
     *
     * @var array<array<string, mixed>>
     */
    protected array $groupStack = [];

    /**
     * The current namespace.
     */
    protected string $namespace = '';

    /**
     * The REST API namespace.
     */
    protected string $apiNamespace = 'booleanpress/v1';

    /**
     * Registered middleware.
     *
     * @var array<string, class-string|Closure>
     */
    protected array $middlewareAliases = [];

    /**
     * Capability required by routes that use the "auth" middleware but declare
     * no permission of their own. Applies to every HTTP method.
     */
    protected string $defaultCapability = 'manage_options';

    /**
     * Create a new router instance.
     */
    public function __construct(?Container $container = null) {
        $this->container = $container ?? Container::getInstance();
    }

    /**
     * Set the capability required by "auth" routes that declare no permission.
     */
    public function setDefaultCapability(string $capability): static {
        $this->defaultCapability = $capability;

        return $this;
    }

    /**
     * Get the capability required by "auth" routes that declare no permission.
     */
    public function getDefaultCapability(): string {
        return $this->defaultCapability;
    }

    /**
     * Set the REST API namespace.
     */
    public function setApiNamespace(string $namespace): static
    {
        $this->apiNamespace = $namespace;
        return $this;
    }

    /**
     * Get the REST API namespace.
     */
    public function getApiNamespace(): string {
        return $this->apiNamespace;
    }

    /**
     * Register a middleware alias.
     */
    public function aliasMiddleware(string $name, string | Closure $middleware): static
    {
        $this->middlewareAliases[$name] = $middleware;
        return $this;
    }

    /**
     * Register a middleware group.
     */
    public function middlewareGroup(string $name, array $middleware): static
    {
        $this->middlewareAliases[$name] = $middleware;
        return $this;
    }

    /**
     * Register a fallback route (Catch-All) for SPA.
     *
     * @param string|array|Closure $action
     */
    public function fallback(string | array | Closure $action): Route {
        $placeholder = 'fallback_placeholder';
        $uri         = '{' . $placeholder . '}';

        $route = $this->addRoute('GET', $uri, $action);

        // Ensure it matches everything by using a permissive regex for the parameter
        // In WP REST API, we might need a custom regex or handle this via standard WP rewrite rules for non-REST SPA
        // For REST-based SPA (headless), this catches API 404s.
        // For standard WP admin pages, `Core\Admin\Page` handles the mounting.

        return $route;
    }

    /**
     * Register a GET route.
     */
    public function get(string $uri, string | array | Closure $action): Route {
        return $this->addRoute('GET', $uri, $action);
    }

    /**
     * Register a POST route.
     */
    public function post(string $uri, string | array | Closure $action): Route {
        return $this->addRoute('POST', $uri, $action);
    }

    /**
     * Register a PUT route.
     */
    public function put(string $uri, string | array | Closure $action): Route {
        return $this->addRoute('PUT', $uri, $action);
    }

    /**
     * Register a PATCH route.
     */
    public function patch(string $uri, string | array | Closure $action): Route {
        return $this->addRoute('PATCH', $uri, $action);
    }

    /**
     * Register a DELETE route.
     */
    public function delete(string $uri, string | array | Closure $action): Route {
        return $this->addRoute('DELETE', $uri, $action);
    }

    /**
     * Register an OPTIONS route.
     */
    public function options(string $uri, string | array | Closure $action): Route {
        return $this->addRoute('OPTIONS', $uri, $action);
    }

    /**
     * Register a route for multiple methods.
     *
     * @param array<string> $methods
     */
    public function match(array $methods, string $uri, string | array | Closure $action): Route {
        $route = null;
        foreach ($methods as $method) {
            $route = $this->addRoute(strtoupper($method), $uri, $action);
        }
        return $route;
    }

    /**
     * Register a route that responds to all HTTP methods.
     */
    public function any(string $uri, string | array | Closure $action): Route {
        return $this->match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], $uri, $action);
    }

    /**
     * Add a route to the router.
     */
    protected function addRoute(string $method, string $uri, string | array | Closure $action): Route {
        $uri    = $this->prefixUri($uri);
        $action = $this->parseAction($action);

        $route = new Route($method, $uri, $action, function (Route $updatedRoute) use ($method, $uri): void {
            $this->routes[$method][$uri] = $updatedRoute->toArray();
        });

        // Apply group middleware and the group's default permission (a route's
        // own ->can()/->permission() call replaces the latter).
        if (!empty($this->groupStack)) {
            $groupMiddleware = $this->getGroupMiddleware();
            $route->middleware($groupMiddleware);

            $groupPermission = $this->getGroupPermission();
            if ($groupPermission !== null) {
                $route->permission($groupPermission);
            }
        }

        $this->routes[$method][$uri] = $route->toArray();

        return $route;
    }

    /**
     * Parse the route action.
     *
     * @return array<string, mixed>
     */
    protected function parseAction(string | array | Closure $action): array {
        if ($action instanceof Closure) {
            return ['uses' => $action];
        }

        if (is_string($action)) {
            return ['uses' => $this->prependNamespace($action)];
        }

        if (isset($action[0]) && is_string($action[0])) {
            $controller = $this->prependNamespace($action[0]);
            $method     = $action[1] ?? '__invoke';
            return ['uses' => "{$controller}@{$method}"];
        }

        return $action;
    }

    /**
     * Prepend the namespace to a controller name.
     *
     * Skips prepending if:
     * - No namespace is set
     * - Controller starts with '\' (explicit FQCN)
     * - Controller already starts with the current namespace
     * - Controller already exists as a fully-qualified class
     */
    protected function prependNamespace(string $controller): string {
        if (empty($this->namespace)) {
            return $controller;
        }

        // Explicit FQCN with leading backslash
        if (str_starts_with($controller, '\\')) {
            return ltrim($controller, '\\');
        }

        // Already starts with the target namespace
        $ns = rtrim($this->namespace, '\\') . '\\';
        if (str_starts_with($controller, $ns)) {
            return $controller;
        }

        // Class already exists as-is (::class resolution produces FQCN without '\')
        if (class_exists($controller)) {
            return $controller;
        }

        return $ns . $controller;
    }

    /**
     * Get the prefix for URIs.
     */
    protected function prefixUri(string $uri): string {
        $prefix = '';

        foreach ($this->groupStack as $group) {
            if (isset($group['prefix'])) {
                $prefix .= '/' . trim($group['prefix'], '/');
            }
        }

        $uri = trim($uri, '/');
        return $prefix ? trim($prefix . '/' . $uri, '/') : $uri;
    }

    /**
     * Get middleware from all groups.
     *
     * @return array<string>
     */
    protected function getGroupMiddleware(): array {
        $middleware = [];

        foreach ($this->groupStack as $group) {
            if (isset($group['middleware'])) {
                $middleware = array_merge($middleware, (array) $group['middleware']);
            }
        }

        return array_unique($middleware);
    }

    /**
     * Get the permission set on the innermost group that declares one.
     */
    protected function getGroupPermission(): string|Closure|null {
        foreach (array_reverse($this->groupStack) as $group) {
            if (isset($group['permission'])) {
                return $group['permission'];
            }
        }

        return null;
    }

    /**
     * Create a route group.
     *
     * @param array<string, mixed> $attributes
     */
    public function group(array $attributes, Closure $callback): void {
        // Save current namespace
        $previousNamespace = $this->namespace;

        // Update namespace if provided
        if (isset($attributes['namespace'])) {
            $this->namespace = $this->prependNamespace($attributes['namespace']);
        }

        $this->groupStack[] = $attributes;

        $callback($this);

        array_pop($this->groupStack);

        // Restore namespace
        $this->namespace = $previousNamespace;
    }

    /**
     * Create a route group with a prefix.
     */
    public function prefix(string $prefix): RouteGroup {
        return new RouteGroup($this, ['prefix' => $prefix]);
    }

    /**
     * Create a route group with middleware.
     */
    public function middleware(string | array $middleware): RouteGroup {
        return new RouteGroup($this, ['middleware' => (array) $middleware]);
    }

    /**
     * Create a route group with a namespace.
     */
    public function namespace(string $namespace): RouteGroup {
        return new RouteGroup($this, ['namespace' => $namespace]);
    }

    /**
     * Register routes with WordPress REST API.
     */
    public function registerRestRoutes(): void {
        if (!function_exists('register_rest_route')) {
            return;
        }

        foreach ($this->routes as $method => $routes) {
            foreach ($routes as $uri => $route) {
                $this->registerRestRoute($method, $uri, $route);
            }
        }
    }

    /**
     * Register a single REST route.
     *
     * @param array<string, mixed> $route
     */
    protected function registerRestRoute(string $method, string $uri, array $route): void {
        // Convert URI parameters to WordPress REST format
        $restUri = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $uri);

        register_rest_route($this->apiNamespace, '/' . $restUri, [
            'methods'             => $method,
            'callback'            => function (\WP_REST_Request $wpRequest) use ($route) {
                return $this->handleRestRequest($wpRequest, $route);
            },
            'permission_callback' => function (\WP_REST_Request $wpRequest) use ($route) {
                return $this->checkPermission($wpRequest, $route);
            }
        ]);
    }

    /**
     * Handle a REST API request.
     *
     * @param array<string, mixed> $route
     */
    protected function handleRestRequest(\WP_REST_Request $wpRequest, array $route): mixed {
        // Try to get the exception handler from container
        $handler = null;
        if ($this->container->has(\BooleanSmtp\Core\Error\Handler::class)) {
            $handler = $this->container->make(\BooleanSmtp\Core\Error\Handler::class);
        }

        try {
            // Create our Request from WP_REST_Request
            $request = $this->createRequestFromWpRest($wpRequest);

            // Run middleware
            if (!empty($route['middleware'])) {
                foreach ($route['middleware'] as $middleware) {
                    $result = $this->runMiddleware($middleware, $request);
                    if ($result !== true) {
                        return $this->convertResponse($result);
                    }
                }
            }

            // Resolve and call the action
            $action = $route['uses'];

            $response = $this->callAction($action, $request);

            // Convert response for WordPress
            return $this->convertResponse($response);
        } catch (\Throwable $e) {
            // If we have a handler, let it handle the reporting and rendering
            if ($handler) {
                $handler->report($e);
                return $this->convertResponse(
                    $handler->render($this->createRequestFromWpRest($wpRequest), $e)
                );
            }

            // Fallback to manual handling if no handler registered
            if ($e instanceof ValidationException) {
                return $this->convertResponse(
                    JsonResponse::validationError($e->errors(), $e->getMessage())
                );
            }

            if ($e instanceof \RuntimeException) {
                if (
                    str_contains(strtolower($e->getMessage()), 'unauthorized') ||
                    str_contains(strtolower($e->getMessage()), 'forbidden')
                ) {
                    return $this->convertResponse(JsonResponse::forbidden($e->getMessage()));
                }
            }

            $this->reportWithoutHandler($e);

            return $this->convertResponse(
                JsonResponse::error($e->getMessage() ?: 'Internal Server Error', 500)
            );
        }
    }

    /**
     * Report an exception when no error handler is bound: to the `core` log channel when a site has
     * switched it on. The response already carries the message, so nothing else is written.
     *
     * @since 0.2.11
     */
    protected function reportWithoutHandler(\Throwable $e): void {
        try {
            $logger = $this->container->make(\BooleanSmtp\Core\Log\Logger::class);
            if ($logger->isEnabled()) {
                $logger->error($e->getMessage(), ['exception' => $e]);
            }
        } catch (\Throwable) {
            // intentionally silent: without a logger there is nowhere of the framework's own to report to.
        }
    }

    /**
     * Create a Request from WP_REST_Request.
     */
    protected function createRequestFromWpRest(\WP_REST_Request $wpRequest): Request {
        return Request::fromWpRest($wpRequest);
    }

    /**
     * Check permission for a route.
     *
     * @param array<string, mixed> $route
     */
    protected function checkPermission(\WP_REST_Request $wpRequest, array $route): bool {
        if (isset($route['permission'])) {
            $permission = $route['permission'];

            if (is_string($permission)) {
                if (!function_exists('current_user_can')) {
                    return false;
                }

                return current_user_can($permission);
            }

            if ($permission instanceof Closure) {
                return (bool) $permission($wpRequest);
            }

            return false;
        }

        // No explicit permission but the route is behind the "auth" middleware: require the
        // router's default capability for every method, reads included. REST runs this callback
        // before the route handler and before our middleware stack, so it is the only place that
        // reliably keeps a logged-in user without the capability away from admin data.
        if ($this->routeUsesAuthMiddleware($route)) {
            if (!function_exists('current_user_can')) {
                return false;
            }

            return current_user_can($this->defaultCapability);
        }

        // Route explicitly opted out of 'auth' via withoutMiddleware('auth') -- e.g. a public
        // provider webhook that can't present WordPress authentication (see Route::withoutMiddleware()'s
        // own docblock). That opt-out is meaningless if this callback still denies it: WordPress's
        // REST dispatcher rejects the request here, before handleRestRequest() and our own
        // middleware stack ever run, regardless of what the route's middleware list says. Let it
        // through; whatever authorization the route needs (e.g. verifying a provider's payload
        // signature) is that route's own responsibility, not this framework's.
        return true;
    }

    /**
     * Whether the route uses the "auth" middleware alias (typically admin REST routes).
     *
     * @param array<string, mixed> $route
     */
    protected function routeUsesAuthMiddleware(array $route): bool
    {
        $stack = $route['middleware'] ?? [];

        return $this->middlewareStackContainsAuth($stack);
    }

    /**
     * @param array<int|string, mixed>|string $middleware
     */
    protected function middlewareStackContainsAuth(array|string $middleware): bool
    {
        if (is_string($middleware)) {
            if ($middleware === 'auth') {
                return true;
            }
            if (isset($this->middlewareAliases[$middleware])) {
                $resolved = $this->middlewareAliases[$middleware];

                return is_array($resolved)
                    ? $this->middlewareStackContainsAuth($resolved)
                    : false;
            }

            return false;
        }

        foreach ($middleware as $m) {
            if ($this->middlewareStackContainsAuth($m)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run a middleware.
     */
    protected function runMiddleware(string | array | Closure $middleware, Request $request): mixed {
        // Recursively handle groups (arrays)
        if (is_array($middleware)) {
            foreach ($middleware as $m) {
                $result = $this->runMiddleware($m, $request);
                if ($result !== true) {
                    return $result;
                }
            }
            return true;
        }

        // Resolve middleware alias
        if (is_string($middleware) && isset($this->middlewareAliases[$middleware])) {
            $alias = $this->middlewareAliases[$middleware];

            // If alias points to an array (group), handle recursively
            if (is_array($alias)) {
                return $this->runMiddleware($alias, $request);
            }

            $middleware = $alias;
        }

        if ($middleware instanceof Closure) {
            return $middleware($request);
        }

        if (is_string($middleware) && class_exists($middleware)) {
            $instance = $this->container->make($middleware);
            return $instance->handle($request, fn() => true);
        }

        return true;
    }

    /**
     * Convert a response for WordPress REST API.
     */
    protected function convertResponse(mixed $response): mixed {
        if ($response instanceof JsonResponse) {
            return new \WP_REST_Response($response->getData(), $response->getStatusCode());
        }

        if ($response instanceof Response) {
            $wp = new \WP_REST_Response($response->getContent(), $response->getStatusCode());
            foreach ($response->getHeaders() as $name => $value) {
                if (\is_array($value)) {
                    foreach ($value as $v) {
                        $wp->header($name, $v);
                    }
                } else {
                    $wp->header($name, $value);
                }
            }

            return $wp;
        }

        if (is_array($response) || is_object($response)) {
            return new \WP_REST_Response($response);
        }

        return $response;
    }

    /**
     * Get all registered routes.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function getRoutes(): array {
        return $this->routes;
    }

    /**
     * Clear all routes.
     */
    public function flush(): void {
        $this->routes     = [];
        $this->groupStack = [];
    }

    /**
     * Register a resourceful route for a controller.
     *
     * Creates standard CRUD routes:
     * - GET    /resource          -> index
     * - GET    /resource/{id}     -> show
     * - POST   /resource          -> store
     * - PUT    /resource/{id}     -> update
     * - DELETE /resource/{id}     -> destroy
     *
     * @param array<string>|null $only Limit to specific actions
     * @param array<string>|null $except Exclude specific actions
     */
    public function resource(string $name, string $controller, ?array $only = null, ?array $except = null): void {
        $resourceRoutes = [
            'index'   => ['GET', $name],
            'show'    => ['GET', $name . '/(?P<id>\d+)'],
            'store'   => ['POST', $name],
            'update'  => ['PUT', $name . '/(?P<id>\d+)'],
            'destroy' => ['DELETE', $name . '/(?P<id>\d+)']
        ];

        foreach ($resourceRoutes as $action => $route) {
            // Apply only/except filters
            if ($only !== null && !in_array($action, $only, true)) {
                continue;
            }
            if ($except !== null && in_array($action, $except, true)) {
                continue;
            }

            [$method, $uri] = $route;
            $this->addRoute($method, $uri, [$controller, $action]);
        }
    }

    /**
     * Register an API resource (same as resource but without create/edit form routes).
     *
     * @param array<string>|null $only
     * @param array<string>|null $except
     */
    public function apiResource(string $name, string $controller, ?array $only = null, ?array $except = null): void {
        $this->resource($name, $controller, $only, $except);
    }

    /**
     * Invoke a route action (closure or "Controller@method") with the request injected.
     *
     * A parameter type-hinted with a FormRequest subclass receives a copy of the current
     * request that has been authorized and validated first; the exceptions that raises are
     * left to the caller (Error\Handler in the REST path).
     *
     * @param Closure|string $action
     */
    protected function callAction(Closure|string $action, Request $request): mixed {
        if ($action instanceof Closure) {
            $reflector = new \ReflectionFunction($action);

            return $this->container->call($action, $this->actionParameters($reflector, $request));
        }

        [$controller, $method] = explode('@', $action);
        $controllerInstance    = $this->container->make($controller);
        $reflector             = new \ReflectionMethod($controllerInstance, $method);

        return $this->container->call([$controllerInstance, $method], $this->actionParameters($reflector, $request));
    }

    /**
     * Build the named parameters for an action: the plain request plus every FormRequest
     * parameter, created from that request and validated.
     *
     * @return array<string, mixed>
     */
    protected function actionParameters(\ReflectionFunctionAbstract $reflector, Request $request): array {
        $parameters = ['request' => $request];

        foreach ($reflector->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $class = $type->getName();

            if (!is_subclass_of($class, FormRequest::class)) {
                continue;
            }

            /** @var FormRequest $formRequest */
            $formRequest = $class::createFrom($request);
            $formRequest->validateResolved();

            $parameters[$parameter->getName()] = $formRequest;
        }

        return $parameters;
    }

    /**
     * Dispatch the request to the appropriate route.
     */
    public function dispatch(Request $request): mixed {
        $method = $request->method();
        // Stored route keys are group-relative and never carry a leading slash
        // (see prefixUri()); match against the same shape.
        $uri = trim($request->path(), '/');

        foreach ($this->routes[$method] ?? [] as $routeUri => $route) {
            $pattern = preg_replace('/\{(\w+)\}/', '([^/]+)', $routeUri);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $uri, $matches)) {
                array_shift($matches);
                preg_match_all('/\{(\w+)\}/', $routeUri, $paramNames);
                $params = array_combine($paramNames[1], $matches);

                $request->setRouteParams($params);

                if (!empty($route['middleware'])) {
                    $result = $this->runMiddleware($route['middleware'], $request);
                    if ($result !== true) {
                        return $result;
                    }
                }

                return $this->callAction($route['uses'], $request);
            }
        }

        throw new \RuntimeException('Route not found', 404);
    }
}
