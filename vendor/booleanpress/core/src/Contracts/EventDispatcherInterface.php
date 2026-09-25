<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Contracts;

/**
 * Event Dispatcher Interface
 */
interface EventDispatcherInterface
{
    /**
     * Register an event listener with the dispatcher.
     *
     * @param string|array<string> $events
     * @param \Closure|string|array<int, mixed> $listener
     */
    public function listen(string|array $events, \Closure|string|array $listener): void;

    /**
     * Register an event subscriber with the dispatcher.
     *
     * @param object|string $subscriber
     */
    public function subscribe(object|string $subscriber): void;

    /**
     * Dispatch an event and call the listeners.
     *
     * @param object|string $event
     * @param mixed $payload
     * @param bool $halt Stop propagation on first non-null response
     * @return array<mixed>|null
     */
    public function dispatch(object|string $event, mixed $payload = [], bool $halt = false): ?array;

    /**
     * Determine if a given event has listeners.
     */
    public function hasListeners(string $eventName): bool;

    /**
     * Remove a set of listeners from the dispatcher.
     *
     * @param string $event The event name
     */
    public function forget(string $event): void;
}
