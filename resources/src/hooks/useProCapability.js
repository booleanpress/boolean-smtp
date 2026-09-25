/**
 * React hook for querying BooleanSMTP Pro capabilities.
 *
 * Reads from window.BooleanSmtpAdmin.pro (set by AppServiceProvider bootstrap props).
 * Returns safe free-tier defaults when Pro is not installed.
 */

const PRO_FEATURE_VALUE_PROPS = {
    'connections.oneclick.save': 'One-click provider setup with automatic credential configuration.',
    'connections.app_permission.save': 'Send as any mailbox on your Microsoft 365 tenant with one admin consent -- no per-mailbox sign-in.',
    'routing.advanced': 'Advanced routing operators including regex matching, conditional chains, and domain-based rules.',
    'routing.bulk_automation': 'Bulk routing automation with scheduled rule activation and provider failover chains.',
    'reports.advanced': 'Detailed delivery reports with provider comparison, trend analysis, and exportable data.',
    'reports.historical': 'Historical analytics with long-term trend tracking and segmented insights.',
    'analytics.dashboard': 'Real-time analytics dashboard with hourly trends, provider scoring, and delivery intelligence.',
    'queue.intelligence': 'Adaptive retry strategies with provider scoring and automatic failover ranking.',
    'deliverability.scoring': 'Domain health scoring with DNS checks, provider recommendations, and risk alerts.',
    'governance.audit': 'Enterprise audit trail with granular role controls and policy presets.',
    'notifications.advanced': 'Proactive connection-health alerts, unlimited notification channels per provider, and smart alert routing.',
};

import { translate } from './useTranslations';

function getProConfig() {
    return window.BooleanSmtpAdmin?.pro || {};
}

/** Translated value proposition for a Pro feature key (`pro.value_prop.<key>` in the i18n payload). */
export function proFeatureValueProp(featureKey) {
    if (!featureKey) return '';
    const fallback = PRO_FEATURE_VALUE_PROPS[featureKey] || '';
    return translate(`pro.value_prop.${featureKey}`, fallback);
}

/**
 * @param {string} [featureKey] - Optional feature key to check access for
 * @returns {object} Pro capability state
 */
export function useProCapability(featureKey) {
    const pro = getProConfig();
    const isProInstalled = Boolean(pro.installed);
    const isProLicensed = Boolean(pro.licensed);
    const features = pro.features || {};

    const hasFeature = featureKey
        ? Boolean(isProLicensed && features[featureKey])
        : false;

    // 3-state lock status: 'unlocked' | 'not_installed' | 'not_licensed'
    let lockState = 'unlocked';
    if (featureKey) {
        if (!isProInstalled) {
            lockState = 'not_installed';
        } else if (!isProLicensed) {
            lockState = 'not_licensed';
        } else if (!features[featureKey]) {
            lockState = 'not_licensed';
        }
    }

    return {
        isProInstalled,
        isProLicensed,
        plan: pro.plan || null,
        licenseStatus: pro.licenseStatus || 'not_installed',
        licenseHealth: pro.licenseHealth || 'offline',
        upgradeUrl: pro.upgradeUrl || '',
        features,
        hasFeature,
        lockState,
        valueProp: proFeatureValueProp(featureKey),
    };
}

export { PRO_FEATURE_VALUE_PROPS };
