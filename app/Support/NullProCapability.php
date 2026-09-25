<?php

/**
 * Default Pro capability implementation for the free plugin.
 *
 * Bound to {@see ProCapabilityContract} when the Pro add-on is not active, so free-plugin code can
 * query Pro capabilities unconditionally without null checks.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Support;

use BooleanSmtp\Contracts\ProCapabilityContract;

/**
 * Default Pro capability implementation for the free plugin.
 *
 * Returns safe free-tier defaults for all capability checks. The Pro add-on overrides this
 * binding at boot with an implementation backed by a real license.
 *
 * @since 1.0.0
 */
class NullProCapability implements ProCapabilityContract
{
    /**
     * Determine whether the Pro add-on plugin is active.
     *
     * @since 1.0.0
     *
     * @return bool True when the `BOOLEAN_SMTP_PRO_VERSION` constant is defined.
     */
    public function isInstalled(): bool
    {
        return defined('BOOLEAN_SMTP_PRO_VERSION');
    }

    /**
     * Determine whether a valid Pro license is active.
     *
     * @since 1.0.0
     *
     * @return bool Always false; the free plugin has no license.
     */
    public function isLicensed(): bool
    {
        return false;
    }

    /**
     * Get the name of the active Pro plan.
     *
     * @since 1.0.0
     *
     * @return string|null Always null; the free plugin has no plan.
     */
    public function getPlan(): ?string
    {
        return null;
    }

    /**
     * Determine whether a Pro feature is available.
     *
     * @since 1.0.0
     *
     * @param  string $featureKey One of the {@see ProFeatureKeys} constants.
     * @return bool Always false; no Pro feature is available in the free plugin.
     */
    public function can(string $featureKey): bool
    {
        return false;
    }

    /**
     * Get the map of Pro feature availability.
     *
     * @since 1.0.0
     *
     * @return array<string, bool> Always empty.
     */
    public function getFeatures(): array
    {
        return [];
    }

    /**
     * Get the URL shown to users to upgrade to Pro.
     *
     * Empty by default: the free plugin shows no upgrade prompt while none is set.
     *
     * @since 1.0.0
     *
     * @return string The upgrade URL, or an empty string for none.
     */
    public function getUpgradeUrl(): string
    {
        $default = '';

        if (function_exists('apply_filters')) {
            /**
             * Filters the URL shown to users to upgrade to BooleanSMTP Pro.
             *
             * @since 1.0.0
             *
             * @param string $url The upgrade URL; empty by default, which hides every upgrade
             *                    prompt. Return a URL to show them and send them there.
             * @return string The filtered URL.
             */
            return (string) \apply_filters('boolean_smtp_pro_upgrade_url', $default);
        }

        return $default;
    }

    /**
     * Get the current license status.
     *
     * @since 1.0.0
     *
     * @return string One of `installed_unlicensed` or `not_installed`.
     */
    public function getLicenseStatus(): string
    {
        if ($this->isInstalled()) {
            return 'installed_unlicensed';
        }

        return 'not_installed';
    }

    /**
     * Get the current license health.
     *
     * @since 1.0.0
     *
     * @return string Always `offline`; the free plugin never contacts a license server.
     */
    public function getLicenseHealth(): string
    {
        return 'offline';
    }
}
