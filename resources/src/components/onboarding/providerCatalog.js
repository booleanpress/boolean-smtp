import { MAIL_PROVIDERS } from '@/config/mailers';

/**
 * Wizard-side knowledge about the launched providers: which delivery mode the free plugin uses,
 * how the transport schema is split into the Sender and Connection cards, which fields sit
 * behind "Advanced", and which schema keys the wizard never shows. Everything else — labels,
 * types, defaults, requirements — comes from `GET transports/{driver}`.
 *
 * @since 1.0.0
 */

/** Display order of the launched providers on the Provider step (mirrors the connection picker). */
export const WIZARD_DRIVER_ORDER = ['ses', 'google', 'outlook', 'smtp', 'php'];

/** Delivery mode the free plugin uses for each multi-mode provider; fixed and hidden in the wizard. */
export const FREE_DELIVERY_MODE = { ses: 'api', google: 'api', outlook: 'api' };

/** Providers whose Connect step ends with an account authorisation. */
export const OAUTH_DRIVERS = ['google', 'outlook'];

/**
 * Per-tab marker (sessionStorage) holding the draft id the wizard left for an OAuth consent, so
 * the relay's return to the connection screen is routed back to the wizard. Never a credential.
 */
export const OAUTH_RETURN_MARKER = 'boolean-smtp.onboarding.oauth-return';

/**
 * Per-tab flag (sessionStorage) copied into the tab the wizard opens for an OAuth consent, so
 * that tab knows to report back and close itself once the account is connected.
 */
export const OAUTH_CONSENT_TAB = 'boolean-smtp.onboarding.oauth-consent-tab';

/**
 * Cross-tab channel (localStorage) the consent tab writes its outcome to; the wizard tab that
 * opened it listens for the `storage` event. Carries the connection id, the outcome and the
 * connected account — never a token or a code.
 */
export const OAUTH_RESULT_KEY = 'boolean-smtp.onboarding.oauth-result';

/** Window name of the consent tab, so a second click reuses it instead of opening another. */
export const OAUTH_CONSENT_WINDOW = 'boolean-smtp-oauth-consent';

/** Schema keys rendered in the Sender card, in order. */
export const SENDER_KEYS = ['from_email', 'from_name'];

/** Schema keys rendered under "Advanced sender options". */
export const SENDER_ADVANCED_KEYS = ['force_from_email', 'force_from_name', 'return_path'];

/**
 * Order of the Connection card's fields where the transport's own order does not read well in
 * two columns, and layout hints for a field the wizard shows differently from the schema.
 */
export const CONNECTION_FIELD_ORDER = {
    smtp: ['host', 'port', 'encryption', 'use_auto_tls', 'authentication', 'username', 'password'],
};
export const CONNECTION_FIELD_HINTS = {
    smtp: {
        use_auto_tls: { standalone: true },
        authentication: { standalone: true, fullWidth: true },
    },
};

/** Schema keys rendered under the Connection card's "Advanced" disclosure, per driver. */
export const ADVANCED_KEYS = {
    smtp: ['key_store', 'ssl_verify_peer'],
    ses: ['key_store', 'iam_selector'],
    google: ['key_store'],
    outlook: ['send_as_shared_mailbox', 'key_store'],
    php: [],
};

/**
 * Schema keys the wizard never renders: the delivery-mode selector (fixed to the free mode), the
 * fields of the modes it does not offer, and the credential store for a provider without credentials.
 */
export const HIDDEN_KEYS = {
    smtp: ['timeout', 'client_hostname', 'keep_alive', 'debug_level'],
    ses: ['delivery_mode', 'region', 'smtp_region', 'smtp_preset', 'access_key', 'secret', 'smtp_username', 'smtp_password'],
    google: ['delivery_mode', 'smtp_preset', 'password'],
    outlook: ['delivery_mode'],
    php: ['key_store'],
};

/** Keys whose value is a credential; the preview only ever reports their state, never the value. */
const SECRET_KEY_PATTERN = /(password|secret|token|api_key|private_key)$/i;

/**
 * Whether a schema key holds a credential.
 *
 * @since 1.0.0
 *
 * @param {string} key Schema key.
 * @returns {boolean}
 */
export function isSecretKey(key) {
    return SECRET_KEY_PATTERN.test(String(key || ''));
}

/**
 * Whether a value is the masked placeholder the API returns for a stored credential.
 *
 * @since 1.0.0
 *
 * @param {unknown} value Field value.
 * @returns {boolean}
 */
export function isMaskedSecret(value) {
    return /^\*{4,}\S{0,8}$/.test(String(value ?? '').trim());
}

/**
 * The launched providers in wizard order.
 *
 * @since 1.0.0
 *
 * @returns {Array<object>} Registry entries from `MAIL_PROVIDERS` that are launched.
 */
export function wizardProviders() {
    return WIZARD_DRIVER_ORDER
        .map(driver => MAIL_PROVIDERS.find(p => p.driver === driver && p.launched))
        .filter(Boolean);
}

