<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Contracts;

use Closure;

/**
 * BooleanPress Container Interface
 *
 * A Laravel-compatible dependency injection container interface. get() and has()
 * follow the PSR-11 signatures without depending on the PSR package.
 */
interface ContainerInterface
{
    /**
     * Find an entry of the container by its identifier and return it.
     *
     * @param string $id Identifier of the entry to look for
     * @return mixed The entry
     *
     * @throws \BooleanSmtp\Core\Container\EntryNotFoundException When no entry was found
     * @throws \BooleanSmtp\Core\Container\BindingResolutionException When the entry cannot be resolved
     */
    public function get(string $id): mixed;

    /**
     * Determine whether the container can return an entry for the given identifier.
     *
     * @param string $id Identifier of the entry to look for
     */
    public function has(string $id): bool;

    /**
     * Register a binding with the container.
     *
     * @param string $abstract The abstract type or alias
     * @param Closure|string|null $concrete The concrete implementation
     * @param bool $shared Whether the binding should be shared (singleton)
     */
    public function bind(string $abstract, Closure|string|null $concrete = null, bool $shared = false): void;

    /**
     * Register a shared binding (singleton) in the container.
     *
     * @param string $abstract The abstract type or alias
     * @param Closure|string|null $concrete The concrete implementation
     */
    public function singleton(string $abstract, Closure|string|null $concrete = null): void;

    /**
     * Register an existing instance in the container.
     *
     * @param string $abstract The abstract type or alias
     * @param mixed $instance The existing instance
     */
    public function instance(string $abstract, mixed $instance): mixed;

    /**
     * Resolve the given type from the container.
     *
     * @param string $abstract The abstract type to resolve
     * @param array<string, mixed> $parameters Override parameters
     * @return mixed The resolved instance
     */
    public function make(string $abstract, array $parameters = []): mixed;

    /**
     * Determine if the given abstract type has been bound.
     *
     * @param string $abstract The abstract type to check
     */
    public function bound(string $abstract): bool;

    /**
     * Alias a type to a different name.
     *
     * @param string $abstract The abstract type
     * @param string $alias The alias name
     */
    public function alias(string $abstract, string $alias): void;

    /**
     * Call the given Closure / class@method and inject its dependencies.
     *
     * @param callable|string $callback The callback to invoke
     * @param array<string, mixed> $parameters Override parameters
     * @return mixed The callback result
     */
    public function call(callable|string $callback, array $parameters = []): mixed;

    /**
     * Define a contextual binding.
     *
     * @param string|array<string> $concrete The concrete class(es)
     * @return ContextualBindingBuilderInterface
     */
    public function when(string|array $concrete): ContextualBindingBuilderInterface;

    /**
     * Register a new before resolving callback.
     *
     * @param string $abstract The abstract type
     * @param Closure|null $callback The callback
     */
    public function beforeResolving(string $abstract, ?Closure $callback = null): void;

    /**
     * Register a new after resolving callback.
     *
     * @param string $abstract The abstract type
     * @param Closure|null $callback The callback
     */
    public function afterResolving(string $abstract, ?Closure $callback = null): void;

    /**
     * Flush the container of all bindings and resolved instances.
     */
    public function flush(): void;
}
