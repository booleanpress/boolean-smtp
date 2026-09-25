<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Container;

use BooleanSmtp\Core\Contracts\ContextualBindingBuilderInterface;
use Closure;

/**
 * Contextual Binding Builder
 *
 * Enables contextual dependency injection where a class can receive
 * different implementations based on where it's being injected.
 *
 * @example
 * $container->when(PhotoController::class)
 *     ->needs(FileSystem::class)
 *     ->give(LocalFileSystem::class);
 */
class ContextualBindingBuilder implements ContextualBindingBuilderInterface
{
    /**
     * The underlying container instance.
     */
    protected Container $container;

    /**
     * The concrete instances we're building for.
     *
     * @var array<string>
     */
    protected array $concrete;

    /**
     * The abstract target needed by the context.
     */
    protected string $needs;

    /**
     * Create a new contextual binding builder.
     *
     * @param Container $container
     * @param array<string> $concrete
     */
    public function __construct(Container $container, array $concrete)
    {
        $this->container = $container;
        $this->concrete = $concrete;
    }

    /**
     * {@inheritDoc}
     */
    public function needs(string $abstract): static
    {
        $this->needs = $abstract;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function give(mixed $implementation): void
    {
        foreach ($this->concrete as $concrete) {
            $this->container->addContextualBinding(
                $concrete,
                $this->needs,
                $implementation instanceof Closure ? $implementation : fn () => $implementation
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function giveTagged(string $tag): void
    {
        $this->give(function (Container $container) use ($tag) {
            return $container->tagged($tag);
        });
    }

    /**
     * {@inheritDoc}
     */
    public function giveConfig(string $key, mixed $default = null): void
    {
        $this->give(function (Container $container) use ($key, $default) {
            $config = $container->make('config');
            return $config->get($key, $default);
        });
    }
}