/**
 * Split a transport's settings schema into the wizard's four groups.
 *
 * @since 1.0.0
 *
 * @param {string} driver Transport driver key.
 * @param {object} schema `settings_schema` from `GET transports/{driver}`.
 * @returns {{ sender: object, senderAdvanced: object, connection: object, advanced: object }}
 */
export function splitWizardSchema(driver, schema = {}) {
    const hidden = new Set(HIDDEN_KEYS[driver] || []);
    const advancedKeys = new Set(ADVANCED_KEYS[driver] || []);
    const out = { sender: {}, senderAdvanced: {}, connection: {}, advanced: {} };

    const hints = CONNECTION_FIELD_HINTS[driver] || {};
    Object.entries(schema || {}).forEach(([key, field]) => {
        if (hidden.has(key)) return;
        if (SENDER_KEYS.includes(key)) {
            out.sender[key] = field;
        } else if (SENDER_ADVANCED_KEYS.includes(key)) {
            out.senderAdvanced[key] = field;
        } else if (advancedKeys.has(key)) {
            out.advanced[key] = field;
        } else {
            out.connection[key] = hints[key] ? { ...field, ...hints[key] } : field;
        }
    });

    const order = CONNECTION_FIELD_ORDER[driver];
    if (order) {
        const ordered = {};
        order.forEach(key => {
            if (key in out.connection) ordered[key] = out.connection[key];
        });
        Object.keys(out.connection).forEach(key => {
            if (!(key in ordered)) ordered[key] = out.connection[key];
        });
        out.connection = ordered;
    }

    return out;
}

/**
 * The settings a fresh draft starts from: schema defaults, the free delivery mode, the wizard's
 * own defaults (SMTP authentication on) and the prefilled sender identity.
 *
 * @since 1.0.0
 *
 * @param {string} driver Transport driver key.
 * @param {object} schema `settings_schema` from `GET transports/{driver}`.
 * @param {{ fromEmail?: string, fromName?: string }} sender Prefill for the Sender card.
 * @returns {object}
 */
export function initialWizardSettings(driver, schema = {}, sender = {}) {
    const values = {};
    Object.entries(schema || {}).forEach(([key, field]) => {
        if (field && field.default !== undefined && field.default !== null) {
            values[key] = field.default;
        }
    });

    if (FREE_DELIVERY_MODE[driver]) {
        values.delivery_mode = FREE_DELIVERY_MODE[driver];
    }
    if (driver === 'smtp') {
        values.authentication = true;
    }
    // The Preview promises "From: <name> <address>"; WordPress stamps its own `wordpress@site`
    // sender on every message, so the wizard's sender is applied to every message unless the
    // user turns the two switches off under "Advanced sender options". Google would rewrite a
    // foreign sender silently and Microsoft refuses it outright.
    if ('force_from_email' in (schema || {})) values.force_from_email = true;
    if ('force_from_name' in (schema || {})) values.force_from_name = true;
    if (sender.fromEmail) values.from_email = sender.fromEmail;
    if (sender.fromName) values.from_name = sender.fromName;

    return values;
}

/**
 * Required schema keys that are visible in the wizard and still empty.
 *
 * @since 1.0.0
 *
 * @param {string} driver Transport driver key.
 * @param {object} schema `settings_schema` from `GET transports/{driver}`.
 * @param {object} values Current settings.
 * @returns {string[]} Keys that need a value before the draft can be saved.
 */
export function missingRequiredKeys(driver, schema = {}, values = {}) {
    const hidden = new Set(HIDDEN_KEYS[driver] || []);
    const missing = [];
    Object.entries(schema || {}).forEach(([key, field]) => {
        if (hidden.has(key) || !field?.required) return;
        if (field.visible_when && values[field.visible_when.key] !== field.visible_when.value) return;
        const value = values[key];
        if (value === undefined || value === null || String(value).trim() === '') {
            missing.push(key);
        }
    });
    return missing;
}

/**
 * Build the connection name the wizard proposes: provider name and sender address.
 *
 * @since 1.0.0
 *
 * @param {string} providerName Display name of the provider.
 * @param {string} fromEmail Sender address, may be empty.
 * @returns {string}
 */
export function proposedConnectionName(providerName, fromEmail) {
    const email = String(fromEmail || '').trim();
    return email ? `${providerName} — ${email}` : providerName;
}

/**
 * A field's value as the preview shows it: a select's option label, a checkbox's on/off, or the
 * raw value.
 *
 * @since 1.0.0
 *
 * @param {object} field Schema field.
 * @param {unknown} value Current value.
 * @returns {string}
 */
export function displayValue(field, value) {
    if (value === undefined || value === null || String(value).trim() === '') return '—';
    if (field?.type === 'select' && field.options && field.options[value] !== undefined) {
        return String(field.options[value]);
    }
    return String(value);
}
