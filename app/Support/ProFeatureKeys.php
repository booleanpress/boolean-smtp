<?php

/**
 * Canonical feature keys for Pro capability gating.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Support;

/**
 * Canonical feature keys of the Pro add-on.
 *
 * A catalogue only: the plugin itself never checks them. The add-on's middleware and edition
 * policies check them against its licence, and the admin UI names them in upgrade cards through
 * the `useProCapability()` hook.
 *
 * @since 1.0.0
 */
class ProFeatureKeys
{
    /**
     * Saving a connection through one-click provider setup.
     *
     * @since 1.0.0
     */
    const CONNECTIONS_ONECLICK_SAVE = 'connections.oneclick.save';

    /**
     * Saving a Microsoft Graph application-permission connection: client-credentials
     * authentication that can send as any mailbox in the tenant with a single admin consent,
     * without per-mailbox OAuth.
     *
     * @since 1.0.0
     */
    const CONNECTIONS_APP_PERMISSION_SAVE = 'connections.app_permission.save';

    /**
     * Managing SES sender identities and DKIM: listing, verifying, and deleting SES sender
     * identities, and reading send quota and statistics.
     *
     * @since 1.0.0
     */
    const CONNECTIONS_SES_IDENTITY_MANAGEMENT = 'connections.ses.identity_management';

    /**
     * Advanced routing rule operators, including regex matching, conditional chains, and
     * domain-based rules.
     *
     * @since 1.0.0
     */
    const ROUTING_ADVANCED = 'routing.advanced';

    /**
     * Bulk routing automation, including scheduled rule activation and provider failover chains.
     *
     * @since 1.0.0
     */
    const ROUTING_BULK_AUTOMATION = 'routing.bulk_automation';

    /**
     * Advanced delivery reports, including provider comparison, trend analysis, and data export.
     *
     * @since 1.0.0
     */
    const REPORTS_ADVANCED = 'reports.advanced';

    /**
     * Historical analytics with long-term trend tracking and segmented insights.
     *
     * @since 1.0.0
     */
    const REPORTS_HISTORICAL = 'reports.historical';

    /**
     * Real-time analytics dashboard with hourly trends and provider scoring.
     *
     * @since 1.0.0
     */
    const ANALYTICS_DASHBOARD = 'analytics.dashboard';

    /**
     * Adaptive send-queue retry strategies with provider scoring and automatic failover ranking.
     *
     * @since 1.0.0
     */
    const QUEUE_INTELLIGENCE = 'queue.intelligence';

    /**
     * Domain deliverability scoring, including DNS checks and provider recommendations.
     *
     * @since 1.0.0
     */
    const DELIVERABILITY_SCORING = 'deliverability.scoring';

    /**
     * Enterprise audit trail with granular role controls and policy presets.
     *
     * @since 1.0.0
     */
    const GOVERNANCE_AUDIT = 'governance.audit';

    /**
     * Advanced notification features, including proactive connection-health alerts and unlimited
     * notification channels per provider.
     *
     * @since 1.0.0
     */
    const NOTIFICATIONS_ADVANCED = 'notifications.advanced';

    /**
     * Human-readable value propositions for each feature key.
     *
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    public static function valueProps(): array
    {
        return [
            self::CONNECTIONS_ONECLICK_SAVE  => 'One-click provider setup with automatic credential configuration.',
            self::CONNECTIONS_APP_PERMISSION_SAVE => 'Send as any mailbox on your Microsoft 365 tenant with one admin consent -- no per-mailbox sign-in.',
            self::CONNECTIONS_SES_IDENTITY_MANAGEMENT => 'Manage SES sender identities and DKIM directly from the connection screen, with live send quota and statistics.',
            self::ROUTING_ADVANCED          => 'Advanced routing operators including regex matching, conditional chains, and domain-based rules.',
            self::ROUTING_BULK_AUTOMATION   => 'Bulk routing automation with scheduled rule activation and provider failover chains.',
            self::REPORTS_ADVANCED          => 'Detailed delivery reports with provider comparison, trend analysis, and exportable data.',
            self::REPORTS_HISTORICAL        => 'Historical analytics with long-term trend tracking and segmented insights.',
            self::ANALYTICS_DASHBOARD       => 'Real-time analytics dashboard with hourly trends, provider scoring, and delivery intelligence.',
            self::QUEUE_INTELLIGENCE        => 'Adaptive retry strategies with provider scoring and automatic failover ranking.',
            self::DELIVERABILITY_SCORING    => 'Domain health scoring with DNS checks, provider recommendations, and risk alerts.',
            self::GOVERNANCE_AUDIT          => 'Enterprise audit trail with granular role controls and policy presets.',
            self::NOTIFICATIONS_ADVANCED    => 'Proactive connection-health alerts, unlimited notification channels per provider, and smart alert routing.',
        ];
    }

    /**
     * All canonical feature keys.
     *
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::CONNECTIONS_ONECLICK_SAVE,
            self::CONNECTIONS_APP_PERMISSION_SAVE,
            self::CONNECTIONS_SES_IDENTITY_MANAGEMENT,
            self::ROUTING_ADVANCED,
            self::ROUTING_BULK_AUTOMATION,
            self::REPORTS_ADVANCED,
            self::REPORTS_HISTORICAL,
            self::ANALYTICS_DASHBOARD,
            self::QUEUE_INTELLIGENCE,
            self::DELIVERABILITY_SCORING,
            self::GOVERNANCE_AUDIT,
            self::NOTIFICATIONS_ADVANCED,
        ];
    }
}
