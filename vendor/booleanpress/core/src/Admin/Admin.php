<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Admin;

class Admin
{
    /**
     * registered pages.
     *
     * @var array<Page>
     */
    protected array $pages = [];

    /**
     * Registered notices.
     *
     * @var array<Notice>
     */
    protected array $notices = [];

    /**
     * Create a new top-level page.
     *
     * @param  string  $title
     * @param  string  $slug
     * @return Page
     */
    public function page(string $title, string $slug): Page
    {
        $page = new Page($title, $slug);
        $this->pages[] = $page;

        // Defer registration to 'admin_menu' hook
        add_action('admin_menu', function () use ($page) {
            $page->register();
        });

        return $page;
    }

    /**
     * Create a new admin notice.
     *
     * @param string $message
     * @param string $type
     * @return Notice
     */
    public function notice(string $message, string $type = 'info'): Notice
    {
        $notice = new Notice($message, $type);
        $this->notices[] = $notice;
        $notice->register();

        return $notice;
    }

    /**
     * Create a new submenu page.
     *
     * @param  string  $parentSlug
     * @param  string  $title
     * @param  string  $slug
     * @return Page
     */
    public function submenu(string $parentSlug, string $title, string $slug): Page
    {
        $page = new Page($title, $slug);
        $page->parent($parentSlug);
        $this->pages[] = $page;

        add_action('admin_menu', function () use ($page) {
            $page->register();
        });

        return $page;
    }
}
