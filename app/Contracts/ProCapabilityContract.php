<?php
/**
 * Contract for querying Pro licensing and feature availability from free code.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Contracts;

/**
 * Guarantees a way for free code to check whether the Pro add-on is installed, licensed, and
 * which features it unlocks, without depending on Pro code directly.
 *
 * @since 1.0.0
 */
interface ProCapabilityContract
{
    /**
     * Determine whether the Pro add-on is installed and active.
     *
     * @since 1.0.0
     *
     * @return bool True when Pro is installed and active.
     */
    public function isInstalled(): bool;

    /**
     * Determine whether Pro has a valid license.
     *
     * @since 1.0.0
     *
     * @return bool True when Pro is installed and licensed.
     */
    public function isLicensed(): bool;

    /**
     * Return the identifier of the current license plan.
     *
     * @since 1.0.0
     *
     * @return string|null The plan identifier, or null when there is no active license.
     */
    public function getPlan(): ?string;

    /**
     * Determine whether a Pro feature is available.
     *
     * @since 1.0.0
     *
     * @param  string $featureKey Feature key to check.
     * @return bool True when the feature is available under the current license.
     */
    public function can(string $featureKey): bool;

    /**
     * Return the availability of every known Pro feature.
     *
     * @since 1.0.0
     *
     * @return array<string, bool> Availability keyed by feature key.
     */
    public function getFeatures(): array;

    /**
     * Return the URL to send the user to for upgrading or purchasing a license.
     *
     * @since 1.0.0
     *
     * @return string The upgrade URL.
     */
    public function getUpgradeUrl(): string;

    /**
     * Return the current license status.
     *
     * @since 1.0.0
     *
     * @return string One of "not_installed", "installed_unlicensed" or "licensed".
     */
    public function getLicenseStatus(): string;

    /**
     * Return the health of the license verification.
     *
     * @since 1.0.0
     *
     * @return string One of "ok", "degraded" or "offline".
     */
    public function getLicenseHealth(): string;
}
