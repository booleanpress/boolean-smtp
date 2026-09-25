<?php
/**
 * Framework helper functions, namespaced so no global symbol is defined.
 *
 * Import the ones a file needs with `use function BooleanSmtp\Core\app;`.
 *
 * @package BooleanSmtp\Core
 * @since   0.1.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Core;

use BooleanSmtp\Core\Container\Container;
use BooleanSmtp\Core\Foundation\Application;

/**
 * Get the available application instance.
 *
 * @param string|null $abstract
 * @param array<string, mixed> $parameters
 * @return mixed|Application
 */
function app(?string $abstract = null, array $parameters = [])
{
    $app = Application::getInstance();

    if (is_null($abstract)) {
        return $app;
    }

    return $app->make($abstract, $parameters);
}

/**
 * Get the evaluated view contents for the given view.
 *
 * @param  string|null  $view
 * @param  array  $data
 * @return \BooleanSmtp\Core\View\View|string
 */
function view(?string $view = null, array $data = [])
{
    if (!app()->bound(\BooleanSmtp\Core\View\View::class)) {
        throw new \RuntimeException('view() needs the View component: add "View" to the plugin\'s $components.');
    }

    $factory = app(\BooleanSmtp\Core\View\View::class);

    if (func_num_args() === 0) {
        return $factory;
    }

    return $factory->render($view, $data);
}

/**
 * Resolve a service from the container.
 *
 * @param string $abstract
 * @param array<string, mixed> $parameters
 * @return mixed
 */
function resolve(string $abstract, array $parameters = []): mixed
{
    return app()->make($abstract, $parameters);
}

/**
 * Get / set the specified configuration value.
 *
 * If an array is passed as the key, we will assume you want to set an array of values.
 *
 * @param array<string, mixed>|string|null $key
 * @param mixed $default
 * @return mixed
 */
function config(array|string|null $key = null, mixed $default = null): mixed
{
    $app = app();

    if (!$app->bound('config')) {
        return $default;
    }

    $config = $app->make('config');

    if ($key === null) {
        return $config;
    }

    if (is_array($key)) {
        foreach ($key as $k => $v) {
            $config->set($k, $v);
        }
        return null;
    }

    return $config->get($key, $default);
}

/**
 * Dispatch an event and call the listeners.
 *
 * @param object|string $event
 * @param mixed $payload
 * @param bool $halt
 * @return array|null
 */
function event(object|string $event, mixed $payload = [], bool $halt = false): ?array
{
    $app = app();

    if (!$app->bound('events')) {
        return null;
    }

    return $app->make('events')->dispatch($event, $payload, $halt);
}

/**
 * Log a message or get the logger instance.
 *
 * @param string|null $message
 * @param array<string, mixed> $context
 * @return mixed
 */
function logger(?string $message = null, array $context = []): mixed
{
    $app = app();

    if (!$app->bound('log')) {
        return null;
    }

    $logger = $app->make('log');

    if ($message === null) {
        return $logger;
    }

    return $logger->debug($message, $context);
}

/**
 * Get / set cache values.
 *
 * @param string|null $key
 * @param mixed $default
 * @return mixed
 */
function cache(?string $key = null, mixed $default = null): mixed
{
    $app = app();

    if (!$app->bound('cache')) {
        return $default;
    }

    $cache = $app->make('cache');

    if ($key === null) {
        return $cache;
    }

    return $cache->get($key, $default);
}

/**
 * Get the path to the base of the plugin.
 */
function base_path(string $path = ''): string
{
    return app('path.base') . ($path ? DIRECTORY_SEPARATOR . $path : $path);
}

/**
 * Get the path to the application folder.
 */
function app_path(string $path = ''): string
{
    return app('path') . ($path ? DIRECTORY_SEPARATOR . $path : $path);
}

/**
 * Get the path to the config folder.
 */
function config_path(string $path = ''): string
{
    return app('path.config') . ($path ? DIRECTORY_SEPARATOR . $path : $path);
}

/**
 * Get the path to the database folder.
 */
function database_path(string $path = ''): string
{
    return app('path.database') . ($path ? DIRECTORY_SEPARATOR . $path : $path);
}

/**
 * Get the path to the storage folder.
 */
function storage_path(string $path = ''): string
{
    return app('path.storage') . ($path ? DIRECTORY_SEPARATOR . $path : $path);
}

/**
 * Get the class "basename" of the given object / class.
 */
function class_basename(object|string $class): string
{
    $class = is_object($class) ? get_class($class) : $class;

    return basename(str_replace('\\', '/', $class));
}

/**
 * Return the default value of the given value.
 *
 * @param mixed $value
 * @param mixed ...$args
 * @return mixed
 */
function value(mixed $value, mixed ...$args): mixed
{
    return $value instanceof \Closure ? $value(...$args) : $value;
}

/**
 * Gets the value of an environment variable.
 *
 * @param string $key
 * @param mixed $default
 * @return mixed
 */
function env(string $key, mixed $default = null): mixed
{
    $value = getenv($key);

    if ($value === false) {
        return value($default);
    }

    return match (strtolower($value)) {
        'true', '(true)' => true,
        'false', '(false)' => false,
        'empty', '(empty)' => '',
        'null', '(null)' => null,
        default => $value,
    };
}

/**
 * Call the given Closure with the given value then return the value.
 *
 * @param mixed $value
 * @param callable|null $callback
 * @return mixed
 */
