import { Suspense, useState, useMemo, useEffect, useCallback } from 'react';
import { toast } from 'sonner';
import { Link } from 'react-router';
import api from '../services/api';
import { MAIL_PROVIDERS } from '../config/mailers';
import { getSetupGuideComponent } from '../components/setup-guides';

import MailerModeTabs, { ProModeGateBanner, useIsModeLocked } from '../components/connections/MailerModeTabs';
import { useProExtensions } from '../hooks/useProExtensions';
import { useProCapability } from '../hooks/useProCapability';
import { Info, CheckCircle2, HelpCircle, Code, Zap, Headset, Send, Copy, Check, Pencil, AlertCircle, ShieldCheck } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { Field, FieldContent, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { InputGroup, InputGroupAddon, InputGroupButton, InputGroupInput } from '@/components/ui/input-group';
import { Label } from '@/components/ui/label';
import { PasswordInput } from '@/components/ui/password-input';
import { ScrollArea } from '@/components/ui/scroll-area';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { SegmentedControl } from '@/components/segmented-control';
import { Textarea } from '@/components/ui/textarea';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import ConnectionFormSkeleton from '../components/connections/ConnectionFormSkeleton';
import DynamicSettingsForm from '../components/connections/DynamicSettingsForm';
import OAuthRefreshHistoryPanel from '../components/OAuthRefreshHistoryPanel';
import { useTranslations, translate } from '../hooks/useTranslations';

/** Must match dual-mode list in MailerManager::isApiDeliveryMode + OAuth trio */
const MULTI_MODE_DRIVERS = [
    'ses', 'google', 'outlook', 'zoho',
    'sendgrid', 'mailgun', 'postmark', 'brevo',
    'sparkpost', 'netcore', 'smtp2go',
    'mailersend', 'mandrill', 'sendlayer',
    'smtpcom', 'elasticemail',
];
const OAUTH_DRIVERS = ['google', 'gmail', 'outlook', 'zoho'];
/** Drivers with a hosted "One Click" OAuth proxy flow (delivery_mode === 'one_click'). */
const ONE_CLICK_DRIVERS = ['outlook', 'google'];

/**
 * Renders whatever Pro has registered for this driver+slot (e.g. SES identity management),
 * or nothing at all if Pro isn't installed/licensed -- same shape as Settings.jsx's
 * SettingsExtensionPanel. Extra props are forwarded to the registered component.
 */
function ConnectionPanelSlot({ driver, slot, ...panelProps }) {
    const { proConnectionPanels } = useProExtensions();
    const panel = proConnectionPanels.find((p) => p.driver === driver && p.slot === slot);
    if (!panel?.component) {
        return null;
    }
    const Panel = panel.component;
    return (
        <Suspense fallback={null}>
            <Panel {...panelProps} />
        </Suspense>
    );
}

function pickSchema(schema, keys) {
    const out = {};
    keys.forEach((k) => {
        if (schema[k]) {
            out[k] = schema[k];
        }
    });
    return out;
}

function getOAuthRedirectDisplayUri(driver) {
    const uris = (typeof window !== 'undefined' && window.BooleanSmtpAdmin?.oauthRedirectUris) || {};
    if (driver === 'outlook') {
        return uris.microsoft || '';
    }
    return uris[driver] || '';
}

/**
 * Path (relative to the REST namespace root, no leading `oauth/`) for the authorize/callback
 * calls -- both Google's and Microsoft's One Click flows live under `pro/oauth/...` as of
 * 2026-09-19 (GoogleOneClickController and MicrosoftOneClickController, both relocated to
 * boolean-smtp-pro/routes/api.php); every other OAuth flow (the delegated `api` mode for either
 * provider, Zoho) stays under the free plugin's `oauth/...` namespace.
 */
function oauthAuthorizePath(driver, deliveryMode = 'api') {
    if (driver === 'outlook') {
        if (deliveryMode === 'one_click') {
            return 'pro/oauth/microsoft/one-click';
        }
        return 'oauth/microsoft';
    }
    if (driver === 'google' && deliveryMode === 'one_click') {
        return 'pro/oauth/google/one-click';
    }
    return `oauth/${driver}`;
}

function oauthStatusProvider(driver, deliveryMode = 'api') {
    if (driver === 'outlook') {
        return deliveryMode === 'one_click' ? 'microsoft_one_click' : 'microsoft';
    }
    if (driver === 'google' && deliveryMode === 'one_click') {
        return 'google_one_click';
    }

    return driver;
}

function hasStoredOAuthTokens(settings) {
    const r = settings?.refresh_token;
    const a = settings?.access_token;
    if (r != null && String(r).trim() !== '') {
        return true;
    }
    if (a != null && String(a).trim() !== '') {
        return true;
    }
    return false;
}

function hasOAuthStatus(connection) {
    return Boolean(connection?.oauth_refresh_available || connection?.oauth_token_expires_at);
}

function isLikelyEmail(value) {
    const v = String(value ?? '').trim();
    // Intentionally simple "looks like" email check; backend will still enforce strict validation.
    return v.length > 3 && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
}

function isLikelyMaskedSecretPlaceholder(value) {
    const v = String(value ?? '').trim();
    return /^\*{4,}\S{0,8}$/.test(v);
}

function maskTokenPreview(value) {
    const v = String(value ?? '').trim();
    if (!v) {
        return '';
    }
    if (v.length <= 8) {
        return '*'.repeat(v.length);
    }

    return `${v.slice(0, 4)}${'*'.repeat(Math.max(4, v.length - 8))}${v.slice(-4)}`;
}

// SES's region and SMTP host:port options come from the backend (SesTransport::getSettingsSchema()
// / getSmtpPresets(), via `metadata`), not a hardcoded list here -- a hand-maintained copy silently
// drifted out of sync with the backend's region coverage and endpoint hostnames in the past.
function sesRegionOptions(metadata, field) {
    return metadata?.settings_schema?.[field]?.options || { 'us-east-1': 'us-east-1' };
}

function sesSmtpPresetsForRegion(smtpPresets, region) {
    const prefix = `ses_${region || 'us-east-1'}_`;
    return Object.fromEntries(
        Object.entries(smtpPresets || {}).filter(([key]) => key.startsWith(prefix))
    );
}

function normalizeSesSettingsForForm(settings = {}) {
    const deliveryMode = String(settings.delivery_mode || 'api');
    const baseRegion = String(settings.region || 'us-east-1');
    const smtpRegion = String(settings.smtp_region || (deliveryMode === 'smtp' ? baseRegion : '') || 'us-east-1');
    const apiRegion = String(settings.api_region || (deliveryMode === 'api' ? baseRegion : '') || 'us-east-1');

    return {
        ...settings,
        key_store: settings.key_store === 'wp-config' ? 'wp_config' : (settings.key_store || 'db'),
        smtp_region: smtpRegion,
        smtp_preset: settings.smtp_preset || `ses_${smtpRegion}_587`,
        smtp_username: settings.smtp_username || (deliveryMode === 'smtp' ? settings.access_key : '') || '',
        smtp_password: settings.smtp_password || (deliveryMode === 'smtp' ? settings.secret : '') || '',
        api_region: apiRegion,
        api_access_key: settings.api_access_key || (deliveryMode === 'api' ? settings.access_key : '') || '',
        api_secret: settings.api_secret || (deliveryMode === 'api' ? settings.secret : '') || '',
    };
}

function buildSesSubmitSettings(settings = {}) {
    const { wp_config_confirmed: _wpAck, ...settingsRest } = settings;
    const deliveryMode = String(settingsRest.delivery_mode || 'api');
    const keyStore = settingsRest.key_store === 'wp-config' ? 'wp_config' : (settingsRest.key_store || 'db');
    const smtpRegion = String(settingsRest.smtp_region || 'us-east-1');
    const apiRegion = String(settingsRest.api_region || 'us-east-1');
    const smtpAccess = String(settingsRest.smtp_username || '');
    const smtpSecret = String(settingsRest.smtp_password || '');
    const apiAccess = String(settingsRest.api_access_key || '');
    const apiSecret = String(settingsRest.api_secret || '');

    return {
        ...settingsRest,
        key_store: keyStore,
        region: deliveryMode === 'api' ? apiRegion : smtpRegion,
        access_key: deliveryMode === 'api' ? apiAccess : smtpAccess,
        secret: deliveryMode === 'api' ? apiSecret : smtpSecret,
    };
}

function sesSmtpEndpointFromPreset(smtpPresets, region, preset) {
    const entry = (smtpPresets || {})[preset];
    if (entry && typeof entry === 'object' && entry.host) {
        return {
            host: entry.host,
            port: Number(entry.port) || 587,
            encryption: entry.encryption || 'tls',
        };
    }
    // Fallback only for a not-yet-loaded/unrecognized preset -- matches the backend's modernized
    // email-smtp.* hostname pattern (SesTransport::REGION_HOSTS), not the legacy smtp.* one.
    return {
        host: `email-smtp.${region || 'us-east-1'}.amazonaws.com`,
        port: 587,
        encryption: 'tls',
    };
}

function buildSesWpConfigSnippet(settings = {}, mode = 'smtp', smtpPresets = {}) {
    if (mode === 'api') {
        const apiRegion = String(settings.api_region || 'us-east-1');
        return [
            "define( 'BOOLEANSMTP_AWS_ACCESS_KEY_ID', '********************' );",
            "define( 'BOOLEANSMTP_AWS_SECRET_ACCESS_KEY', '********************' );",
            `define( 'BOOLEANSMTP_AWS_REGION', '${apiRegion}' );`,
        ].join('\n');
    }

    const smtpRegion = String(settings.smtp_region || 'us-east-1');
    const endpoint = sesSmtpEndpointFromPreset(smtpPresets, smtpRegion, settings.smtp_preset);
    return [
        "define( 'BOOLEANSMTP_AWS_SES_SMTP_USERNAME', '********************' );",
        "define( 'BOOLEANSMTP_AWS_SES_SMTP_PASSWORD', '********************' );",
        `define( 'BOOLEANSMTP_AWS_SES_SMTP_HOST', '${endpoint.host}' );`,
        `define( 'BOOLEANSMTP_AWS_SES_SMTP_PORT', ${endpoint.port} );`,
        `define( 'BOOLEANSMTP_AWS_SES_SMTP_ENCRYPTION', '${endpoint.encryption}' );`,
    ].join('\n');
}

function isLikelySecretKeyField(fieldKey = '') {
    const key = String(fieldKey).toLowerCase();
    return ['key', 'secret', 'password', 'token', 'client'].some((word) => key.includes(word));
}

function buildGenericWpConfigSnippet(driver, settings = {}, schema = {}, mode = 'smtp') {
    const excluded = new Set([
        'delivery_mode',
        'key_store',
        'from_email',
        'from_name',
        'force_from_email',
        'force_from_name',
        'return_path',
    ]);
    const safeDriver = String(driver || 'mailer').replace(/[^a-z0-9_-]/gi, '_').toUpperCase();

    const constants = Object.entries(schema)
        .filter(([key, field]) => {
            if (excluded.has(key)) return false;
            if (!field || typeof field !== 'object') return false;
            const visible = field.visible_when;
            if (visible?.key === 'delivery_mode') {
                return String(visible.value) === String(mode);
            }
            return true;
        })
        .filter(([, field]) => ['text', 'password', 'select', 'number', 'email'].includes(String(field.type || 'text')))
        .map(([key, field]) => {
            const constName = `BOOLEANSMTP_${safeDriver}_${String(key).toUpperCase().replace(/[^A-Z0-9]/g, '_')}`;
            const rawValue = settings[key] ?? field.default ?? '';
            const rendered =
                String(field.type) === 'number'
                    ? (Number.isFinite(Number(rawValue)) ? Number(rawValue) : 0)
                    : (isLikelySecretKeyField(key) ? '********************' : String(rawValue || ''));
            return typeof rendered === 'number'
                ? `define( '${constName}', ${rendered} );`
                : `define( '${constName}', '${rendered}' );`;
        });

    let body = constants.length > 0 ? constants.join('\n') : "// No transport constants required for this mode.";
    if (String(driver) === 'outlook' && String(mode) === 'api') {
        const tokenLine = "define( 'BOOLEANSMTP_OUTLOOK_TOKEN', '********************' );";
        body = body === "// No transport constants required for this mode."
            ? tokenLine
            : `${body}\n${tokenLine}`;
    }
    return body;
}

function buildEnvSnippetFromConstantSnippet(constantsSnippet = '') {
    const lines = String(constantsSnippet || '').split('\n');

    return lines
        .map((line) => {
            const match = line.trim().match(/^define\(\s*'([^']+)'\s*,\s*(.+?)\s*\);$/);
            if (!match) {
                return '';
            }

            const name = match[1];
            let value = match[2].trim();

            if (value.startsWith("'") && value.endsWith("'")) {
                value = value.slice(1, -1);
            }

            return `${name}=${value}`;
        })
        .filter(Boolean)
        .join('\n');
}

function pickSchemaForMode(schema, mode) {
    const out = {};
    Object.entries(schema || {}).forEach(([key, field]) => {
        if (key === 'delivery_mode' || key === 'key_store') {
            return;
        }
        const visible = field?.visible_when;
        if (visible?.key === 'delivery_mode' && String(visible.value) !== String(mode)) {
            return;
        }
        out[key] = field;
    });
    return out;
}

function normalizeSmtpPresetOptions(presets = {}) {
    const out = {};
    Object.entries(presets || {}).forEach(([key, value]) => {
        if (value && typeof value === 'object') {
            out[key] = value.label || `${value.host || 'smtp'}:${value.port || ''}`.replace(/:$/, '');
            return;
        }
        out[key] = String(value || key);
    });
    return out;
}

export default function ConnectionForm({
    driver,
    initialData = null,
    metadata: initialMetadata = null,
    loading: initialLoading = false,
    onSave,
    onCancel,
    onTest = null,
    onRemoteSettingsUpdated = null,
    saving = false,
    testing = false,
    testResult = null,
    error = '',
    validationErrors = {},
    senderConflict = null
}) {
    const { t } = useTranslations();
    const { isProLicensed } = useProCapability();

    const provider = useMemo(() => MAIL_PROVIDERS.find(p => p.driver === driver), [driver]);
    const providerDisplayName = useMemo(
        () => (provider ? t(`mailers.${provider.driver}.name`, provider.name) : driver),
        [provider, driver, t]
    );

    const [metadata, setMetadata] = useState(initialMetadata);
    const [loadingMetadata, setLoadingMetadata] = useState(initialLoading && !initialMetadata);
    const [existingConnections, setExistingConnections] = useState([]);
    const [nameTouched, setNameTouched] = useState(false);
    const [fromEmailTouched, setFromEmailTouched] = useState(false);
    const [fromNameTouched, setFromNameTouched] = useState(false);
    const [form, setForm] = useState({
        name: initialData?.name || t('connection_form.name_placeholder', 'My {{provider}} Connection', { provider: providerDisplayName }),
        is_active: initialData?.is_active ?? true,
        priority: initialData?.priority ?? 0,
        settings: driver === 'ses'
            ? normalizeSesSettingsForForm(initialData?.settings || {})
            : (initialData?.settings || {})
    });

    const { senderSchema, connectionSchema } = useMemo(() => {
        const schema = metadata?.settings_schema || {};
        const sender = {};
        const connection = {};
        const senderFields = ['from_email', 'from_name', 'force_from_email', 'force_from_name', 'return_path'];

        Object.entries(schema).forEach(([key, field]) => {
            if (senderFields.includes(key)) {
                sender[key] = {
                    ...field,
                    placeholder: key === 'from_email' ? 'e.g. info@booleansmtp.com' : (key === 'from_name' ? 'e.g. Boolean SMTP' : (key === 'return_path' ? 'e.g. bounce@booleansmtp.com' : field.label))
                };
            } else {
                connection[key] = field;
            }
        });

        return { senderSchema: sender, connectionSchema: connection };
    }, [metadata]);

    // Length > 0, not > 1: this used to be equivalent (every MULTI_MODE_DRIVERS entry always had
    // >=2 native modes), but Outlook now natively offers only 'api' on a Pro-free site (SMTP was
    // removed 2026-09-19; One Click/Application Permission only exist once Pro adds them via
    // filter) -- with a >1 requirement, a Pro-free Outlook connection would fall through to the
    // generic single-mode rendering path below, which has no delivery_mode-aware field visibility
    // (client_id/tenant_id never render, since form.settings.delivery_mode is never actually set
    // to 'api' for a new connection) and no OAuth Authorize/redirect-URI UI at all. MailerModeTabs
    // itself already hides the tab bar when there's only one entry, so this only changes which
    // rendering path is used, not whether a tab bar appears.
    const isMultiMode = useMemo(
        () =>
            MULTI_MODE_DRIVERS.includes(driver) &&
            metadata?.delivery_modes &&
            Object.keys(metadata.delivery_modes).length > 0,
        [driver, metadata]
    );
    const isOAuthDriver = useMemo(() => OAUTH_DRIVERS.includes(driver), [driver]);
    const activeDeliveryMode = form.settings.delivery_mode || 'api';
    const isActiveModeLocked = useIsModeLocked(activeDeliveryMode);
    const isOneClickMode = ONE_CLICK_DRIVERS.includes(driver) && activeDeliveryMode === 'one_click';

    const orderedDeliveryModes = useMemo(() => {
        const modes = metadata?.delivery_modes || {};
        const entries = Object.entries(modes).sort((a, b) => {
            const rank = (k) => (k === 'api' ? 0 : k === 'smtp' ? 1 : k === 'one_click' ? 2 : 9);
            return rank(a[0]) - rank(b[0]);
        });
        return Object.fromEntries(entries);
    }, [metadata?.delivery_modes]);

    const multiModeCommonSchema = useMemo(() => ({}), []);

    // Translated schema labels are resolved outside the memos below so that the
    // memoised schema objects only depend on primitive strings (not on `t`).
    const schemaLabels = {
        region: t('connection_form.schema_region', 'Region'),
        smtpHostPort: t('connection_form.schema_smtp_host_port', 'SMTP Host:Port'),
        smtpHostPortLockedHelp: t('connection_form.schema_smtp_host_port_locked_help', 'Host and port are locked to selected SES region.'),
        smtpHostPortChooseHelp: t('connection_form.schema_smtp_host_port_choose_help', 'Choose the SMTP endpoint combination for this provider.'),
        smtpUsername: t('connection_form.schema_smtp_username', 'SMTP Username'),
        smtpPassword: t('connection_form.schema_smtp_password', 'SMTP Password'),
        accessKeyId: t('connection_form.schema_access_key_id', 'Access Key ID'),
        secretAccessKey: t('connection_form.schema_secret_access_key', 'Secret Access Key'),
    };

    const smtpTabSchema = useMemo(() => {
        if (driver === 'ses') {
            const activeRegion = form?.settings?.smtp_region || 'us-east-1';
            const presets = normalizeSmtpPresetOptions(sesSmtpPresetsForRegion(metadata?.smtp_presets, activeRegion));
            return {
                smtp_region: {
                    type: 'select',
                    label: schemaLabels.region,
                    required: true,
                    default: 'us-east-1',
                    options: sesRegionOptions(metadata, 'smtp_region'),
                },
                smtp_preset: {
                    type: 'select',
                    label: schemaLabels.smtpHostPort,
                    required: true,
                    default: `ses_${activeRegion}_587`,
                    options: presets,
                    help: schemaLabels.smtpHostPortLockedHelp,
                },
                smtp_username: {
                    type: 'text',
                    label: schemaLabels.smtpUsername,
                    required: true,
                    default: '',
                },
                smtp_password: {
                    type: 'password',
                    label: schemaLabels.smtpPassword,
                    required: true,
                    default: '',
                },
            };
        }
        const modeSchema = pickSchemaForMode(connectionSchema, 'smtp');
        const presetOptions = normalizeSmtpPresetOptions(metadata?.smtp_presets || {});
        if (!modeSchema.smtp_preset && Object.keys(presetOptions).length > 0) {
            modeSchema.smtp_preset = {
                type: 'select',
                label: schemaLabels.smtpHostPort,
                required: true,
                default: Object.keys(presetOptions)[0],
                options: presetOptions,
                help: schemaLabels.smtpHostPortChooseHelp,
            };
        }
        const prioritizedKeys = ['region', 'smtp_preset', 'host', 'port', 'encryption', 'username', 'password', 'api_key', 'domain'];
        return {
            ...pickSchema(modeSchema, prioritizedKeys),
            ...Object.fromEntries(Object.entries(modeSchema).filter(([k]) => !prioritizedKeys.includes(k))),
        };
    }, [
        connectionSchema,
        driver,
        form?.settings?.smtp_region,
        metadata,
        schemaLabels.region,
        schemaLabels.smtpHostPort,
        schemaLabels.smtpHostPortLockedHelp,
        schemaLabels.smtpHostPortChooseHelp,
        schemaLabels.smtpUsername,
        schemaLabels.smtpPassword,
    ]);

    const apiTabSchema = useMemo(() => {
        if (driver === 'ses') {
            return {
                api_access_key: {
                    type: 'text',
                    label: schemaLabels.accessKeyId,
                    required: true,
                    default: '',
                },
                api_secret: {
                    type: 'password',
                    label: schemaLabels.secretAccessKey,
                    required: true,
                    default: '',
                },
                api_region: {
                    type: 'select',
                    label: schemaLabels.region,
                    required: true,
                    default: 'us-east-1',
                    options: sesRegionOptions(metadata, 'api_region'),
                    fullWidth: true,
                },
            };
        }
        let modeSchema = pickSchemaForMode(connectionSchema, 'api');
        if (driver === 'zoho') {
            const { client_id: _cid, client_secret: _cs, ...rest } = modeSchema;
            modeSchema = rest;
        }
        const prioritizedKeys = ['region', 'api_key', 'client_id', 'client_secret', 'tenant_id', 'domain', 'username', 'password'];
        return {
            ...pickSchema(modeSchema, prioritizedKeys),
            ...Object.fromEntries(Object.entries(modeSchema).filter(([k]) => !prioritizedKeys.includes(k))),
        };
    }, [connectionSchema, driver, metadata, schemaLabels.accessKeyId, schemaLabels.secretAccessKey, schemaLabels.region]);

    const zohoApiCredentialSchema = useMemo(() => {
        if (driver !== 'zoho') {
            return {};
        }
        return pickSchema(pickSchemaForMode(connectionSchema, 'api'), ['client_id', 'client_secret']);
    }, [connectionSchema, driver]);

    const oneClickTabSchema = useMemo(() => {
        if (!isOneClickMode) {
            return {};
        }

        const modeSchema = pickSchemaForMode(connectionSchema, 'one_click');
        const {
            one_click_bearer_token: _token,
            one_click_status: _status,
            ...safeSchema
        } = modeSchema;
        return safeSchema;
    }, [connectionSchema, isOneClickMode]);

    /**
     * Pro-only Application Permission mode (Outlook, client-credentials send-as-any-mailbox) --
     * its own credential fields (app_client_id/app_client_secret/app_tenant_id), separate from
     * the delegated `api` mode's client_id/client_secret/tenant_id, added via
     * boolean_smtp_outlook_settings_schema by boolean-smtp-pro's MicrosoftSchemaExtender. Only
     * relevant for outlook -- other drivers never have an 'app_permission' mode in their schema,
     * so pickSchemaForMode() naturally returns {} for them.
     */
    const appPermissionTabSchema = useMemo(
        () => pickSchemaForMode(connectionSchema, 'app_permission'),
        [connectionSchema]
    );

    const genericConnectionSchema = useMemo(() => {
        if (isMultiMode) {
            return {};
        }
        return connectionSchema;
    }, [isMultiMode, connectionSchema]);

    const [credentialsVerified, setCredentialsVerified] = useState(false);
    const [authorizeClicked, setAuthorizeClicked] = useState(false);
    const [pastedToken, setPastedToken] = useState('');
    const [verifyBusy, setVerifyBusy] = useState(false);
    const [tokenSaveBusy, setTokenSaveBusy] = useState(false);
    const [authorizeBusy, setAuthorizeBusy] = useState(false);
    const [verifyMessage, setVerifyMessage] = useState('');
    const [oauthInlineMessage, setOauthInlineMessage] = useState('');
    const [oauthConnectionId, setOauthConnectionId] = useState(initialData?.id ?? null);
    const [redirectCopied, setRedirectCopied] = useState(false);
    const [transportConstantsCopied, setTransportConstantsCopied] = useState(false);
    const [wpConfigAck, setWpConfigAck] = useState(false);
    const [wpConfigAckError, setWpConfigAckError] = useState(false);
    const [settingsTouched, setSettingsTouched] = useState({});

    const activeTabSchema = useMemo(() => {
        if (!isMultiMode) return genericConnectionSchema;
        const mode = activeDeliveryMode;
        if (mode === 'smtp') {
            return smtpTabSchema;
        }
        if (mode === 'one_click') {
            return oneClickTabSchema;
        }
        if (mode === 'app_permission') {
            return appPermissionTabSchema;
        }
        return apiTabSchema;
    }, [isMultiMode, genericConnectionSchema, activeDeliveryMode, smtpTabSchema, oneClickTabSchema, appPermissionTabSchema, apiTabSchema]);

    const smtpAuthEnabled = useMemo(
        () => Boolean(form.settings.authentication),
        [form.settings.authentication]
    );
    // Fetch metadata if not provided (e.g., on Edit page)
    useEffect(() => {
        if (!metadata && driver) {
            setLoadingMetadata(true);
            api.get(`transports/${driver}`)
                .then(res => setMetadata(res.data || res))
                .catch(err => console.error('Failed to load metadata', err))
                .finally(() => setLoadingMetadata(false));
        }
    }, [driver, metadata]);

    useEffect(() => {
        api.get('connections')
            .then((res) => {
                const rows = res?.data || res || [];
                setExistingConnections(Array.isArray(rows) ? rows : []);
            })
            .catch(() => setExistingConnections([]));
    }, []);

    // Keep delivery_mode consistent with tabs; otherwise visible_when rules hide fields.
    useEffect(() => {
        if (!isMultiMode) return;
        if (form?.settings?.delivery_mode) return;
        setForm(prev => ({
            ...prev,
            settings: { ...prev.settings, delivery_mode: 'api' }
        }));
    }, [driver, isMultiMode, form?.settings?.delivery_mode]);

    useEffect(() => {
        if (initialData?.id) {
            return;
        }
        setNameTouched(true);
        setFromEmailTouched(true);
        setFromNameTouched(true);
    }, [initialData?.id]);

    useEffect(() => {
        if ((form.settings.key_store || 'db') === 'wp-config') {
            setForm(prev => ({
                ...prev,
                settings: { ...prev.settings, key_store: 'wp_config' }
            }));
        }
    }, [form.settings.key_store]);

    useEffect(() => {
        if (driver !== 'ses') return;
        const selectedRegion = form.settings.smtp_region || 'us-east-1';
        const allowed = Object.keys(sesSmtpPresetsForRegion(metadata?.smtp_presets, selectedRegion));
        const current = form.settings.smtp_preset;
        if (allowed.length > 0 && (!current || !allowed.includes(current))) {
            setForm(prev => ({
                ...prev,
                settings: { ...prev.settings, smtp_preset: allowed[0] }
            }));
        }
    }, [driver, form.settings.smtp_region, form.settings.smtp_preset, metadata?.smtp_presets]);

    useEffect(() => {
        setOauthConnectionId(initialData?.id ?? null);
    }, [initialData?.id]);

    useEffect(() => {
        if (initialData?.settings?.client_id) {
            setCredentialsVerified(true);
        } else {
            setCredentialsVerified(false);
        }
    }, [initialData?.id, initialData?.settings?.client_id]);

    useEffect(() => {
        if (!initialData?.settings) {
            return;
        }
        setForm((prev) => ({
            ...prev,
            settings: {
                ...prev.settings,
                refresh_token: initialData.settings.refresh_token ?? prev.settings.refresh_token,
                access_token: initialData.settings.access_token ?? prev.settings.access_token,
                token_expires_at: initialData.settings.token_expires_at ?? prev.settings.token_expires_at,
                api_domain: initialData.settings.api_domain ?? prev.settings.api_domain,
                client_id: initialData.settings.client_id ?? prev.settings.client_id,
                client_secret: initialData.settings.client_secret ?? prev.settings.client_secret,
            },
        }));
    }, [
        initialData?.settings?.refresh_token,
        initialData?.settings?.access_token,
        initialData?.settings?.token_expires_at,
        initialData?.settings?.client_id,
        initialData?.settings?.client_secret,
    ]);

    useEffect(() => {
        if (typeof window === 'undefined') {
            return;
        }

        const url = new URL(window.location.href);
        const params = url.searchParams;
        const expectedProvider = oauthStatusProvider(driver, activeDeliveryMode);
        const expectedConnectionId = String(initialData?.id ?? oauthConnectionId ?? '');
        let shouldCleanup = false;

        const prefillProvider = params.get('oauth_provider');
        const prefillCode = params.get('oauth_code');
        const prefillConnectionId = params.get('oauth_connection_id');
        if (
            prefillProvider === expectedProvider &&
            prefillCode &&
            expectedConnectionId !== '' &&
            prefillConnectionId === expectedConnectionId
        ) {
            setCredentialsVerified(true);
            setAuthorizeClicked(true);
            // Reconnect/edit flows may already have an old code in the textarea.
            // Always replace with the fresh callback code to avoid exchanging stale/used tokens.
            setPastedToken(prefillCode);
            setOauthInlineMessage(translate('connection_form.oauth_code_received', 'Authorization code received from the callback page. Click the main button below to finish saving it.'));
            shouldCleanup = true;
        }

        const statusProvider = (params.get('oauth') || params.get('oauth_provider') || '').replace('/', '_');
        const status = params.get('oauth_status') || params.get('status');
        const message = params.get('oauth_message') || params.get('message');
        if (statusProvider === expectedProvider && status) {
            if (status === 'success') {
                setCredentialsVerified(true);
                setAuthorizeClicked(false);
                setPastedToken('');
                setOauthInlineMessage(message || translate('connection_form.oauth_completed', 'OAuth authorization completed successfully.'));
                if (typeof onRemoteSettingsUpdated === 'function') {
                    onRemoteSettingsUpdated();
                }
            } else if (message) {
                setOauthInlineMessage(message);
            }
            shouldCleanup = true;
        }

        if (!shouldCleanup) {
            return;
        }

        ['oauth_provider', 'oauth_code', 'oauth_state', 'oauth_connection_id', 'oauth_status', 'oauth_message', 'oauth', 'status', 'message']
            .forEach((key) => params.delete(key));
        const nextUrl = `${url.pathname}${params.toString() ? `?${params.toString()}` : ''}${url.hash}`;
        window.history.replaceState({}, '', nextUrl);
    }, [driver, initialData, oauthConnectionId, onRemoteSettingsUpdated, activeDeliveryMode]);

    const updateSettings = (key, value) => {
        setForm(prev => ({
            ...prev,
            settings: { ...prev.settings, [key]: value }
        }));
    };

    const normalizedCurrentConnectionId = useMemo(() => {
        const id = initialData?.id;
        if (id == null) return null;
        const parsed = Number(id);
        return Number.isFinite(parsed) ? parsed : null;
    }, [initialData?.id]);

    const getConnectionNameValidation = useCallback((nameValue) => {
        const normalized = String(nameValue || '').trim().toLowerCase();
        if (!normalized) {
            return t('connection_form.connection_name_required', 'Connection name is required.');
        }
        const hasDuplicate = existingConnections.some((conn) => {
            const connId = Number(conn?.id);
            if (normalizedCurrentConnectionId != null && connId === normalizedCurrentConnectionId) {
                return false;
            }
            const existingName = String(conn?.name || '').trim().toLowerCase();
            return existingName !== '' && existingName === normalized;
        });
        return hasDuplicate ? t('connection_form.connection_name_duplicate', 'A connection with this name already exists. Please choose a unique name.') : '';
    }, [existingConnections, normalizedCurrentConnectionId, t]);

    const getFromEmailValidation = useCallback((emailValue) => {
        const normalized = String(emailValue || '').trim().toLowerCase();
        if (!normalized) {
            return t('connection_form.from_email_required', 'From Email is required.');
        }
        if (!isLikelyEmail(normalized)) {
            return t('connection_form.from_email_invalid', 'From Email must be a valid email address.');
        }
        // Whether another connection may use this sender is the server's sender rule to decide
        // (it differs between editions); its answer arrives as a field error on save.
        return '';
    }, [t]);

    const nameValidationMessage = useMemo(
        () => getConnectionNameValidation(form.name),
        [form.name, getConnectionNameValidation]
    );

    const fromEmailValidationMessage = useMemo(
        () => getFromEmailValidation(form.settings?.from_email),
        [form.settings?.from_email, getFromEmailValidation]
    );
    const fromNameValidationMessage = useMemo(() => {
        return String(form.settings?.from_name || '').trim() === '' ? t('connection_form.from_name_required', 'From Name is required.') : '';
    }, [form.settings?.from_name, t]);

    const settingsValidationErrors = useMemo(() => {
        const errors = {};
        Object.entries(activeTabSchema).forEach(([key, field]) => {
            if (field.required) {
                const val = form.settings[key] ?? field.default;
                if (val === undefined || val === null || String(val).trim() === '') {
                    errors[key] = t('connection_form.field_required', '{{field}} is required.', { field: field.label });
                }
            }
        });

        // Additional SMTP authentication validation
        if (driver === 'smtp' && smtpAuthEnabled) {
            if (!form.settings.username || String(form.settings.username).trim() === '') {
                errors.username = t('connection_form.username_required_with_auth', 'Username is required when authentication is enabled.');
            }
            if (!form.settings.password || String(form.settings.password).trim() === '') {
                errors.password = t('connection_form.password_required_with_auth', 'Password is required when authentication is enabled.');
            }
        }

        return errors;
    }, [activeTabSchema, form.settings, driver, smtpAuthEnabled, t]);

    const localSenderErrors = useMemo(() => {
        const out = {};
        if (fromEmailTouched && fromEmailValidationMessage) {
            out.from_email = fromEmailValidationMessage;
        }
        if (fromNameTouched && fromNameValidationMessage) {
            out.from_name = fromNameValidationMessage;
        }
        return out;
    }, [fromEmailTouched, fromEmailValidationMessage, fromNameTouched, fromNameValidationMessage]);

    const nameFieldError = useMemo(() => {
        if (!nameTouched) {
            return '';
        }
        return nameValidationMessage || validationErrors?.name || '';
    }, [nameTouched, nameValidationMessage, validationErrors?.name]);

    // The API names settings fields `settings.<key>`; the form's fields are keyed by `<key>`.
    const serverErrors = useMemo(() => {
        const out = {};
        Object.entries(validationErrors || {}).forEach(([key, message]) => {
            out[key.startsWith('settings.') ? key.slice('settings.'.length) : key] = message;
        });
        return out;
    }, [validationErrors]);

    const visibleSettingsErrors = useMemo(() => {
        const out = {};
        Object.keys(settingsValidationErrors).forEach(key => {
            if (settingsTouched[key]) {
                out[key] = settingsValidationErrors[key];
            }
        });
        return { ...serverErrors, ...out };
    }, [settingsValidationErrors, settingsTouched, serverErrors]);

    const senderErrors = useMemo(() => {
        return {
            ...serverErrors,
            ...localSenderErrors,
        };
    }, [serverErrors, localSenderErrors]);

    const shouldShowPriorityField = useMemo(() => {
        const currentFrom = String(form.settings?.from_email || '').trim().toLowerCase();
        if (!isLikelyEmail(currentFrom)) {
            return false;
        }

        let hasDifferentDriverMatch = false;
        let hasSameDriverMatch = false;

        existingConnections.forEach((conn) => {
            const connId = Number(conn?.id);
            if (normalizedCurrentConnectionId != null && connId === normalizedCurrentConnectionId) {
                return;
            }

            const connFrom = String(conn?.settings?.from_email || '').trim().toLowerCase();
            if (connFrom !== currentFrom) {
                return;
            }

            if (String(conn?.driver || '') === String(driver || '')) {
                hasSameDriverMatch = true;
            } else {
                hasDifferentDriverMatch = true;
            }
        });

        // If there is a same-driver duplicate, the form cannot be saved anyway,
        // so showing Priority (which is for cross-driver routing) is not logical.
        if (hasSameDriverMatch) {
            return false;
        }

        return hasDifferentDriverMatch;
    }, [driver, existingConnections, form.settings?.from_email, normalizedCurrentConnectionId]);

    useEffect(() => {
        if (!shouldShowPriorityField) {
            return;
        }
        if (!form.priority || Number(form.priority) <= 0) {
            setForm((prev) => ({
                ...prev,
                priority: 10,
            }));
        }
    }, [shouldShowPriorityField, form.priority]);

    const handleSettingsBlur = useCallback((key) => {
        setSettingsTouched(prev => ({ ...prev, [key]: true }));
    }, []);

    const oauthConnected = hasStoredOAuthTokens(form.settings) || hasOAuthStatus(initialData);
    const oneClickToken = String(form.settings?.one_click_bearer_token || '').trim();
    const oneClickConnected = oneClickToken !== '';
    const oneClickStatus = String(form.settings?.one_click_status || (oneClickConnected ? 'connected' : 'not_connected'));
    const oneClickMaskedToken = maskTokenPreview(oneClickToken);
    const canStartOneClickAuthorize = isLikelyEmail(form.settings?.from_email || '');
    const supportsKeyStore = Boolean(connectionSchema?.key_store);
    const supportsCredentialStorageTabs = supportsKeyStore && (isMultiMode || driver === 'smtp');
    const activeKeyStore = form.settings.key_store || 'db';
    const isWpConfigMode = supportsCredentialStorageTabs && activeKeyStore === 'wp_config';
    const isEnvMode = supportsCredentialStorageTabs && activeKeyStore === 'env';
    const isExternalCredentialMode = supportsCredentialStorageTabs && (isWpConfigMode || isEnvMode);
    const currentSesMode = driver === 'smtp' ? 'smtp' : (form.settings.delivery_mode || 'api');
    const activeWpConfigSchema = currentSesMode === 'api' ? apiTabSchema : smtpTabSchema;
    const transportConstantSnippet = useMemo(
        () => (driver === 'ses'
            ? buildSesWpConfigSnippet(form.settings, currentSesMode, metadata?.smtp_presets)
            : buildGenericWpConfigSnippet(driver, form.settings, activeWpConfigSchema, currentSesMode)),
        [driver, form.settings, activeWpConfigSchema, currentSesMode, metadata?.smtp_presets]
    );
    const transportExternalSnippet = useMemo(
        () => (isEnvMode ? buildEnvSnippetFromConstantSnippet(transportConstantSnippet) : transportConstantSnippet),
        [isEnvMode, transportConstantSnippet]
    );
    const shouldSaveTokenWithSubmit = useMemo(() => {
        const mode = activeDeliveryMode;
        const supportsInlineTokenSave = mode === 'api' || mode === 'one_click';
        return isOAuthDriver && supportsInlineTokenSave && !!pastedToken.trim();
    }, [isOAuthDriver, activeDeliveryMode, pastedToken]);


    const handleSave = async () => {
        setNameTouched(true);
        setFromEmailTouched(true);
        setFromNameTouched(true);

        // Mark all active settings as touched to trigger showing frontend errors
        const allTouched = {};
        Object.keys(activeTabSchema).forEach(key => {
            allTouched[key] = true;
        });
        setSettingsTouched(allTouched);

        if (nameValidationMessage || fromEmailValidationMessage || fromNameValidationMessage || Object.keys(settingsValidationErrors).length > 0) {
            return;
        }
        if (isExternalCredentialMode && !wpConfigAck) {
            setWpConfigAckError(true);
            return;
        }

        const apiMode = activeDeliveryMode === 'api';
        if (
            isOAuthDriver &&
            apiMode &&
            !isWpConfigMode &&
            authorizeClicked &&
            !oauthConnected &&
            !pastedToken.trim()
        ) {
            setOauthInlineMessage(t('connection_form.paste_authorization_before_save', 'Paste the authorization code or token before saving.'));
            return;
        }

        const hasOneClickToken = String(form.settings?.one_click_bearer_token || '').trim() !== '';
        if (isOneClickMode && !hasOneClickToken && !pastedToken.trim()) {
            setOauthInlineMessage(t('connection_form.complete_one_click_before_save', 'Complete One Click connection or paste a bearer token before saving.'));
            return;
        }

        const isEditMode = Boolean(initialData?.id);
        const nextSettings = driver === 'ses'
            ? buildSesSubmitSettings(form.settings)
            : form.settings;
        const payload = {
            ...form,
            settings: nextSettings,
            priority: Number(form.priority || 0),
            connection_id: oauthConnectionId || null,
        };

        if (shouldSaveTokenWithSubmit) {
            const cid = oauthConnectionId;
            if (!cid) {
                setOauthInlineMessage(t('connection_form.verify_authorize_before_token_save', 'Verify and authorize first before saving token.'));
                return;
            }

            setTokenSaveBusy(true);
            setOauthInlineMessage('');
            try {
                const rawClientSecret = String(form.settings.client_secret || '').trim();
                const shouldVerifyCredentials = !(
                    driver === 'outlook' &&
                    isEditMode
                ) && !isLikelyMaskedSecretPlaceholder(rawClientSecret) && !isOneClickMode;

                // Edit/reconnect often has masked secret placeholder in UI.
                // Skip verify in that case and use the server-stored secret.
                if (shouldVerifyCredentials) {
                    const verifyBody = {
                        client_id: form.settings.client_id || '',
                        client_secret: form.settings.client_secret || '',
                    };
                    if (driver === 'outlook') {
                        verifyBody.tenant_id = form.settings.tenant_id || 'common';
                    }
                    if (driver === 'zoho') {
                        verifyBody.region = form.settings.region || 'us';
                    }
                    await api.post(`connections/${cid}/verify-credentials`, verifyBody);
                }

                await api.post(`connections/${cid}/oauth-token`, {
                    token: pastedToken.trim(),
                    delivery_mode: activeDeliveryMode,
                });
                setOauthInlineMessage(t('connection_form.oauth_token_saved', 'OAuth token saved.'));
                setAuthorizeClicked(false);
                setPastedToken('');
            } catch (e) {
                const msg = e instanceof Error ? e.message : t('connection_form.failed_save_token', 'Failed to save token.');
                // User-requested behavior: editing Microsoft connection should still save
                // even if token exchange endpoint rejects pasted/prefilled token.
                if (driver === 'outlook' && isEditMode) {
                    setOauthInlineMessage(t('connection_form.token_save_failed_settings_saved', '{{message}} Connection settings were saved.', { message: msg }));
                } else {
                    setOauthInlineMessage(msg);
                    return;
                }
            } finally {
                setTokenSaveBusy(false);
            }
        }

        await onSave(payload);
    };

    const handleCopyRedirectUri = useCallback(async () => {
        const uri = getOAuthRedirectDisplayUri(driver);
        if (!uri || !navigator?.clipboard?.writeText) {
            return;
        }
        await navigator.clipboard.writeText(uri);
        setRedirectCopied(true);
        window.setTimeout(() => setRedirectCopied(false), 1500);
    }, [driver]);

    const handleCopySesConstants = useCallback(async () => {
        if (!navigator?.clipboard?.writeText) {
            return;
        }
        await navigator.clipboard.writeText(transportExternalSnippet);
        setTransportConstantsCopied(true);
        window.setTimeout(() => setTransportConstantsCopied(false), 1500);
    }, [transportExternalSnippet]);

    useEffect(() => {
        setWpConfigAck(false);
        setWpConfigAckError(false);
    }, [driver, form.settings.delivery_mode, form.settings.key_store]);

    const onOffOptions = [
        { value: 'on', label: t('common.on', 'On') },
        { value: 'off', label: t('common.off', 'Off') },
    ];
    const credentialStorageToggle = (
        <div className="space-y-2">
            <Label id="credential-storage-label" className="text-xs font-semibold text-muted-foreground">
                {t('connection_form.credential_storage', 'Credential Storage')}
            </Label>
            <SegmentedControl
                labelledBy="credential-storage-label"
                value={form.settings.key_store || 'db'}
                onValueChange={(value) => updateSettings('key_store', value)}
                options={[
                    { value: 'db', label: t('connection_form.storage_database', 'Database') },
                    { value: 'wp_config', label: t('connection_form.storage_wp_config', 'WP Config') },
                    { value: 'env', label: t('connection_form.storage_environment', 'Environment') },
                ]}
            />
        </div>
    );

    const sesWpConfigBlock = (
        <div className="space-y-3">
            <Field className="gap-1.5">
                <FieldLabel htmlFor="ses-wp-config-snippet" className="text-muted-foreground">
                    {isEnvMode ? t('connection_form.environment_variables', 'Environment variables') : t('connection_form.wp_config_constants', 'wp-config.php constants')} ({currentSesMode === 'api' ? t('connections.mode_api', 'API') : t('connections.mode_smtp', 'SMTP')})
                </FieldLabel>
                <div className="relative rounded-lg border bg-muted/20 p-3">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={handleCopySesConstants}
                        className="absolute top-2 right-2 z-10 text-muted-foreground hover:text-foreground"
                        title={isEnvMode ? t('connection_form.copy_environment_variables', 'Copy environment variables') : t('connection_form.copy_constants', 'Copy constants')}
                    >
                        {transportConstantsCopied ? <Check /> : <Copy />}
                        <span className="text-xs">{transportConstantsCopied ? t('connection_form.copied', 'Copied') : t('connection_form.copy', 'Copy')}</span>
                    </Button>
                    <pre id="ses-wp-config-snippet" className="pt-0.5 pr-24 font-mono text-xs leading-5 break-all whitespace-pre-wrap text-foreground">
                        {transportExternalSnippet}
                    </pre>
                </div>
            </Field>
            <Field orientation="horizontal" data-invalid={wpConfigAckError || undefined} className="items-start gap-2">
                <Checkbox
                    id="ses-wp-config-ack"
                    checked={wpConfigAck}
                    aria-invalid={wpConfigAckError}
                    onCheckedChange={(v) => {
                        setWpConfigAck(v === true);
                        setWpConfigAckError(false);
                    }}
                />
                <div className="flex flex-col gap-1">
                    <FieldLabel htmlFor="ses-wp-config-ack" className="font-normal leading-snug">
                        {t('connection_form.external_credentials_ack', 'I have added the external credential values')}
                    </FieldLabel>
                    {wpConfigAckError && (
                        <FieldError className="text-xs">{t('connection_form.external_credentials_ack_error', 'Confirm that you added the external credential values before saving.')}</FieldError>
                    )}
                </div>
            </Field>
        </div>
    );

    // Static-credential equivalent of handleVerifyCredentials below (OAuth's client_id/secret
    // check) -- SES uses an access-key/secret pair instead of an OAuth app, so it needs its own
    // handler, but follows the exact same "create a draft connection if none exists yet" shape.
    const [validateSesBusy, setValidateSesBusy] = useState(false);
    const [validateSesResult, setValidateSesResult] = useState(null);

    const handleValidateSesCredentials = useCallback(async () => {
        setValidateSesBusy(true);
        setValidateSesResult(null);
        try {
            let cid = oauthConnectionId;
            if (!cid) {
                if (!form.settings?.from_email || String(form.settings.from_email).trim() === '') {
                    setValidateSesResult({ ok: false, message: t('connection_form.from_email_required_sender_settings', 'From Email is required (see Sender Settings).') });
                    return;
                }
                const createRes = await api.post('connections', {
                    name: form.name,
                    driver,
                    settings: buildSesSubmitSettings(form.settings),
                    is_active: form.is_active ?? true,
                });
                const payload = createRes.data ?? createRes;
                cid = payload?.id ?? payload?.data?.id;
                if (!cid) {
                    throw new Error(t('connection_form.failed_create_connection_draft', 'Failed to create connection draft.'));
                }
                setOauthConnectionId(cid);
            }

            const mode = form.settings?.delivery_mode || 'api';
            const accessKey = mode === 'smtp'
                ? (form.settings?.smtp_username || form.settings?.access_key || '')
                : (form.settings?.api_access_key || form.settings?.access_key || '');
            const secret = mode === 'smtp'
                ? (form.settings?.smtp_password || form.settings?.secret || '')
                : (form.settings?.api_secret || form.settings?.secret || '');
            const region = mode === 'smtp'
                ? (form.settings?.smtp_region || 'us-east-1')
                : (form.settings?.api_region || 'us-east-1');

            const res = await api.post(`connections/${cid}/verify-api-credentials`, {
                access_key: accessKey,
                secret,
                region,
                delivery_mode: mode,
            });
            const payload = res.data ?? res;
            setValidateSesResult({
                ok: true,
                message: t(
                    'connection_form.ses_credentials_valid',
                    'Valid — AWS accepted these credentials. 24h quota: {{quota}}, sent today: {{sent}}.',
                    { quota: payload?.max_24_hour_send ?? '?', sent: payload?.sent_last_24_hours ?? '?' }
                ),
            });
        } catch (e) {
            setValidateSesResult({
                ok: false,
                message: e instanceof Error ? e.message : t('connection_form.ses_credentials_invalid', 'Validation failed.'),
            });
        } finally {
            setValidateSesBusy(false);
        }
    }, [oauthConnectionId, form.name, form.is_active, form.settings, driver, t]);

    const handleVerifyCredentials = useCallback(async () => {
        let cid = oauthConnectionId;
        setVerifyBusy(true);
        setVerifyMessage('');
        try {
            // On the "new" page there is no connection id yet; create a draft so verify/token can run.
            if (!cid) {
                // Preflight: multi-mode API validation requires Sender Settings `from_email` too.
                if (!form.settings?.from_email || String(form.settings.from_email).trim() === '') {
                    setVerifyMessage(t('connection_form.from_email_required_sender_settings', 'From Email is required (see Sender Settings).'));
                    return;
                }
                if (!isLikelyEmail(form.settings.from_email)) {
                    setVerifyMessage(t('connection_form.from_email_must_be_valid', 'From Email must be a valid email address.'));
                    return;
                }

                // Force API mode for the draft connection so backend validation matches the current tab.
                const draftSettings = {
                    ...form.settings,
                    delivery_mode: 'api',
                };

                if (driver === 'outlook') {
                    draftSettings.tenant_id = draftSettings.tenant_id || 'common';
                }
                if (driver === 'zoho') {
                    draftSettings.region = draftSettings.region || 'us';
                }

                const createRes = await api.post('connections', {
                    name: form.name,
                    driver,
                    settings: draftSettings,
                    is_active: form.is_active || true,
                });
                const payload = createRes.data ?? createRes;
                cid = payload?.id ?? payload?.data?.id;
                if (!cid) {
                    throw new Error(t('connection_form.failed_create_connection_draft', 'Failed to create connection draft.'));
                }
                setOauthConnectionId(cid);
            }

            const body = {
                client_id: form.settings.client_id || '',
                client_secret: form.settings.client_secret || '',
            };
            if (driver === 'outlook') {
                body.tenant_id = form.settings.tenant_id || 'common';
            }
            if (driver === 'zoho') {
                body.region = form.settings.region || 'us';
            }
            await api.post(`connections/${cid}/verify-credentials`, body);
            setCredentialsVerified(true);
            setVerifyMessage(t('connection_form.credentials_verified_saved', 'Credentials verified and saved.'));
            if (typeof onRemoteSettingsUpdated === 'function') {
                await onRemoteSettingsUpdated();
            } else {
                const res = await api.get(`connections/${cid}`);
                const conn = res.data ?? res;
                if (conn?.settings) {
                    setForm(prev => ({
                        ...prev,
                        settings: conn.settings,
                    }));
                }
            }
        } catch (e) {
            setVerifyMessage(e instanceof Error ? e.message : t('connection_form.verify_failed', 'Verify failed.'));
        } finally {
            setVerifyBusy(false);
        }
    }, [oauthConnectionId, form.name, form.is_active, form.settings, driver, onRemoteSettingsUpdated, t]);

    const openAuthorize = useCallback(async () => {
        let cid = oauthConnectionId;
        setAuthorizeBusy(true);
        try {
            if (!cid) {
                if (!isOneClickMode) {
                    return;
                }

                if (!form.settings?.from_email || String(form.settings.from_email).trim() === '') {
                    setOauthInlineMessage(t('connection_form.from_email_required_before_one_click', 'From Email is required (Sender Settings) before starting One Click connection.'));
                    return;
                }
                if (!isLikelyEmail(form.settings.from_email)) {
                    setOauthInlineMessage(t('connection_form.from_email_valid_before_one_click', 'From Email must be a valid email address before starting One Click connection.'));
                    return;
                }

                const createRes = await api.post('connections', {
                    name: form.name,
                    driver,
                    settings: {
                        ...form.settings,
                        delivery_mode: 'one_click',
                    },
                    is_active: form.is_active || true,
                });

                const payload = createRes.data ?? createRes;
                cid = payload?.id ?? payload?.data?.id;
                if (!cid) {
                    throw new Error(t('connection_form.failed_create_draft_one_click', 'Failed to create connection draft for One Click authorization.'));
                }
                setOauthConnectionId(cid);
            }

            const path = oauthAuthorizePath(driver, activeDeliveryMode);
            const res = await api.get(`${path}/authorize`, { connection_id: cid });
            const url = res.data?.authorization_url;
            if (url) {
                window.open(url, '_blank', 'noopener,noreferrer');
                setAuthorizeClicked(true);
            }
        } catch (e) {
            toast.error(e instanceof Error ? e.message : t('connection_form.oauth_request_failed', 'OAuth request failed.'));
        } finally {
            setAuthorizeBusy(false);
        }
    }, [oauthConnectionId, driver, activeDeliveryMode, isOneClickMode, form.name, form.is_active, form.settings, t]);

    /**
     * Whether to show the "Grant admin consent for your organization" shortcut (MS-007) --
     * Microsoft's `/v2.0/adminconsent` endpoint lets one tenant admin approve the app for the
     * whole org in one step instead of every mailbox owner consenting individually. Only makes
     * sense once a real tenant is in play: delegated `api` mode with a non-default Tenant ID, or
     * Pro's `app_permission` mode, where admin consent isn't just convenient but mandatory (an
     * Application permission grant has no per-user consent path at all).
     */
    const canGrantAdminConsent = useMemo(() => {
        if (driver !== 'outlook') {
            return false;
        }
        if (activeDeliveryMode === 'app_permission') {
            return Boolean(String(form.settings.app_tenant_id || '').trim()) && Boolean(String(form.settings.app_client_id || '').trim());
        }
        if (activeDeliveryMode === 'api') {
            const tenant = String(form.settings.tenant_id || 'common').trim().toLowerCase();
            return tenant !== '' && !['common', 'organizations', 'consumers'].includes(tenant) && Boolean(String(form.settings.client_id || '').trim());
        }
        return false;
    }, [driver, activeDeliveryMode, form.settings.app_tenant_id, form.settings.app_client_id, form.settings.tenant_id, form.settings.client_id]);

    const openAdminConsent = useCallback(() => {
        const isAppPermission = activeDeliveryMode === 'app_permission';
        const tenant = String((isAppPermission ? form.settings.app_tenant_id : form.settings.tenant_id) || '').trim();
        const clientId = String((isAppPermission ? form.settings.app_client_id : form.settings.client_id) || '').trim();
        if (tenant === '' || clientId === '') {
            return;
        }

        const redirectUri = getOAuthRedirectDisplayUri('outlook');
        const query = new URLSearchParams({
            client_id: clientId,
            scope: 'https://graph.microsoft.com/.default',
            ...(redirectUri ? { redirect_uri: redirectUri } : {}),
        });
        const url = `https://login.microsoftonline.com/${encodeURIComponent(tenant)}/v2.0/adminconsent?${query.toString()}`;
        window.open(url, '_blank', 'noopener,noreferrer');
    }, [activeDeliveryMode, form.settings.app_tenant_id, form.settings.app_client_id, form.settings.tenant_id, form.settings.client_id]);

    const adminConsentBlock = canGrantAdminConsent && (
        <Field className="max-w-2xl gap-1.5">
            <Button type="button" variant="outline" size="sm" className="w-fit" onClick={openAdminConsent}>
                <ShieldCheck />
                {t('connection_form.grant_admin_consent', 'Grant admin consent for your organization')}
            </Button>
            <FieldDescription className="text-xs">
                {t('connection_form.grant_admin_consent_help', 'Lets a Microsoft 365 administrator approve this app for the whole organization in one step, instead of every mailbox owner consenting individually. Opens in a new tab.')}
            </FieldDescription>
        </Field>
    );

    if (!provider) return null;
    if (loadingMetadata) {
        return <ConnectionFormSkeleton />;
    }

    const SetupGuide = getSetupGuideComponent(driver);
    const saveButtonLabel = shouldSaveTokenWithSubmit
        ? (initialData ? t('connection_form.save_token_update', 'Save Token and Update') : t('connection_form.save_token_register', 'Save Token and Register'))
        : (initialData ? t('connection_form.update_connection', 'Update Connection') : t('connection_form.register_connection', 'Register Connection'));

    const oauthTokenCapture = authorizeClicked && (
        <div className="max-w-2xl space-y-2 pt-2">
            <Separator />
            <div className="space-y-2 rounded-md border border-dashed p-3">
                <Field className="gap-1">
                    <FieldLabel htmlFor="oauth-pasted-token" className="text-muted-foreground">
                        {isOneClickMode ? t('connection_form.one_click_bearer_token', 'One Click bearer token') : t('connection_form.token_from_provider', 'Token from provider')}
                    </FieldLabel>
                    <FieldDescription className="text-xs leading-relaxed">
                        {isOneClickMode
                            ? t('connection_form.one_click_token_help', 'After One Click authorization in the new tab, paste the returned bearer token here if it is not auto-saved.')
                            : t('connection_form.oauth_token_help', 'After authorizing in the new tab, copy the code shown on the callback page or use the return button there to prefill it here.')}
                    </FieldDescription>
                    <Textarea
                        id="oauth-pasted-token"
                        value={pastedToken}
                        onChange={(e) => setPastedToken(e.target.value)}
                        rows={2}
                        className="resize-y font-mono text-xs"
                        placeholder={isOneClickMode ? t('connection_form.paste_one_click_token', 'Paste One Click bearer token') : t('connection_form.paste_authorization_or_refresh_token', 'Paste authorization code or refresh token')}
                    />
                    <FieldDescription className="text-xs">
                        {t('connection_form.token_saved_main_button', 'Token will be saved when you click the main button below.')}
                    </FieldDescription>
                </Field>
            </div>
            {oauthInlineMessage && (
                <p className="text-sm text-muted-foreground">{oauthInlineMessage}</p>
            )}
        </div>
    );

    return (
        <div>
            {Boolean(window.BooleanSmtpAdmin?.debug) && error && (
                <Alert variant="destructive" className="mb-6">
                    <AlertCircle />
                    <AlertDescription>{error}</AlertDescription>
                </Alert>
            )}

            <div className="flex min-h-screen flex-col items-start lg:flex-row">
                {/* Main Form Area */}
                <div className="w-full flex-1 space-y-8 pb-20 lg:pr-10">
                    {/* Provider Row */}
                    <div className="space-y-4">
                        <p className="text-sm font-medium text-muted-foreground">{t('connection_form.provider', 'Connection Provider')}</p>
                        <div className="flex items-center gap-4">
                            <div className="relative flex h-22 w-44 items-center justify-center rounded-lg border-2 border-primary bg-muted p-4">
                                <div className="absolute -top-2.5 -right-2.5 rounded-full border-2 border-primary bg-background p-0.5">
                                    <CheckCircle2 className="size-4 fill-primary/10 text-primary" />
                                </div>
                                {provider.logo ? (
                                    <img src={provider.logo} alt={providerDisplayName} className="size-full object-contain" />
                                ) : (
                                    <div className="scale-110 transform">{provider.icon}</div>
                                )}
                            </div>
                            {!initialData && (
                                <Button type="button" variant="outline" size="sm" onClick={onCancel}>
                                    <Pencil />
                                    {t('connection_form.change', 'Change')}
                                </Button>
                            )}
                        </div>

                        <Card>
                            <CardContent>
                                <Field className="max-w-md gap-1.5" data-invalid={Boolean(nameFieldError) || undefined}>
                                    <FieldLabel htmlFor="connection-name" className="text-muted-foreground">{t('connection_form.name', 'Connection Name')}</FieldLabel>
                                    <Input
                                        id="connection-name"
                                        type="text"
                                        value={form.name}
                                        onChange={e => setForm({ ...form, name: e.target.value })}
                                        onBlur={() => setNameTouched(true)}
                                        aria-invalid={Boolean(nameFieldError)}
                                        placeholder={t('connection_form.name_placeholder', 'My {{provider}} Connection', { provider: providerDisplayName })}
                                    />
                                    {nameFieldError && <FieldError className="text-xs">{nameFieldError}</FieldError>}
                                </Field>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Card 1: Sender Settings */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Send className="size-4 text-primary" />
                                {t('connection_form.sender_settings', 'Sender Settings')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <DynamicSettingsForm
                                schema={senderSchema}
                                values={form.settings}
                                onChange={updateSettings}
                                errors={senderErrors}
                                onFieldBlur={(key) => {
                                    if (key === 'from_email') {
                                        setFromEmailTouched(true);
                                    }
                                    if (key === 'from_name') {
                                        setFromNameTouched(true);
                                    }
                                }}
                            />

                            {senderConflict?.id && senderErrors.from_email ? (
                                <div className="mt-2 flex flex-col gap-1 text-xs" data-testid="sender-conflict">
                                    <Link
                                        to={`/connections/${senderConflict.id}`}
                                        className="w-fit font-medium text-primary underline-offset-4 hover:underline"
                                    >
                                        {t('connection_form.sender_conflict_edit', "Edit '{{name}}'", { name: senderConflict.name })}
                                    </Link>
                                    {!isProLicensed ? (
                                        <p className="text-muted-foreground">
                                            {t('connection_form.sender_conflict_pro', 'Sending one address through several providers, with failover or load balancing, is part of BooleanSMTP Pro.')}
                                        </p>
                                    ) : null}
                                </div>
                            ) : null}

                            {shouldShowPriorityField && (
                                <div className="mt-8 space-y-4">
                                    <Separator />
                                    <div className="flex flex-wrap items-center gap-x-8 gap-y-3">
                                        <FieldContent className="w-56 max-w-full flex-none gap-1.5">
                                            <FieldLabel htmlFor="connection-priority">
                                                {t('connection_form.priority', 'Priority')}
                                                <Badge variant="outline" className="border-primary/20 bg-primary/10 text-primary">{t('connection_form.advanced', 'Advanced')}</Badge>
                                            </FieldLabel>
                                            <Input
                                                id="connection-priority"
                                                type="number"
                                                min={1}
                                                value={form.priority ?? 10}
                                                onChange={(e) => setForm((prev) => ({
                                                    ...prev,
                                                    priority: Math.max(1, Number(e.target.value || 1)),
                                                }))}
                                                className="font-mono"
                                                placeholder={t('connection_form.priority_placeholder', '10')}
                                            />
                                        </FieldContent>
                                        <FieldDescription className="min-w-60 max-w-full flex-1 text-xs leading-relaxed">
                                            {t('connection_form.priority_help', 'Lower numbers have higher priority (e.g., 1 wins over 10). Used when the same sender exists in multiple providers to determine which connection wins routing preference.')}
                                        </FieldDescription>
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Card 2: Connection Settings (Hidden for PHP) */}
                    {driver !== 'php' && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Code className="size-4 text-primary" />
                                    {driver === 'smtp' ? t('connection_form.smtp_credentials', 'SMTP Server Credentials') : t('connection_form.provider_connection', '{{provider}} Connection', { provider: providerDisplayName })}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-6">
                                {isMultiMode ? (
                                    <>
                                        <MailerModeTabs
                                            modes={orderedDeliveryModes}
                                            value={activeDeliveryMode}
                                            onChange={(m) => {
                                                if (supportsCredentialStorageTabs) {
                                                    setForm(prev => ({
                                                        ...prev,
                                                        settings: {
                                                            ...prev.settings,
                                                            delivery_mode: m,
                                                            key_store: 'db'
                                                        }
                                                    }));
                                                    return;
                                                }
                                                updateSettings('delivery_mode', m);
                                            }}
                                        />
                                        <ProModeGateBanner activeMode={activeDeliveryMode} />

                                        {supportsCredentialStorageTabs && credentialStorageToggle}

                                        {activeDeliveryMode === 'smtp' && (
                                            <div className="space-y-6 pt-2">
                                                {isExternalCredentialMode ? (
                                                    sesWpConfigBlock
                                                ) : (
                                                    <DynamicSettingsForm
                                                        schema={smtpTabSchema}
                                                        values={form.settings}
                                                        onChange={updateSettings}
                                                        errors={visibleSettingsErrors}
                                                        onFieldBlur={handleSettingsBlur}
                                                    />
                                                )}
                                            </div>
                                        )}

                                        {activeDeliveryMode === 'api' && (
                                            <div className="space-y-6 pt-2">
                                                {isExternalCredentialMode ? (
                                                    sesWpConfigBlock
                                                ) : (
                                                    <>
                                                        {driver === 'zoho' && (
                                                            <div className="grid md:grid-cols-2 gap-4">
                                                                <DynamicSettingsForm
                                                                    schema={pickSchema(zohoApiCredentialSchema, ['client_id'])}
                                                                    values={form.settings}
                                                                    onChange={updateSettings}
                                                                    errors={visibleSettingsErrors}
                                                                    onFieldBlur={handleSettingsBlur}
                                                                />
                                                                <DynamicSettingsForm
                                                                    schema={pickSchema(zohoApiCredentialSchema, ['client_secret'])}
                                                                    values={form.settings}
                                                                    onChange={updateSettings}
                                                                    errors={visibleSettingsErrors}
                                                                    onFieldBlur={handleSettingsBlur}
                                                                />
                                                            </div>
                                                        )}
                                                        <DynamicSettingsForm
                                                            schema={apiTabSchema}
                                                            values={form.settings}
                                                            onChange={updateSettings}
                                                            errors={visibleSettingsErrors}
                                                            onFieldBlur={handleSettingsBlur}
                                                        />
                                                    </>
                                                )}
                                                {isOAuthDriver && !isWpConfigMode && (
                                                    <Field className="max-w-2xl gap-1.5">
                                                        <FieldLabel htmlFor="oauth-redirect-uri" className="text-muted-foreground">
                                                            {t('connection_form.authorized_redirect_uri', 'Authorized Redirect URI')}
                                                        </FieldLabel>
                                                        <InputGroup>
                                                            <InputGroupInput
                                                                id="oauth-redirect-uri"
                                                                readOnly
                                                                value={getOAuthRedirectDisplayUri(driver)}
                                                                className="font-mono text-xs text-muted-foreground"
                                                            />
                                                            <InputGroupAddon align="inline-end">
                                                                <InputGroupButton
                                                                    size="sm"
                                                                    onClick={handleCopyRedirectUri}
                                                                    title={t('connection_form.copy_redirect_uri', 'Copy redirect URI')}
                                                                >
                                                                    {redirectCopied ? <Check /> : <Copy />}
                                                                    {redirectCopied ? t('connection_form.copied', 'Copied') : t('connection_form.copy', 'Copy')}
                                                                </InputGroupButton>
                                                            </InputGroupAddon>
                                                        </InputGroup>
                                                        <FieldDescription className="text-xs">
                                                            {t('connection_form.add_uri_cloud_console', 'Add this URI in your cloud console for the OAuth client.')}
                                                        </FieldDescription>
                                                    </Field>
                                                )}

                                                {driver === 'outlook' && !isWpConfigMode && adminConsentBlock}

                                                {isOAuthDriver && !isWpConfigMode && (oauthConnected ? (
                                                    <Alert variant="success">
                                                        <CheckCircle2 />
                                                        <AlertTitle>{t('connection_form.oauth_tokens_stored', 'OAuth tokens are stored for this connection.')}</AlertTitle>
                                                        <AlertDescription className="w-full">
                                                            <div className="mt-2 flex flex-wrap items-center gap-3">
                                                                <Button
                                                                    type="button"
                                                                    variant="outline"
                                                                    disabled={verifyBusy}
                                                                    onClick={handleVerifyCredentials}
                                                                >
                                                                    {verifyBusy ? <Spinner /> : <CheckCircle2 />}
                                                                    {verifyBusy ? t('connection_form.verifying', 'Verifying…') : t('connection_form.reverify_credentials', 'Re-verify credentials')}
                                                                </Button>
                                                                <Button
                                                                    type="button"
                                                                    disabled={authorizeBusy || !oauthConnectionId}
                                                                    onClick={openAuthorize}
                                                                >
                                                                    {authorizeBusy ? <Spinner /> : <Zap />}
                                                                    {authorizeBusy ? t('connection_form.opening', 'Opening…') : t('connection_form.reconnect_account', 'Reconnect account')}
                                                                </Button>
                                                            </div>
                                                            {verifyMessage && (
                                                                <p className="mt-3 text-sm text-muted-foreground">{verifyMessage}</p>
                                                            )}
                                                            {oauthInlineMessage && !authorizeClicked && (
                                                                <p className="mt-3 text-sm text-muted-foreground">{oauthInlineMessage}</p>
                                                            )}
                                                            {oauthTokenCapture}
                                                        </AlertDescription>
                                                    </Alert>
                                                ) : (
                                                    <>
                                                        <div className="flex flex-wrap items-center gap-3">
                                                            <Button
                                                                type="button"
                                                                variant="outline"
                                                                disabled={
                                                                    verifyBusy ||
                                                                    !form.settings.from_email ||
                                                                    String(form.settings.from_email).trim() === '' ||
                                                                    !isLikelyEmail(form.settings.from_email) ||
                                                                    !form.settings.client_id ||
                                                                    !form.settings.client_secret
                                                                }
                                                                onClick={handleVerifyCredentials}
                                                            >
                                                                {verifyBusy ? <Spinner /> : <CheckCircle2 />}
                                                                {verifyBusy ? t('connection_form.verifying', 'Verifying…') : t('connection_form.verify_credentials', 'Verify credentials')}
                                                            </Button>
                                                            <Button
                                                                type="button"
                                                                disabled={
                                                                    authorizeBusy ||
                                                                    !oauthConnectionId ||
                                                                    !credentialsVerified
                                                                }
                                                                onClick={openAuthorize}
                                                            >
                                                                {authorizeBusy ? <Spinner /> : <Zap />}
                                                                {authorizeBusy ? t('connection_form.opening', 'Opening…') : t('connection_form.authorize', 'Authorize')}
                                                            </Button>
                                                        </div>
                                                        {verifyMessage && (
                                                            <p
                                                                className={cn('text-sm', verifyMessage.includes('failed') || verifyMessage.includes('Validation') ? 'text-destructive' : 'text-muted-foreground')}
                                                            >
                                                                {verifyMessage}
                                                            </p>
                                                        )}
                                                        {oauthInlineMessage && !authorizeClicked && (
                                                            <p className="text-sm text-muted-foreground">{oauthInlineMessage}</p>
                                                        )}
                                                        {oauthTokenCapture}
                                                    </>
                                                ))}
                                            </div>
                                        )}

                                        {driver === 'ses' && !isExternalCredentialMode && (
                                            <div className="space-y-3 pt-2">
                                                <Separator />
                                                <div className="flex flex-wrap items-center gap-3">
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        disabled={validateSesBusy}
                                                        onClick={handleValidateSesCredentials}
                                                    >
                                                        {validateSesBusy ? <Spinner /> : <CheckCircle2 />}
                                                        {validateSesBusy
                                                            ? t('connection_form.validating', 'Validating…')
                                                            : t('connection_form.validate_credentials', 'Validate Credentials')}
                                                    </Button>
                                                    <p className="text-xs text-muted-foreground">
                                                        {t('connection_form.ses_validate_help', 'Confirms AWS accepts this Access Key/Secret and can reach SES — before you save or send.')}
                                                    </p>
                                                </div>
                                                {validateSesResult && (
                                                    <p className={cn('text-sm', validateSesResult.ok ? 'text-success' : 'text-destructive')}>
                                                        {validateSesResult.message}
                                                    </p>
                                                )}
                                            </div>
                                        )}

                                        {activeDeliveryMode === 'app_permission' && (
                                            <div className="space-y-6 pt-2">
                                                <DynamicSettingsForm
                                                    schema={appPermissionTabSchema}
                                                    values={form.settings}
                                                    onChange={updateSettings}
                                                    errors={visibleSettingsErrors}
                                                    onFieldBlur={handleSettingsBlur}
                                                />
                                                {adminConsentBlock}
                                            </div>
                                        )}

                                        {isOneClickMode && (
                                            <div className="space-y-6 pt-2">
                                                {Object.keys(oneClickTabSchema).length > 0 && (
                                                    <DynamicSettingsForm
                                                        schema={oneClickTabSchema}
                                                        values={form.settings}
                                                        onChange={updateSettings}
                                                        errors={visibleSettingsErrors}
                                                        onFieldBlur={handleSettingsBlur}
                                                    />
                                                )}

                                                {/* Both Google's and Microsoft's One Click connect cards are Pro-owned
                                                    (relocated 2026-09-19; Microsoft's predates this session and was gate-in-place
                                                    until this relocation) -- renders nothing if Pro isn't installed, which
                                                    correctly matches the schema no longer offering 'one_click' as a mode at all
                                                    in that case. */}
                                                <ConnectionPanelSlot
                                                    driver={driver}
                                                    slot="one-click-connect"
                                                    authorizeBusy={authorizeBusy}
                                                    canAuthorize={canStartOneClickAuthorize}
                                                    onAuthorize={openAuthorize}
                                                    connected={oneClickConnected}
                                                    status={oneClickStatus}
                                                    maskedToken={oneClickMaskedToken}
                                                    inlineMessage={!authorizeClicked ? oauthInlineMessage : ''}
                                                    tokenCapture={oauthTokenCapture}
                                                />
                                            </div>
                                        )}

                                        {Object.keys(multiModeCommonSchema).length > 0 && (
                                            <div className="space-y-4 pt-2">
                                                <Separator />
                                                <DynamicSettingsForm
                                                    schema={multiModeCommonSchema}
                                                    values={form.settings}
                                                    onChange={updateSettings}
                                                />
                                            </div>
                                        )}
                                    </>
                                ) : (
                                    <>
                                        {driver === 'smtp' ? (
                                            <div className="space-y-6">
                                                {supportsCredentialStorageTabs && credentialStorageToggle}

                                                {isExternalCredentialMode ? (
                                                    sesWpConfigBlock
                                                ) : (
                                                    <div className="space-y-4">
                                                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                                            <Field className="gap-1.5" data-invalid={Boolean(visibleSettingsErrors?.host) || undefined}>
                                                                <FieldLabel htmlFor="smtp-host" className="text-muted-foreground">{t('connection_form.smtp_host', 'SMTP Host')}</FieldLabel>
                                                                <Input
                                                                    id="smtp-host"
                                                                    value={form.settings.host || ''}
                                                                    onChange={(e) => updateSettings('host', e.target.value)}
                                                                    onBlur={() => handleSettingsBlur('host')}
                                                                    aria-invalid={Boolean(visibleSettingsErrors?.host)}
                                                                    placeholder={t('connection_form.smtp_host_placeholder', 'e.g. smtp.gmail.com')}
                                                                />
                                                                {visibleSettingsErrors?.host && <FieldError className="text-xs">{visibleSettingsErrors.host}</FieldError>}
                                                            </Field>
                                                            <Field className="gap-1.5" data-invalid={Boolean(visibleSettingsErrors?.port) || undefined}>
                                                                <FieldLabel htmlFor="smtp-port" className="text-muted-foreground">{t('connection_form.port', 'Port')}</FieldLabel>
                                                                <Input
                                                                    id="smtp-port"
                                                                    type="number"
                                                                    value={form.settings.port ?? 587}
                                                                    onChange={(e) => updateSettings('port', e.target.value)}
                                                                    onBlur={() => handleSettingsBlur('port')}
                                                                    aria-invalid={Boolean(visibleSettingsErrors?.port)}
                                                                    placeholder={t('connection_form.smtp_port_placeholder', 'e.g. 587 or 465')}
                                                                />
                                                                {visibleSettingsErrors?.port && <FieldError className="text-xs">{visibleSettingsErrors.port}</FieldError>}
                                                            </Field>
                                                        </div>

                                                        <div className="grid grid-cols-1 items-start gap-4 md:grid-cols-3">
                                                            <Field className="gap-1.5" data-invalid={Boolean(visibleSettingsErrors?.encryption) || undefined}>
                                                                <FieldLabel htmlFor="smtp-encryption" className="text-muted-foreground">{t('connection_form.encryption_method', 'Encryption Method')}</FieldLabel>
                                                                <Select
                                                                    value={form.settings.encryption || 'tls'}
                                                                    onValueChange={(value) => {
                                                                        updateSettings('encryption', value);
                                                                        handleSettingsBlur('encryption');
                                                                    }}
                                                                >
                                                                    <SelectTrigger id="smtp-encryption" className="w-full" aria-invalid={Boolean(visibleSettingsErrors?.encryption)}>
                                                                        <SelectValue placeholder={t('connection_form.select_encryption', 'Select Encryption')} />
                                                                    </SelectTrigger>
                                                                    <SelectContent>
                                                                        <SelectItem value="none">{t('connection_form.none', 'None')}</SelectItem>
                                                                        <SelectItem value="tls">TLS</SelectItem>
                                                                        <SelectItem value="ssl">SSL</SelectItem>
                                                                    </SelectContent>
                                                                </Select>
                                                                {visibleSettingsErrors?.encryption && <FieldError className="text-xs">{visibleSettingsErrors.encryption}</FieldError>}
                                                            </Field>
                                                            <div className="space-y-1.5">
                                                                <Label id="smtp-auto-tls-label" className="text-muted-foreground">{t('connection_form.auto_tls', 'Auto TLS')}</Label>
                                                                <SegmentedControl
                                                                    fullWidth
                                                                    labelledBy="smtp-auto-tls-label"
                                                                    value={(form.settings.use_auto_tls ?? true) ? 'on' : 'off'}
                                                                    onValueChange={(value) => updateSettings('use_auto_tls', value === 'on')}
                                                                    options={onOffOptions}
                                                                />
                                                            </div>
                                                            <div className="space-y-1.5">
                                                                <Label id="smtp-authentication-label" className="text-muted-foreground">{t('connection_form.authentication', 'Authentication')}</Label>
                                                                <SegmentedControl
                                                                    fullWidth
                                                                    labelledBy="smtp-authentication-label"
                                                                    value={smtpAuthEnabled ? 'on' : 'off'}
                                                                    onValueChange={(value) => updateSettings('authentication', value === 'on')}
                                                                    options={onOffOptions}
                                                                />
                                                            </div>
                                                        </div>

                                                        {smtpAuthEnabled && (
                                                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                                                <Field className="gap-1.5" data-invalid={Boolean(visibleSettingsErrors?.username) || undefined}>
                                                                    <FieldLabel htmlFor="smtp-username" className="text-muted-foreground">{t('common.username', 'Username')}</FieldLabel>
                                                                    <Input
                                                                        id="smtp-username"
                                                                        value={form.settings.username || ''}
                                                                        onChange={(e) => updateSettings('username', e.target.value)}
                                                                        onBlur={() => handleSettingsBlur('username')}
                                                                        aria-invalid={Boolean(visibleSettingsErrors?.username)}
                                                                        placeholder={t('connection_form.smtp_username_placeholder', 'SMTP Username')}
                                                                    />
                                                                    {visibleSettingsErrors?.username && <FieldError className="text-xs">{visibleSettingsErrors.username}</FieldError>}
                                                                </Field>
                                                                <Field className="gap-1.5" data-invalid={Boolean(visibleSettingsErrors?.password) || undefined}>
                                                                    <FieldLabel htmlFor="smtp-password" className="text-muted-foreground">{t('connection_form.smtp_password', 'SMTP Password')}</FieldLabel>
                                                                    <PasswordInput
                                                                        id="smtp-password"
                                                                        value={form.settings.password || ''}
                                                                        onChange={(e) => updateSettings('password', e.target.value)}
                                                                        onBlur={() => handleSettingsBlur('password')}
                                                                        aria-invalid={Boolean(visibleSettingsErrors?.password)}
                                                                        placeholder={t('connection_form.smtp_password_placeholder', 'SMTP Password')}
                                                                    />
                                                                    {visibleSettingsErrors?.password && <FieldError className="text-xs">{visibleSettingsErrors.password}</FieldError>}
                                                                </Field>
                                                            </div>
                                                        )}
                                                    </div>
                                                )}
                                            </div>
                                        ) : (
                                            <DynamicSettingsForm
                                                schema={genericConnectionSchema}
                                                values={form.settings}
                                                onChange={updateSettings}
                                                errors={visibleSettingsErrors}
                                                onFieldBlur={handleSettingsBlur}
                                            />
                                        )}

                                        {driver === 'ses' && (
                                            <ConnectionPanelSlot
                                                driver="ses"
                                                slot="identity-management"
                                                connectionId={initialData?.id ?? null}
                                                settings={form.settings}
                                                onApplyFromEmail={(email) => updateSettings('from_email', email)}
                                            />
                                        )}
                                    </>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {/* OAuth Refresh History Panel - developer-only diagnostics, hidden entirely
                        unless a developer opts in with add_filter('boolean_smtp_oauth_debug_ui',
                        '__return_true') -- see AppServiceProvider::adminSpaBootstrapProps(). */}
                    {Boolean(window.BooleanSmtpAdmin?.oauthDebug) && (initialData?.id || oauthConnectionId) && isOAuthDriver && !isOneClickMode && (
                        <div className="overflow-hidden rounded-lg border bg-card">
                            <OAuthRefreshHistoryPanel
                                connectionId={initialData?.id || oauthConnectionId}
                                driver={driver}
                                onRefresh={(payload) => {
                                    const expiresAt = payload?.token_expires_at;
                                    if (!expiresAt) {
                                        if (typeof onRemoteSettingsUpdated === 'function') {
                                            onRemoteSettingsUpdated();
                                        }
                                        return;
                                    }

                                    setForm((prev) => ({
                                        ...prev,
                                        settings: {
                                            ...prev.settings,
                                            token_expires_at: expiresAt,
                                        },
                                    }));

                                    if (typeof onRemoteSettingsUpdated === 'function') {
                                        onRemoteSettingsUpdated();
                                    }
                                }}
                            />
                        </div>
                    )}

                    {/* Test Result Section */}
                    {testResult && (
                        <Alert variant={testResult.success ? 'success' : 'destructive'}>
                            {testResult.success ? <CheckCircle2 /> : <Info />}
                            <AlertTitle>
                                {testResult.success ? t('connection_form.test_sent', 'Test email sent successfully!') : t('connection_form.test_failed', 'Test email failed to send')}
                            </AlertTitle>
                            <AlertDescription>
                                {testResult.message && !testResult.error && <p>{testResult.message}</p>}
                                {testResult.error && <p>{testResult.error}</p>}
                                {testResult.to && <p>{t('connection_form.recipient', 'Recipient')}: {testResult.to}</p>}
                            </AlertDescription>
                        </Alert>
                    )}

                    {/* Actions */}
                    <div className="flex flex-wrap items-center gap-3 pt-4">
                        {isActiveModeLocked ? (
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    {/* aria-disabled (not `disabled`) keeps the button focusable so the tooltip can explain why saving is blocked. */}
                                    <Button type="button" size="lg" aria-disabled="true" className="cursor-not-allowed opacity-50">
                                        {saveButtonLabel}
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>{t('connection_form.activate_pro_to_save_mode', 'Activate Pro to save this delivery mode')}</TooltipContent>
                            </Tooltip>
                        ) : (
                            <Button type="button" size="lg" onClick={handleSave} disabled={saving || tokenSaveBusy}>
                                {saving && <Spinner />}
                                {saveButtonLabel}
                            </Button>
                        )}
                        {onTest && initialData && (
                            <Button type="button" variant="outline" size="lg" onClick={() => onTest(form)} disabled={testing || saving}>
                                {testing && <Spinner />}
                                {t('connection_form.send_test_email', 'Send Test Email')}
                            </Button>
                        )}
                        <Button type="button" variant="ghost" size="lg" onClick={onCancel}>
                            {t('common.cancel', 'Cancel')}
                        </Button>
                    </div>
                </div>

                {/* Sidebar: Setup Guide — hidden on mobile, where the vertical space is better spent on the form itself. */}
                <div className="hidden w-full space-y-6 lg:block lg:w-[380px]">
                    {SetupGuide ? (
                        <Card className="top-6 lg:sticky">
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <HelpCircle className="size-4 text-primary" />
                                    {t('connection_form.setup_guide', 'Setup Guide')}
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <ScrollArea className="max-h-96 lg:max-h-[calc(100vh-200px)]">
                                    <SetupGuide
                                        providerName={providerDisplayName}
                                        docsUrl={provider?.docsUrl || ''}
                                        deliveryMode={isMultiMode ? activeDeliveryMode : undefined}
                                        keyStore={connectionSchema?.key_store ? (form.settings.key_store || 'db') : undefined}
                                        sesDeliveryMode={driver === 'ses' ? activeDeliveryMode : undefined}
                                        sesKeyStore={driver === 'ses' ? (form.settings.key_store || 'db') : undefined}
                                    />
                                </ScrollArea>
                            </CardContent>
                        </Card>
                    ) : (
                        <Empty className="top-6 lg:sticky">
                            <EmptyHeader>
                                <EmptyMedia variant="icon"><Headset /></EmptyMedia>
                                <EmptyTitle>{t('connection_form.no_guide', 'No Guide Available')}</EmptyTitle>
                                <EmptyDescription>
                                    {t('connection_form.no_guide_desc', 'Follow the provider documentation for {{provider}} credentials.', { provider: providerDisplayName })}
                                </EmptyDescription>
                            </EmptyHeader>
                            {provider.docsUrl && (
                                <EmptyContent>
                                    <Button variant="link" size="sm" asChild>
                                        <a href={provider.docsUrl} target="_blank" rel="noopener noreferrer">
                                            {t('connection_form.visit_docs', 'Visit Documentation')}
                                        </a>
                                    </Button>
                                </EmptyContent>
                            )}
                        </Empty>
                    )}
                </div>
            </div>
        </div>
    );
}

