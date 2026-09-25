<?php
/**
 * Registers the REST API routes for the plugin.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Providers;

use BooleanSmtp\Core\Container\ServiceProvider;
use BooleanSmtp\Core\Http\Middleware\AuthMiddleware;
use BooleanSmtp\Core\Http\Router;

/**
 * Wires the `rest_api_init` hook to the router that loads `routes/api.php`.
 *
 * @since 1.0.0
 */
class RouteServiceProvider extends ServiceProvider
{
    /**
     * Hook route registration into WordPress `rest_api_init`.
     *
     * @since 1.0.0
     */
    public function boot(): void
    {
        $this->addAction('rest_api_init', [$this, 'registerRoutes']);
    }

    /**
     * Register the plugin's routes with the WordPress REST API.
     *
     * @since 1.0.0
     */
    public function registerRoutes(): void
    {
        $this->router()->registerRestRoutes();
    }

    /**
     * Build the router with `routes/api.php` loaded.
     *
     * Sets the `booleansmtp/v1` REST namespace, aliases the `auth` middleware and binds it to the
     * admin capability, then requires `routes/api.php` inside one group that applies `auth` and
     * that capability to every route, so a route is protected by default; the free plugin registers
     * no public route. The router's default capability is set to the same value for any route that
     * leaves the group.
     *
     * Tests dispatch through this same router, so what they exercise is the production route table.
     *
     * @since 1.0.0
     *
     * @return Router
     */
    public function router(): Router
    {
        $capability = $this->capability();

        $this->app->bind(AuthMiddleware::class, static fn (): AuthMiddleware => new AuthMiddleware($capability));

        $router = new Router($this->app);
        $router->setApiNamespace('booleansmtp/v1');
        $router->setDefaultCapability($capability);
        $router->aliasMiddleware('auth', AuthMiddleware::class);

        $routesPath = $this->app->basePath('routes/api.php');
        $router->middleware('auth')
            ->can($capability)
            ->namespace('BooleanSmtp\Http\Controllers')
            ->group(function (Router $r) use ($routesPath): void {
                $router = $r;
                require $routesPath;
            });

        return $router;
    }

    /**
     * The capability required for every admin REST route.
     *
     * @since 1.0.0
     *
     * @return string
     */
    private function capability(): string
    {
        /**
         * Filters the capability required to use the BooleanSMTP REST API.
         *
         * Applies to every route except the public provider webhook. Defaults to
         * `manage_options`; return a finer capability to delegate the admin UI to another role.
         *
         * @since 1.0.0
         *
         * @param string $capability The WordPress capability. Default `manage_options`.
         * @return string The filtered capability.
         */
        return (string) apply_filters('boolean_smtp_rest_capability', 'manage_options');
    }
}
