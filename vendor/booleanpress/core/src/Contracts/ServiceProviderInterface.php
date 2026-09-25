<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Contracts;

/**
 * Service Provider Interface
 *
 * Service providers are the central place of all BooleanPress application bootstrapping.
 * Your own application, as well as all core services, are bootstrapped via service providers.
 */
interface ServiceProviderInterface
{
    /**
     * Register any application services.
     *
     * This is where you bind things into the service container. You should
     * only use $this->app to bind things. Do not perform any other actions
     * in this method, as the application is not yet fully booted.
     */
    public function register(): void;

    /**
     * Bootstrap any application services.
     *
     * This is called after all service providers have been registered.
     * You may perform any initialization logic here.
     */
    public function boot(): void;

    /**
     * Get the services provided by the provider.
     *
     * @return array<string> List of service identifiers
     */
    public function provides(): array;

    /**
     * Determine if the provider is deferred.
     *
     * Deferred providers are only loaded when one of their services is needed.
     */
    public function isDeferred(): bool;

    /**
     * Call the booting callbacks and boot the provider.
     */
    public function callBootingCallbacks(): void;
}
