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

async function apiFetch(endpoint, options = {}, retriedOnInvalidNonce = false) {
    const url = `${API_BASE}/${endpoint}`.replace(/\/+/g, '/').replace(':/', '://');

    const headers = {
        'X-WP-Nonce': resolveNonce(),
        ...options.headers,
    };

    if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
        headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(options.body);
    }

    const response = await fetch(url, {
        ...options,
        headers,
        credentials: 'same-origin',
    });

    const raw = await response.text();
    const contentType = response.headers.get('content-type') || '';

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
        // Retry once if nonce is stale/missing and another global source may now be available.
        if (
            !retriedOnInvalidNonce &&
            response.status === 403 &&
            payload &&
            payload.code === 'rest_cookie_invalid_nonce'
        ) {
            return apiFetch(endpoint, options, true);
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
