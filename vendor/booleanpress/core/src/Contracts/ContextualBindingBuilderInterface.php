<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Contracts;

/**
 * Contextual Binding Builder Interface
 *
 * Enables contextual dependency injection where a class can receive
 * different implementations based on where it's being injected.
 */
interface ContextualBindingBuilderInterface
{
    /**
     * Define the abstract target that depends on the context.
     *
     * @param string $abstract The abstract type needed by the context
     * @return static
     */
    public function needs(string $abstract): static;

    /**
     * Define the implementation for the contextual binding.
     *
     * @param mixed $implementation The concrete implementation
     */
    public function give(mixed $implementation): void;

    /**
     * Define tagged implementations for the contextual binding.
     *
     * @param string $tag The tag name
     */
    public function giveTagged(string $tag): void;

    /**
     * Specify the configuration item to bind as a primitive.
     *
     * @param string $key The configuration key
     * @param mixed $default Default value if config not found
     */
    public function giveConfig(string $key, mixed $default = null): void;
}
