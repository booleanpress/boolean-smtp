<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Http;

/**
 * HTTP Response
 *
 * Represents an HTTP response with content, status code, and headers.
 */
class Response
{
    /**
     * HTTP status codes and their messages.
     */
    protected const STATUS_TEXTS = [
        200 => 'OK',
        201 => 'Created',
        204 => 'No Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        304 => 'Not Modified',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
    ];

    /**
     * The response content.
     */
    protected string $content;

    /**
     * The HTTP status code.
     */
    protected int $statusCode;

    /**
     * The response headers.
     *
     * @var array<string, string|array<string>>
     */
    protected array $headers = [];

    /**
     * Create a new response instance.
     *
     * @param string $content Response body
     * @param int $status HTTP status code
     * @param array<string, string|array<string>> $headers Response headers
     */
    public function __construct(string $content = '', int $status = 200, array $headers = [])
    {
        $this->content = $content;
        $this->statusCode = $status;
        $this->headers = $headers;
    }

    /**
     * Create a new response instance.
     */
    public static function make(string $content = '', int $status = 200, array $headers = []): static
    {
        return new static($content, $status, $headers);
    }

    /**
     * Get the response content.
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Set the response content.
     */
    public function setContent(string $content): static
    {
        $this->content = $content;
        return $this;
    }

    /**
     * Get the HTTP status code.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Set the HTTP status code.
     */
    public function setStatusCode(int $code): static
    {
        $this->statusCode = $code;
        return $this;
    }

    /**
     * Get all headers.
     *
     * @return array<string, string|array<string>>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Get a specific header.
     */
    public function getHeader(string $name): string|array|null
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Set a header.
     */
    public function header(string $name, string|array $value): static
    {
        $this->headers[strtolower($name)] = $value;
        return $this;
    }

    /**
     * Add multiple headers.
     *
     * @param array<string, string|array<string>> $headers
     */
    public function withHeaders(array $headers): static
    {
        foreach ($headers as $name => $value) {
            $this->header($name, $value);
        }
        return $this;
    }

    /**
     * Set the Content-Type header.
     */
    public function contentType(string $type, ?string $charset = null): static
    {
        $value = $type;
        if ($charset !== null) {
            $value .= "; charset={$charset}";
        }
        return $this->header('Content-Type', $value);
    }

    /**
     * Set cache control headers.
     */
    public function cache(int $seconds, bool $public = true): static
    {
        $visibility = $public ? 'public' : 'private';
        return $this->header('Cache-Control', "{$visibility}, max-age={$seconds}");
    }

    /**
     * Set no-cache headers.
     */
    public function noCache(): static
    {
        return $this->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    /**
     * Send the response to the client.
     */
    public function send(): void
    {
        $this->sendHeaders();
        $this->sendContent();
    }

    /**
     * Send the response headers.
     */
    protected function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        // Send status line
        $statusText = self::STATUS_TEXTS[$this->statusCode] ?? 'Unknown';
        header("HTTP/1.1 {$this->statusCode} {$statusText}", true, $this->statusCode);

        // Send headers
        foreach ($this->headers as $name => $value) {
            $name = implode('-', array_map('ucfirst', explode('-', $name)));
            if (is_array($value)) {
                foreach ($value as $v) {
                    header("{$name}: {$v}", false);
                }
            } else {
                header("{$name}: {$value}");
            }
        }
    }

    /**
     * Send the response content.
     */
    protected function sendContent(): void
    {
        echo $this->content;
    }

    /**
     * Check if response is successful.
     */
    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Check if response is redirect.
     */
    public function isRedirect(): bool
    {
        return in_array($this->statusCode, [301, 302, 303, 307, 308]);
    }

    /**
     * Check if response is client error.
     */
    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    /**
     * Check if response is server error.
     */
    public function isServerError(): bool
    {
        return $this->statusCode >= 500 && $this->statusCode < 600;
    }
}
