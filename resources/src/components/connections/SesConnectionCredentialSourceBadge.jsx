import React from 'react';
import CredentialSourceBadge from '../CredentialSourceBadge';
import { useTranslations } from '../../hooks/useTranslations';

export function normalizeCredentialSource(source) {
    if (!source) {
        return null;
    }

    if (source === 'db') {
        return 'database';
    }

    return source;
}

export function buildSesCredentialSourceBadgeData(connection) {
    if (!connection || connection.driver !== 'ses') {
        return null;
    }

    const accessSource = normalizeCredentialSource(connection.credential_sources?.access_key?.source);
    const secretSource = normalizeCredentialSource(connection.credential_sources?.secret_key?.source);

    if (!accessSource && !secretSource) {
        return null;
    }

    if (accessSource && secretSource && accessSource !== secretSource) {
        return {
            source: 'unknown',
            labelKey: 'credential_source.mixed_sources',
            label: 'Mixed Sources',
            locked: true,
        };
    }

    const source = accessSource || secretSource || 'unknown';
    const labels = {
        database: { key: 'credential_source.database', text: 'Database' },
        wp_config: { key: 'credential_source.wp_config', text: 'WP Config' },
        env: { key: 'credential_source.environment', text: 'Environment' },
        iam_role: { key: 'credential_source.iam_role', text: 'IAM Role' },
        unknown: { key: 'credential_source.unknown', text: 'Unknown' },
    };

    const selected = labels[source] || labels.unknown;

    return {
        source,
        labelKey: selected.key,
        label: selected.text,
        locked: source !== 'database',
    };
}

export default function SesConnectionCredentialSourceBadge({ connection }) {
    const { t } = useTranslations();
    const badge = buildSesCredentialSourceBadgeData(connection);

    if (!badge) {
        return null;
    }

    return (
        <CredentialSourceBadge
            source={badge.source}
            label={t(badge.labelKey || 'credential_source.unknown', badge.label)}
            locked={badge.locked}
        />
    );
}
