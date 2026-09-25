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
     * Create a new exception handler instance.
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Report an exception: to the `core` log channel when a site has switched it on, otherwise to
     * PHP's error log as before — the framework writes no file of its own by default.
     */
    public function report(Throwable $e): void
    {
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
        if ($request->expectsJson() || $this->isRestRequest()) {
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
     */
    protected function isRestRequest(): bool
    {
        return (\defined('REST_REQUEST') && REST_REQUEST)
            || str_contains($_SERVER['REQUEST_URI'] ?? '', '/wp-json/');
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
