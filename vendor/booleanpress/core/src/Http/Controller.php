<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Http;

use BooleanSmtp\Core\Container\Container;

/**
 * Base Controller
 *
 * Provides common functionality for all controllers including
 * response helpers, validation, and authorization.
 */
abstract class Controller
{
    /**
     * The container instance.
     *
     * Nullable so subclasses that omit parent::__construct() do not leave an uninitialized typed property.
     */
    protected ?Container $container = null;

    /**
     * Create a new controller instance.
     */
    public function __construct(?Container $container = null)
    {
        $this->container = $container ?? Container::getInstance();
    }

    /**
     * Resolve the container (lazy fallback when a child constructor does not call parent).
     */
    protected function getContainer(): Container
    {
        return $this->container ??= Container::getInstance();
    }

    /**
     * Create a response.
     */
    protected function response(string $content = '', int $status = 200, array $headers = []): Response
    {
        return new Response($content, $status, $headers);
    }

    /**
     * Create a JSON response.
     */
    protected function json(mixed $data = null, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, $headers);
    }

    /**
     * Create a success JSON response.
     */
    protected function success(mixed $data = null, string $message = 'Success'): JsonResponse
    {
        return JsonResponse::success($data, $message);
    }

    /**
     * Success JSON response (alias used by app controllers).
     */
    protected function ok(mixed $data = null, string $message = 'Success'): JsonResponse
    {
        return JsonResponse::success($data, $message);
    }

    /**
     * Create an error JSON response.
     */
    protected function error(string $message, int $status = 400, mixed $errors = null): JsonResponse
    {
        return JsonResponse::error($message, $status, $errors);
    }

    /**
     * Create a not found response.
     */
    protected function notFound(string $message = 'Resource not found'): JsonResponse
    {
        return JsonResponse::notFound($message);
    }

    /**
     * Create an unauthorized response.
     */
    protected function unauthorized(string $message = 'Unauthorized'): JsonResponse
    {
        return JsonResponse::unauthorized($message);
    }

    /**
     * Create a forbidden response.
     */
    protected function forbidden(string $message = 'Forbidden'): JsonResponse
    {
        return JsonResponse::forbidden($message);
    }

    /**
     * Create a validation error response.
     *
     * @param array<string, array<string>> $errors
     */
    protected function validationError(array $errors, string $message = 'Validation failed'): JsonResponse
    {
        return JsonResponse::validationError($errors, $message);
    }

    /**
     * Create a created response.
     */
    protected function created(mixed $data = null, string $message = 'Created'): JsonResponse
    {
        return JsonResponse::success($data, $message)->setStatusCode(201);
    }

    /**
     * Create a no content response.
     */
    protected function noContent(): JsonResponse
    {
        return new JsonResponse(null, 204);
    }

    /**
     * Create a safe redirect response (local only).
     *
     * Uses wp_safe_redirect() to prevent Open Redirect vulnerabilities.
     */
    protected function safeRedirect(string $url, int $status = 302): Response
    {
        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect($url, $status);
            // We still return a Response object for consistency, though WP handles the header
            return (new Response('', $status))->header('Location', $url);
        }

        return $this->redirect($url, $status);
    }

    /**
     * Create a redirect response.
     *
     * WARNING: This method allows external redirects. Use safeRedirect() for local paths
     * to prevent Open Redirect vulnerabilities.
     */
    protected function redirect(string $url, int $status = 302): Response
    {
        return (new Response('', $status))->header('Location', $url);
    }

    /**
     * Validate the request.
     *
     * @param Request $request
     * @param array<string, string|array<string>> $rules
     * @return array<string, mixed>
     * @throws ValidationException
     */
    protected function validate(Request $request, array $rules): array
    {
        return $request->validate($rules);
    }

    /**
     * Check if the current user has a capability.
     */
    protected function authorize(string $capability): bool
    {
        if (function_exists('current_user_can')) {
            return current_user_can($capability);
        }
        return false;
    }

    /**
     * Authorize or throw an exception.
     */
    protected function authorizeOrFail(string $capability, string $message = 'Unauthorized'): void
    {
        if (!$this->authorize($capability)) {
            throw new \RuntimeException($message);
        }
    }

    /**
     * Get a service from the container.
     */
    protected function make(string $abstract, array $parameters = []): mixed
    {
        return $this->getContainer()->make($abstract, $parameters);
    }

    /**
     * Call a method with automatic dependency injection.
     */
    protected function call(callable|string $callback, array $parameters = []): mixed
    {
        return $this->getContainer()->call($callback, $parameters);
    }

    /**
     * Dispatch an event.
     */
    protected function dispatch(object|string $event, mixed $payload = []): ?array
    {
        $app = $this->getContainer();

        if (!$app->bound('events')) {
            return null;
        }

        return $app->make('events')->dispatch($event, $payload);
    }

    /**
     * Get the current user.
     */
    protected function user(): ?\WP_User
    {
        if (function_exists('wp_get_current_user')) {
            $user = wp_get_current_user();
            return $user->exists() ? $user : null;
        }
        return null;
    }

    /**
     * Get the current user ID.
     */
    protected function userId(): int
    {
        return $this->user()?->ID ?? 0;
    }
}
