<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Http;

/**
 * JSON Response
 *
 * An HTTP response with JSON content type and automatic JSON encoding.
 */
class JsonResponse extends Response
{
    /**
     * The JSON encoding options.
     */
    protected int $encodingOptions = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;

    /**
     * The original data before JSON encoding.
     */
    protected mixed $data;

    /**
     * Create a new JSON response.
     *
     * @param mixed $data Data to encode as JSON
     * @param int $status HTTP status code
     * @param array<string, string> $headers Response headers
     * @param int $options JSON encoding options
     */
    public function __construct(
        mixed $data = null,
        int $status = 200,
        array $headers = [],
        int $options = 0
    ) {
        $this->data = $data;

        if ($options !== 0) {
            $this->encodingOptions = $options;
        }

        parent::__construct('', $status, $headers);

        $this->setData($data);
        $this->header('Content-Type', 'application/json');
    }

    /**
     * Create a JSON response.
     *
     * @param mixed $data
     * @param int $status
     * @param array<string, string> $headers
     */
    public static function create(mixed $data = null, int $status = 200, array $headers = []): static
    {
        return new static($data, $status, $headers);
    }

    /**
     * Create a success response.
     */
    public static function success(mixed $data = null, string $message = 'Success'): static
    {
        return new static([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ]);
    }

    /**
     * Create an error response.
     */
    public static function error(string $message, int $status = 400, mixed $errors = null): static
    {
        $data = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $data['errors'] = $errors;
        }

        return new static($data, $status);
    }

    /**
     * Create a not found response.
     */
    public static function notFound(string $message = 'Resource not found'): static
    {
        return static::error($message, 404);
    }

    /**
     * Create an unauthorized response.
     */
    public static function unauthorized(string $message = 'Unauthorized'): static
    {
        return static::error($message, 401);
    }

    /**
     * Create a forbidden response.
     */
    public static function forbidden(string $message = 'Forbidden'): static
    {
        return static::error($message, 403);
    }

    /**
     * Create a validation error response.
     *
     * @param array<string, array<string>> $errors
     */
    public static function validationError(array $errors, string $message = 'Validation failed'): static
    {
        return static::error($message, 422, $errors);
    }

    /**
     * Set the data to encode.
     */
    public function setData(mixed $data): static
    {
        $this->data = $data;

        if ($data === null) {
            $this->content = '';
            return $this;
        }

        $json = json_encode($data, $this->encodingOptions);

        if ($json === false) {
            throw new \RuntimeException('Failed to encode data as JSON: ' . json_last_error_msg());
        }

        $this->content = $json;

        return $this;
    }

    /**
     * Get the original data.
     */
    public function getData(): mixed
    {
        return $this->data;
    }

    /**
     * Set JSON encoding options.
     */
    public function setEncodingOptions(int $options): static
    {
        $this->encodingOptions = $options;

        if ($this->data !== null) {
            $this->setData($this->data);
        }

        return $this;
    }

    /**
     * Enable pretty print.
     */
    public function prettyPrint(): static
    {
        return $this->setEncodingOptions($this->encodingOptions | JSON_PRETTY_PRINT);
    }

    /**
     * Create response from WordPress REST format.
     */
    public static function fromWpRest(mixed $data, int $status = 200): static
    {
        // Format compatible with WP_REST_Response
        return new static([
            'data' => $data,
            'status' => $status,
        ], $status);
    }

    /**
     * Create paginated response.
     *
     * @param array<mixed> $items
     */
    public static function paginated(
        array $items,
        int $total,
        int $perPage,
        int $currentPage
    ): static {
        $lastPage = (int) ceil($total / $perPage);

        return new static([
            'data' => $items,
            'meta' => [
                'current_page' => $currentPage,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }
}
