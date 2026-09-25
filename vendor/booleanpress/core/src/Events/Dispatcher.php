<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Events;

use BooleanSmtp\Core\Container\Container;
use BooleanSmtp\Core\Contracts\EventDispatcherInterface;
use Closure;

/**
 * Event Dispatcher
 *
 * Manages event listeners and dispatches events throughout the application.
 * Integrates with WordPress hooks while providing a clean Laravel-like API.
 */
class Dispatcher implements EventDispatcherInterface
{
    /**
     * The IoC container instance.
     */
    protected Container $container;

    /**
     * The registered event listeners.
     *
     * @var array<string, array<int, array{listener: Closure, original: mixed}>>
     */
    protected array $listeners = [];

    /**
     * The wildcard listeners.
     *
     * @var array<string, array<int, array{listener: Closure, original: mixed}>>
     */
    protected array $wildcards = [];

    /**
     * The registered event subscribers.
     *
     * @var array<string, bool>
     */
    protected array $subscribers = [];

    /**
     * The queue resolver instance.
     */
    protected ?Closure $queueResolver = null;

    /**
     * Create a new event dispatcher instance.
     */
    public function __construct(?Container $container = null)
    {
        $this->container = $container ?? Container::getInstance();
    }

    /**
     * {@inheritDoc}
     */
    public function listen(string|array $events, Closure|string|array $listener): void
    {
        foreach ((array) $events as $event) {
            if (str_contains($event, '*')) {
                $this->setupWildcardListen($event, $listener);
            } else {
                $this->listeners[$event][] = [
                    'listener' => $this->makeListener($listener),
                    'original' => $listener,
                ];
            }
        }
    }

    /**
     * Setup a wildcard listener callback.
     */
    protected function setupWildcardListen(string $event, Closure|string|array $listener): void
    {
        $this->wildcards[$event][] = [
            'listener' => $this->makeListener($listener, true),
            'original' => $listener,
        ];
    }

    /**
     * Register a listener to be called once.
     */
    public function once(string $event, Closure|string|array $listener): void
    {
        $onceListener = function (...$args) use ($event, $listener) {
            $this->forget($event);
            return $this->container->call($this->makeListener($listener), $args);
        };

        $this->listen($event, $onceListener);
    }

    /**
     * Make a listener into a callable.
     */
    protected function makeListener(Closure|string|array $listener, bool $wildcard = false): Closure
    {
        if (is_string($listener)) {
            return $this->createClassListener($listener, $wildcard);
        }

        if (is_array($listener) && isset($listener[0])) {
            return $this->createClassListener($listener, $wildcard);
        }

        return function ($event, $payload) use ($listener, $wildcard) {
            if ($wildcard) {
                return $listener($event, $payload);
            }

            return $listener(...array_values($payload));
        };
    }

    /**
     * Create a class based listener.
     */
    protected function createClassListener(string|array $listener, bool $wildcard): Closure
    {
        return function ($event, $payload) use ($listener, $wildcard) {
            if (is_string($listener)) {
                [$class, $method] = $this->parseClassCallable($listener);
            } else {
                [$class, $method] = $listener;
            }

            $instance = $this->container->make($class);

            $method = $method ?? 'handle';

            if ($wildcard) {
                return $instance->{$method}($event, $payload);
            }

            return $instance->{$method}(...array_values($payload));
        };
    }

    /**
     * Parse the class@method callable.
     *
     * @return array{0: string, 1: string}
     */
    protected function parseClassCallable(string $listener): array
    {
        return str_contains($listener, '@')
            ? explode('@', $listener, 2)
            : [$listener, 'handle'];
    }

