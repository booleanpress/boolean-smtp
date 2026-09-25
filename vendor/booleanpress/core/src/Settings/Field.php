<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Settings;

class Field
{
    protected string $type;
    protected string $key;
    protected string $label;
    protected mixed $default = null;
    protected array $options = [];
    protected string $description = '';

    public function __construct(string $type, string $key, string $label)
    {
        $this->type = $type;
        $this->key = $key;
        $this->label = $label;
    }

    public function default(mixed $value): self
    {
        $this->default = $value;
        return $this;
    }

    public function options(array $options): self
    {
        $this->options = $options;
        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'key' => $this->key,
            'label' => $this->label,
            'default' => $this->default,
            'options' => $this->options,
            'description' => $this->description,
        ];
    }
}
