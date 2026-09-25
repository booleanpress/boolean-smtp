import { Suspense, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import api from '../services/api';
import { toast } from 'sonner';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Button } from '@/components/ui/button';
import { FieldLabel } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { AtSign, CalendarClock, FileText, FlaskConical, GitBranch, Info, RotateCcw, ScrollText, Send, ShieldCheck, Terminal, Trash2, UserRound, Save } from 'lucide-react';
import { cn } from '@/lib/utils';
import { SectionCard } from '@/components/section-card';
import SettingsSkeleton from '../components/skeletons/SettingsSkeleton';
import { useTranslations } from '../hooks/useTranslations';
import { useProExtensions } from '../hooks/useProExtensions';

/**
 * Whether the Sender Identity card is shown.
 *
 * The default sender only takes effect when it is forced over every connection, so the card stays
 * hidden until that setting has a clearer job. Stored values keep working.
 *
 * @since 1.0.0
 * @type {boolean}
 */
const SHOW_SENDER_IDENTITY = false;

function SettingsGroup({ children, className }) {
    return <div className={cn('min-w-0 space-y-3', className)}>{children}</div>;
}

function SettingsRow({ icon: Icon, label, description, htmlFor, control, inlineControl = false, className }) {
    const { t } = useTranslations();
    const helpLabel = t('settings.more_info', 'More information about {{setting}}', { setting: label });

    return (
        <div
            data-layout={inlineControl ? 'inline-control' : 'split-control'}
            className={cn(
                inlineControl
                    ? 'flex min-w-0 items-center justify-between gap-3'
                    : 'grid gap-3 sm:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)] sm:items-center',
                className
            )}
        >
            <div className={cn('min-w-0', inlineControl && 'flex-1')}>
                <div className="flex min-w-0 items-center gap-1.5">
                    <Icon data-slot="settings-row-icon" className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <FieldLabel htmlFor={htmlFor} className="min-w-0 text-sm leading-5">{label}</FieldLabel>
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                className="shrink-0 text-muted-foreground hover:text-foreground"
                                aria-label={helpLabel}
                            >
                                <Info className="size-4" aria-hidden="true" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent className="max-w-xs">{description}</TooltipContent>
                    </Tooltip>
                </div>
            </div>
            <div className={cn('min-w-0', inlineControl && 'shrink-0')}>{control}</div>
        </div>
    );
}

function connectionLabel(connection) {
    const name = String(connection?.name || '');
    const fromEmail = String(connection?.settings?.from_email || '').trim();

    return fromEmail ? `${name} <${fromEmail}>` : name;
}

function SettingsExtensionPanel({ panel }) {
    const Panel = panel.component;

    if (!Panel) {
        return null;
    }

    return (
        <Suspense
            fallback={(
                <Card className="gap-4 py-5">
                    <CardContent className="flex items-center px-5 text-muted-foreground">
                        <Spinner />
                    </CardContent>
                </Card>
            )}
        >
            <Panel />
        </Suspense>
    );
}

