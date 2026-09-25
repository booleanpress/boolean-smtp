<?php
/**
 * What a migration source may read: the site's options, another plugin's tables through the
 * framework's database driver, wp-config constants and the site's timezone.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration;

use BooleanSmtp\Core\Database\Drivers\DriverInterface;

/**
 * Read-only facade a source adapter works through, so the adapters stay testable on the SQLite
 * harness and never touch WordPress globals themselves.
 *
 * @since 1.0.0
 */
final class SourceContext
{
    /**
     * @since 1.0.0
     *
     * @param DriverInterface           $driver    Framework database driver (MySQL on a site, SQLite in tests).
     * @param array<string, mixed>|null $constants Constants to answer from instead of the process's own — for tests,
     *                                             which cannot undefine a constant once defined.
     */
    public function __construct(
        private readonly DriverInterface $driver,
        private readonly ?array $constants = null,
    ) {}

    /**
     * A copy of this context answering constant lookups from the given map.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $constants Constant name → value.
     * @return self
     */
    public function withConstants(array $constants): self
    {
        return new self($this->driver, $constants);
    }

    /**
     * The site-prefixed name of a table.
     *
     * @since 1.0.0
     *
     * @param  string $nameWithoutPrefix Table name without the site prefix, e.g. `fsmpt_email_logs`.
     * @return string
     */
    public function table(string $nameWithoutPrefix): string
    {
        return $this->driver->getTable($nameWithoutPrefix);
    }

    /**
     * Whether a prefixed table exists.
     *
     * @since 1.0.0
     *
     * @param  string $nameWithoutPrefix Table name without the site prefix.
     * @return bool
     */
    public function tableExists(string $nameWithoutPrefix): bool
    {
        return $this->driver->tableExists($nameWithoutPrefix);
    }

    /**
     * The column names a table really has, so a reader can skip a column a newer edition of the
     * source plugin added.
     *
     * @since 1.0.0
     *
     * @param  string $nameWithoutPrefix Table name without the site prefix.
     * @return list<string>
     */
    public function columns(string $nameWithoutPrefix): array
    {
        return array_values(array_map('strval', $this->driver->getTableColumns($nameWithoutPrefix)));
    }

    /**
     * Run a SELECT with `%s`/`%d` placeholders and return every row.
     *
     * @since 1.0.0
     *
     * @param  string      $sql      Statement with placeholders.
     * @param  list<mixed> $bindings Values for the placeholders.
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->driver->select($sql, $bindings);
    }

    /**
     * Run a SELECT with `%s`/`%d` placeholders and return one scalar.
     *
     * @since 1.0.0
     *
     * @param  string      $sql      Statement with placeholders.
     * @param  list<mixed> $bindings Values for the placeholders.
     * @return mixed `null` when the query returned nothing.
     */
    public function selectVar(string $sql, array $bindings = []): mixed
    {
        return $this->driver->selectVar($sql, $bindings);
    }

    /**
     * Read a WordPress option.
     *
     * @since 1.0.0
     *
     * @param  string $name    Option name.
     * @param  mixed  $default Returned when the option is unset.
     * @return mixed
     */
    public function option(string $name, mixed $default = false): mixed
    {
        if (! \function_exists('get_option')) {
            return $default;
        }

        return \get_option($name, $default);
    }

    /**
     * Whether a wp-config constant is defined with a non-empty value — how the source plugins
     * decide that a credential lives in `wp-config.php` rather than in their option.
     *
     * @since 1.0.0
     *
     * @param  string $name Constant name.
     * @return bool
     */
    public function constantSet(string $name): bool
    {
        $value = $this->constants !== null
            ? ($this->constants[$name] ?? null)
            : (\defined($name) ? \constant($name) : null);

        return $value !== '' && $value !== null && $value !== false;
    }

    /**
     * The value of a wp-config constant, or null when it is not set.
     *
     * @since 1.0.0
     *
     * @param  string $name Constant name.
     * @return mixed
     */
    public function constant(string $name): mixed
    {
        if (! $this->constantSet($name)) {
            return null;
        }

        return $this->constants !== null ? $this->constants[$name] : \constant($name);
    }
}
