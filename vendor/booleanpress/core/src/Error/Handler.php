<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Error;

use BooleanSmtp\Core\Http\JsonResponse;
use BooleanSmtp\Core\Http\Request;
use BooleanSmtp\Core\Http\Response;
use BooleanSmtp\Core\Foundation\Application;
use Throwable;

class Handler
{
    /**
     * The application instance.
     */
    protected Application $app;

    /**
     * Exceptions that are an answer to the caller rather than a fault: invalid input, a missing
     * record, a refused action. They are rendered, never reported.
     *
     * @since 0.2.11
     *
     * @var list<class-string<Throwable>>
     */
    protected array $dontReport = [
        \BooleanSmtp\Core\Exceptions\ValidationException::class,
        \BooleanSmtp\Core\Exceptions\ModelNotFoundException::class,
        \BooleanSmtp\Core\Exceptions\AuthorizationException::class,
    ];

    /**
     * Create a new exception handler instance.
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Report an exception: to the `core` log channel when a site has switched it on, otherwise to
     * PHP's error log as before — the framework writes no file of its own by default. Exceptions
     * listed in {@see $dontReport} are not reported.
     *
     * @since 0.2.11 Skips the exceptions listed in `$dontReport`.
     */
    public function report(Throwable $e): void
    {
        foreach ($this->dontReport as $class) {
            if ($e instanceof $class) {
                return;
            }
        }

        try {
            $logger = $this->app->make(\BooleanSmtp\Core\Log\Logger::class);
            if ($logger->isEnabled()) {
                $logger->error($e->getMessage(), ['exception' => $e]);
                return;
            }
        } catch (\Throwable) {
            // intentionally silent: the logger itself failed; PHP's error log below still records the exception.
        }

        error_log('BooleanPress Error: ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the core log channel is off; PHP's own log keeps the report.
    }

    /**
     * Render an exception into an HTTP response.
     */
    public function render(Request $request, Throwable $e): Response|JsonResponse
    {
        // Force JSON response for REST API requests, AJAX, or JSON-expecting clients
        if ($request->expectsJson() || $this->isRestRequest() || $this->isRestPath($request)) {
            return $this->renderJson($e);
        }

        // Render HTML error page
        // For now, simple output, but could load a view
        return new Response(
            $this->renderHtml($e),
            $this->getStatusCode($e)
        );
    }

    /**
     * Determine if the current request is a REST API request.
     *
     * @since 0.2.11 Asks WordPress instead of reading the request URI.
     */
    protected function isRestRequest(): bool
    {
        return (\defined('REST_REQUEST') && REST_REQUEST)
            || (\function_exists('wp_is_json_request') && wp_is_json_request());
    }

    /**
     * Whether the request being answered is addressed to the REST API: a request built by
     * {@see Request::fromWpRest()} carries the REST path, including on an internal dispatch
     * (`rest_do_request()`) where WordPress defines no `REST_REQUEST`.
     *
     * @since 0.2.11
     */
    protected function isRestPath(Request $request): bool
    {
        $prefix = \function_exists('rest_get_url_prefix') ? trim((string) rest_get_url_prefix(), '/') : 'wp-json';

        return str_starts_with($request->path(), '/' . $prefix . '/');
    }

    /**
     * Render JSON error response.
     */
    protected function renderJson(Throwable $e): JsonResponse
    {
        // ValidationException gets special treatment
        if ($e instanceof \BooleanSmtp\Core\Exceptions\ValidationException) {
            return JsonResponse::validationError($e->errors(), $e->getMessage());
        }

        $payload = [
            'success' => false,
            'message' => $e->getMessage() ?: 'Internal Server Error',
        ];

        if ($this->app->isDebug()) {
            $payload['exception'] = get_class($e);
            $payload['file'] = $e->getFile();
            $payload['line'] = $e->getLine();
            $payload['trace'] = explode("\n", $e->getTraceAsString());
        }

        return new JsonResponse($payload, $this->getStatusCode($e));
    }

    /**
     * Render HTML error page.
     */
    protected function renderHtml(Throwable $e): string
    {
        $statusCode = $this->getStatusCode($e);
        $message = $e->getMessage();

        if (!$this->app->isDebug()) {
            $message = match ($statusCode) {
                404 => 'Not Found',
                403 => 'Forbidden',
                500 => 'Server Error',
                default => 'An error occurred',
            };
        }

        return "
            <!DOCTYPE html>
            <html>
            <head><title>Error {$statusCode}</title></head>
            <body style='font-family: sans-serif; text-align: center; padding: 50px;'>
                <h1>Error {$statusCode}</h1>
                <p>{$message}</p>
            </body>
            </html>
        ";
    }

    /**
     * Get the HTTP status code for the exception.
     */
    protected function getStatusCode(Throwable $e): int
    {
        $code = $e->getCode();

        if ($code >= 400 && $code < 600) {
            return $code;
        }

        return 500;
    }
}
