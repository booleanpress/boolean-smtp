import { useCallback, useEffect, useRef, useState } from 'react';
import api from '@/services/api';

/**
 * Every key of the `dashboard/onboarding` record with its default, mirrored from the server contract.
 *
 * @since 1.0.0
 */
export const EMPTY_ONBOARDING = {
    start: false,
    provider: false,
    connect: false,
    verify: false,
    review: false,
    send_test: false,
    verify_skipped: false,
    draft_connection_id: null,
    applied_connection_id: null,
    migration_source: null,
    dismissed_at: null,
};

/**
 * Load and patch the onboarding progress record. `patch()` merges on the server and mirrors the
 * saved record locally; concurrent patches are serialised so a fast Continue never loses a flag.
 *
 * @since 1.0.0
 *
 * @returns {{ onboarding: object, loaded: boolean, error: string, patch: (changes: object) => Promise<object>, reload: () => Promise<object> }}
 */
export function useOnboardingState() {
    const [onboarding, setOnboarding] = useState(EMPTY_ONBOARDING);
    const [loaded, setLoaded] = useState(false);
    const [error, setError] = useState('');
    const stateRef = useRef(EMPTY_ONBOARDING);
    const queueRef = useRef(Promise.resolve());

    const reload = useCallback(async () => {
        const res = await api.get('dashboard/onboarding');
        const next = { ...EMPTY_ONBOARDING, ...(res?.data || {}) };
        stateRef.current = next;
        setOnboarding(next);
        return next;
    }, []);

    useEffect(() => {
        let cancelled = false;
        const load = async () => {
            try {
                await reload();
            } catch (err) {
                if (!cancelled) setError(err?.message || 'Failed to load onboarding progress.');
            } finally {
                if (!cancelled) setLoaded(true);
            }
        };
        load();
        return () => {
            cancelled = true;
        };
    }, [reload]);

    const patch = useCallback((changes) => {
        const run = async () => {
            const res = await api.post('dashboard/onboarding', { onboarding: { ...stateRef.current, ...changes } });
            const next = { ...EMPTY_ONBOARDING, ...(res?.data || {}) };
            stateRef.current = next;
            setOnboarding(next);
            return next;
        };
        const chained = queueRef.current.then(run, run);
        queueRef.current = chained.catch(() => {});
        return chained;
    }, []);

    return { onboarding, loaded, error, patch, reload };
}
