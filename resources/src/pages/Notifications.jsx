import { useState, useEffect } from 'react';
import api, { fieldErrors } from '../services/api';
import { Edit2, Trash2, Send, Search, BookOpen, Bell, CheckCircle2, ArrowRight } from 'lucide-react';
import { Button } from '../components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '../components/ui/input';
import { Checkbox } from '../components/ui/checkbox';
import { Switch } from '../components/ui/switch';
import { Field, FieldContent, FieldDescription, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import {
    AlertDialog, AlertDialogAction, AlertDialogCancel,
    AlertDialogContent, AlertDialogDescription, AlertDialogFooter,
    AlertDialogHeader, AlertDialogTitle
} from '../components/ui/alert-dialog';
import { toast } from 'sonner';
import { cn } from '@/lib/utils';
import NotificationsSkeleton from '../components/skeletons/NotificationsSkeleton';
import { useTranslations } from '../hooks/useTranslations';
import { alertDocsUrl } from '@/config/docs';
import { isMaskedSecret } from '@/components/onboarding/providerCatalog';
// Bundled with the plugin: an admin page never loads images from a third-party host.
import slackIcon from '../assets/alerts/slack-icon.svg';
import telegramIcon from '../assets/alerts/telegram.svg';
import discordIcon from '../assets/alerts/discord-icon.svg';

const PROVIDER_ICONS = {
    slack: slackIcon,
    telegram: telegramIcon,
    discord: discordIcon,
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

/**
 * Whether a settings field holds a secret: the server sends a saved one back with only its last
 * four characters showing, and keeps the saved value when it comes back unchanged.
 *
 * @since 1.0.0
 * @param {{ type?: string, secret?: boolean }} field Field definition from the provider's schema.
 * @returns {boolean}
 */
function isSecretField(field) {
    return field.secret === true || field.type === 'password';
}

const ChannelEditor = ({ form, setForm, available, onSave, onCancel, onSendTest, testingForm = false, isEditing = false, saving = false, errors = {} }) => {
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
                                ? t('notifications.edit_provider', 'Edit {{name}} alerts', { name: providerName })
                                : t('notifications.set_up_provider', 'Set up {{name}} alerts', { name: providerName })}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {t('notifications.editor_subtitle', 'Alerts reach {{name}} when an email finally fails, a connection fails its health check, or a sign-in can no longer be refreshed.', { name: providerName })}
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
                        {t('common.save', 'Save')}
                    </Button>
                </div>
            </div>

            <div className="grid grid-cols-1 gap-8 md:grid-cols-3">
                <div className="space-y-8 md:col-span-2">
                    {form.type && selectedProvider?.schema && (
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('notifications.provider_settings', 'Settings')}</CardTitle>
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
                                                {errors[key] && <FieldDescription className="text-destructive">{errors[key]}</FieldDescription>}
                                                {!errors[key] && isSecretField(field) && isMaskedSecret(form.settings[key]) && (
                                                    <FieldDescription>
                                                        {t('notifications.secret_saved', 'Saved. Only the last 4 characters are shown; paste a new value to replace it.')}
                                                    </FieldDescription>
                                                )}
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
                                    <FieldLabel htmlFor="channel-is-active">{t('notifications.send_alerts', 'Send alerts')}</FieldLabel>
                                    <FieldDescription>
                                        {t('notifications.send_alerts_help', 'Turn off to keep these settings without sending alerts.')}
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

/**
 * The Alerts screen: one row per provider (Telegram, Slack, Discord), each set up once.
 *
 * @since 1.0.0
 */
export default function Notifications() {
    const { t } = useTranslations();
    const [available, setAvailable] = useState({});
    const [channels, setChannels] = useState({});
    const [loading, setLoading] = useState(true);
    const [editingType, setEditingType] = useState(null);
    const [form, setForm] = useState({ type: '', settings: {}, is_active: true });
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    const [testingType, setTestingType] = useState(null);
    const [testingForm, setTestingForm] = useState(false);
    const [pendingDisconnect, setPendingDisconnect] = useState(null);

    useEffect(() => { loadData({ initial: true }); }, []);

    /**
     * Load the providers and their setups.
     *
     * Only the very first load (`initial: true`) may swap the page for the skeleton; later reloads
     * keep the rendered rows in place.
     *
     * @since 1.0.0
     * @param {{ initial?: boolean }} [options]
     */
    async function loadData({ initial = false } = {}) {
        if (initial) setLoading(true);
        try {
            const res = await api.get('notifications');
            setAvailable(res.data?.available || {});
            setChannels(res.data?.channels || {});
        } catch (err) {
            console.error(err);
            toast.error(t('notifications.failed_load_settings', 'Failed to load notification settings'));
        } finally {
            if (initial) setLoading(false);
        }
    }

    function startSetup(type) {
        const saved = channels[type];
        setForm({ type, settings: { ...(saved?.settings || {}) }, is_active: saved ? !!saved.is_active : true });
        setErrors({});
        setEditingType(type);
    }

    async function handleSave() {
        setSaving(true);
        setErrors({});
        try {
            await api.put(`notifications/${form.type}`, { settings: form.settings, is_active: form.is_active });
            toast.success(t('notifications.saved', 'Alert settings saved.'));
            setEditingType(null);
            loadData();
        } catch (err) {
            setErrors(fieldErrors(err.errors));
            toast.error(err.message);
        } finally {
            setSaving(false);
        }
    }

    async function sendTest(type, settings) {
        try {
            const res = await api.post(`notifications/${type}/test`, settings ? { settings } : {});
            const success = !!res.data?.success;
            // testChannel() returns a single 'error' string for a delivery failure, or an
            // 'errors' object (field -> message) when the provider rejects the settings
            // before delivery is attempted -- both are more specific than the top-level message.
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

    async function handleSendTestForRow(type) {
        setTestingType(type);
        await sendTest(type);
        setTestingType(null);
    }

    async function handleSendTestForForm(type, settings) {
        setTestingForm(true);
        await sendTest(type, settings);
        setTestingForm(false);
    }

    async function handleToggle(type, isActive) {
        try {
            await api.put(`notifications/${type}`, { is_active: !isActive });
            toast.success(isActive
                ? t('notifications.alerts_paused', 'Alerts paused.')
                : t('notifications.alerts_resumed', 'Alerts turned on.'));
            loadData();
        } catch (err) {
            toast.error(t('notifications.update_failed', 'Update failed: {{message}}', { message: err.message }));
        }
    }

    async function handleDisconnect(type) {
        try {
            await api.delete(`notifications/${type}`);
            toast.success(t('notifications.disconnected', 'Disconnected.'));
            loadData();
        } catch (err) {
            toast.error(t('notifications.delete_failed', 'Delete failed: {{message}}', { message: err.message }));
        }
    }

    if (loading) {
        return <NotificationsSkeleton />;
    }

    if (editingType) {
        return (
            <ChannelEditor
                isEditing={!!channels[editingType]}
                form={form}
                setForm={setForm}
                available={available}
                onSave={handleSave}
                saving={saving}
                errors={errors}
                onSendTest={handleSendTestForForm}
                testingForm={testingForm}
                onCancel={() => setEditingType(null)}
            />
        );
    }

    return (
        <div className="mx-auto max-w-4xl space-y-8">
            <div className="space-y-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {t('notifications.page_title', 'Alerts')}
                </h1>
                <p className="text-sm text-muted-foreground">
                    {t('notifications.page_subtitle', 'Get a message in Telegram, Slack or Discord when an email finally fails, a connection fails its health check, or a sign-in can no longer be refreshed. Set up any or all of them.')}
                </p>
            </div>

            <ul className="divide-y overflow-hidden rounded-lg border bg-card" aria-label={t('notifications.providers_label', 'Alert providers')}>
                {Object.entries(available).map(([type, info]) => {
                    const setup = channels[type];
                    return (
                        <li key={type} className="flex flex-wrap items-center gap-4 p-4">
                            <span className="flex size-10 shrink-0 items-center justify-center rounded-lg border bg-muted">
                                {PROVIDER_ICONS[type] ? (
                                    <img src={PROVIDER_ICONS[type]} className="size-5 object-contain" alt="" aria-hidden="true" />
                                ) : (
                                    <Bell className="size-5 text-muted-foreground" aria-hidden="true" />
                                )}
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className="font-medium text-foreground">{info.name}</p>
                                <p className="text-xs text-muted-foreground">
                                    {!setup
                                        ? t('notifications.not_connected', 'Not connected')
                                        : setup.is_active
                                            ? t('notifications.connected', 'Connected')
                                            : t('notifications.paused', 'Connected, alerts paused')}
                                </p>
                            </div>
                            {setup ? (
                                <div className="flex items-center gap-2">
                                    <Switch
                                        checked={!!setup.is_active}
                                        onCheckedChange={() => handleToggle(type, !!setup.is_active)}
                                        aria-label={t('notifications.toggle_provider', 'Send {{name}} alerts', { name: info.name })}
                                    />
                                    <Button
                                        variant="ghost"
                                        size="icon-sm"
                                        onClick={() => handleSendTestForRow(type)}
                                        disabled={testingType === type}
                                        aria-label={t('notifications.send_test_provider', 'Send a test alert to {{name}}', { name: info.name })}
                                        title={t('notifications.send_test_short', 'Send Test')}
                                    >
                                        {testingType === type ? <Spinner /> : <Send />}
                                    </Button>
                                    <Button variant="outline" size="sm" onClick={() => startSetup(type)}>
                                        <Edit2 /> {t('common.edit', 'Edit')}
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon-sm"
                                        onClick={() => setPendingDisconnect({ type, name: info.name })}
                                        aria-label={t('notifications.disconnect_provider', 'Disconnect {{name}}', { name: info.name })}
                                        title={t('notifications.disconnect', 'Disconnect')}
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>
                            ) : (
                                <Button onClick={() => startSetup(type)}>
                                    {t('notifications.set_up', 'Set up')} <ArrowRight />
                                </Button>
                            )}
                        </li>
                    );
                })}
            </ul>

            <AlertDialog open={!!pendingDisconnect} onOpenChange={open => { if (!open) setPendingDisconnect(null); }}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{t('notifications.disconnect_title', 'Disconnect {{name}}?', { name: pendingDisconnect?.name || '' })}</AlertDialogTitle>
                        <AlertDialogDescription>
                            {t('notifications.disconnect_description', 'Its settings are removed and alerts stop. You can set it up again at any time.')}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('common.cancel', 'Cancel')}</AlertDialogCancel>
                        <AlertDialogAction
                            variant="destructive"
                            onClick={() => { handleDisconnect(pendingDisconnect.type); setPendingDisconnect(null); }}
                        >
                            {t('notifications.disconnect', 'Disconnect')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
