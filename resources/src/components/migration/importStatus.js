/**
 * How the assessment statuses of an imported connection are shown, shared by the wizard's
 * import steps and the Tools → Migration page.
 *
 * @since 1.0.0
 */

/**
 * The badge for an assessment status.
 *
 * @since 1.0.0
 *
 * @param {(key: string, fallback: string) => string} t Translator.
 * @param {string} status `ready`, `needs_password`, `needs_authorization`, `needs_review` or `unsupported`.
 * @param {string|null} [conversion] `sendgrid_relay` / `postmark_relay` when the connection became an SMTP relay.
 * @returns {{ variant: string, label: string }}
 */
export function importStatusBadge(t, status, conversion = null) {
    switch (status) {
        case 'ready':
            return conversion
                ? { variant: 'success', label: t('migration.status_ready_relay', 'Ready — as SMTP relay') }
                : { variant: 'success', label: t('migration.status_ready', 'Ready to import') };
        case 'needs_password':
            return { variant: 'warning', label: t('migration.status_needs_password', 'Needs password') };
        case 'needs_authorization':
            return { variant: 'info', label: t('migration.status_needs_authorization', 'Needs re-authorization') };
        case 'needs_review':
            return { variant: 'warning', label: t('migration.status_needs_review', 'Needs review') };
        default:
            return { variant: 'outline', label: t('migration.status_unsupported', 'Set up manually') };
    }
}

/**
 * One sentence on what the status means for the user.
 *
 * @since 1.0.0
 *
 * @param {(key: string, fallback: string, replacements?: object) => string} t Translator.
 * @param {{ status: string, missing?: string[], issues?: Array<{ key: string, message: string }>, reason?: string|null }} connection Assessed connection.
 * @returns {string}
 */
export function importStatusHint(t, connection) {
    switch (connection.status) {
        case 'ready':
            return t('migration.hint_ready', 'Everything came across; test it and apply.');
        case 'needs_password':
            return t('migration.hint_needs_password', 'Your old plugin kept the {{keys}} in wp-config.php or did not store it — enter it again.', { keys: (connection.missing || []).map(key => credentialLabel(t, key)).join(', ') || t('migration.credential_password', 'password') });
        case 'needs_authorization':
            return t('migration.hint_needs_authorization', 'The client id and secret came across; sign in once to connect the account.');
        case 'needs_review':
            return (connection.issues || []).map(issue => issue.message).join(' ');
        default:
            return connection.reason || t('migration.hint_unsupported', 'This mailer is not available in this version.');
    }
}

/**
 * A credential key as a word: `api_secret` → "secret access key".
 *
 * @since 1.0.0
 *
 * @param {(key: string, fallback: string) => string} t Translator.
 * @param {string} key Settings key.
 * @returns {string}
 */
export function credentialLabel(t, key) {
    switch (key) {
        case 'password': return t('migration.credential_password', 'password');
        case 'username': return t('migration.credential_username', 'username');
        case 'api_secret': return t('migration.credential_api_secret', 'secret access key');
        case 'api_access_key': return t('migration.credential_api_access_key', 'access key');
        case 'client_secret': return t('migration.credential_client_secret', 'client secret');
        case 'client_id': return t('migration.credential_client_id', 'client id');
        case 'api_key': return t('migration.credential_api_key', 'API key');
        case 'server_token': return t('migration.credential_server_token', 'server token');
        default: return key;
    }
}

/**
 * The plugins page search that shows a source plugin, for the deactivation link.
 *
 * @since 1.0.0
 *
 * @param {string} name Source plugin display name.
 * @returns {string}
 */
export function pluginsPageUrl(name) {
    const admin = typeof window !== 'undefined' ? (window.BooleanSmtpAdmin || {}) : {};
    const base = admin.adminUrl || admin.admin_url || '';
    const root = base ? base.replace(/\/?$/, '/') : '/wp-admin/';
    return `${root}plugins.php?s=${encodeURIComponent(name)}`;
}

/**
 * One line on what an import did: new inactive drafts, replaced connections, kept connections.
 *
 * @since 1.0.0
 *
 * @param {(key: string, fallback: string, replacements?: object) => string} t Translator.
 * @param {Array<object>} connections Assessed connections from the run.
 * @returns {string}
 */
export function importSummary(t, connections) {
    const drafts = connections.filter(c => c.outcome === 'imported').length;
    const replaced = connections.filter(c => c.outcome === 'replaced').length;
    const kept = connections.filter(c => c.outcome === 'kept').length;
    const parts = [];
    if (drafts > 0) parts.push(t('migration.summary_drafts', '{{count}} inactive draft(s) ready under Connections', { count: drafts }));
    if (replaced > 0) parts.push(t('migration.summary_replaced', '{{count}} connection(s) replaced', { count: replaced }));
    if (kept > 0) parts.push(t('migration.summary_kept', '{{count}} kept as they were', { count: kept }));
    return parts.length > 0 ? `${parts.join('; ')}.` : t('migration.summary_nothing', 'Nothing was imported.');
}

/**
 * The badge for what an import did with a connection, or null before an import touched it.
 *
 * @since 1.0.0
 *
 * @param {(key: string, fallback: string) => string} t Translator.
 * @param {string|null|undefined} outcome `imported`, `replaced`, `kept` or `skipped`.
 * @returns {{ variant: string, label: string }|null}
 */
export function importOutcomeBadge(t, outcome) {
    switch (outcome) {
        case 'imported':
            return { variant: 'success', label: t('migration.outcome_badge_imported', 'Imported') };
        case 'replaced':
            return { variant: 'success', label: t('migration.outcome_badge_replaced', 'Replaced') };
        case 'kept':
            return { variant: 'outline', label: t('migration.outcome_badge_kept', 'Kept yours') };
        case 'skipped':
            return { variant: 'outline', label: t('migration.outcome_badge_skipped', 'Not imported') };
        default:
            return null;
    }
}
