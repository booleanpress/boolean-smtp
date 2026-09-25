import { useState, useEffect } from 'react';
import api from '../services/api';
import {
    Edit2, Trash2, Play, Square, Send, Search, BookOpen,
    Bell, CheckCircle2, ArrowRight
} from 'lucide-react';
import { Button } from '../components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Input } from '../components/ui/input';
import { Checkbox } from '../components/ui/checkbox';
import { Switch } from '../components/ui/switch';
import { Field, FieldContent, FieldDescription, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { Spinner } from '@/components/ui/spinner';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import {
    AlertDialog, AlertDialogAction, AlertDialogCancel,
    AlertDialogContent, AlertDialogDescription, AlertDialogFooter,
    AlertDialogHeader, AlertDialogTitle, AlertDialogTrigger
} from '../components/ui/alert-dialog';
import { toast } from 'sonner';
import { cn } from '@/lib/utils';
import NotificationsSkeleton from '../components/skeletons/NotificationsSkeleton';
import { useTranslations } from '../hooks/useTranslations';
import { useProCapability } from '@/hooks/useProCapability';
import { TABLE_HEADER_CLASS } from '@/lib/table';
import { alertDocsUrl } from '@/config/docs';
// Bundled with the plugin: an admin page never loads images from a third-party host.
import slackIcon from '../assets/alerts/slack-icon.svg';
import telegramIcon from '../assets/alerts/telegram.svg';
import discordIcon from '../assets/alerts/discord-icon.svg';

const PROVIDER_ICONS = {
    slack: slackIcon,
    telegram: telegramIcon,
    discord: discordIcon,
    webhook: '', // Fallback to Bell icon
};

const PROVIDER_SETUP_HINTS = {
    telegram: [
        'Create a bot via @BotFather in Telegram and copy its bot token.',
        'Send your new bot any message.',
        'Paste the bot token below, then click Detect to fill in the Chat ID.',
    ],
    slack: [
        'In Slack, create an Incoming Webhook for your workspace.',
        'Copy the generated Webhook URL and paste it below.',
    ],
    discord: [
        'In Discord, open Server Settings > Integrations > Webhooks.',
        'Create a webhook, then copy and paste its URL below.',
    ],
};

const ChannelEditor = ({ form, setForm, available, onSave, onCancel, onSendTest, testingForm = false, isEditing = false, saving = false }) => {
    const { t } = useTranslations();
    const selectedProvider = available[form.type];
    const providerName = selectedProvider?.name || form.type;

    const [detecting, setDetecting] = useState(false);
    const [detectedChats, setDetectedChats] = useState(null);

    async function handleDetectChats() {
        const botToken = form.settings.bot_token;
        if (!botToken) return;
        setDetecting(true);
        setDetectedChats(null);
        try {
            const res = await api.post('notifications/telegram/detect-chats', { bot_token: botToken });
            setDetectedChats(res.data?.chats || []);
        } catch (err) {
            toast.error(err.message);
            setDetectedChats([]);
        } finally {
            setDetecting(false);
        }
    }

    return (
        <div className="mx-auto max-w-5xl space-y-8 pb-20">
            <div className="flex flex-col gap-4 rounded-xl border bg-card p-6 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-center gap-3">
                    <span className="flex size-11 shrink-0 items-center justify-center rounded-lg border bg-muted">
                        {PROVIDER_ICONS[form.type] ? (
                            <img src={PROVIDER_ICONS[form.type]} className="size-5 object-contain" alt="" aria-hidden="true" />
                        ) : (
                            <Bell className="size-5 text-muted-foreground" aria-hidden="true" />
                        )}
                    </span>
                    <div className="space-y-1">
                        <h2 className="text-xl font-semibold tracking-tight">
                            {isEditing
                                ? t('notifications.edit_connection_provider', 'Edit {{name}} Channel', { name: providerName })
                                : t('notifications.register_channel_provider', 'Connect {{name}}', { name: providerName })}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {t('notifications.editor_subtitle_provider', 'Configure your {{name}} connection to receive real-time delivery alerts.', { name: providerName })}
                        </p>
                    </div>
                </div>
                <div className="flex items-center gap-3">
                    <Button variant="outline" onClick={() => onSendTest(form.type, form.settings)} disabled={testingForm || !form.type}>
                        {testingForm ? <Spinner /> : <Send />}
                        {t('notifications.send_test_short', 'Send Test')}
                    </Button>
                    <Button variant="ghost" onClick={onCancel}>{t('common.cancel', 'Cancel')}</Button>
                    <Button onClick={onSave} disabled={saving}>
                        {saving ? <Spinner /> : <CheckCircle2 />}
                        {isEditing ? t('notifications.update_channel', 'Update Channel') : t('notifications.activate_channel', 'Activate Channel')}
                    </Button>
                </div>
            </div>

            <div className="grid grid-cols-1 gap-8 md:grid-cols-3">
                <div className="space-y-8 md:col-span-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('notifications.general_information', 'General Information')}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <FieldGroup>
                                <Field>
                                    <FieldLabel htmlFor="channel-name">{t('notifications.friendly_label', 'Friendly Label')}</FieldLabel>
                                    <Input
                                        id="channel-name"
                                        placeholder={t('notifications.friendly_label_placeholder', 'e.g. Critical Alerts (Production)')}
                                        value={form.name}
                                        onChange={e => setForm(p => ({ ...p, name: e.target.value }))}
                                    />
                                    <FieldDescription>{t('notifications.friendly_label_help', 'Internal name used to identify this connection in logs.')}</FieldDescription>
                                </Field>
                            </FieldGroup>
                        </CardContent>
                    </Card>

                    {form.type && selectedProvider?.schema && (
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('notifications.connection_payload', 'Connection Payload')}</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <FieldGroup>
                                    {Object.entries(selectedProvider.schema).map(([key, field]) => {
                                        const isTelegramChatId = form.type === 'telegram' && key === 'chat_id';

                                        if (field.type === 'checkbox') {
                                            return (
                                                <Field key={key} orientation="horizontal">
                                                    <Checkbox
                                                        id={`channel-setting-${key}`}
                                                        checked={!!form.settings[key]}
                                                        onCheckedChange={v => setForm(p => ({ ...p, settings: { ...p.settings, [key]: v } }))}
                                                    />
                                                    <FieldContent>
                                                        <FieldLabel htmlFor={`channel-setting-${key}`}>{field.label}</FieldLabel>
                                                        {field.help && <FieldDescription>{field.help}</FieldDescription>}
                                                    </FieldContent>
                                                </Field>
                                            );
                                        }

                                        return (
                                            <Field key={key}>
                                                <FieldLabel htmlFor={`channel-setting-${key}`}>{field.label}</FieldLabel>
                                                <div className="flex gap-2">
                                                    <Input
                                                        id={`channel-setting-${key}`}
                                                        type={field.type === 'password' ? 'password' : 'text'}
                                                        autoComplete={field.type === 'password' ? 'new-password' : 'off'}
                                                        value={form.settings[key] || ''}
                                                        onChange={e => {
                                                            const value = e.target.value;
                                                            setForm(p => ({ ...p, settings: { ...p.settings, [key]: value } }));
                                                            // A previously-detected chat list belongs to whichever bot token was
                                                            // active when Detect ran -- editing the token invalidates it.
                                                            if (form.type === 'telegram' && key === 'bot_token') {
                                                                setDetectedChats(null);
                                                            }
                                                        }}
                                                        placeholder={field.placeholder || t('notifications.enter_field_placeholder', 'Enter {{field}}...', { field: field.label.toLowerCase() })}
                                                    />
                                                    {isTelegramChatId && (
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            onClick={handleDetectChats}
                                                            disabled={detecting || !form.settings.bot_token}
                                                        >
                                                            {detecting ? <Spinner /> : <Search />}
                                                            {t('notifications.detect_chat_id', 'Detect')}
                                                        </Button>
                                                    )}
                                                </div>
                                                {isTelegramChatId && detectedChats !== null && (
                                                    detectedChats.length > 0 ? (
                                                        <div className="mt-2 space-y-1 rounded-md border p-2">
                                                            <p className="px-2 pb-1 text-xs text-muted-foreground">
                                                                {t('notifications.detect_chat_id_pick', 'Pick the chat to use:')}
                                                            </p>
                                                            {detectedChats.map(chat => (
                                                                <Button
                                                                    key={chat.id}
                                                                    type="button"
                                                                    variant="ghost"
                                                                    onClick={() => setForm(p => ({ ...p, settings: { ...p.settings, chat_id: String(chat.id) } }))}
                                                                    className={cn(
                                                                        'h-auto w-full justify-between px-2 py-1.5 text-left font-normal',
                                                                        String(chat.id) === String(form.settings.chat_id) && 'bg-accent'
                                                                    )}
                                                                >
                                                                    <span className="truncate">{chat.label}</span>
                                                                    <span className="shrink-0 text-xs text-muted-foreground">{chat.type}</span>
                                                                </Button>
                                                            ))}
                                                        </div>
                                                    ) : (
                                                        <FieldDescription>
                                                            {t('notifications.detect_chat_id_empty', 'No chats found yet. Message your bot once, then click Detect again.')}
                                                        </FieldDescription>
                                                    )
                                                )}
                                            </Field>
                                        );
                                    })}
                                </FieldGroup>
                            </CardContent>
                        </Card>
                    )}
                </div>

                <div className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('common.status', 'Status')}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Field orientation="horizontal">
                                <Checkbox
                                    id="channel-is-active"
                                    checked={form.is_active}
                                    onCheckedChange={v => setForm(p => ({ ...p, is_active: v }))}
                                />
                                <FieldContent>
                                    <FieldLabel htmlFor="channel-is-active">{t('notifications.live_channel', 'Live Channel')}</FieldLabel>
                                    <FieldDescription>
                                        {t('notifications.live_channel_help', 'Only active channels will receive real-time alerts. Ensure your API keys are correct before enabling.')}
                                    </FieldDescription>
                                </FieldContent>
                            </Field>
                        </CardContent>
                    </Card>

                    {form.type && (
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('notifications.quick_setup', 'Quick Setup')}</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {(PROVIDER_SETUP_HINTS[form.type] || []).length > 0 && (
                                    <ol className="list-decimal space-y-2 pl-4 text-sm text-muted-foreground">
                                        {PROVIDER_SETUP_HINTS[form.type].map((hint, i) => (
                                            <li key={i}>{hint}</li>
                                        ))}
                                    </ol>
                                )}
                                <a
                                    href={alertDocsUrl(form.type)}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="inline-flex items-center gap-1 text-sm font-medium text-primary underline-offset-4 hover:underline"
                                >
                                    <BookOpen className="size-3.5" aria-hidden="true" />
                                    {t('notifications.view_documentation', 'View setup documentation')}
                                </a>
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>
        </div>
    );
};