function tap(mixed $value, ?callable $callback = null): mixed
{
    if ($callback === null) {
        return $value;
    }

    $callback($value);

    return $value;
}

/**
 * Throw the given exception if the given condition is true.
 *
 * @param mixed $condition
 * @param \Throwable|string $exception
 * @param mixed ...$parameters
 * @return mixed
 * @throws \Throwable
 */
function throw_if(mixed $condition, \Throwable|string $exception = 'RuntimeException', mixed ...$parameters): mixed
{
    if ($condition) {
        if (is_string($exception) && class_exists($exception)) {
            $exception = new $exception(...$parameters);
        }

        throw is_string($exception) ? new \RuntimeException($exception) : $exception;
    }

    return $condition;
}

/**
 * Throw the given exception unless the given condition is true.
 *
 * @param mixed $condition
 * @param \Throwable|string $exception
 * @param mixed ...$parameters
 * @return mixed
 * @throws \Throwable
 */
function throw_unless(mixed $condition, \Throwable|string $exception = 'RuntimeException', mixed ...$parameters): mixed
{
    throw_if(!$condition, $exception, ...$parameters);

    return $condition;
}

/**
 * Get an item from an array or object using "dot" notation.
 *
 * @param mixed $target
 * @param string|array<string>|null $key
 * @param mixed $default
 * @return mixed
 */
function data_get(mixed $target, string|array|null $key, mixed $default = null): mixed
{
    if ($key === null) {
        return $target;
    }

    $key = is_array($key) ? $key : explode('.', $key);

    foreach ($key as $segment) {
        if (is_array($target) && array_key_exists($segment, $target)) {
            $target = $target[$segment];
        } elseif (is_object($target) && isset($target->{$segment})) {
            $target = $target->{$segment};
        } else {
            return value($default);
        }
    }

    return $target;
}

/**
 * Set an item on an array or object using "dot" notation.
 *
 * @param mixed $target
 * @param string|array<string> $key
 * @param mixed $value
 * @param bool $overwrite
 * @return mixed
 */
function data_set(mixed &$target, string|array $key, mixed $value, bool $overwrite = true): mixed
{
    $segments = is_array($key) ? $key : explode('.', $key);

    $segment = array_shift($segments);

    if (empty($segments)) {
        if (is_array($target)) {
            if ($overwrite || !array_key_exists($segment, $target)) {
                $target[$segment] = $value;
            }
        } elseif (is_object($target)) {
            if ($overwrite || !isset($target->{$segment})) {
                $target->{$segment} = $value;
            }
        }
    } else {
        if (is_array($target)) {
            if (!isset($target[$segment])) {
                $target[$segment] = [];
            }
            data_set($target[$segment], $segments, $value, $overwrite);
        } elseif (is_object($target)) {
            if (!isset($target->{$segment})) {
                $target->{$segment} = [];
            }
            data_set($target->{$segment}, $segments, $value, $overwrite);
        }
    }

    return $target;
}

/**
 * Create a collection from the given value.
 *
 * @param mixed $value
 * @return \BooleanSmtp\Core\Support\Collection
 */
function collect(mixed $value = []): \BooleanSmtp\Core\Support\Collection
{
    return new \BooleanSmtp\Core\Support\Collection($value);
}

/**
 * Provide access to optional objects.
 *
 * @param mixed $value
 * @param callable|null $callback
 * @return mixed
 */
function optional(mixed $value = null, ?callable $callback = null): mixed
{
    if ($callback === null) {
        return new class ($value) {
            public function __construct(protected mixed $value)
            {
            }

            public function __get(string $name): mixed
            {
                return $this->value?->{$name} ?? null;
            }

            public function __call(string $name, array $arguments): mixed
            {
                return $this->value?->{$name}(...$arguments);
            }
        };
    }

    if ($value !== null) {
        return $callback($value);
    }

    return null;
}

/**
 * Retry an operation a given number of times.
 *
 * @param int $times
 * @param callable $callback
 * @param int $sleepMilliseconds
 * @param callable|null $when
 * @return mixed
 * @throws \Throwable
 */
function retry(int $times, callable $callback, int $sleepMilliseconds = 0, ?callable $when = null): mixed
{
    $attempts = 0;
    $backoff = $sleepMilliseconds;

    beginning:
    $attempts++;

    try {
        return $callback($attempts);
    } catch (\Throwable $e) {
        if ($attempts < $times && ($when === null || $when($e))) {
            usleep($backoff * 1000);
            $backoff = min($backoff * 2, 30000); // Exponential backoff, max 30s
            goto beginning;
        }

        throw $e;
    }
}


/**
 * Returns all traits used by a class, its parent classes and trait of their traits.
 *
 * @param string|object $class
 * @return array<string>
 */
function class_uses_recursive(string|object $class): array
{
    if (is_object($class)) {
        $class = get_class($class);
    }

    $results = [];

    foreach (array_reverse(class_parents($class) ?: []) + [$class => $class] as $classItem) {
        $results += trait_uses_recursive($classItem);
    }

    return array_unique($results);
}

/**
 * Returns all traits used by a trait and its traits.
 *
 * @param string $trait
 * @return array<string>
 */
function trait_uses_recursive(string $trait): array
{
    $traits = class_uses($trait) ?: [];

    foreach ($traits as $usedTrait) {
        $traits += trait_uses_recursive($usedTrait);
    }

    return $traits;
}
