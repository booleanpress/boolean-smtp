<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Container;

use ArrayAccess;
use BooleanSmtp\Core\Contracts\ContainerInterface;
use BooleanSmtp\Core\Contracts\ContextualBindingBuilderInterface;
use Closure;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * BooleanPress Dependency Injection Container
 *
 * A powerful IoC container with autowiring, contextual binding, and singleton support.
 *
 * @implements ArrayAccess<string, mixed>
 */
class Container implements ContainerInterface, ArrayAccess
{
    /**
     * The current globally available container instance (if any).
     */
    protected static ?Container $instance = null;

    /**
     * The container's bindings.
     *
     * @var array<string, array{concrete: Closure|string|null, shared: bool}>
     */
    protected array $bindings = [];

    /**
     * The container's shared instances (singletons).
     *
     * @var array<string, mixed>
     */
    protected array $instances = [];

    /**
     * The registered type aliases.
     *
     * @var array<string, string>
     */
    protected array $aliases = [];

    /**
     * The contextual binding map.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $contextual = [];

    /**
     * The stack of classes being built (for circular dependency detection).
     *
     * @var array<string>
     */
    protected array $buildStack = [];

    /**
     * The registered "before resolving" callbacks.
     *
     * @var array<string, array<Closure>>
     */
    protected array $beforeResolvingCallbacks = [];

    /**
     * The registered "after resolving" callbacks.
     *
     * @var array<string, array<Closure>>
     */
    protected array $afterResolvingCallbacks = [];

    /**
     * The registered tags.
     *
     * @var array<string, array<string>>
     */
    protected array $tags = [];

    /**
     * Set the globally available instance of the container.
     */
    public static function setInstance(?Container $container = null): ?Container
    {
        return static::$instance = $container;
    }

    /**
     * Determine whether a globally available container instance has been set.
     *
     * Unlike getInstance(), this never creates one; use it as the "is the
     * application booted?" probe.
     */
    public static function hasInstance(): bool
    {
        return static::$instance !== null;
    }