    /**
     * {@inheritDoc}
     */
    public function subscribe(object|string $subscriber): void
    {
        $subscriberClass = is_string($subscriber) ? $subscriber : get_class($subscriber);

        if (isset($this->subscribers[$subscriberClass])) {
            return;
        }

        $this->subscribers[$subscriberClass] = true;

        $subscriber = is_string($subscriber)
            ? $this->container->make($subscriber)
            : $subscriber;

        $events = $subscriber->subscribe($this);

        if (is_array($events)) {
            foreach ($events as $event => $listeners) {
                foreach ((array) $listeners as $listener) {
                    if (is_string($listener) && method_exists($subscriber, $listener)) {
                        $this->listen($event, [$subscriberClass, $listener]);
                    } else {
                        $this->listen($event, $listener);
                    }
                }
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function dispatch(object|string $event, mixed $payload = [], bool $halt = false): ?array
    {
        // Get the event name
        [$event, $payload] = $this->parseEventAndPayload($event, $payload);

        $responses = [];

        foreach ($this->getListeners($event) as $listenerData) {
            $listener = $listenerData['listener'];
            $original = $listenerData['original'];

            // Check if listener class implements ShouldQueue
            if ($this->shouldQueue($original)) {
                $this->queueListener($original, $event, $payload);
                continue;
            }

            $response = $listener($event, $payload);

            // If the event is propagation stopped, stop here
            if (isset($payload[0]) && $payload[0] instanceof Event && $payload[0]->isPropagationStopped()) {
                break;
            }

            // If halting and we have a response, return immediately
            if ($halt && $response !== null) {
                return [$response];
            }

            // Add response if it's not null
            if ($response !== null) {
                $responses[] = $response;
            }
        }

        // Also fire WordPress action if available
        $this->fireWordPressAction($event, $payload);

        return $halt ? null : $responses;
    }

    /**
     * Determine if the listener should be queued.
     */
    protected function shouldQueue(mixed $listener): bool
    {
        if (is_string($listener)) {
            [$class] = $this->parseClassCallable($listener);
            return is_subclass_of($class, \BooleanSmtp\Core\Contracts\Queue\ShouldQueue::class);
        }

        if (is_array($listener) && isset($listener[0]) && is_string($listener[0])) {
            return is_subclass_of($listener[0], \BooleanSmtp\Core\Contracts\Queue\ShouldQueue::class);
        }

        return false;
    }

    /**
     * Queue the listener.
     *
     * Uses the queue resolver when one was set, otherwise the `queue` binding of the Queue
     * component. When neither exists (the plugin did not register the component) the
     * listener is skipped: a queued listener cannot run inline.
     */
    protected function queueListener(mixed $listener, string $event, array $payload): void
    {
        $connection = $this->resolveQueue();

        if ($connection === null) {
            return;
        }

        if (is_string($listener)) {
            [$class, $method] = $this->parseClassCallable($listener);
        } else {
            [$class, $method] = $listener;
        }

        $connection->push(new \BooleanSmtp\Core\Queue\CallQueuedHandler(
            $class,
            $method,
            $payload
        ));
    }

    /**
     * The queue connection queued listeners are pushed to, if the Queue component is available.
     */
    protected function resolveQueue(): ?object
    {
        if ($this->queueResolver) {
            return call_user_func($this->queueResolver);
        }

        if ($this->container->bound('queue')) {
            return $this->container->make('queue');
        }

        return null;
    }

    /**
     * Parse the given event and payload and prepare them.
     *
     * @return array{0: string, 1: array<mixed>}
     */
    protected function parseEventAndPayload(object|string $event, mixed $payload): array
    {
        if (is_object($event)) {
            return [get_class($event), [$event]];
        }

        return [$event, (array) $payload];
    }

    /**
     * Get all listeners for a given event.
     *
     * @return array<array{listener: Closure, original: mixed}>
     */
    public function getListeners(string $eventName): array
    {
        $listeners = array_merge(
            $this->listeners[$eventName] ?? [],
            $this->getWildcardListeners($eventName)
        );

        return $listeners;
    }

    /**
     * Get the wildcard listeners for the event.
     *
     * @return array<array{listener: Closure, original: mixed}>
     */
    protected function getWildcardListeners(string $eventName): array
    {
        $wildcards = [];

        foreach ($this->wildcards as $key => $listeners) {
            if ($this->wildcardMatches($key, $eventName)) {
                $wildcards = array_merge($wildcards, $listeners);
            }
        }

        return $wildcards;
    }

    /**
     * Determine if a wildcard pattern matches an event name.
     */
    protected function wildcardMatches(string $pattern, string $eventName): bool
    {
        $pattern = preg_quote($pattern, '#');
        $pattern = str_replace('\*', '.*', $pattern);

        return (bool) preg_match('#^' . $pattern . '\z#u', $eventName);
    }

    /**
     * Fire a WordPress action hook for the event.
     *
     * @param array<mixed> $payload
     */
    protected function fireWordPressAction(string $event, array $payload): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        // Convert event class name to hook format
        $hook = $this->eventToWordPressHook($event);

        do_action($hook, ...$payload);
    }

    /**
     * Convert an event name to a WordPress hook name.
     */
    protected function eventToWordPressHook(string $event): string
    {
        // Convert namespace to hook format
        // App\Events\UserRegistered -> app_events_user_registered
        $hook = str_replace('\\', '_', $event);
        $hook = preg_replace('/([A-Z])/', '_$1', $hook);
        $hook = strtolower(trim($hook, '_'));
        $hook = str_replace('__', '_', $hook);

        return $hook;
    }

    /**
     * {@inheritDoc}
     */
    public function hasListeners(string $eventName): bool
    {
        return isset($this->listeners[$eventName])
            || !empty($this->getWildcardListeners($eventName));
    }

    /**
     * {@inheritDoc}
     */
    public function forget(string $event): void
    {
        if (str_contains($event, '*')) {
            unset($this->wildcards[$event]);
        } else {
            unset($this->listeners[$event]);
        }
    }

    /**
     * Remove all of the listeners from the dispatcher.
     */
    public function flush(): void
    {
        $this->listeners = [];
        $this->wildcards = [];
        $this->subscribers = [];
    }

    /**
     * Register the queue resolver implementation.
     */
    public function setQueueResolver(Closure $resolver): static
    {
        $this->queueResolver = $resolver;

        return $this;
    }
}
