import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router';
import { toast } from 'sonner';
import api, { fieldErrors } from '../services/api';
import { MAIL_PROVIDERS } from '../config/mailers';
import { ONBOARDING_STEP_IDS, onboardingSteps, resolveOnboardingStepId } from '../config/onboardingSteps';
import { useTranslations } from '../hooks/useTranslations';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import ConnectStep from '@/components/onboarding/ConnectStep';
import DoneScreen from '@/components/onboarding/DoneScreen';
import ImportPickStep from '@/components/onboarding/ImportPickStep';
import ProviderStep, { providerCopy } from '@/components/onboarding/ProviderStep';
import ReviewStep from '@/components/onboarding/ReviewStep';
import StartStep from '@/components/onboarding/StartStep';
import StepRail from '@/components/onboarding/StepRail';
import VerifyStep from '@/components/onboarding/VerifyStep';
import { useOnboardingState } from '@/components/onboarding/useOnboardingState';
import {
    OAUTH_CONSENT_TAB,
    OAUTH_CONSENT_WINDOW,
    OAUTH_DRIVERS,
    OAUTH_RESULT_KEY,
    OAUTH_RETURN_MARKER,
    displayValue,
    initialWizardSettings,
    isMaskedSecret,
    isSecretKey,
    missingRequiredKeys,
    proposedConnectionName,
    splitWizardSchema,
} from '@/components/onboarding/providerCatalog';

const STEP_START = 0;
const STEP_PROVIDER = 1;
const STEP_CONNECT = 2;
const STEP_VERIFY = 3;
const STEP_REVIEW = 4;
const RETENTION_OPTIONS = [7, 30, 90];

/**
 * The retention option closest to the days another plugin kept its logs for.
 *
 * @since 1.0.0
 *
 * @param {number} days Retention the source plugin used.
 * @returns {number} One of the wizard's retention options.
 */
export function nearestRetention(days) {
    return RETENTION_OPTIONS.reduce((best, option) => (Math.abs(option - days) < Math.abs(best - days) ? option : best), RETENTION_OPTIONS[0]);
}

/**
 * Whether an assessed connection is the draft the wizard holds: by id when the import assigned
 * one (the API returns ids as strings), by name when the assessment is a dry run.
 *
 * @since 1.0.0
 *
 * @param {{ connection_id?: number|string|null, name: string }} connection Assessed connection.
 * @param {{ id: number|string, name?: string }} draft The wizard's draft.
 * @returns {boolean}
 */
export function isDraftOf(connection, draft) {
    if (connection.connection_id !== null && connection.connection_id !== undefined) {
        return String(connection.connection_id) === String(draft.id);
    }
    return connection.name === draft.name;
}

/**
 * The imported drafts the Review step lists as staying inactive: every connection the import
 * could write, minus the one being set up. The picked source key identifies it even after the
 * draft is renamed on Review and when the assessment carries no ids yet (a resumed wizard reads
 * it back as a dry run).
 *
 * @since 1.0.0
 *
 * @param {Array<{ name: string, status: string, source_key?: string, connection_id?: number|string|null }>} connections Assessed connections.
 * @param {{ id: number|string, name?: string }|null} draft The wizard's draft.
 * @param {string|null} pickedKey The source key chosen on the import step.
 * @returns {Array<object>}
 */
export function otherImportedDrafts(connections, draft, pickedKey) {
    if (!draft) return [];
    return (connections || []).filter(c => c.status !== 'unsupported' && !(pickedKey && c.source_key === pickedKey) && !isDraftOf(c, draft));
}

/**
 * The name of a migration source id, from the catalog the bootstrap carries.
 *
 * @since 1.0.0
 *
 * @param {string|null} slug Source id.
 * @returns {string}
 */
function migrationSourceName(slug) {
    const catalog = (typeof window !== 'undefined' && window.BooleanSmtpAdmin?.migrationSources) || [];
    return catalog.find(source => source.id === slug)?.name || slug || '';
}

/**
 * Short local time for the preview's "ran at" values.
 *
 * @since 1.0.0
 *
 * @returns {string}
 */
