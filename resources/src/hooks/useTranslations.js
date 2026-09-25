import { useMemo } from 'react';

function getFromPath(object, path) {
    if (!object || !path) {
        return undefined;
    }

    return String(path)
        .split('.')
        .reduce((current, segment) => (current && current[segment] !== undefined ? current[segment] : undefined), object);
}

function applyReplacements(text, replacements = {}) {
    if (typeof text !== 'string' || !replacements || typeof replacements !== 'object') {
        return text;
    }

    return Object.entries(replacements).reduce((acc, [key, value]) => {
        return acc.replaceAll(`{{${key}}}`, String(value));
    }, text);
}

export function translate(key, fallback = '', replacements = {}, source) {
    const i18nSource = source || (typeof window !== 'undefined' ? window.BooleanSmtpAdmin?.i18n : null) || {};
    const found = getFromPath(i18nSource, key);

    if (typeof found === 'string' && found.length > 0) {
        return applyReplacements(found, replacements);
    }

    const fallbackText = fallback || key;
    return applyReplacements(fallbackText, replacements);
}

const EMPTY_I18N = Object.freeze({});

export function useTranslations() {
    const config = typeof window !== 'undefined' ? window.BooleanSmtpAdmin || {} : {};
    const i18n = config.i18n || EMPTY_I18N;

    // `t` is memoised on the i18n payload so it is safe to list in hook dependency arrays.
    const t = useMemo(
        () => (key, fallback = '', replacements = {}) => translate(key, fallback, replacements, i18n),
        [i18n],
    );

    return {
        t,
        locale: config.locale || 'en_US',
        isRtl: Boolean(config.isRtl),
    };
}
