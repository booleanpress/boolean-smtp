<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Admin;

/**
 * Admin Notice Class
 *
 * Represents a WordPress admin notice.
 * Used for persistent notices like license requirements or missing dependencies.
 */
class Notice
{
    protected string $message;
    protected string $type = 'info'; // success, error, warning, info
    protected bool $dismissible = true;
    protected bool $inline = false;
    protected ?string $id = null;
    protected string $capability = 'manage_options';

    /**
     * Create a new notice instance.
     */
    public function __construct(string $message, string $type = 'info')
    {
        $this->message = $message;
        $this->type = $type;
    }

    /**
     * Set notice type to success.
     */
    public function success(): self
    {
        $this->type = 'success';
        return $this;
    }

    /**
     * Set notice type to error.
     */
    public function error(): self
    {
        $this->type = 'error';
        return $this;
    }

    /**
     * Set notice type to warning.
     */
    public function warning(): self
    {
        $this->type = 'warning';
        return $this;
    }

    /**
     * Set notice type to info.
     */
    public function info(): self
    {
        $this->type = 'info';
        return $this;
    }

    /**
     * Set if notice is dismissible.
     */
    public function dismissible(bool $dismissible = true): self
    {
        $this->dismissible = $dismissible;
        return $this;
    }

    /**
     * Set if notice is inline.
     */
    public function inline(bool $inline = true): self
    {
        $this->inline = $inline;
        return $this;
    }

    /**
     * Set notice ID.
     */
    public function id(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Set required capability.
     */
    public function capability(string $capability): self
    {
        $this->capability = $capability;
        return $this;
    }

    /**
     * Register the notice with WordPress.
     */
    public function register(): void
    {
        add_action('admin_notices', function () {
            if (!current_user_can($this->capability)) {
                return;
            }

            $classes = [
                'notice',
                "notice-{$this->type}",
            ];

            if ($this->dismissible) {
                $classes[] = 'is-dismissible';
            }

            if ($this->inline) {
                $classes[] = 'inline';
            }

            $idAttr = $this->id ? ' id="' . esc_attr($this->id) . '"' : '';
            $classAttr = ' class="' . esc_attr(implode(' ', $classes)) . '"';

            printf(
                '<div%s%s><p>%s</p></div>',
                $idAttr,
                $classAttr,
                wp_kses_post($this->message)
            );
        });
    }
}
