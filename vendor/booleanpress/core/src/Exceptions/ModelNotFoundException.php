<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Exceptions;

/**
 * Model Not Found Exception
 *
 * Thrown when a model cannot be found in the database.
 */
class ModelNotFoundException extends BooleanPressException
{
    /**
     * The model class name.
     */
    protected string $model = '';

    /**
     * The affected model IDs.
     *
     * @var array<int|string>
     */
    protected array $ids = [];

    /**
     * @param string          $message
     * @param int             $code     HTTP status the error handler renders (404).
     * @param \Throwable|null $previous
     */
    public function __construct(string $message = 'No query results for model.', int $code = 404, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Set the affected model and IDs.
     *
     * @param string $model
     * @param array<int|string>|int|string $ids
     * @return static
     */
    public function setModel(string $model, array|int|string $ids = []): static
    {
        $this->model = $model;
        $this->ids = is_array($ids) ? $ids : [$ids];

        $this->message = "No query results for model [{$model}]";

        if (!empty($this->ids)) {
            $this->message .= ' ' . implode(', ', $this->ids);
        }

        return $this;
    }

    /**
     * Get the affected model class.
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Get the affected model IDs.
     *
     * @return array<int|string>
     */
    public function getIds(): array
    {
        return $this->ids;
    }
}