    /**
     * Get the globally available instance of the container.
     */
    public static function getInstance(): static
    {
        if (static::$instance === null) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    /**
     * {@inheritDoc}
     */
    public function bind(string $abstract, Closure|string|null $concrete = null, bool $shared = false): void
    {
        $this->dropStaleInstances($abstract);

        if ($concrete === null) {
            $concrete = $abstract;
        }

        if (!$concrete instanceof Closure) {
            $concrete = $this->getClosure($abstract, $concrete);
        }

        $this->bindings[$abstract] = [
            'concrete' => $concrete,
            'shared' => $shared,
        ];
    }

    /**
     * Get the Closure to be used when building a type.
     */
    protected function getClosure(string $abstract, string $concrete): Closure
    {
        return function (Container $container, array $parameters = []) use ($abstract, $concrete) {
            if ($abstract === $concrete) {
                return $container->build($concrete, $parameters);
            }

            return $container->resolve($concrete, $parameters);
        };
    }

    /**
     * {@inheritDoc}
     */
    public function singleton(string $abstract, Closure|string|null $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * {@inheritDoc}
     */
    public function instance(string $abstract, mixed $instance): mixed
    {
        $this->instances[$abstract] = $instance;

        return $instance;
    }

    /**
     * {@inheritDoc}
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        return $this->resolve($abstract, $parameters);
    }

    /**
     * Resolve the given type from the container.
     *
     * @param string $abstract
     * @param array<string, mixed> $parameters
     * @return mixed
     */
    protected function resolve(string $abstract, array $parameters = []): mixed
    {
        $abstract = $this->getAlias($abstract);

        $this->fireBeforeResolvingCallbacks($abstract, $parameters);

        // If an instance is currently being managed as a singleton, return it.
        if (isset($this->instances[$abstract]) && empty($parameters)) {
            return $this->instances[$abstract];
        }

        $concrete = $this->getConcrete($abstract);

        $object = $this->isBuildable($concrete, $abstract)
            ? $this->build($concrete, $parameters)
            : $this->make($concrete, $parameters);

        // If the requested type is registered as a singleton, cache the instance.
        if ($this->isShared($abstract) && empty($parameters)) {
            $this->instances[$abstract] = $object;
        }

        $this->fireAfterResolvingCallbacks($abstract, $object);

        return $object;
    }

    /**
     * Get the concrete type for a given abstract.
     */
    protected function getConcrete(string $abstract): Closure|string
    {
        // Check contextual binding first
        if (isset($this->contextual[end($this->buildStack)][$abstract])) {
            return $this->contextual[end($this->buildStack)][$abstract];
        }

        if (isset($this->bindings[$abstract])) {
            return $this->bindings[$abstract]['concrete'];
        }

        return $abstract;
    }

    /**
     * Determine if the given concrete is buildable.
     */
    protected function isBuildable(Closure|string $concrete, string $abstract): bool
    {
        return $concrete === $abstract || $concrete instanceof Closure;
    }

    /**
     * Instantiate a concrete instance of the given type.
     *
     * @param Closure|string $concrete
     * @param array<string, mixed> $parameters
     * @return mixed
     * @throws BindingResolutionException
     */
    public function build(Closure|string $concrete, array $parameters = []): mixed
    {
        if ($concrete instanceof Closure) {
            return $concrete($this, $parameters);
        }

        try {
            $reflector = new ReflectionClass($concrete);
        } catch (ReflectionException $e) {
            throw new BindingResolutionException("Target class [$concrete] does not exist.", 0, $e);
        }

        if (!$reflector->isInstantiable()) {
            throw new BindingResolutionException("Target [$concrete] is not instantiable.");
        }

        $this->buildStack[] = $concrete;

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            array_pop($this->buildStack);
            return new $concrete();
        }

        $dependencies = $constructor->getParameters();
        $instances = $this->resolveDependencies($dependencies, $parameters);

        array_pop($this->buildStack);

        return $reflector->newInstanceArgs($instances);
    }

    /**
     * Resolve all of the dependencies from the ReflectionParameters.
     *
     * @param array<ReflectionParameter> $dependencies
     * @param array<string, mixed> $parameters
     * @return array<mixed>
     */
    protected function resolveDependencies(array $dependencies, array $parameters): array
    {
        $results = [];

        foreach ($dependencies as $dependency) {
            $name = $dependency->getName();

            // If we have an override parameter, use it
            if (array_key_exists($name, $parameters)) {
                $results[] = $parameters[$name];
                continue;
            }

            // Try to resolve by type hint
            $type = $dependency->getType();

            if ($type === null || !$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                // No type hint or primitive type, try default value
                if ($dependency->isDefaultValueAvailable()) {
                    $results[] = $dependency->getDefaultValue();
                } elseif ($dependency->allowsNull()) {
                    $results[] = null;
                } else {
                    throw new BindingResolutionException(
                        "Unresolvable dependency [\${$name}] in class " . $dependency->getDeclaringClass()?->getName()
                    );
                }
                continue;
            }

            $typeName = $type->getName();

            try {
                $results[] = $this->make($typeName);
            } catch (BindingResolutionException $e) {
                if ($dependency->isDefaultValueAvailable()) {
                    $results[] = $dependency->getDefaultValue();
                } elseif ($dependency->allowsNull()) {
                    $results[] = null;
                } else {
                    throw $e;
                }
            }
        }

        return $results;
    }

    /**
     * {@inheritDoc}
     */
    public function bound(string $abstract): bool
    {
        return isset($this->bindings[$abstract])
            || isset($this->instances[$abstract])
            || $this->isAlias($abstract);
    }

    /**
     * Determine if a given string is an alias.
     */
    public function isAlias(string $name): bool
    {
        return isset($this->aliases[$name]);
    }

    /**
     * {@inheritDoc}
     */
    public function alias(string $abstract, string $alias): void
    {
        if ($abstract === $alias) {
            throw new InvalidArgumentException("[$abstract] is aliased to itself.");
        }

        $this->aliases[$alias] = $abstract;
    }

    /**
     * Get the alias for an abstract if available.
     */
    protected function getAlias(string $abstract): string
    {
        return isset($this->aliases[$abstract])
            ? $this->getAlias($this->aliases[$abstract])
            : $abstract;
    }

    /**
     * Determine if a given type is shared (singleton).
     */
    protected function isShared(string $abstract): bool
    {
        return isset($this->instances[$abstract])
            || (isset($this->bindings[$abstract]['shared']) && $this->bindings[$abstract]['shared'] === true);
    }

    /**
     * Drop stale instances when rebinding.
     */
    protected function dropStaleInstances(string $abstract): void
    {
        unset($this->instances[$abstract], $this->aliases[$abstract]);
    }

    /**
     * {@inheritDoc}
     */
    public function call(callable|string $callback, array $parameters = []): mixed
    {
        if (is_string($callback) && str_contains($callback, '@')) {
            $callback = explode('@', $callback);
        }

        if (is_array($callback)) {
            $reflector = new ReflectionMethod($callback[0], $callback[1]);
        } elseif (is_string($callback)) {
            $reflector = new ReflectionFunction($callback);
        } else {
            $reflector = new ReflectionFunction($callback);
        }

        $dependencies = $this->resolveDependencies($reflector->getParameters(), $parameters);

        if (is_array($callback) && is_string($callback[0])) {
            $callback[0] = $this->make($callback[0]);
        }

        return call_user_func_array($callback, $dependencies);
    }

    /**
     * {@inheritDoc}
     */
    public function when(string|array $concrete): ContextualBindingBuilderInterface
    {
        $aliases = [];

        foreach ((array) $concrete as $c) {
            $aliases[] = $this->getAlias($c);
        }

        return new ContextualBindingBuilder($this, $aliases);
    }

    /**
     * Add a contextual binding to the container.
     *
     * @param string $concrete The concrete class
     * @param string $abstract The abstract class/interface needed
     * @param mixed $implementation The implementation to provide
     */
    public function addContextualBinding(string $concrete, string $abstract, mixed $implementation): void
    {
        $this->contextual[$concrete][$abstract] = $implementation;
    }

    /**
     * Tag a set of bindings with a given tag.
     *
     * @param array<string>|string $abstracts
     * @param array<string>|string $tags
     */
    public function tag(array|string $abstracts, array|string $tags): void
    {
        $tags = (array) $tags;

        foreach ($tags as $tag) {
            if (!isset($this->tags[$tag])) {
                $this->tags[$tag] = [];
            }

            foreach ((array) $abstracts as $abstract) {
                $this->tags[$tag][] = $abstract;
            }
        }
    }

    /**
     * Resolve all of the bindings for a given tag.
     *
     * @param string $tag
     * @return iterable<mixed>
     */
    public function tagged(string $tag): iterable
    {
        if (!isset($this->tags[$tag])) {
            return [];
        }

        return array_map(fn (string $abstract) => $this->make($abstract), $this->tags[$tag]);
    }

    /**
     * {@inheritDoc}
     */
    public function beforeResolving(string $abstract, ?Closure $callback = null): void
    {
        if ($callback !== null) {
            $this->beforeResolvingCallbacks[$abstract][] = $callback;
        }
    }

    /**
     * Fire all of the before resolving callbacks.
     *
     * @param string $abstract
     * @param array<string, mixed> $parameters
     */
    protected function fireBeforeResolvingCallbacks(string $abstract, array $parameters): void
    {
        foreach ($this->beforeResolvingCallbacks[$abstract] ?? [] as $callback) {
            $callback($abstract, $parameters, $this);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function afterResolving(string $abstract, ?Closure $callback = null): void
    {
        if ($callback !== null) {
            $this->afterResolvingCallbacks[$abstract][] = $callback;
        }
    }

    /**
     * Fire all of the after resolving callbacks.
     *
     * @param string $abstract
     * @param mixed $object
     */
    protected function fireAfterResolvingCallbacks(string $abstract, mixed $object): void
    {
        foreach ($this->afterResolvingCallbacks[$abstract] ?? [] as $callback) {
            $callback($object, $this);
        }
    }

    /**
     * Register a resolving callback (alias for afterResolving).
     */
    public function resolving(string $abstract, ?Closure $callback = null): void
    {
        $this->afterResolving($abstract, $callback);
    }

    /**
     * {@inheritDoc}
     */
    public function flush(): void
    {
        $this->aliases = [];
        $this->bindings = [];
        $this->instances = [];
        $this->contextual = [];
        $this->beforeResolvingCallbacks = [];
        $this->afterResolvingCallbacks = [];
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $id): bool
    {
        return $this->bound($id);
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $id): mixed
    {
        try {
            return $this->make($id);
        } catch (BindingResolutionException $e) {
            if ($this->has($id)) {
                throw $e;
            }

            throw new EntryNotFoundException($id, 0, $e);
        }
    }

    /**
     * Determine if the given offset exists.
     *
     * @param string $offset
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->bound($offset);
    }

    /**
     * Get the value at the given offset.
     *
     * @param string $offset
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->make($offset);
    }

    /**
     * Set the value at the given offset.
     *
     * @param string $offset
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->bind($offset, $value instanceof Closure ? $value : fn () => $value);
    }

    /**
     * Unset the value at the given offset.
     *
     * @param string $offset
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->bindings[$offset], $this->instances[$offset]);
    }

    /**
     * Dynamically access container services.
     */
    public function __get(string $key): mixed
    {
        return $this[$key];
    }

    /**
     * Dynamically set container services.
     */
    public function __set(string $key, mixed $value): void
    {
        $this[$key] = $value;
    }
}
