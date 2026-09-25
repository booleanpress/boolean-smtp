<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Admin;

use BooleanSmtp\Core\Assets\Manifest;
use Closure;

class Page
{
    /**
     * The page title.
     */
    protected string $title;

    /**
     * The menu title.
     */
    protected string $menuTitle;

    /**
     * The capability required for this menu to be displayed to the user.
     */
    protected string $capability;

    /**
     * The menu slug.
     */
    protected string $menuSlug;

    /**
     * The icon URL or Dashicons class.
     */
    protected string $iconUrl = '';

    /**
     * The position in the menu order.
     */
    protected ?int $position = null;

    /**
     * The parent slug for submenus.
     */
    protected ?string $parentSlug = null;

    /**
     * The frontend entry point file (React/Vue).
     */
    protected ?string $frontendEntry = null;

    /**
     * The mount ID for the Vue/React app.
     */
    protected string $mountId = 'app';

    /**
     * The initial props for the Vue app.
     */
    protected array|Closure $props = [];

    /**
     * Create a new page instance.
     *
     * @param  string  $title
     * @param  string  $menuSlug
     * @param  string  $capability
     */
    public function __construct(string $title, string $menuSlug, string $capability = 'manage_options')
    {
        $this->title = $title;
        $this->menuTitle = $title;
        $this->menuSlug = $menuSlug;
        $this->capability = $capability;
    }

    /**
     * Set the mount ID.
     *
     * @param  string  $id
     * @return $this
     */
    public function mountId(string $id): self
    {
        $this->mountId = $id;
        return $this;
    }

    /**
     * Set the menu title.
     *
     * @param  string  $menuTitle
     * @return $this
     */
    public function menuTitle(string $menuTitle): self
    {
        $this->menuTitle = $menuTitle;
        return $this;
    }

    /**
     * Set the capability.
     *
     * @param  string  $capability
     * @return $this
     */
    public function capability(string $capability): self
    {
        $this->capability = $capability;
        return $this;
    }

    /**
     * Set the icon.
     *
     * @param  string  $iconUrl
     * @return $this
     */
    public function icon(string $iconUrl): self
    {
        $this->iconUrl = $iconUrl;
        return $this;
    }

    /**
     * Set the position.
     *
     * @param  int  $position
     * @return $this
     */
    public function position(int $position): self
    {
        $this->position = $position;
        return $this;
    }

    /**
     * Set the parent slug (for submenus).
     *
     * @param  string  $parentSlug
     * @return $this
     */
    public function parent(string $parentSlug): self
    {
        $this->parentSlug = $parentSlug;
        return $this;
    }

    /**
     * Set the frontend entry point.
     *
     * @param  string  $entry
     * @return $this
     */
    public function frontend(string $entry): self
    {
        $this->frontendEntry = $entry;
        return $this;
    }

    /**
     * Set initial props.
     *
     * @param  array|Closure  $props
     * @return $this
     */
    public function props(array|Closure $props): self
    {
        $this->props = $props;
        return $this;
    }

    /**
     * Register the page with WordPress.
     *
     * @return void
     */
    public function register(): void
    {
        $callback = function () {
            $this->render();
        };

        if ($this->parentSlug) {
            add_submenu_page(
                $this->parentSlug,
                $this->title,
                $this->menuTitle,
                $this->capability,
                $this->menuSlug,
                $callback,
                $this->position
            );
        } else {
            add_menu_page(
                $this->title,
                $this->menuTitle,
                $this->capability,
                $this->menuSlug,
                $callback,
                $this->iconUrl,
                $this->position
            );
        }
    }

    /**
     * Render the page content (App Shell).
     *
     * @return void
     */
    protected function render(): void
    {
        $props = $this->resolveProps();
        $jsonProps = esc_attr((string) json_encode($props));

        echo "<div class='wrap'><div id='" . esc_attr($this->mountId) . "' data-props='{$jsonProps}'></div></div>";
    }

    /**
     * Resolve props (execute closures).
     *
     * @return array
     */
    protected function resolveProps(): array
    {
        $props = $this->props instanceof Closure ? ($this->props)() : $this->props;

        $resolved = [];
        foreach ($props as $key => $value) {
            $resolved[$key] = $value instanceof Closure ? $value() : $value;
        }
        return $resolved;
    }

    /**
     * Get the frontend entry point.
     *
     * @return string|null
     */
    public function getFrontendEntry(): ?string
    {
        return $this->frontendEntry;
    }
}
