import { Compass, Plug, KeyRound, Send, ClipboardCheck } from 'lucide-react';

/**
 * Wizard step ids, in rail order. They are also the boolean keys of the `dashboard/onboarding`
 * record the wizard and the dashboard checklist share.
 *
 * @since 1.0.0
 */
export const ONBOARDING_STEP_IDS = ['start', 'provider', 'connect', 'verify', 'review'];

/**
 * Step ids of the earlier six-screen wizard, mapped to the step that replaces each one so old
 * deep links (`#/onboard?step=create_connection`) keep working.
 *
 * @since 1.0.0
 */
export const LEGACY_STEP_MAP = {
    migrate: 'start',
    create_connection: 'provider',
    send_test: 'verify',
    setup_alerts: 'review',
    final_settings: 'review',
};

/**
 * The five wizard steps: rail labels, checklist labels and icons. Icons are Lucide components.
 *
 * @since 1.0.0
 */
export const onboardingSteps = [
    {
        id: 'start',
        titleKey: 'onboarding.step.start.title',
        subtitleKey: 'onboarding.step.start.subtitle',
        title: 'Start',
        subtitle: 'New connection or import',
        icon: Compass,
    },
    {
        id: 'provider',
        titleKey: 'onboarding.step.provider.title',
        subtitleKey: 'onboarding.step.provider.subtitle',
        title: 'Provider',
        subtitle: 'Choose how this site sends',
        icon: Plug,
    },
    {
        id: 'connect',
        titleKey: 'onboarding.step.connect.title',
        subtitleKey: 'onboarding.step.connect.subtitle',
        title: 'Connect',
        subtitle: 'Sender identity and credentials',
        icon: KeyRound,
    },
    {
        id: 'verify',
        titleKey: 'onboarding.step.verify.title',
        subtitleKey: 'onboarding.step.verify.subtitle',
        title: 'Verify',
        subtitle: 'Optional delivery test',
        icon: Send,
    },
    {
        id: 'review',
        titleKey: 'onboarding.step.review.title',
        subtitleKey: 'onboarding.step.review.subtitle',
        title: 'Review',
        subtitle: 'Apply the setup',
        icon: ClipboardCheck,
    },
];

/**
 * Resolve a step id from a query value, accepting the earlier wizard's ids.
 *
 * @since 1.0.0
 *
 * @param {string|null|undefined} value The `step` query value.
 * @returns {string|null} A current step id, or null when the value is unknown.
 */
export function resolveOnboardingStepId(value) {
    if (!value) return null;
    if (ONBOARDING_STEP_IDS.includes(value)) return value;
    return LEGACY_STEP_MAP[value] || null;
}

/**
 * Path for a wizard deep link (HashRouter); a draft id is carried along when known.
 *
 * @since 1.0.0
 *
 * @param {string} stepId Step to open.
 * @param {number|null} [connectionId] Draft connection to load.
 * @returns {string}
 */
export function onboardingStepPath(stepId, connectionId = null) {
    const params = new URLSearchParams({ step: stepId });
    if (connectionId) params.set('connection', String(connectionId));
    return `/onboard?${params.toString()}`;
}

/**
 * Whether a checklist row is done. `verify` is done once a test email has been accepted from
 * anywhere (the wizard, the Test Email tool) — skipping the step in the wizard does not close it.
 *
 * @since 1.0.0
 *
 * @param {string} stepId Step id.
 * @param {object|null|undefined} onboarding The `dashboard/onboarding` record.
 * @returns {boolean}
 */
export function isOnboardingStepComplete(stepId, onboarding) {
    if (!onboarding || typeof onboarding !== 'object') return false;
    if (stepId === 'verify') return !!onboarding.send_test;
    return !!onboarding[stepId];
}

/**
 * Whether setup is applied, or every checklist row is done before Apply.
 *
 * @since 1.0.0
 *
 * @param {object|null|undefined} onboarding The `dashboard/onboarding` record.
 * @returns {boolean}
 */
export function isOnboardingComplete(onboarding) {
    if (onboarding?.review && onboarding?.applied_connection_id) return true;
    return ONBOARDING_STEP_IDS.every(id => isOnboardingStepComplete(id, onboarding));
}