export default function Settings() {
    const { t } = useTranslations();
    const { settingsPanels } = useProExtensions();
    const [settings, setSettings] = useState({});
    const [savedSettings, setSavedSettings] = useState({});
    const [connections, setConnections] = useState([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [isBottomSaveVisible, setIsBottomSaveVisible] = useState(true);
    const bottomSaveRef = useRef(null);

    const loadData = useCallback(async () => {
        setLoading(true);
        try {
            const [settingsRes, connRes] = await Promise.all([
                api.get('settings'),
                api.get('connections'),
            ]);
            const loadedSettings = settingsRes.data || {};

            setSettings(loadedSettings);
            setSavedSettings(loadedSettings);
            setConnections(Array.isArray(connRes.data) ? connRes.data : []);
        } catch (err) {
            toast.error(t('settings.load_failed', 'Failed to load settings data: {{message}}', { message: err.message }));
        } finally {
            setLoading(false);
        }
    }, [t]);

    useEffect(() => {
        loadData();
    }, [loadData]);

    useEffect(() => {
        if (loading || !bottomSaveRef.current || typeof IntersectionObserver === 'undefined') {
            return undefined;
        }

        const observer = new IntersectionObserver(([entry]) => {
            setIsBottomSaveVisible(entry.isIntersecting);
        }, { threshold: 0.1 });

        observer.observe(bottomSaveRef.current);

        return () => observer.disconnect();
    }, [loading]);

    const hasChanges = useMemo(
        () => JSON.stringify(settings) !== JSON.stringify(savedSettings),
        [savedSettings, settings]
    );

    async function handleSave() {
        if (saving || !hasChanges) {
            return;
        }

        setSaving(true);
        try {
            await api.put('settings', settings);
            setSavedSettings(settings);
            toast.success(t('settings.save_success', 'Settings saved successfully'));
        } catch (err) {
            toast.error(t('settings.save_failed', 'Error saving settings: {{message}}', { message: err.message }));
        } finally {
            setSaving(false);
        }
    }

    function update(key, value) {
        setSettings(prev => ({ ...prev, [key]: value }));
    }

    if (loading) {
        return <SettingsSkeleton />;
    }

    const fallbackEnabled = settings.fallback_enabled ?? true;
    const autoRetry = settings.auto_retry ?? true;
    const forceFrom = Boolean(settings.force_from);
    const loggingEnabled = settings.log_emails ?? true;

    return (
        <div className="mx-auto max-w-7xl space-y-6 pb-8">
            <div className="space-y-1">
                <h1 className="text-xl font-semibold tracking-tight">{t('settings.title', 'General Settings')}</h1>
                <p className="max-w-2xl text-sm leading-5 text-muted-foreground">{t('settings.subtitle', 'Configure global plugin behavior, logging, and connection defaults.')}</p>
            </div>

            <div className="space-y-4">
                {settingsPanels.map((panel, index) => (
                    <SettingsExtensionPanel key={panel.id || `settings-panel-${index}`} panel={panel} />
                ))}

                <div data-testid="settings-core-card-grid" className="grid grid-cols-1 gap-4 xl:grid-cols-2 xl:items-start">
                {SHOW_SENDER_IDENTITY && (
                    <SectionCard
                        title={t('settings.sender_identity', 'Sender Identity')}
                        description={t('settings.sender_identity_desc', 'Set the sender name and email used when no connection or plugin provides one.')}
                        contentClassName="px-4 py-4 sm:px-5 sm:py-5"
                    >
                        <SettingsGroup>
                                <SettingsRow
                                    icon={AtSign}
                                    htmlFor="settings-from-email"
                                    label={t('settings.default_from_email', 'Default From Email')}
                                    description={t('settings.default_from_email_desc', 'The address used when a more specific sender is not provided.')}
                                    control={(
                                        <Input
                                            id="settings-from-email"
                                            type="email"
                                            className="h-10"
                                            value={settings.from_email || ''}
                                            onChange={e => update('from_email', e.target.value)}
                                            placeholder={t('settings.default_from_email_placeholder', 'e.g. hello@yourdomain.com')}
                                        />
                                    )}
                                />
                                <SettingsRow
                                    icon={UserRound}
                                    htmlFor="settings-from-name"
                                    label={t('settings.default_from_name', 'Default From Name')}
                                    description={t('settings.default_from_name_desc', 'The display name shown in recipients\' inboxes.')}
                                    control={(
                                        <Input
                                            id="settings-from-name"
                                            type="text"
                                            className="h-10"
                                            value={settings.from_name || ''}
                                            onChange={e => update('from_name', e.target.value)}
                                            placeholder={t('settings.default_from_name_placeholder', 'e.g. John from BooleanSMTP')}
                                        />
                                    )}
                                />
                                <SettingsRow
                                    icon={ShieldCheck}
                                    htmlFor="settings-force-from"
                                    inlineControl
                                    label={t('settings.force_from', 'Force From name and email globally')}
                                    description={t('settings.force_from_desc', 'Override the identity a connection or a plugin sets. Off: the values above only fill in what is missing.')}
                                    control={(
                                        <div className="flex justify-start sm:justify-end">
                                            <Switch
                                                id="settings-force-from"
                                                checked={forceFrom}
                                                onCheckedChange={v => update('force_from', v)}
                                            />
                                        </div>
                                    )}
                                />
                        </SettingsGroup>
                    </SectionCard>
                )}

                <SectionCard
                    title={t('settings.delivery_reliability', 'Delivery & Reliability')}
                    description={t('settings.delivery_reliability_desc', 'Choose the default delivery connection and how failed sends recover.')}
                    contentClassName="px-4 py-4 sm:px-5 sm:py-5"
                >
                    <SettingsGroup>
                        <SettingsRow
                            icon={Send}
                            htmlFor="settings-default-connection"
                            label={t('settings.default_connection', 'Default Connection')}
                            description={t('settings.default_connection_desc', 'Used for outgoing email when no routing rule overrides it.')}
                            control={(
                                <Select
                                    value={settings.default_connection_id ? String(settings.default_connection_id) : 'none'}
                                    onValueChange={v => update('default_connection_id', v === 'none' ? null : Number(v))}
                                >
                                    <SelectTrigger id="settings-default-connection" className="h-10 w-full">
                                        <SelectValue placeholder={t('settings.select_primary', 'Select primary connection')} />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">{t('settings.system_default_php', 'System Default (PHP Mail)')}</SelectItem>
                                        {connections.map(c => (
                                            <SelectItem key={c.id} value={String(c.id)}>{connectionLabel(c)}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            )}
                        />
                        <SettingsRow
                            icon={GitBranch}
                            htmlFor="settings-fallback-enabled"
                            inlineControl
                            label={t('settings.fallback_connection', 'Fallback Connection')}
                            description={t('settings.fallback_connection_desc', 'Used when the primary connection cannot deliver the message.')}
                            control={(
                                <div className="flex justify-start sm:justify-end">
                                    <Switch
                                        id="settings-fallback-enabled"
                                        checked={fallbackEnabled}
                                        onCheckedChange={v => update('fallback_enabled', v)}
                                    />
                                </div>
                            )}
                        />
                        {/* The connection picker belongs to the switch above: shown only while fallback is on. */}
                        {fallbackEnabled && (
                            <div className="grid gap-2 pl-6 sm:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)] sm:items-center sm:gap-3">
                                <FieldLabel htmlFor="settings-fallback-connection" className="text-sm font-normal text-muted-foreground">
                                    {t('settings.fallback_use_connection', 'Send through')}
                                </FieldLabel>
                                <Select
                                    value={settings.fallback_connection_id ? String(settings.fallback_connection_id) : 'none'}
                                    onValueChange={v => update('fallback_connection_id', v === 'none' ? null : Number(v))}
                                >
                                    <SelectTrigger id="settings-fallback-connection" className="h-10 w-full">
                                        <SelectValue placeholder={t('settings.select_fallback', 'Select fallback connection')} />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">{t('settings.no_fallback', 'No Fallback (None)')}</SelectItem>
                                        {connections.map(c => (
                                            <SelectItem key={c.id} value={String(c.id)}>{connectionLabel(c)}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}
                        <SettingsRow
                            icon={RotateCcw}
                            htmlFor="settings-auto-retry"
                            inlineControl
                            label={t('settings.auto_retry', 'Retry on other connections')}
                            description={t('settings.auto_retry_desc', 'When a send fails, retry it on your other active connections, up to 2 attempts.')}
                            control={(
                                <div className="flex justify-start sm:justify-end">
                                    <Switch
                                        id="settings-auto-retry"
                                        checked={autoRetry}
                                        onCheckedChange={v => update('auto_retry', v)}
                                    />
                                </div>
                            )}
                        />
                    </SettingsGroup>
                </SectionCard>

                <SectionCard
                    title={t('settings.logging_retention', 'Logging & Retention')}
                    description={t('settings.logging_retention_desc', 'Choose which delivery records to keep and how long to retain them.')}
                    contentClassName="px-4 py-4 sm:px-5 sm:py-5"
                >
                    <SettingsGroup>
                        <SettingsRow
                            icon={ScrollText}
                            htmlFor="settings-log-emails"
                            inlineControl
                            label={t('settings.log_all_active_emails', 'Log All Active Emails')}
                            description={t('settings.log_all_active_emails_desc', 'Keep a delivery record for outgoing emails.')}
                            control={(
                                <div className="flex justify-start sm:justify-end">
                                    <Switch
                                        id="settings-log-emails"
                                        checked={loggingEnabled}
                                        onCheckedChange={v => update('log_emails', v)}
                                    />
                                </div>
                            )}
                        />
                                    <SettingsRow
                                        icon={Terminal}
                                        htmlFor="settings-log-mailer-diagnostics"
                                        inlineControl
                                        label={t('settings.log_mailer_resolution', 'Log mailer resolution to PHP error log')}
                                        description={t('settings.log_mailer_resolution_desc', 'Write one JSON line per email to the server error log. Off by default.')}
                                        control={(
                                            <div className="flex justify-start sm:justify-end">
                                                <Switch
                                                    id="settings-log-mailer-diagnostics"
                                                    checked={settings.log_mailer_diagnostics || false}
                                                    onCheckedChange={v => update('log_mailer_diagnostics', v)}
                                                />
                                            </div>
                                        )}
                                    />
                                    <SettingsRow
                                        icon={CalendarClock}
                                        htmlFor="settings-log-retention"
                                        label={t('settings.retention_period', 'Retention Period')}
                                        description={t('settings.log_retention_desc', 'Automatically clear stored email logs after this period.')}
                                        control={(
                                            <Select
                                                value={String(settings.log_retention_days || '30')}
                                                onValueChange={v => update('log_retention_days', Number(v))}
                                            >
                                                <SelectTrigger id="settings-log-retention" className="h-10 w-full">
                                                    <SelectValue placeholder={t('settings.retention_period', 'Retention period')} />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="7">{t('settings.retention_7_days', 'Clear after 7 Days')}</SelectItem>
                                                    <SelectItem value="14">{t('settings.retention_14_days', 'Clear after 14 Days')}</SelectItem>
                                                    <SelectItem value="30">{t('settings.retention_30_days', 'Clear after 30 Days')}</SelectItem>
                                                    <SelectItem value="90">{t('settings.retention_90_days', 'Clear after 90 Days')}</SelectItem>
                                                    <SelectItem value="365">{t('settings.retention_1_year', 'Clear after 1 Year')}</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        )}
                                    />
                    </SettingsGroup>
                </SectionCard>

                <SectionCard
                    title={t('settings.testing_content', 'Testing & Content')}
                    description={t('settings.testing_content_desc', 'Configure safe testing, diagnostic visibility, and HTML email compatibility.')}
                    contentClassName="px-4 py-4 sm:px-5 sm:py-5"
                >
                    <SettingsGroup>
                                    <SettingsRow
                                        icon={FlaskConical}
                                        htmlFor="settings-simulation-enabled"
                                        inlineControl
                                        label={t('settings.email_simulation_mode', 'Email Simulation Mode')}
                                        description={t('settings.email_simulation_mode_desc', 'Record emails without sending them. Use only on local or staging sites.')}
                                        control={(
                                            <div className="flex justify-start sm:justify-end">
                                                <Switch
                                                    id="settings-simulation-enabled"
                                                    checked={settings.simulation_enabled || false}
                                                    onCheckedChange={v => update('simulation_enabled', v)}
                                                />
                                            </div>
                                        )}
                                    />
                                    <SettingsRow
                                        icon={FileText}
                                        htmlFor="settings-auto-plain-text"
                                        inlineControl
                                        label={t('settings.auto_plain_text', 'Auto-generate Plain Text')}
                                        description={t('settings.auto_plain_text_desc', 'Create a plain-text alternative for HTML email to improve inbox compatibility.')}
                                        control={(
                                            <div className="flex justify-start sm:justify-end">
                                                <Switch
                                                    id="settings-auto-plain-text"
                                                    checked={settings.auto_plain_text ?? true}
                                                    onCheckedChange={v => update('auto_plain_text', v)}
                                                />
                                            </div>
                                        )}
                                    />
                                <SettingsRow
                                    icon={Terminal}
                                    htmlFor="settings-show-test-email-console"
                                    inlineControl
                                    label={t('settings.show_test_email_console', 'Test Email Activity Console')}
                                    description={t('settings.show_test_email_console_desc', 'Show the SMTP handshake log on the Test Email page. API request and response diagnostics require the developer filter.')}
                                    control={(
                                        <div className="flex justify-start sm:justify-end">
                                            <Switch
                                                id="settings-show-test-email-console"
                                                checked={settings.show_test_email_console || false}
                                                onCheckedChange={v => update('show_test_email_console', v)}
                                            />
                                        </div>
                                    )}
                                />
                    </SettingsGroup>
                </SectionCard>

                <SectionCard
                    title={t('settings.data_section', 'Data')}
                    description={t('settings.data_section_desc', 'Choose what happens to your data when BooleanSMTP is deleted. Deactivating the plugin never deletes anything.')}
                    contentClassName="px-4 py-4 sm:px-5 sm:py-5"
                >
                    <SettingsGroup>
                        <SettingsRow
                            icon={Trash2}
                            htmlFor="settings-delete-data-on-uninstall"
                            inlineControl
                            label={t('settings.delete_data_on_uninstall', 'Delete data on uninstall')}
                            description={settings.delete_data_on_uninstall
                                ? t('settings.delete_data_on_uninstall_on', 'On: deleting the plugin from the Plugins screen permanently deletes its connections, email logs and settings.')
                                : t('settings.delete_data_on_uninstall_off', 'Off: deleting the plugin keeps its connections, email logs and settings, so a reinstall picks up where you left off.')}
                            control={(
                                <div className="flex justify-start sm:justify-end">
                                    <Switch
                                        id="settings-delete-data-on-uninstall"
                                        checked={settings.delete_data_on_uninstall || false}
                                        onCheckedChange={v => update('delete_data_on_uninstall', v)}
                                    />
                                </div>
                            )}
                        />
                    </SettingsGroup>
                </SectionCard>

                </div>

                <div ref={bottomSaveRef} className="flex justify-end border-t pt-4">
                    <Button onClick={handleSave} disabled={saving || !hasChanges} className="w-full sm:w-auto">
                        {saving ? <Spinner /> : <Save />}
                        {saving ? t('settings.saving', 'Saving...') : t('settings.save_settings', 'Save Settings')}
                    </Button>
                </div>
            </div>

            {hasChanges && !isBottomSaveVisible && (
                <div className="fixed right-4 bottom-4 z-40 sm:right-6 sm:bottom-6" data-testid="floating-save-settings">
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Button
                                type="button"
                                size="icon-lg"
                                className="shadow-lg"
                                onClick={handleSave}
                                disabled={saving}
                                aria-label={t('settings.save_settings', 'Save Settings')}
                                title={t('settings.save_settings', 'Save Settings')}
                            >
                                {saving ? <Spinner /> : <Save />}
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>{t('settings.save_settings', 'Save Settings')}</TooltipContent>
                    </Tooltip>
                </div>
            )}
        </div>
    );
}