export default function Notifications() {
    const { t } = useTranslations();
    const { hasFeature: hasAdvancedNotifications } = useProCapability('notifications.advanced');
    const [channels, setChannels] = useState([]);
    const [available, setAvailable] = useState({});
    const [loading, setLoading] = useState(true);
    const [editingId, setEditingId] = useState(null);
    const [showNewForm, setShowNewForm] = useState(false);
    const [selectedIds, setSelectedIds] = useState([]);
    const [isBulkActing, setIsBulkActing] = useState(false);
    const [saving, setSaving] = useState(false);
    const [testingId, setTestingId] = useState(null);
    const [testingForm, setTestingForm] = useState(false);
    const [pendingDisableChannel, setPendingDisableChannel] = useState(null);

    const [form, setForm] = useState({
        type: '',
        name: '',
        settings: {},
        is_active: true
    });

    useEffect(() => { loadData({ initial: true }); }, []);

    /**
     * Load channels and the available channel catalogue.
     *
     * Only the very first load (`initial: true`) may swap the page for the skeleton. Re-loads after a
     * save, delete, toggle or bulk action keep the rendered list in place, so the screen does not
     * "splash" back to the skeleton on every interaction.
     *
     * @since 1.0.0
     * @param {{ initial?: boolean }} [options]
     */
    async function loadData({ initial = false } = {}) {
        if (initial) setLoading(true);
        try {
            const res = await api.get('notifications');
            setChannels(res.data?.channels || []);
            setAvailable(res.data?.available || {});
        } catch (err) {
            console.error(err);
            toast.error(t('notifications.failed_load_settings', 'Failed to load notification settings'));
        } finally {
            if (initial) setLoading(false);
        }
    }

    async function handleSave() {
        if (!form.name || !form.type) {
            toast.error(t('notifications.validation_name_type', 'Please provide a name and channel type.'));
            return;
        }
        setSaving(true);
        try {
            if (editingId) {
                await api.put(`notifications/${editingId}`, form);
                toast.success(t('notifications.channel_updated', 'Notification channel updated.'));
            } else {
                await api.post('notifications', form);
                toast.success(t('notifications.channel_added', 'Notification channel added.'));
            }
            setShowNewForm(false);
            setEditingId(null);
            loadData();
        } catch (err) {
            toast.error(err.response?.data?.message || err.message);
        } finally {
            setSaving(false);
        }
    }

    async function handleDelete(id) {
        try {
            await api.delete(`notifications/${id}`);
            toast.success(t('notifications.channel_deleted', 'Channel deleted.'));
            loadData();
            setSelectedIds(prev => prev.filter(i => i !== id));
        } catch (err) {
            toast.error(t('notifications.delete_failed', 'Delete failed: {{message}}', { message: err.message }));
        }
    }

    async function sendTest(type, settings) {
        try {
            const res = await api.post('notifications/test', { type, settings });
            const success = !!res.data?.success;
            // testChannel() returns a single 'error' string for a delivery failure, or an
            // 'errors' object (field -> message) when validateSettings() rejects the form
            // before ever attempting delivery -- both are real, more specific reasons than
            // the generic top-level message and should win when present.
            const validationErrors = res.data?.errors && typeof res.data.errors === 'object'
                ? Object.values(res.data.errors).join(' ')
                : null;
            const message = res.data?.error || validationErrors || res.message || (success
                ? t('notifications.test_sent', 'Test message sent.')
                : t('notifications.test_failed', 'Test failed.'));
            if (success) {
                toast.success(message);
            } else {
                toast.error(message);
            }
        } catch (err) {
            toast.error(err.message);
        }
    }

    async function handleSendTestForChannel(ch) {
        setTestingId(ch.id);
        await sendTest(ch.type, ch.settings);
        setTestingId(null);
    }

    async function handleSendTestForForm(type, settings) {
        setTestingForm(true);
        await sendTest(type, settings);
        setTestingForm(false);
    }

    async function handleToggle(id, isActive) {
        try {
            await api.put(`notifications/${id}`, { is_active: !isActive });
            toast.success(t('notifications.channel_toggle', 'Channel {{state}}.', { state: isActive ? t('notifications.disabled', 'disabled') : t('notifications.enabled', 'enabled') }));
            loadData();
        } catch (err) {
            toast.error(t('notifications.update_failed', 'Update failed: {{message}}', { message: err.message }));
        }
    }

    function confirmDisable() {
        if (pendingDisableChannel) {
            handleToggle(pendingDisableChannel.id, pendingDisableChannel.is_active);
        }
        setPendingDisableChannel(null);
    }

    async function handleBulkAction(action) {
        if (selectedIds.length === 0) return;
        setIsBulkActing(true);
        try {
            await api.post('notifications/bulk', { ids: selectedIds, action });
            toast.success(t('notifications.bulk_action_completed', 'Bulk {{action}} completed.', { action }));
            setSelectedIds([]);
            loadData();
        } catch (err) {
            toast.error(t('notifications.bulk_action_failed', 'Bulk action failed: {{message}}', { message: err.message }));
        } finally {
            setIsBulkActing(false);
        }
    }

    function startEdit(ch) {
        setForm({
            type: ch.type,
            name: ch.name,
            settings: ch.settings || {},
            is_active: ch.is_active,
        });
        setEditingId(ch.id);
        setShowNewForm(false);
    }

    const toggleSelectAll = () => {
        if (selectedIds.length === channels.length) {
            setSelectedIds([]);
        } else {
            setSelectedIds(channels.map(c => c.id));
        }
    };

    const toggleSelectOne = (id) => {
        setSelectedIds(prev =>
            prev.includes(id) ? prev.filter(i => i !== id) : [...prev, id]
        );
    };

    if (loading) {
        return <NotificationsSkeleton />;
    }

    if (showNewForm || editingId) {
        return (
            <ChannelEditor
                isEditing={!!editingId}
                form={form}
                setForm={setForm}
                available={available}
                onSave={handleSave}
                saving={saving}
                onSendTest={handleSendTestForForm}
                testingForm={testingForm}
                onCancel={() => { setShowNewForm(false); setEditingId(null); }}
            />
        );
    }

    const allSelected = channels.length > 0 && selectedIds.length === channels.length;
    const channelCountByType = channels.reduce((counts, ch) => ({ ...counts, [ch.type]: (counts[ch.type] || 0) + 1 }), {});
    const allProviderEntries = Object.entries(available);
    // Free-plan provider cards disappear after that provider has one channel,
    // rather than being shown disabled with a lock/Pro badge
    // (avoids WP.org-style paywall-teaser patterns on what looks like a real, clickable control).
    const providersToShow = hasAdvancedNotifications
        ? allProviderEntries
        : allProviderEntries.filter(([type]) => (channelCountByType[type] || 0) < 1);

    return (
        <div className="mx-auto max-w-7xl space-y-8">
            <div className="space-y-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {t('notifications.page_title', 'Notification Alerts')}
                </h1>
                <p className="text-sm text-muted-foreground">
                    {t('notifications.page_subtitle', 'Securely pipeline critical delivery events to your preferred operations stack.')}
                </p>
            </div>

            {/* Providers Selection */}
            <section className="space-y-4">
                <h2 className="text-base font-semibold">
                    {t('notifications.available_providers', 'Available Providers')}
                </h2>

                {providersToShow.length > 0 && (
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                        {providersToShow.map(([type, info]) => (
                            <Button
                                key={type}
                                variant="outline"
                                onClick={() => {
                                    setForm({ type, name: '', settings: {}, is_active: true });
                                    setShowNewForm(true);
                                    setEditingId(null);
                                }}
                                className="group h-auto flex-col items-start justify-start gap-4 rounded-xl border bg-card p-6 text-left whitespace-normal shadow-none transition-colors hover:border-primary/40 hover:bg-card dark:border-border dark:bg-card dark:hover:bg-card"
                            >
                                <span className="flex size-12 items-center justify-center rounded-lg border bg-muted">
                                    {PROVIDER_ICONS[type] ? (
                                        <img src={PROVIDER_ICONS[type]} className="size-6 object-contain" alt="" aria-hidden="true" />
                                    ) : (
                                        <Bell className="size-6 text-muted-foreground" aria-hidden="true" />
                                    )}
                                </span>

                                <span className="block space-y-1">
                                    <span className="block text-sm font-medium">{info.name}</span>
                                    <span className="line-clamp-2 block text-xs font-normal text-muted-foreground">
                                        {t('notifications.provider_connect_description', 'Connect your {{name}} workspace for real-time delivery alerts.', { name: info.name })}
                                    </span>
                                </span>

                                <span className="mt-auto flex w-full items-center justify-between text-xs font-medium text-primary">
                                    {t('notifications.setup_connect', 'Setup Connect')}
                                    <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                                </span>
                            </Button>
                        ))}
                    </div>
                )}

            </section>

            {/* Connected Channels */}
            {channels.length > 0 && (
                <section className="space-y-4">
                    <h2 className="text-base font-semibold">
                        {t('notifications.connected_channels', 'Connected Channels')}
                    </h2>
                    <div className="overflow-hidden rounded-lg border bg-card">
                        {selectedIds.length > 0 && (
                            <div className="flex flex-wrap items-center justify-between gap-3 border-b bg-primary/5 p-3">
                                <span className="text-sm font-medium text-primary">
                                    {t('notifications.channels_selected', '{{count}} Channel{{suffix}} Selected', { count: selectedIds.length, suffix: selectedIds.length > 1 ? 's' : '' })}
                                </span>
                                <div className="flex flex-wrap items-center gap-2">
                                    <Button variant="outline" size="sm" onClick={() => handleBulkAction('enable')} disabled={isBulkActing}>
                                        <Play /> {t('notifications.enable', 'Enable')}
                                    </Button>
                                    <Button variant="outline" size="sm" onClick={() => handleBulkAction('disable')} disabled={isBulkActing}>
                                        <Square /> {t('notifications.disable', 'Disable')}
                                    </Button>
                                    <AlertDialog>
                                        <AlertDialogTrigger asChild>
                                            <Button variant="outline" size="sm" className="border-destructive/30 text-destructive hover:bg-destructive/10 hover:text-destructive" disabled={isBulkActing}>
                                                <Trash2 /> {t('common.delete', 'Delete')}
                                            </Button>
                                        </AlertDialogTrigger>
                                        <AlertDialogContent>
                                            <AlertDialogHeader>
                                                <AlertDialogTitle>{t('notifications.bulk_remove_title', 'Bulk Remove {{count}} Channels?', { count: selectedIds.length })}</AlertDialogTitle>
                                                <AlertDialogDescription>{t('notifications.bulk_remove_description', 'Are you sure you want to permanently delete the selected alert channels? This cannot be undone.')}</AlertDialogDescription>
                                            </AlertDialogHeader>
                                            <AlertDialogFooter>
                                                <AlertDialogCancel>{t('common.cancel', 'Cancel')}</AlertDialogCancel>
                                                <AlertDialogAction variant="destructive" onClick={() => handleBulkAction('delete')}>{t('notifications.delete_forever', 'Delete Forever')}</AlertDialogAction>
                                            </AlertDialogFooter>
                                        </AlertDialogContent>
                                    </AlertDialog>
                                </div>
                            </div>
                        )}
                        <Table aria-label={t('notifications.table_label', 'Notification channels')}>
                            <TableHeader className={TABLE_HEADER_CLASS}>
                                <TableRow>
                                    <TableHead className="w-10 px-4">
                                        <Checkbox
                                            checked={allSelected}
                                            onCheckedChange={toggleSelectAll}
                                            aria-label={t('notifications.select_all', 'Select all channels')}
                                        />
                                    </TableHead>
                                    <TableHead className="px-4 text-muted-foreground">{t('notifications.channel', 'Channel')}</TableHead>
                                    <TableHead className="w-52 px-4 text-right text-muted-foreground">{t('common.actions', 'Actions')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {channels.map(ch => {
                                    const isSelected = selectedIds.includes(ch.id);
                                    return (
                                        <TableRow key={ch.id} data-state={isSelected ? 'selected' : undefined}>
                                            <TableCell className="px-4 py-1.5">
                                                <Checkbox
                                                    checked={isSelected}
                                                    onCheckedChange={() => toggleSelectOne(ch.id)}
                                                    aria-label={t('notifications.select_channel', 'Select {{name}}', { name: ch.name })}
                                                />
                                            </TableCell>
                                            <TableCell className="px-4 py-1.5">
                                                <div className="flex items-center gap-3">
                                                    <span className="flex size-8 shrink-0 items-center justify-center rounded-md border bg-muted">
                                                        {PROVIDER_ICONS[ch.type] ? (
                                                            <img src={PROVIDER_ICONS[ch.type]} className="size-4 object-contain" alt="" aria-hidden="true" />
                                                        ) : (
                                                            <Bell className="size-4 text-muted-foreground" aria-hidden="true" />
                                                        )}
                                                    </span>
                                                    <div className="min-w-0">
                                                        <p className="truncate font-medium text-foreground">{ch.name}</p>
                                                        <p className="text-xs text-muted-foreground">#{ch.id}</p>
                                                    </div>
                                                </div>
                                            </TableCell>
                                            <TableCell className="px-4 py-1.5">
                                                <div className="flex items-center justify-end gap-2">
                                                    <Switch
                                                        checked={ch.is_active}
                                                        onCheckedChange={(checked) => {
                                                            if (ch.is_active && !checked) {
                                                                setPendingDisableChannel(ch);
                                                            } else {
                                                                handleToggle(ch.id, ch.is_active);
                                                            }
                                                        }}
                                                        aria-label={t('notifications.toggle_channel', 'Enable or disable {{name}}', { name: ch.name })}
                                                        title={ch.is_active ? t('notifications.connected', 'Connected') : t('notifications.not_connected', 'Not connected')}
                                                    />
                                                    <Button
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        onClick={() => handleSendTestForChannel(ch)}
                                                        disabled={testingId === ch.id}
                                                        aria-label={t('notifications.send_test_channel', 'Send test message to {{name}}', { name: ch.name })}
                                                        title={t('notifications.send_test_short', 'Send Test')}
                                                    >
                                                        {testingId === ch.id ? <Spinner /> : <Send />}
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        onClick={() => startEdit(ch)}
                                                        aria-label={t('notifications.edit_channel', 'Edit {{name}}', { name: ch.name })}
                                                        title={t('notifications.edit_connection', 'Edit Connection')}
                                                    >
                                                        <Edit2 />
                                                    </Button>
                                                    <AlertDialog>
                                                        <AlertDialogTrigger asChild>
                                                            <Button
                                                                variant="ghost"
                                                                size="icon-sm"
                                                                aria-label={t('notifications.delete_channel', 'Delete {{name}}', { name: ch.name })}
                                                                title={t('common.delete', 'Delete')}
                                                            >
                                                                <Trash2 />
                                                            </Button>
                                                        </AlertDialogTrigger>
                                                        <AlertDialogContent>
                                                            <AlertDialogHeader>
                                                                <AlertDialogTitle>{t('notifications.delete_channel_title', 'Delete Channel?')}</AlertDialogTitle>
                                                                <AlertDialogDescription>{t('notifications.delete_channel_description', 'Permanently remove the alert channel "{{name}}"? This cannot be undone.', { name: ch.name })}</AlertDialogDescription>
                                                            </AlertDialogHeader>
                                                            <AlertDialogFooter>
                                                                <AlertDialogCancel>{t('common.cancel', 'Cancel')}</AlertDialogCancel>
                                                                <AlertDialogAction variant="destructive" onClick={() => handleDelete(ch.id)}>{t('notifications.yes_delete', 'Yes, Delete')}</AlertDialogAction>
                                                            </AlertDialogFooter>
                                                        </AlertDialogContent>
                                                    </AlertDialog>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </div>

                    <AlertDialog open={!!pendingDisableChannel} onOpenChange={open => { if (!open) setPendingDisableChannel(null); }}>
                        <AlertDialogContent>
                            <AlertDialogHeader>
                                <AlertDialogTitle>{t('notifications.disconnect_channel_title', 'Disconnect Channel?')}</AlertDialogTitle>
                                <AlertDialogDescription>
                                    {t('notifications.disconnect_channel_confirm', 'Stop sending alerts through "{{name}}"? You can turn it back on anytime.', { name: pendingDisableChannel?.name || '' })}
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            <AlertDialogFooter>
                                <AlertDialogCancel>{t('common.cancel', 'Cancel')}</AlertDialogCancel>
                                <AlertDialogAction onClick={confirmDisable}>{t('notifications.yes_disconnect', 'Yes, Disconnect')}</AlertDialogAction>
                            </AlertDialogFooter>
                        </AlertDialogContent>
                    </AlertDialog>
                </section>
            )}

            {channels.length === 0 && (
                <Empty className="border bg-card">
                    <EmptyHeader>
                        <EmptyMedia variant="icon"><Bell className="text-primary" aria-hidden="true" /></EmptyMedia>
                        <EmptyTitle>{t('notifications.empty_title', 'Build Your Sentry Stack')}</EmptyTitle>
                        <EmptyDescription>{t('notifications.empty_description', 'Configure deep-linking alerts for Slack, Discord, or Telegram. Never miss a critical delivery failure again.')}</EmptyDescription>
                    </EmptyHeader>
                </Empty>
            )}
        </div>
    );
}
