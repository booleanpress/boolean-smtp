import { Lock, Database, FileText, Cloud, HelpCircle } from 'lucide-react';

import { useTranslations } from '@/hooks/useTranslations';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Item, ItemContent, ItemTitle } from '@/components/ui/item';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

const SOURCE_VARIANTS = {
    database: 'info',
    wp_config: 'secondary',
    env: 'warning',
    iam_role: 'success',
    unknown: 'outline',
};

const SOURCE_ICONS = {
    database: Database,
    wp_config: FileText,
    env: Cloud,
    iam_role: Cloud,
    unknown: HelpCircle,
};

const EXTERNAL_SOURCES = ['wp_config', 'env', 'iam_role'];

function normalizeSource(value) {
    return value === 'db' ? 'database' : value;
}

/**
 * Phase E: Credential Source Badge
 * Shows which credential source is providing access/secret keys
 * Displays for each key separately (access_key and secret_key)
 */
export default function CredentialSourceBadge({ source, label, locked = false }) {
    const { t } = useTranslations();
    const normalizedSource = normalizeSource(source);
    const variant = SOURCE_VARIANTS[normalizedSource] || SOURCE_VARIANTS.unknown;
    const Icon = SOURCE_ICONS[normalizedSource] || Database;

    const tooltips = {
        database: t('credential_sources.tooltip_database', 'Credentials from BooleanSMTP database (connection form)'),
        wp_config: t('credential_sources.tooltip_wp_config', 'Credentials from WordPress wp-config constants (BOOLEANSMTP_AWS_SES_*)'),
        env: t('credential_sources.tooltip_env', 'Credentials from server environment variables (BOOLEANSMTP_AWS_SES_*)'),
        iam_role: t('credential_sources.tooltip_iam_role', 'Credentials from EC2 IAM role (IMDSv2, opt-in)'),
        unknown: t('credential_sources.tooltip_unknown', 'No credentials detected for this type'),
    };
    const tooltip = tooltips[normalizedSource] || t('credential_sources.tooltip_default', 'Credential source');

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Badge variant={variant} tabIndex={0} className={cn(locked && 'opacity-60')}>
                    <Icon aria-hidden="true" />
                    <span>{label || normalizedSource}</span>
                    {locked && <Lock aria-hidden="true" />}
                </Badge>
            </TooltipTrigger>
            <TooltipContent>{tooltip}</TooltipContent>
        </Tooltip>
    );
}

/**
 * Credential Source Field Wrapper
 * Wraps form fields and disables them if external credential source detected
 */
export function CredentialSourceField({ source, children, locked = false, hint = '' }) {
    const { t } = useTranslations();
    const normalizedSource = normalizeSource(source);

    return (
        <div className="relative">
            {children}

            {locked && (
                <Alert variant="warning" className="mt-1">
                    <HelpCircle />
                    <AlertTitle>{t('credential_sources.field_locked', 'Field locked: External credential source detected')}</AlertTitle>
                    <AlertDescription className="text-xs">
                        <p>
                            {normalizedSource === 'wp_config' && t('credential_sources.lock_reason_wp_config', 'BOOLEANSMTP_AWS_* constants are set in wp-config.php')}
                            {normalizedSource === 'env' && t('credential_sources.lock_reason_env', 'BOOLEANSMTP_AWS_* environment variables are active')}
                            {normalizedSource === 'iam_role' && t('credential_sources.lock_reason_iam_role', 'EC2 IAM role credentials are enabled and will be used')}
                            {hint && ` — ${hint}`}
                        </p>
                        <p>{t('credential_sources.edit_hint', 'To edit this field, remove the external source first.')}</p>
                    </AlertDescription>
                </Alert>
            )}
        </div>
    );
}

/**
 * Credential Sources Overview Panel
 * Shows both access key and secret key sources in one view
 */
export function CredentialSourcesPanel({ accessKeySource, secretKeySource, showLockingInfo = true }) {
    const { t } = useTranslations();
    if (!accessKeySource && !secretKeySource) {
        return null;
    }

    const accessSource = normalizeSource(accessKeySource?.source);
    const secretSource = normalizeSource(secretKeySource?.source);

    const isLocked = EXTERNAL_SOURCES.includes(accessSource) || EXTERNAL_SOURCES.includes(secretSource);
    const notConfigured = <span className="text-xs text-muted-foreground">{t('credential_sources.not_configured', 'Not configured')}</span>;

    return (
        <Card className="mt-4">
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Lock className="size-4 text-muted-foreground" aria-hidden="true" />
                    {t('credential_sources.title', 'Credential Sources (Phase E)')}
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="grid grid-cols-2 gap-3">
                    <div className="space-y-1">
                        <p className="text-xs font-medium text-muted-foreground">{t('credential_sources.access_key_source', 'Access Key Source:')}</p>
                        {accessKeySource ? (
                            <CredentialSourceBadge
                                source={accessSource}
                                label={accessKeySource.label}
                                locked={accessSource !== 'database'}
                            />
                        ) : notConfigured}
                    </div>

                    <div className="space-y-1">
                        <p className="text-xs font-medium text-muted-foreground">{t('credential_sources.secret_key_source', 'Secret Key Source:')}</p>
                        {secretKeySource ? (
                            <CredentialSourceBadge
                                source={secretSource}
                                label={secretKeySource.label}
                                locked={secretSource !== 'database'}
                            />
                        ) : notConfigured}
                    </div>
                </div>

                <Item variant="outline" size="sm">
                    <ItemContent>
                        <ItemTitle className="text-xs">{t('credential_sources.precedence_title', 'Credential Precedence:')}</ItemTitle>
                        <ol className="space-y-1 text-xs text-muted-foreground">
                            <li><span className="font-mono">{t('credential_sources.precedence_database_label', '1. Database')}</span> ({t('credential_sources.precedence_database_desc', 'connection form')})</li>
                            <li><span className="font-mono">{t('credential_sources.precedence_wp_config_label', '2. wp-config')}</span> ({t('credential_sources.precedence_wp_config_desc', 'BOOLEANSMTP_AWS_*')})</li>
                            <li><span className="font-mono">{t('credential_sources.precedence_env_label', '3. Environment')}</span> ({t('credential_sources.precedence_env_desc', 'BOOLEANSMTP_AWS_*')})</li>
                            <li><span className="font-mono">{t('credential_sources.precedence_iam_role_label', '4. EC2 IAM Role')}</span> ({t('credential_sources.precedence_iam_role_desc', 'IMDSv2, opt-in')})</li>
                        </ol>
                    </ItemContent>
                </Item>

                {showLockingInfo && isLocked && (
                    <Alert variant="warning">
                        <AlertDescription className="text-xs">
                            <p><strong>{t('credential_sources.note_label', 'Note:')}</strong> {t('credential_sources.locked_note', 'Some credential fields are locked because external credentials are detected. Database values are ignored when higher-priority sources are present.')}</p>
                        </AlertDescription>
                    </Alert>
                )}
            </CardContent>
        </Card>
    );
}
