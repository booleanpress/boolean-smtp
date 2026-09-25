<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Events;

use BooleanSmtp\Core\Container\ServiceProvider;

/**
 * Event Service Provider
 *
 * Base service provider for registering event listeners and subscribers.
 * Extend this class in your application to define event mappings.
 */
class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string|string>>
     */
    protected array $listen = [];

    /**
     * The subscriber classes to register.
     *
     * @var array<int, class-string>
     */
    protected array $subscribe = [];

    /**
     * Register the application's event listeners.
     */
    public function register(): void
    {
        $this->app->singleton('events', function ($app) {
            return new Dispatcher($app);
        });

        $this->app->alias('events', Dispatcher::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $events = $this->app->make('events');

        foreach ($this->listen as $event => $listeners) {
            foreach (array_unique($listeners) as $listener) {
                $events->listen($event, $listener);
            }
        }

        foreach ($this->subscribe as $subscriber) {
            $events->subscribe($subscriber);
        }
    }

    /**
     * Get the events and handlers.
     *
     * @return array<class-string, array<int, class-string|string>>
     */
    public function listens(): array
    {
        return $this->listen;
    }
}
