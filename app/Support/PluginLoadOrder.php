<?php

/**
 * Keeps BooleanSMTP first in WordPress's plugin load order, so it is the plugin that defines
 * `wp_mail()`.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Support;

/**
 * WordPress loads plugins in the order of the `active_plugins` option (and, for a network,
 * `active_sitewide_plugins`) and lets the first plugin that declares the pluggable `wp_mail()`
 * keep it. A site whose option lists another mail plugin earlier sends its email through that
 * plugin no matter how well this one is configured, so the entry is moved to the front — on
 * activation, whenever another plugin is activated, and once an hour on an admin screen while
 * another plugin holds `wp_mail()`.
 *
 * The move is made through `pre_update_option_active_plugins`, not by writing the option
 * directly, because other mail plugins hook that same filter to put themselves first on every
 * write; a direct write is simply undone by theirs. This one runs late enough to have the last
 * word, and `boolean_smtp_load_first` turns the whole behaviour off.
 *
 * @since 1.0.0
 */
final class PluginLoadOrder
{
    /**
     * How long a repair on an admin screen waits before it may run again, in seconds. It bounds
     * the cost when another plugin moves itself to the front on every request too: the two never
     * trade places more than once an hour.
     *
     * @since 1.0.0
     */
    private const REPAIR_INTERVAL = 3600;

    /**
     * Priority of the load-order filters. Other mail plugins reorder the option from the same
     * filter at the default priority, so this one runs well after them and has the last word —
     * while still leaving room for a site that deliberately wants to run later.
     *
     * @since 1.0.0
     */
    private const FILTER_PRIORITY = 9999;

    /**
     * @since 1.0.0
     *
     * @param string $pluginFile The plugin's entry file, as `plugin_basename()` returns it.
     */
    public function __construct(private readonly string $pluginFile) {}

    /**
     * Hook the reordering onto every write of the active-plugins option.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register(): void
    {
        if (! $this->enabled()) {
            return;
        }
        \add_filter('pre_update_option_active_plugins', [$this, 'promoteInList'], self::FILTER_PRIORITY);
        \add_filter('pre_update_site_option_active_sitewide_plugins', [$this, 'promoteInMap'], self::FILTER_PRIORITY);
    }

    /**
     * Put the plugin's entry first in a list of active plugins.
     *
     * @since 1.0.0
     *
     * @param  mixed $plugins The value being written to `active_plugins`.
     * @return mixed The value to write.
     */
    public function promoteInList(mixed $plugins): mixed
    {
        if (! \is_array($plugins)) {
            return $plugins;
        }
        $index = array_search($this->pluginFile, $plugins, true);
        if ($index === false || $index === 0) {
            return $plugins;
        }
        unset($plugins[$index]);
        array_unshift($plugins, $this->pluginFile);

        return array_values($plugins);
    }

    /**
     * Put the plugin's entry first in a network's map of active plugins.
     *
     * @since 1.0.0
     *
     * @param  mixed $plugins The value being written to `active_sitewide_plugins`.
     * @return mixed The value to write.
     */
    public function promoteInMap(mixed $plugins): mixed
    {
        if (! \is_array($plugins) || ! isset($plugins[$this->pluginFile]) || array_key_first($plugins) === $this->pluginFile) {
            return $plugins;
        }
        $activated = $plugins[$this->pluginFile];
        unset($plugins[$this->pluginFile]);

        return [$this->pluginFile => $activated] + $plugins;
    }

    /**
     * Move the plugin to the front of the load order now, when it is not already there.
     *
     * @since 1.0.0
     *
     * @return bool True when the order was rewritten.
     */
    public function ensureFirst(): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        return \is_multisite() && $this->isNetworkActive()
            ? $this->promoteNetwork()
            : $this->promoteSite();
    }

    /**
     * Whether the site lets the plugin keep itself first.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    private function enabled(): bool
    {
        /**
         * Filters whether BooleanSMTP keeps itself first in the plugin load order.
         *
         * Being first is what lets the plugin define `wp_mail()` and therefore send, log and
         * alert on the site's email. Return false to leave the order alone — on a site that
         * deliberately lets another plugin handle mail, for instance.
         *
         * @since 1.0.0
         *
         * @param  bool   $enabled    Whether to move the plugin to the front. Default true.
         * @param  string $pluginFile The plugin's entry file, as `plugin_basename()` returns it.
         * @return bool Whether to move the plugin to the front.
         */
        return (bool) \apply_filters('boolean_smtp_load_first', true, $this->pluginFile);
    }

    /**
     * Repair the order from an admin screen, at most once per interval, and only while another
     * plugin holds `wp_mail()`.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function repairWhenOutranked(): void
    {
        if (BooleanSmtpWpMail::isTakeoverActive()) {
            return;
        }
        if (\get_site_transient('boolean_smtp_load_order_repaired') !== false) {
            return;
        }
        \set_site_transient('boolean_smtp_load_order_repaired', 1, self::REPAIR_INTERVAL);
        $this->ensureFirst();
    }

    /**
     * Whether the plugin is network-activated.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    private function isNetworkActive(): bool
    {
        $network = \get_site_option('active_sitewide_plugins', []);

        return \is_array($network) && isset($network[$this->pluginFile]);
    }

    /**
     * Move the entry to the front of this site's `active_plugins`.
     *
     * @since 1.0.0
     *
     * @return bool True when the option was rewritten.
     */
    private function promoteSite(): bool
    {
        $active = \get_option('active_plugins', []);
        if (! \is_array($active) || array_search($this->pluginFile, $active, true) === false) {
            return false;
        }
        // The value is reordered here and again by the registered filter, which WordPress applies
        // after every other plugin's — so another mail plugin putting itself first inside the
        // same write does not get the last word.
        return (bool) \update_option('active_plugins', $this->promoteInList($active));
    }

    /**
     * Move the entry to the front of the network's `active_sitewide_plugins`, which WordPress
     * loads before any single site's plugins and keys by file name.
     *
     * @since 1.0.0
     *
     * @return bool True when the option was rewritten.
     */
    private function promoteNetwork(): bool
    {
        $network = \get_site_option('active_sitewide_plugins', []);
        if (! \is_array($network) || ! isset($network[$this->pluginFile]) || array_key_first($network) === $this->pluginFile) {
            return false;
        }
        return (bool) \update_site_option('active_sitewide_plugins', $this->promoteInMap($network));
    }
}
