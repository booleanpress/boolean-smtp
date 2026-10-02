import { toast } from 'sonner';

import { translate } from '../hooks/useTranslations';
import { endpointUrl } from '../lib/rest';

const config = window.BooleanSmtpAdmin || {};

const API_BASE = config.apiBase || '/wp-json/booleansmtp/v1';
const NONCE = config.nonce || '';

/**
 * Error thrown for any non-2xx REST response.
 *
 * `errors` carries the server's field errors for a 422 exactly as the API returns them:
 * `{ field: ['message', …] }` (a message may also arrive as a plain string). Use
 * {@link fieldErrors} to flatten it for form components.
 *
 * @since 1.0.0
 */
export class ApiError extends Error {
    /**
     * @param {string} message HTTP-level message from the payload or status.
     * @param {number} status  HTTP status code.
     * @param {object|null} payload Decoded JSON body, when there was one.
     */
    constructor(message, status, payload) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.payload = payload ?? null;
        this.errors = payload && payload.errors && typeof payload.errors === 'object' ? payload.errors : null;
    }
}

/**
 * Flatten an API `errors` object to one message per field.
 *
 * @since 1.0.0
 *
 * @param {object|null|undefined} errors The `errors` object of a 422 response.
 * @returns {Record<string, string>} First message per field; empty when there are none.
 */
export function fieldErrors(errors) {
    if (!errors || typeof errors !== 'object') return {};
    const out = {};
    for (const [field, messages] of Object.entries(errors)) {
        const first = Array.isArray(messages) ? messages[0] : messages;
        if (first) out[field] = String(first);
    }
    return out;
}

function resolveNonce() {
    if (typeof window === 'undefined') {
        return NONCE;
    }

    return (
        window.BooleanSmtpAdmin?.nonce ||
        window.booleansmtpNonce ||
        window.wpApiSettings?.nonce ||
        NONCE ||
        ''
    );
}

/**
 * Ask WordPress for a fresh REST nonce, as its own API client does. A nonce lives 12 to 24 hours, so
 * a screen left open longer holds a stale one; WordPress hands out a new one only while the user is
 * still logged in.
 *
 * @since 1.0.0
 * @returns {Promise<string>} The new nonce, or an empty string when WordPress gave none.
 */
async function refreshNonce() {
    const ajaxUrl = typeof window !== 'undefined' ? window.ajaxurl : '';
    if (!ajaxUrl) {
        return '';
    }

    try {
        const response = await fetch(`${ajaxUrl}?action=rest-nonce`, { credentials: 'same-origin' });
        const nonce = response.ok ? (await response.text()).trim() : '';
        if (!/^[a-f0-9]{10}$/i.test(nonce)) {
            return '';
        }
        if (window.BooleanSmtpAdmin) {
            window.BooleanSmtpAdmin.nonce = nonce;
        }
        return nonce;
    } catch {
        return '';
    }
}

/**
 * Tell the user, once, that WordPress no longer accepts their login, instead of letting each screen
 * report an empty or failed load. Reloading takes them to WordPress's login page.
 *
 * @since 1.0.0
 */
function notifySessionEnded() {
    toast.error(translate('common.session_ended', 'Your WordPress session has ended. Reload the page to log in again.'), {
        id: 'boolean-smtp-session-ended',
        duration: Infinity,
        action: {
            label: translate('common.reload_page', 'Reload page'),
            onClick: () => window.location.reload(),
        },
    });
}

async function apiFetch(endpoint, options = {}, retriedOnInvalidNonce = false) {
    const url = endpointUrl(API_BASE, endpoint);

    const headers = {
        'X-WP-Nonce': resolveNonce(),
        ...options.headers,
    };

    // The caller's options stay untouched, so a retry sends the same JSON body with its content type.
    let body = options.body;
    if (body && typeof body === 'object' && !(body instanceof FormData)) {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(body);
    }

    const response = await fetch(url, {
        ...options,
        body,
        headers,
        credentials: 'same-origin',
    });

    const raw = await response.text();
    const contentType = response.headers.get('content-type') || '';

    // A server that sends a logged-out REST call to the login page answers with that page's HTML.
    if (response.redirected && /\/wp-login\.php/.test(response.url || '')) {
        notifySessionEnded();
        throw new ApiError(translate('common.session_ended', 'Your WordPress session has ended. Reload the page to log in again.'), 401, null);
    }

    let payload;
    if (contentType.includes('application/json') || raw.trimStart().startsWith('{') || raw.trimStart().startsWith('[')) {
        try {
            payload = raw ? JSON.parse(raw) : null;
        } catch (e) {
            const preview = raw.slice(0, 200).replace(/\s+/g, ' ');
            throw new Error(
                preview.startsWith('<')
                    ? `Server returned HTML instead of JSON (${response.status}). Often a PHP error or login page. ${preview}`
                    : (e.message || 'Invalid JSON response')
            );
        }
    } else {
        throw new Error(
            raw.trimStart().startsWith('<')
                ? `Server returned HTML instead of JSON (${response.status}). ${raw.slice(0, 120).replace(/\s+/g, ' ')}`
                : (raw || response.statusText || `Request failed: ${response.status}`)
        );
    }

    if (!response.ok) {
        // A rejected login is either a stale nonce (retry once with a fresh one) or an ended session.
        const loginRejected = response.status === 401 || (response.status === 403 && payload?.code === 'rest_cookie_invalid_nonce');
        if (loginRejected && !retriedOnInvalidNonce && (await refreshNonce())) {
            return apiFetch(endpoint, options, true);
        }
        if (loginRejected) {
            // WordPress's own words here ("Cookie check failed") mean nothing to the user; the screen
            // shows the same sentence as the toast.
            notifySessionEnded();
            throw new ApiError(translate('common.session_ended', 'Your WordPress session has ended. Reload the page to log in again.'), 401, payload);
        }

        const msg =
            (payload && (payload.message || payload.code)) ||
            `Request failed: ${response.status}`;

        throw new ApiError(typeof msg === 'string' ? msg : JSON.stringify(msg), response.status, payload);
    }

    return payload;
}

export const api = {
    get: (endpoint, params = {}) => {
        const query = new URLSearchParams(params).toString();
        const url = query ? `${endpoint}?${query}` : endpoint;
        return apiFetch(url, { method: 'GET' });
    },

    post: (endpoint, data = {}) =>
        apiFetch(endpoint, { method: 'POST', body: data }),

    put: (endpoint, data = {}) =>
        apiFetch(endpoint, { method: 'PUT', body: data }),

    delete: (endpoint, data = {}) =>
        apiFetch(endpoint, { method: 'DELETE', body: data }),
};

export default api;