function now() {
    return new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

/**
 * Map a 422 `errors` object onto setting keys (`settings.host` → `host`).
 *
 * @since 1.0.0
 *
 * @param {object|null} errors API field errors.
 * @returns {Record<string, string>}
 */
function settingErrors(errors) {
    const flat = fieldErrors(errors);
    const out = {};
    Object.entries(flat).forEach(([key, message]) => {
        out[key.startsWith('settings.') ? key.slice('settings.'.length) : key] = message;
    });
    return out;
}

/**
 * Read the OAuth outcome the admin URL carries (outside the hash) and remove it, so a reload does
 * not replay it. Two shapes arrive here: the hosted relay's return (`oauth_provider`, `oauth_code`
 * + `oauth_state`, `oauth_connection_id`, or `oauth_status` + `oauth_message` when consent was
 * refused) and the site callback's own (`oauth`, `status`, `message`).
 *
 * @since 1.0.0
 *
 * @returns {{ provider: string, status: string, message: string, code: string, connectionId: string }|null}
 */
function takeOAuthReturn() {
    if (typeof window === 'undefined') return null;
    const url = new URL(window.location.href);
    const p = url.searchParams;
    const provider = p.get('oauth_provider') || p.get('oauth') || '';
    const code = p.get('oauth_code') || '';
    const status = p.get('oauth_status') || p.get('status') || (code ? 'code' : '');
    if (!provider || !status) return null;
    const message = p.get('oauth_message') || p.get('message') || '';
    const connectionId = p.get('oauth_connection_id') || p.get('connection') || '';
    ['oauth', 'status', 'message', 'oauth_provider', 'oauth_status', 'oauth_message', 'oauth_code', 'oauth_state', 'oauth_connection_id'].forEach(key => p.delete(key));
    window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
    return { provider: provider === 'microsoft' ? 'outlook' : provider, status, message, code, connectionId };
}

/**
 * The OAuth provider slug the API uses for a driver.
 *
 * @since 1.0.0
 *
 * @param {string} driver `google` or `outlook`.
 * @returns {string}
 */
function oauthProviderFor(driver) {
    return driver === 'outlook' ? 'microsoft' : driver;
}

/**
 * Whether this tab is the one the wizard opened for an OAuth consent (the flag was in the
 * opener's sessionStorage when the tab was created, so the copy carries it).
 *
 * @since 1.0.0
 *
 * @returns {boolean}
 */
function isConsentTab() {
    try {
        return window.sessionStorage.getItem(OAUTH_CONSENT_TAB) === '1';
    } catch {
        return false;
    }
}

/**
 * Tell the tab that opened this one how the consent ended. The `storage` event only fires in
 * other tabs, which is exactly the audience.
 *
 * @since 1.0.0
 *
 * @param {{ connectionId: number|string, status: 'connected'|'error', account?: string|null, message?: string }} result
 */
function announceOAuthResult(result) {
    try {
        window.localStorage.setItem(OAUTH_RESULT_KEY, JSON.stringify({ ...result, at: Date.now() }));
    } catch {
        // Without the channel the opener's polling picks the connection up a few seconds later.
    }
}

/** How often the waiting wizard re-reads the draft while the consent tab is open, in ms. */
const OAUTH_WAIT_POLL_MS = 3000;

/**
 * Whether a connection payload says its account is authorised.
 *
 * @since 1.0.0
 *
 * @param {object|null} row Connection payload.
 * @returns {boolean}
 */
function isOAuthConnected(row) {
    return Boolean(row && (row.oauth_refresh_available || row.oauth_token_expires_at));
}

/**
 * The guided setup wizard: Start · Provider · Connect · Verify · Review, then Done. The page owns
 * the wizard state; each step renders the shared shell and preview.
 *
 * @since 1.0.0
 */
export default function OnboardingWizard() {
    const navigate = useNavigate();
    const { t } = useTranslations();
    const [searchParams, setSearchParams] = useSearchParams();
    const { onboarding, loaded: onboardingLoaded, patch } = useOnboardingState();

    const admin = typeof window !== 'undefined' ? (window.BooleanSmtpAdmin || {}) : {};
    const adminEmail = admin.user?.user_email || admin.currentUserEmail || '';
    const siteName = (admin.siteName || '').trim();

    const [stepIndex, setStepIndex] = useState(() => {
        const id = resolveOnboardingStepId(searchParams.get('step'));
        return id ? ONBOARDING_STEP_IDS.indexOf(id) : STEP_START;
    });
    const [initialised, setInitialised] = useState(false);
    const [loadError, setLoadError] = useState('');

    const [path, setPath] = useState('new');
    const [scan, setScan] = useState({ status: 'idle', sources: [] });
    const [migrationSource, setMigrationSource] = useState(null);
    // The import branch: the dry-run assessment of the chosen plugin, the connection the user
    // picked from it, and the log import's progress after Apply.
    const [assessment, setAssessment] = useState(null);
    const [importPick, setImportPick] = useState(null);
    const [importResolutions, setImportResolutions] = useState({});
    const [logsImport, setLogsImport] = useState(null);
    const importFocusRef = useRef(null);

    const [driver, setDriver] = useState(null);
    const [metadata, setMetadata] = useState(null);
    const metadataCache = useRef({});
    const [settings, setSettings] = useState({});
    const [settingsDriver, setSettingsDriver] = useState(null);
    const [name, setName] = useState('');
    const [nameTouched, setNameTouched] = useState(false);
    const [errors, setErrors] = useState({});
    const [draft, setDraft] = useState(null);
    const savedSettingsRef = useRef(null);


    const [sesCheck, setSesCheck] = useState({ status: 'idle' });
    const [oauth, setOauth] = useState({ connected: false, account: null, status: 'idle', message: '' });
    const oauthReturnRef = useRef(null);
    const consentWindowRef = useRef(null);
    const consentTab = useMemo(() => isConsentTab(), []);

    const [probe, setProbe] = useState({ status: 'idle' });
    const [test, setTest] = useState({ status: 'idle' });
    const [recipient, setRecipient] = useState(adminEmail);

    const [review, setReview] = useState({ makePrimary: true, retention: 30, importLogs: false });
    const [hasOtherActive, setHasOtherActive] = useState(false);

    const [busy, setBusy] = useState(false);
    const [skipBusy, setSkipBusy] = useState(false);
    const [discardBusy, setDiscardBusy] = useState(false);
    const [applyResult, setApplyResult] = useState(null);

    const steps = useMemo(() => onboardingSteps.map(s => ({ id: s.id, title: t(s.titleKey, s.title) })), [t]);
    const provider = useMemo(() => MAIL_PROVIDERS.find(p => p.driver === driver) || null, [driver]);
    const providerName = provider ? t(`mailers.${provider.driver}.name`, provider.name) : '';
    const copy = useMemo(() => providerCopy(t), [t]);
    const methodLine = driver ? (copy[driver]?.method || '') : '';
    const hasProbe = driver !== null && driver !== 'php';
    const sender = { fromEmail: settings.from_email || '', fromName: settings.from_name || '' };

    const goTo = useCallback((index, connectionId = draft?.id) => {
        setStepIndex(index);
        const params = { step: ONBOARDING_STEP_IDS[index] };
        if (connectionId) params.connection = String(connectionId);
        setSearchParams(params, { replace: true });
    }, [draft, setSearchParams]);

    const loadMetadata = useCallback(async (nextDriver) => {
        if (metadataCache.current[nextDriver]) return metadataCache.current[nextDriver];
        const res = await api.get(`transports/${nextDriver}`);
        const data = res?.data || res;
        metadataCache.current[nextDriver] = data;
        return data;
    }, []);

    // First load: site state, then the draft the record (or the URL) points at.
    useEffect(() => {
        if (!onboardingLoaded || initialised) return;
        let cancelled = false;
        // The return is read once from the URL; a re-run of this effect must not lose it.
        oauthReturnRef.current = takeOAuthReturn() || oauthReturnRef.current;
        (async () => {
            try {
                const [connRes, settingsRes] = await Promise.all([
                    api.get('connections'),
                    api.get('settings'),
                ]);
                if (cancelled) return;

                const connections = connRes?.data || [];
                const retention = Number(settingsRes?.data?.log_retention_days);
                setReview(prev => ({ ...prev, retention: RETENTION_OPTIONS.includes(retention) ? retention : 30 }));

                const requested = Number(searchParams.get('connection')) || onboarding.draft_connection_id || null;
                let resumed = null;
                if (requested) {
                    let row = null;
                    try {
                        const res = await api.get(`connections/${requested}`);
                        row = res?.data || null;
                    } catch {
                        row = null;
                    }
                    if (row && row.id && !row.is_active) {
                        resumed = row;
                    } else if (!cancelled) {
                        // The record or the URL pointed at a draft that is gone (deleted from Connections,
                        // or the site was reset) or already applied: say so instead of silently starting over.
                        toast.info(row && row.is_active
                            ? t('onboarding.draft_already_active', 'That connection is already active — this starts a new setup.')
                            : t('onboarding.draft_missing', 'The saved draft no longer exists — starting again from the provider step.'));
                    }
                }
                if (cancelled) return;

                setHasOtherActive(connections.some(c => c.is_active && (!resumed || c.id !== resumed.id)));

                if (resumed) {
                    const meta = await loadMetadata(resumed.driver);
                    if (cancelled) return;
                    setDriver(resumed.driver);
                    setMetadata(meta);
                    const values = { ...initialWizardSettings(resumed.driver, meta.settings_schema, {}), ...(resumed.settings || {}) };
                    setSettings(values);
                    setSettingsDriver(resumed.driver);
                    savedSettingsRef.current = values;
                    setName(resumed.name || '');
                    setNameTouched(true);
                    setDraft(resumed);
                    const back = oauthReturnRef.current;
                    const codeForThisDraft = back && back.code && (!back.connectionId || String(back.connectionId) === String(resumed.id)) ? back.code : '';
                    if (codeForThisDraft) {
                        // The hosted relay brought an authorization code: exchange it now, with this
                        // session's credentials, and read the connected account back.
                        setOauth({ connected: false, account: null, status: 'exchanging', message: '' });
                        try {
                            await api.post(`connections/${resumed.id}/oauth-token`, { token: codeForThisDraft, delivery_mode: 'api' });
                            const fresh = (await api.get(`connections/${resumed.id}`))?.data || resumed;
                            if (cancelled) return;
                            setDraft(fresh);
                            const connected = isOAuthConnected(fresh);
                            setOauth({ connected, account: fresh.oauth_account_email || null, status: 'idle', message: '' });
                            if (consentTab) {
                                announceOAuthResult({ connectionId: fresh.id, status: connected ? 'connected' : 'error', account: fresh.oauth_account_email || null, message: connected ? '' : t('onboarding.oauth_no_grant', 'The provider did not grant offline access.') });
                            }
                        } catch (err) {
                            if (cancelled) return;
                            const message = err?.message || t('onboarding.oauth_exchange_failed', 'The authorization code could not be exchanged.');
                            setOauth({ connected: isOAuthConnected(resumed), account: resumed.oauth_account_email || null, status: 'error', message });
                            if (consentTab) {
                                announceOAuthResult({ connectionId: resumed.id, status: 'error', message });
                            }
                        }
                    } else {
                        const refused = back && back.status !== 'success' && back.status !== 'code';
                        setOauth({
                            connected: isOAuthConnected(resumed),
                            account: resumed.oauth_account_email || null,
                            status: refused ? 'error' : 'idle',
                            message: refused ? back.message : '',
                        });
                        if (consentTab && back) {
                            announceOAuthResult({ connectionId: resumed.id, status: refused ? 'error' : 'connected', account: resumed.oauth_account_email || null, message: refused ? back.message : '' });
                        }
                    }
                    if (onboarding.migration_source) {
                        setPath('import');
                        setMigrationSource(onboarding.migration_source);
                        try {
                            await loadAssessment(onboarding.migration_source);
                        } catch {
                            // The Review step offers the log import only when the assessment is known.
                        }
                    }
                    const urlStep = resolveOnboardingStepId(searchParams.get('step'));
                    const firstIncomplete = !onboarding.connect ? STEP_CONNECT : (!onboarding.verify && !onboarding.verify_skipped ? STEP_VERIFY : STEP_REVIEW);
                    const target = urlStep ? Math.min(ONBOARDING_STEP_IDS.indexOf(urlStep), STEP_REVIEW) : firstIncomplete;
                    setStepIndex(Math.max(target, STEP_START));
                } else {
                    const urlStep = resolveOnboardingStepId(searchParams.get('step'));
                    // Without a draft nothing beyond the Provider step is reachable.
                    setStepIndex(urlStep ? Math.min(ONBOARDING_STEP_IDS.indexOf(urlStep), STEP_PROVIDER) : STEP_START);
                }
            } catch (err) {
                if (!cancelled) setLoadError(err?.message || t('onboarding.load_failed', 'The wizard could not load.'));
            } finally {
                if (!cancelled) setInitialised(true);
            }
        })();
        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [onboardingLoaded, initialised]);

    // Keep the URL in step with the rail once initialised.
    useEffect(() => {
        if (!initialised || applyResult) return;
        const id = ONBOARDING_STEP_IDS[stepIndex];
        if (searchParams.get('step') !== id || (draft?.id && searchParams.get('connection') !== String(draft.id))) {
            const params = { step: id };
            if (draft?.id) params.connection = String(draft.id);
            setSearchParams(params, { replace: true });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [initialised, stepIndex, draft?.id, applyResult]);

    // Connection check runs when the Verify step is entered.
    const runProbe = useCallback(async () => {
        if (!draft?.id || !hasProbe) return;
        setProbe({ status: 'running' });
        try {
            const res = await api.post(`connections/${draft.id}/test`);
            const data = res?.data || {};
            setProbe(data.success === false
                ? { status: 'failed', message: data.error || res?.message || t('onboarding.verify_probe_failed', 'Connection check failed'), at: now() }
                : { status: 'passed', at: now() });
        } catch (err) {
            setProbe({ status: 'failed', message: err?.message || t('onboarding.verify_probe_failed', 'Connection check failed'), at: now() });
        }
    }, [draft, hasProbe, t]);

    useEffect(() => {
        if (!initialised || stepIndex !== STEP_VERIFY || probe.status !== 'idle') return undefined;
        const timer = setTimeout(runProbe, 0);
        return () => clearTimeout(timer);
    }, [initialised, stepIndex, probe.status, runProbe]);

    const updateSetting = useCallback((key, value) => {
        setSettings(prev => ({ ...prev, [key]: value }));
        setErrors(prev => {
            if (!prev[key]) return prev;
            const next = { ...prev };
            delete next[key];
            return next;
        });
    }, []);

    const focusField = useCallback((key) => {
        if (typeof document === 'undefined') return;
        const el = document.getElementById(key);
        if (el && typeof el.focus === 'function') el.focus();
    }, []);

    const runScan = useCallback(async () => {
        if (scan.status === 'loading' || scan.status === 'done') return;
        setScan({ status: 'loading', sources: [] });
        try {
            const res = await api.get('tools/migration/scan');
            const raw = res?.data || {};
            const sources = Array.isArray(raw) ? raw : Object.values(raw);
            setScan({ status: 'done', sources });
        } catch (err) {
            setScan({ status: 'error', sources: [], error: err?.message || '' });
        }
    }, [scan.status]);

    const handlePathChange = useCallback(async (nextPath) => {
        setPath(nextPath);
        if (nextPath !== 'import') {
            setMigrationSource(null);
            return;
        }
        await runScan();
    }, [runScan]);

    // A wizard resumed on the import branch has chosen its plugin already; the scan says what
    // state that plugin is in, which the Done screen needs to know.
    useEffect(() => {
        if (migrationSource && scan.status === 'idle') runScan();
    }, [migrationSource, scan.status, runScan]);

    /**
     * Whether the plugin being imported from is still running on this site. Null until the scan
     * has answered — nothing is claimed about a plugin the wizard has not looked at.
     */
    const migrationSourceActive = useMemo(
        () => (scan.status === 'done' ? Boolean(scan.sources.find(source => source.slug === migrationSource)?.is_active) : null),
        [scan.status, scan.sources, migrationSource],
    );

    /**
     * Assess the chosen plugin (a dry run: nothing is written) and remember what it holds.
     */
    const loadAssessment = useCallback(async (source) => {
        const res = await api.post('tools/migration/import', { source, dry_run: true, import_logs: true });
        const data = res?.data || {};
        const next = { source, sourceName: migrationSourceName(source), connections: data.connections || [], logs: data.logs || { available: false }, suggestions: data.suggestions || {} };
        setAssessment(next);
        if (next.suggestions.retention_days) {
            setReview(prev => ({ ...prev, retention: nearestRetention(Number(next.suggestions.retention_days)) }));
        }
        return next;
    }, []);

    const continueFromStart = useCallback(async () => {
        setBusy(true);
        try {
            if (path === 'import') {
                const assessed = assessment && assessment.source === migrationSource ? assessment : await loadAssessment(migrationSource);
                if (!assessed.connections.some(c => c.status !== 'unsupported')) {
                    toast.error(t('onboarding.import_nothing', 'None of the connections in {{plugin}} can be imported.', { plugin: assessed.sourceName }));
                    return;
                }
            } else {
                setAssessment(null);
                setImportPick(null);
            }
            await patch({ start: true, migration_source: path === 'import' ? migrationSource : null });
            goTo(STEP_PROVIDER);
        } catch (err) {
            toast.error(err?.message || t('onboarding.save_failed', 'Could not save your progress.'));
        } finally {
            setBusy(false);
        }
    }, [patch, path, migrationSource, assessment, loadAssessment, goTo, t]);

    /**
     * The import branch's second step: write every importable connection of the plugin as an
     * inactive draft (a re-run updates the same drafts), then open the picked one on Connect.
     */
    const continueFromImportPick = useCallback(async () => {
        if (!assessment || !importPick) return;
        setBusy(true);
        try {
            const res = await api.post('tools/migration/import', { source: assessment.source, resolutions: importResolutions });
            const data = res?.data || {};
            const imported = data.connections || [];
            const chosen = imported.find(c => c.source_key === importPick);
            // Keeping the site's own connection for the same sender sets that one up instead.
            const chosenId = chosen?.connection_id || (chosen?.outcome === 'kept' ? chosen.sender_conflict?.existing_id : null);
            if (!chosenId) {
                throw new Error(t('onboarding.import_failed', 'The connection could not be imported.'));
            }
            const row = (await api.get(`connections/${chosenId}`))?.data;
            if (!row?.id) {
                throw new Error(t('onboarding.import_failed', 'The connection could not be imported.'));
            }
            const meta = await loadMetadata(row.driver);
            setAssessment(prev => (prev ? { ...prev, connections: imported } : prev));
            setDriver(row.driver);
            setMetadata(meta);
            const values = { ...initialWizardSettings(row.driver, meta.settings_schema, {}), ...(row.settings || {}) };
            setSettings(values);
            setSettingsDriver(row.driver);
            savedSettingsRef.current = values;
            setName(row.name || '');
            setNameTouched(true);
            setDraft(row);
            setProbe({ status: 'idle' });
            setTest({ status: 'idle' });
            setSesCheck({ status: 'idle' });
            setOauth({ connected: isOAuthConnected(row), account: row.oauth_account_email || null, status: 'idle', message: '' });
            // What the assessment could not bring across is shown on the field itself.
            const next = {};
            (chosen.missing || []).forEach(key => {
                next[key] = t('onboarding.import_missing_field', 'Your old plugin kept this in wp-config.php or did not store it — enter it again.');
            });
            (chosen.issues || []).forEach(issue => {
                next[issue.key] = issue.message;
            });
            setErrors(next);
            importFocusRef.current = Object.keys(next)[0] || null;
            await patch({ provider: true, draft_connection_id: row.id, connect: false, verify: false, verify_skipped: false });
            goTo(STEP_CONNECT, row.id);
        } catch (err) {
            toast.error(err?.message || t('onboarding.import_failed', 'The connection could not be imported.'));
        } finally {
            setBusy(false);
        }
    }, [assessment, importPick, importResolutions, loadMetadata, patch, goTo, t]);

    // On Connect, the first field the import could not fill gets the focus.
    useEffect(() => {
        if (stepIndex !== STEP_CONNECT || !importFocusRef.current) return undefined;
        const key = importFocusRef.current;
        importFocusRef.current = null;
        const timer = setTimeout(() => focusField(key), 50);
        return () => clearTimeout(timer);
    }, [stepIndex, focusField]);

    const handleDriverChange = useCallback((nextDriver) => {
        setDriver(nextDriver);
    }, []);

    const continueFromProvider = useCallback(async () => {
        if (!driver) return;
        setBusy(true);
        try {
            const meta = await loadMetadata(driver);
            setMetadata(meta);
            if (draft && draft.driver !== driver) {
                // The provider changed under a saved draft: the old draft is deleted, a new one starts.
                await api.delete(`connections/${draft.id}`);
                setDraft(null);
                savedSettingsRef.current = null;
                setProbe({ status: 'idle' });
                setTest({ status: 'idle' });
                setSesCheck({ status: 'idle' });
                setOauth({ connected: false, account: null, status: 'idle', message: '' });
                setSettings(initialWizardSettings(driver, meta.settings_schema, { fromEmail: adminEmail, fromName: siteName }));
                setSettingsDriver(driver);
                setNameTouched(false);
                await patch({ provider: true, draft_connection_id: null, connect: false, verify: false, verify_skipped: false });
            } else {
                if (!draft && settingsDriver !== driver) {
                    setSettings(initialWizardSettings(driver, meta.settings_schema, { fromEmail: adminEmail, fromName: siteName }));
                    setSettingsDriver(driver);
                }
                await patch({ provider: true });
            }
            setErrors({});
            goTo(STEP_CONNECT, draft && draft.driver !== driver ? null : draft?.id);
        } catch (err) {
            toast.error(err?.message || t('onboarding.transport_failed', 'Could not load the provider.'));
        } finally {
            setBusy(false);
        }
    }, [driver, draft, settingsDriver, loadMetadata, adminEmail, siteName, patch, goTo, t]);

    /**
     * Validate the visible required fields and save the draft (create or update). Returns the
     * saved row, or null when validation or the API stopped it (errors are already on screen).
     */
    const saveDraft = useCallback(async () => {
        if (!driver || !metadata) return null;
        const schema = metadata.settings_schema || {};
        const missing = missingRequiredKeys(driver, schema, settings);
        if (missing.length > 0) {
            const next = {};
            missing.forEach(key => {
                next[key] = t('onboarding.field_required', '{{field}} is required.', { field: schema[key]?.label || key });
            });
            setErrors(next);
            focusField(missing[0]);
            return null;
        }

        const payloadSettings = { ...settings };
        const connectionName = nameTouched && name.trim() ? name.trim() : proposedConnectionName(providerName, settings.from_email);
        setErrors({});
        try {
            const changed = JSON.stringify(savedSettingsRef.current) !== JSON.stringify(payloadSettings);
            let saved = draft;
            if (!draft) {
                const res = await api.post('connections', { name: connectionName, driver, settings: payloadSettings, is_active: false });
                saved = res?.data || null;
            } else if (changed || connectionName !== draft.name) {
                const res = await api.put(`connections/${draft.id}`, { name: connectionName, driver, settings: payloadSettings, is_active: false });
                saved = res?.data || draft;
            }
            if (!saved?.id) {
                throw new Error(t('onboarding.draft_failed', 'The draft could not be saved.'));
            }
            setDraft(saved);
            setName(saved.name || connectionName);
            savedSettingsRef.current = payloadSettings;
            if (changed || !draft) {
                setProbe({ status: 'idle' });
                setTest({ status: 'idle' });
                setSesCheck({ status: 'idle' });
            }
            await patch({ draft_connection_id: saved.id, ...(changed ? { verify: false, verify_skipped: false } : {}) });
            return { saved, changed };
        } catch (err) {
            const mapped = settingErrors(err?.errors);
            if (Object.keys(mapped).length > 0) {
                setErrors(mapped);
                focusField(Object.keys(mapped)[0]);
            } else {
                toast.error(err?.message || t('onboarding.draft_failed', 'The draft could not be saved.'));
            }
            return null;
        }
    }, [driver, metadata, settings, nameTouched, name, providerName, draft, patch, focusField, t]);

    const continueFromConnect = useCallback(async () => {
        setBusy(true);
        try {
            const result = await saveDraft();
            if (!result) return;
            await patch({ connect: true });
            goTo(STEP_VERIFY, result.saved.id);
        } finally {
            setBusy(false);
        }
    }, [saveDraft, patch, goTo]);

    const validateSes = useCallback(async () => {
        setBusy(true);
        try {
            const result = await saveDraft();
            if (!result) return;
            setSesCheck({ status: 'running' });
            const res = await api.post(`connections/${result.saved.id}/verify-api-credentials`, {
                access_key: settings.api_access_key || '',
                secret: settings.api_secret || '',
                region: settings.api_region || settings.region || 'us-east-1',
                delivery_mode: 'api',
                from_email: settings.from_email || '',
            });
            const data = res?.data || {};
            setSesCheck({
                status: 'passed',
                quota: data.max_24_hour_send != null ? String(data.max_24_hour_send) : undefined,
                sent: data.sent_last_24_hours != null ? String(data.sent_last_24_hours) : undefined,
                identity: data.identity || null,
            });
        } catch (err) {
            // The alert's title already says AWS rejected the keys; keep the reason only.
            const reason = String(err?.message || '').replace(/^AWS rejected these credentials:\s*/i, '');
            setSesCheck({ status: 'failed', message: reason || t('onboarding.ses_invalid_reason_unknown', 'AWS did not say why. Check the region and the key pair.') });
        } finally {
            setBusy(false);
        }
    }, [saveDraft, settings, t]);

    const connectAccount = useCallback(async () => {
        if (!driver) return;
        setBusy(true);
        setOauth(prev => ({ ...prev, status: 'starting', message: '' }));
        try {
            const result = await saveDraft();
            if (!result) {
                setOauth(prev => ({ ...prev, status: 'idle' }));
                return;
            }
            const res = await api.get(`oauth/${oauthProviderFor(driver)}/authorize`, { connection_id: result.saved.id, return_to: 'onboard' });
            const url = res?.data?.authorization_url;
            if (!url) {
                throw new Error(t('onboarding.oauth_no_url', 'The provider did not return an authorization URL.'));
            }
            // The relay returns to the connection screen for this id; the marker routes it back to
            // the wizard, and the consent-tab flag tells that tab to report back and close. Both
            // are copied into the new tab at the moment it opens.
            try {
                window.sessionStorage.setItem(OAUTH_RETURN_MARKER, String(result.saved.id));
                window.sessionStorage.setItem(OAUTH_CONSENT_TAB, '1');
            } catch {
                // Without the marker the relay's return lands on the connection screen, where the
                // code is exchanged as before; the wizard resumes from the dashboard afterwards.
            }
            let consentWindow = null;
            try {
                // A tab left over from an earlier attempt carries that attempt's marker: start afresh.
                if (consentWindowRef.current && !consentWindowRef.current.closed && typeof consentWindowRef.current.close === 'function') {
                    consentWindowRef.current.close();
                }
                consentWindow = window.open(url, OAUTH_CONSENT_WINDOW);
            } catch {
                consentWindow = null;
            }
            try {
                window.sessionStorage.removeItem(OAUTH_CONSENT_TAB);
            } catch {
                // The flag only matters in the tab that was just opened.
            }
            if (consentWindow) {
                // This page stays put and updates when the other tab reports back.
                consentWindowRef.current = consentWindow;
                setOauth(prev => ({ ...prev, status: 'waiting', message: '' }));
                return;
            }
            // A blocked pop-up: same tab, the return brings the wizard back.
            window.location.assign(url);
        } catch (err) {
            setOauth(prev => ({ ...prev, status: 'error', message: err?.message || t('onboarding.oauth_request_failed', 'The authorization could not be started.') }));
        } finally {
            setBusy(false);
        }
    }, [driver, saveDraft, t]);

    const stopWaitingForConsent = useCallback(() => {
        consentWindowRef.current = null;
        setOauth(prev => (prev.status === 'waiting' ? { ...prev, status: 'idle', message: '' } : prev));
    }, []);

    // While the consent tab is open: take its report the moment it arrives, and re-read the draft
    // every few seconds in case the report never comes (the tab was closed, or storage is off).
    useEffect(() => {
        if (oauth.status !== 'waiting' || !draft?.id) return undefined;
        let cancelled = false;
        const draftId = String(draft.id);
        // A reconnect starts from a row that is already connected: only a new grant (a new expiry) counts.
        const before = { connected: isOAuthConnected(draft), expiresAt: draft.oauth_token_expires_at ?? null };
        const refresh = async () => {
            try {
                const fresh = (await api.get(`connections/${draftId}`))?.data;
                if (cancelled || !fresh || !isOAuthConnected(fresh)) return;
                if (before.connected && (fresh.oauth_token_expires_at ?? null) === before.expiresAt) return;
                setDraft(fresh);
                setOauth({ connected: true, account: fresh.oauth_account_email || null, status: 'idle', message: '' });
            } catch {
                // Keep waiting; the next tick tries again.
            }
        };
        const onStorage = (event) => {
            if (event.key !== OAUTH_RESULT_KEY || !event.newValue) return;
            let result = null;
            try {
                result = JSON.parse(event.newValue);
            } catch {
                result = null;
            }
            if (!result || String(result.connectionId) !== draftId) return;
            if (result.status === 'error') {
                setOauth(prev => ({ ...prev, status: 'error', message: result.message || '' }));
                return;
            }
            refresh();
        };
        window.addEventListener('storage', onStorage);
        window.addEventListener('focus', refresh);
        const timer = window.setInterval(refresh, OAUTH_WAIT_POLL_MS);
        return () => {
            cancelled = true;
            window.removeEventListener('storage', onStorage);
            window.removeEventListener('focus', refresh);
            window.clearInterval(timer);
        };
    }, [oauth.status, draft]);

    // In the consent tab itself: once the outcome is known, close the tab (the wizard tab has it).
    useEffect(() => {
        if (!consentTab || !initialised || oauth.status === 'exchanging') return undefined;
        if (!oauth.connected && oauth.status !== 'error') return undefined;
        const timer = window.setTimeout(() => {
            try {
                window.close();
            } catch {
                // Some browsers keep a tab open that they did not open themselves; the page says so.
            }
        }, 1500);
        return () => window.clearTimeout(timer);
    }, [consentTab, initialised, oauth.connected, oauth.status]);

    const copyRedirectUri = useCallback(async (uri) => {
        try {
            await navigator.clipboard.writeText(uri);
            toast.success(t('onboarding.redirect_uri_copied', 'Redirect URI copied.'));
        } catch {
            toast.error(t('onboarding.redirect_uri_copy_failed', 'Could not copy — select the field and copy it by hand.'));
        }
    }, [t]);

    const sendTest = useCallback(async () => {
        if (!draft?.id) return;
        setTest({ status: 'sending' });
        try {
            const res = await api.post('test-email', { to: recipient.trim(), connection_id: draft.id });
            const data = res?.data || {};
            if (data.sent) {
                setTest({ status: 'accepted', to: recipient.trim(), at: now() });
                await patch({ verify: true, verify_skipped: false, send_test: true });
            } else {
                setTest({ status: 'failed', message: data.error || res?.message || t('onboarding.verify_test_failed', 'The test was not accepted'), at: now() });
            }
        } catch (err) {
            setTest({ status: 'failed', message: err?.message || t('onboarding.verify_test_failed', 'The test was not accepted'), at: now() });
        }
    }, [draft, recipient, patch, t]);

    const skipVerify = useCallback(async () => {
        setSkipBusy(true);
        try {
            await patch({ verify_skipped: true });
            goTo(STEP_REVIEW);
        } catch (err) {
            toast.error(err?.message || t('onboarding.save_failed', 'Could not save your progress.'));
        } finally {
            setSkipBusy(false);
        }
    }, [patch, goTo, t]);

    const apply = useCallback(async () => {
        if (!draft?.id) return;
        setBusy(true);
        try {
            if (name.trim() && name.trim() !== draft.name) {
                await api.put(`connections/${draft.id}`, { name: name.trim() });
            }
            const res = await api.post('dashboard/onboarding/apply', {
                connection_id: draft.id,
                make_primary: review.makePrimary,
                log_retention_days: review.retention,
                import_logs: Boolean(review.importLogs && assessment?.logs?.available),
                migration_source: migrationSource || undefined,
            });
            setApplyResult(res?.data || null);
            setLogsImport(res?.data?.logs_import || null);
            setSearchParams({ step: ONBOARDING_STEP_IDS[STEP_REVIEW] }, { replace: true });
            toast.success(t('onboarding.applied', 'Setup applied. WordPress mail now goes through {{name}}.', { name: name || draft.name }));
        } catch (err) {
            toast.error(err?.message || t('onboarding.apply_failed', 'The setup could not be applied.'));
        } finally {
            setBusy(false);
        }
    }, [draft, name, review, migrationSource, assessment, setSearchParams, t]);

    const discard = useCallback(async () => {
        if (!draft?.id) return;
        setDiscardBusy(true);
        try {
            await api.delete(`connections/${draft.id}`);
            await patch({ draft_connection_id: null, connect: false, verify: false, verify_skipped: false });
            setDraft(null);
            savedSettingsRef.current = null;
            setSettings({});
            setSettingsDriver(null);
            setName('');
            setNameTouched(false);
            setProbe({ status: 'idle' });
            setTest({ status: 'idle' });
            setSesCheck({ status: 'idle' });
            setOauth({ connected: false, account: null, status: 'idle', message: '' });
            setErrors({});
            toast.success(t('onboarding.discarded', 'Draft discarded. Nothing else changed.'));
            goTo(STEP_START, null);
        } catch (err) {
            toast.error(err?.message || t('onboarding.discard_failed', 'The draft could not be discarded.'));
        } finally {
            setDiscardBusy(false);
        }
    }, [draft, patch, goTo, t]);

    /**
     * The log offer follows the retention the user picks on Review: rows older than it would be
     * pruned right after the import, so the count is re-read for that window.
     */
    const refreshLogOffer = useCallback(async (retentionDays) => {
        if (path !== 'import' || !assessment?.logs?.available || !migrationSource) return;
        try {
            const res = await api.post('tools/migration/import', { source: migrationSource, dry_run: true, import_connections: false, import_logs: true, retention_days: retentionDays });
            const logs = res?.data?.logs;
            if (logs) setAssessment(prev => (prev ? { ...prev, logs } : prev));
        } catch {
            // The count shown stays the last one read; Apply imports whatever the window holds then.
        }
    }, [path, assessment, migrationSource]);

    /**
     * After Apply, keep importing the source's log one chunk at a time until it is done; leaving
     * the page is safe, the next run resumes from the stored cursor.
     */
    const continueLogsImport = useCallback(async () => {
        if (!migrationSource) return;
        try {
            const res = await api.post('tools/migration/import', { source: migrationSource, import_connections: false, import_logs: true });
            setLogsImport(res?.data?.logs || null);
        } catch (err) {
            setLogsImport(prev => (prev ? { ...prev, done: true, error: err?.message || '' } : prev));
        }
    }, [migrationSource]);

    const credentialRows = useMemo(() => {
        if (!driver || !metadata) return [];
        const schema = splitWizardSchema(driver, metadata.settings_schema || {}).connection;
        const extra = [];
        if (OAUTH_DRIVERS.includes(driver)) {
            extra.push({
                label: t('onboarding.preview_account', 'Account'),
                value: oauth.connected ? (oauth.account || undefined) : undefined,
                badge: oauth.connected
                    ? { variant: 'success', label: t('onboarding.account_connected', 'Connected') }
                    : { variant: 'warning', label: t('onboarding.account_not_connected', 'Not connected') },
            });
        }
        if (driver === 'ses') {
            extra.push({
                label: t('onboarding.preview_aws_check', 'AWS check'),
                value: sesCheck.status === 'passed' && sesCheck.identity
                    ? (sesCheck.identity.verified ? t('onboarding.ses_identity_verified_short', 'sender identity verified') : t('onboarding.ses_identity_unverified_short', 'sender identity not verified'))
                    : undefined,
                badge: sesCheck.status === 'passed'
                    ? { variant: sesCheck.identity && !sesCheck.identity.verified ? 'warning' : 'success', label: t('onboarding.verify_passed', 'Passed') }
                    : { variant: 'outline', label: t('onboarding.verify_not_run', 'Not run') },
            });
        }
        return [...Object.entries(schema)
            .filter(([, field]) => !field.visible_when || settings[field.visible_when.key] === field.visible_when.value)
            .map(([key, field]) => {
                const value = settings[key];
                const filled = value !== undefined && value !== null && String(value).trim() !== '';
                if (isSecretKey(key)) {
                    return {
                        label: field.label,
                        badge: draft && (isMaskedSecret(value) || filled)
                            ? { variant: 'success', label: t('onboarding.secret_stored', 'Stored') }
                            : { variant: 'outline', label: t('onboarding.secret_missing', 'Not entered') },
                    };
                }
                if (field.type === 'checkbox') {
                    return { label: field.label, value: value ? t('common.on', 'On') : t('common.off', 'Off') };
                }
                return { label: field.label, value: displayValue(field, value) };
            }), ...extra];
    }, [driver, metadata, settings, draft, oauth, sesCheck, t]);

    if (!onboardingLoaded || !initialised) {
        return (
            <div className="flex min-h-[40vh] items-center justify-center">
                <Spinner className="size-8 text-primary" aria-label={t('common.loading', 'Loading...')} />
            </div>
        );
    }

    if (loadError) {
        return (
            <Alert variant="destructive">
                <AlertTitle>{t('onboarding.load_failed', 'The wizard could not load.')}</AlertTitle>
                <AlertDescription>{loadError}</AlertDescription>
            </Alert>
        );
    }

    if (applyResult) {
        return (
            <DoneScreen
                result={applyResult}
                providerName={providerName}
                sender={sender}
                retention={review.retention}
                migration={migrationSource ? { source: migrationSource, sourceName: assessment?.sourceName || migrationSourceName(migrationSource), sourceActive: migrationSourceActive, logs: logsImport, onContinueLogs: continueLogsImport } : null}
            />
        );
    }

    const shell = (index) => ({ stepNumber: index + 1, stepCount: steps.length, stepName: steps[index].title });
    // What the record says is done (survives walking back), and how far the rail may jump forward:
    // Connect needs a chosen provider, Verify a saved draft, Review a test that was accepted or skipped.
    const completedSteps = [
        Boolean(onboarding.start),
        Boolean(onboarding.provider) && (Boolean(driver) || Boolean(importPick)),
        Boolean(onboarding.connect) && Boolean(draft),
        Boolean(draft) && (test.status === 'accepted' || Boolean(onboarding.verify) || Boolean(onboarding.verify_skipped)),
        false,
    ];
    const providerReady = Boolean(driver) && Boolean(metadata) && settingsDriver === driver;
    const furthestReachable = !providerReady
        ? STEP_PROVIDER
        : !draft
            ? STEP_CONNECT
            : (test.status === 'accepted' || onboarding.verify || onboarding.verify_skipped) ? STEP_REVIEW : STEP_VERIFY;

    return (
        <div className="flex flex-col gap-8">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                <div className="min-w-0 flex-1">
                    <StepRail
                        steps={steps}
                        current={stepIndex}
                        completed={completedSteps}
                        canNavigate={index => index <= furthestReachable}
                        onNavigate={index => goTo(index)}
                    />
                </div>
                <Button type="button" variant="outline" size="sm" className="self-end sm:self-auto" onClick={() => navigate('/')}>
                    {t('onboarding.finish_later', 'Finish later')}
                </Button>
            </div>

            {stepIndex === STEP_START && (
                <StartStep
                    shell={shell(STEP_START)}
                    path={path}
                    onPathChange={handlePathChange}
                    scan={scan}
                    migrationSource={migrationSource}
                    onMigrationSourceChange={setMigrationSource}
                    onContinue={continueFromStart}
                    continueBusy={busy}
                />
            )}

            {stepIndex === STEP_PROVIDER && path === 'import' && assessment && (
                <ImportPickStep
                    shell={shell(STEP_PROVIDER)}
                    sourceName={assessment.sourceName}
                    connections={assessment.connections}
                    picked={importPick}
                    onPick={setImportPick}
                    resolutions={importResolutions}
                    onResolve={(key, answer) => setImportResolutions(prev => ({ ...prev, [key]: answer }))}
                    onBack={() => goTo(STEP_START)}
                    onContinue={continueFromImportPick}
                    continueBusy={busy}
                />
            )}

            {stepIndex === STEP_PROVIDER && !(path === 'import' && assessment) && (
                <ProviderStep
                    shell={shell(STEP_PROVIDER)}
                    driver={driver}
                    onDriverChange={handleDriverChange}
                    onBack={() => goTo(STEP_START)}
                    onContinue={continueFromProvider}
                    continueBusy={busy}
                    sender={{ fromEmail: settings.from_email || adminEmail, fromName: settings.from_name || siteName }}
                />
            )}

            {stepIndex === STEP_CONNECT && providerReady && (
                <ConnectStep
                    shell={shell(STEP_CONNECT)}
                    driver={driver}
                    provider={provider}
                    providerName={providerName}
                    methodLine={methodLine}
                    metadata={metadata}
                    settings={settings}
                    onSettingChange={updateSetting}
                    errors={errors}
                    draft={draft}
                    sesCheck={sesCheck}
                    onValidateSes={validateSes}
                    oauth={{ ...oauth, redirectUri: OAUTH_DRIVERS.includes(driver) ? (admin.oauthRedirectUris?.[oauthProviderFor(driver)] || '') : '', consentTab }}
                    onStopWaiting={stopWaitingForConsent}
                    onConnectAccount={connectAccount}
                    onCopyRedirectUri={copyRedirectUri}
                    onBack={() => goTo(STEP_PROVIDER)}
                    onContinue={continueFromConnect}
                    continueBusy={busy}
                />
            )}

            {stepIndex === STEP_VERIFY && draft && (
                <VerifyStep
                    shell={shell(STEP_VERIFY)}
                    providerName={providerName}
                    methodLine={methodLine}
                    sender={sender}
                    hasProbe={hasProbe}
                    probe={probe}
                    onRunProbe={runProbe}
                    test={test}
                    recipient={recipient}
                    onRecipientChange={setRecipient}
                    onSendTest={sendTest}
                    onBack={() => goTo(STEP_CONNECT)}
                    onSkip={skipVerify}
                    skipBusy={skipBusy}
                    onContinue={() => goTo(STEP_REVIEW)}
                    continueBusy={busy}
                />
            )}

            {stepIndex === STEP_REVIEW && draft && (
                <ReviewStep
                    shell={shell(STEP_REVIEW)}
                    name={name || proposedConnectionName(providerName, settings.from_email)}
                    onNameChange={value => {
                        setName(value);
                        setNameTouched(true);
                    }}
                    providerName={providerName}
                    methodLine={methodLine}
                    sender={sender}
                    credentialRows={credentialRows}
                    probe={probe}
                    test={test}
                    hasProbe={hasProbe}
                    hasOtherActive={hasOtherActive}
                    review={review}
                    onReviewChange={(key, value) => {
                        setReview(prev => ({ ...prev, [key]: value }));
                        if (key === 'retention') refreshLogOffer(value);
                    }}
                    importInfo={path === 'import' && assessment ? {
                        sourceName: assessment.sourceName,
                        others: otherImportedDrafts(assessment.connections, draft, importPick),
                        logs: assessment.logs,
                        suggestedRetention: assessment.suggestions?.retention_days ? Number(assessment.suggestions.retention_days) : null,
                    } : null}
                    onApply={apply}
                    applyBusy={busy}
                    onDiscard={discard}
                    discardBusy={discardBusy}
                    onBack={() => goTo(STEP_VERIFY)}
                />
            )}

            {((stepIndex === STEP_CONNECT && !providerReady) || ((stepIndex === STEP_VERIFY || stepIndex === STEP_REVIEW) && !draft)) && (
                <Alert variant="info">
                    <AlertTitle>{t('onboarding.step_unreachable', 'Start with a provider')}</AlertTitle>
                    <AlertDescription>
                        {t('onboarding.step_unreachable_desc', 'This step needs a saved draft. Choose a provider first.')}{' '}
                        <Button variant="link" size="sm" className="h-auto p-0" onClick={() => goTo(driver ? STEP_PROVIDER : STEP_START)}>
                            {t('onboarding.step_unreachable_action', 'Go there')}
                        </Button>
                    </AlertDescription>
                </Alert>
            )}
        </div>
    );
}
