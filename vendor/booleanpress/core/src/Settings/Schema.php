<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Settings;

class Schema
{
    /**
     * The registered settings fields.
     *
     * @var array
     */
    protected array $fields = [];

    /**
     * Add a text field.
     *
     * @param  string  $key
     * @param  string  $label
     * @return Field
     */
    public function text(string $key, string $label): Field
    {
        return $this->addField('text', $key, $label);
    }

    /**
     * Add a number field.
     *
     * @param  string  $key
     * @param  string  $label
     * @return Field
     */
    public function number(string $key, string $label): Field
    {
        return $this->addField('number', $key, $label);
    }

    /**
     * Add a boolean toggle field.
     *
     * @param  string  $key
     * @param  string  $label
     * @return Field
     */
    public function boolean(string $key, string $label): Field
    {
        return $this->addField('boolean', $key, $label);
    }

    /**
     * Add a select field.
     *
     * @param  string  $key
     * @param  string  $label
     * @param  array  $options
     * @return Field
     */
    public function select(string $key, string $label, array $options): Field
    {
        $field = $this->addField('select', $key, $label);
        $field->options($options);
        return $field;
    }

    /**
     * Add a generic field.
     *
     * @param  string  $type
     * @param  string  $key
     * @param  string  $label
     * @return Field
     */
    protected function addField(string $type, string $key, string $label): Field
    {
        $field = new Field($type, $key, $label);
        $this->fields[$key] = $field;
        return $field;
    }

    /**
     * Get all registered fields.
     *
     * @return array
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * Export the schema to JSON for Vue.
     *
     * @return array
     */
    public function toArray(): array
    {
        return array_map(function (Field $field) {
            return $field->toArray();
        }, $this->fields);
    }
}
