import { useState, useEffect } from 'react';
import { toast } from 'sonner';
import { Mail, FileText, Send, Terminal, CheckCircle2, AlertCircle, Info, ChevronDown, Timer } from 'lucide-react';

import api from '../services/api';
import { useTranslations } from '../hooks/useTranslations';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';

// Log level → colour inside the inverted (bg-foreground) terminal panel.
const LEVEL_COLORS = {
    error: 'text-destructive',
    warn: 'text-warning',
    info: 'text-info',
    debug: 'text-background/40',
};

const SUCCESS_TOKENS = ['true', 'OK', 'Queued', 'Successfully', '250 2.0.0'];
const MESSAGE_KEYS = ['To', 'Subject', 'Headers', 'Result', 'Host', 'Port', 'Connection', 'Mailer', 'From', 'Sender', 'Envelope sender', 'Sendmail path', 'Additional params'];
const MESSAGE_KEY_REGEX = new RegExp(`^(${MESSAGE_KEYS.join('|')}):(.*)`, 'is');

function colorizeGeneral(text) {
    if (typeof text !== 'string') return text;
    const parts = text.split(/(true|false|OK|Queued|Successfully|250 2\.0\.0|\[Mailer resolved\]|[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/g);
    return parts.map((part, i) => {
        if (!part) return null;
        if (SUCCESS_TOKENS.includes(part)) return <span key={i} className="font-semibold text-success">{part}</span>;
        if (part === 'false') return <span key={i} className="font-semibold text-destructive">{part}</span>;
        if (part === '[Mailer resolved]') return <span key={i} className="font-semibold text-chart-4">{part}</span>;
        if (part.includes('@') && part.includes('.')) return <span key={i} className="text-chart-1 underline underline-offset-2 opacity-90">{part}</span>;
        return <span key={i}>{part}</span>;
    });
}

function formatMessage(message, isLast) {
    if (!message) return message;
    if (typeof message !== 'string') return message;

    // Last message is always highlighted
    if (isLast) {
        return <span className="font-semibold text-chart-1">{message}</span>;
    }

    // Identify keys like To:, Subject:, Headers:, Result:, Host:, Port:, etc.
    const match = message.match(MESSAGE_KEY_REGEX);

    if (match) {
        const key = match[1] + ':';
        const value = match[2];
        return (
            <span>
                <span className="font-semibold text-warning">{key}</span>
                <span>{colorizeGeneral(value)}</span>
            </span>
        );
    }

    // SMTP Handshake markers
    if (message.startsWith('>')) return <span className="text-chart-1">{colorizeGeneral(message)}</span>;
    if (message.startsWith('<')) return <span className="text-background/60">{colorizeGeneral(message)}</span>;

    return <span>{colorizeGeneral(message)}</span>;
}

function isSuccessLine(message) {
    return (message.includes('OK') || message.includes('250 2.0.0') || message.includes('Successfully')) && !message.startsWith('>');
}

function getCurrentUserEmail() {
    if (typeof window === 'undefined') {
        return '';
    }
    return String(window.BooleanSmtpAdmin?.currentUserEmail || '').trim();
}

function connectionLabel(connection, t) {
    const name = String(connection?.name || '');
    const driver = String(connection?.driver || '');
    const fromEmail = String(connection?.settings?.from_email || '').trim();
    const primary = connection?.is_primary ? ` - ${t('test_email.primary', 'Primary')}` : '';

    return `${name} (${driver})${fromEmail ? ` <${fromEmail}>` : ''}${primary}`;
}

export default function TestEmail() {
    const { t } = useTranslations();
    const [to, setTo] = useState(() => getCurrentUserEmail());
    const [subject, setSubject] = useState(t('test_email.default_subject', 'Test Email - BooleanSMTP Check'));
    const [isHtml, setIsHtml] = useState(true);
    const [includeMultipart, setIncludeMultipart] = useState(true);
    const [connectionId, setConnectionId] = useState('');
    const [connections, setConnections] = useState([]);
    const [settings, setSettings] = useState(null);
    const [sending, setSending] = useState(false);
    const [result, setResult] = useState(null);
    const [showActivityConsole, setShowActivityConsole] = useState(true);
    const [showApiDebug, setShowApiDebug] = useState(true);
    // "Default" sends through the connection chosen under Settings → Default Connection.
    const defaultConnection = connections.find(c => String(c.id) === String(settings?.default_connection_id ?? '') && c.is_active !== false) || null;
    const defaultLabel = defaultConnection
        ? t('test_email.default_connection_named', 'Default connection — {{name}}', { name: defaultConnection.name })
        : t('test_email.default_connection', 'Default connection');

    async function loadConnections() {
        try {
            const res = await api.get('connections');
            const data = res.data ? res.data : res;
            setConnections(Array.isArray(data) ? data : []);
        } catch (e) {
            console.error(e);
        }
    }

    useEffect(() => {
        loadConnections();

        api.get('settings')
            .then(res => {
                const data = res.data ? res.data : res;
                setSettings(data);
                setIncludeMultipart(Boolean(data?.auto_plain_text ?? true));
            })
            .catch(console.error);
    }, []);

    async function handleSend() {
        if (!to.trim()) return;

        setSending(true);
        setResult(null);
        try {
            const payload = { to, subject, html: isHtml };
            if (isHtml) {
                payload.multipart = includeMultipart;
            }
            if (connectionId && connectionId !== 'default') {
                payload.connection_id = Number(connectionId);
            }
            const res = await api.post('test-email', payload);
            setResult(res.data ? res.data : res);
        } catch (err) {
            setResult({ sent: false, error: err.message });
        } finally {
            setSending(false);
            await loadConnections();
        }
    }

    function copyLogs() {
        const logs = result.debug_log.map(l => `[${l.timestamp}] [${l.level.toUpperCase()}] ${l.message}`).join('\n');
        const apiDebugText = result.api_debug ? `\n\n[API DEBUG]\n${JSON.stringify(result.api_debug, null, 2)}` : '';
        navigator.clipboard.writeText(logs + apiDebugText);
        toast.success(t('test_email.logs_copied', 'Logs copied to clipboard'));
    }

    function formatSeconds(seconds) {
        const value = Number(seconds);
        if (!Number.isFinite(value)) return t('test_email.unknown', 'unknown');
        if (value <= 0) return t('test_email.expired', 'expired');
        const mins = Math.floor(value / 60);
        if (mins < 60) return `${mins}m`;
        const hours = Math.floor(mins / 60);
        const remMins = mins % 60;
        return `${hours}h ${remMins}m`;
    }

    function formatUnix(ts) {
        const value = Number(ts);
        if (!Number.isFinite(value) || value <= 0) return t('test_email.unknown', 'unknown');
        return new Date(value * 1000).toLocaleString();
    }

    function mailerStatus() {
        const selected = connectionId && connectionId !== 'default' ? connections.find(c => String(c.id) === connectionId) : defaultConnection;
        const fromEmail = selected?.settings?.from_email || selected?.settings?.email || selected?.settings?.username || settings?.from_email || '—';

        if (sending) {
            return { dot: 'animate-pulse bg-warning', tone: 'text-muted-foreground', label: t('test_email.processing_request', 'Processing Request...'), fromEmail: null };
        }
        if (!selected) {
            return { dot: 'bg-success', tone: 'text-success', label: t('test_email.default_mailer_ready', 'Default connection: Ready'), fromEmail };
        }
        if (!selected.last_used_at) {
            return { dot: 'bg-muted-foreground', tone: 'text-muted-foreground', label: t('test_email.mailer_not_used_yet', 'Mailer ({{driver}}): Not used yet', { driver: selected.driver }), fromEmail };
        }
        if (selected.health_status === 'error') {
            return { dot: 'bg-destructive', tone: 'text-destructive', label: t('test_email.mailer_issue', 'Mailer ({{driver}}): Experiencing issue, test and fix', { driver: selected.driver }), fromEmail };
        }
        return { dot: 'bg-success', tone: 'text-success', label: t('test_email.mailer_operational', 'Mailer ({{driver}}): Operational', { driver: selected.driver }), fromEmail };
    }

    const status = mailerStatus();
    const selectedLastUsed = connectionId ? connections.find(c => String(c.id) === connectionId)?.last_used_at : null;

    return (
        <div className="mx-auto max-w-5xl space-y-6">
            <div>
                <h1 className="text-xl font-semibold tracking-tight">{t('test_email.title', 'Email Deliverability Test')}</h1>
                <p className="text-sm text-muted-foreground">
                    {t('test_email.subtitle', 'Verify your SMTP configuration by sending a test email and inspecting the handshake logs.')}
                </p>
            </div>

            <Card>
                <CardContent className="space-y-6">
                    <FieldGroup className="gap-6">
                        <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                            <Field>
                                <FieldLabel htmlFor="test-email-connection">{t('test_email.mailer_service', 'Select Mailer')}</FieldLabel>
                                <Select value={connectionId || 'default'} onValueChange={(val) => setConnectionId(val === 'default' ? '' : val)}>
                                    <SelectTrigger id="test-email-connection" className="w-full">
                                        <SelectValue placeholder={defaultLabel} />
                                    </SelectTrigger>
                                    <SelectContent position="popper">
                                        <SelectItem value="default">{defaultLabel}</SelectItem>
                                        {connections.map(c => (
                                            <SelectItem key={c.id} value={String(c.id)}>
                                                {connectionLabel(c, t)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>

                            <Field>
                                <FieldLabel htmlFor="test-email-to">{t('test_email.to_address', 'To Address')}</FieldLabel>
                                <InputGroup>
                                    <InputGroupAddon><Mail aria-hidden="true" /></InputGroupAddon>
                                    <InputGroupInput
                                        id="test-email-to"
                                        type="email"
                                        value={to}
                                        onChange={e => setTo(e.target.value)}
                                        placeholder={t('test_email.to_placeholder', 'you@example.com')}
                                    />
                                </InputGroup>
                            </Field>
                        </div>

                        <Field>
                            <FieldLabel htmlFor="test-email-subject">{t('test_email.subject', 'Subject')}</FieldLabel>
                            <InputGroup>
                                <InputGroupAddon><FileText aria-hidden="true" /></InputGroupAddon>
                                <InputGroupInput
                                    id="test-email-subject"
                                    type="text"
                                    value={subject}
                                    onChange={e => setSubject(e.target.value)}
                                />
                            </InputGroup>
                        </Field>
                    </FieldGroup>

                    <Separator />

                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <div className="flex flex-wrap items-center gap-6">
                            <Field orientation="horizontal" className="w-auto">
                                <Switch id="test-email-html" checked={isHtml} onCheckedChange={setIsHtml} />
                                <FieldLabel htmlFor="test-email-html">{t('test_email.send_as_html', 'Send as HTML')}</FieldLabel>
                            </Field>
                            {isHtml && (
                                <Field orientation="horizontal" className="w-auto">
                                    <Switch id="test-email-multipart" checked={includeMultipart} onCheckedChange={setIncludeMultipart} />
                                    <FieldLabel htmlFor="test-email-multipart">{t('test_email.include_multipart', 'Include Plain-Text Alternative (Multipart)')}</FieldLabel>
                                </Field>
                            )}
                        </div>
                        <Button type="button" onClick={handleSend} disabled={sending || !to.trim()}>
                            {sending ? <Spinner /> : <Send />}
                            {sending ? t('test_email.sending', 'Sending...') : t('test_email.send_test_email', 'Send Test Email')}
                        </Button>
                    </div>
                </CardContent>
                <CardFooter className="flex-wrap justify-between gap-2 border-t">
                    <div className={cn('flex items-center gap-2 text-xs font-semibold', status.tone)}>
                        <span className={cn('size-2 shrink-0 rounded-full', status.dot)} aria-hidden="true" />
                        <span>
                            {status.label}
                            {status.fromEmail && <span className="ml-1 font-medium lowercase">({status.fromEmail})</span>}
                        </span>
                    </div>

                    {selectedLastUsed && (
                        <span className="text-xs text-muted-foreground italic">
                            {t('test_email.last_active', 'Last active: {{time}}', { time: selectedLastUsed })}
                        </span>
                    )}
                </CardFooter>
            </Card>

            {result && (
                <div className="space-y-6 animate-in fade-in slide-in-from-bottom-2">
                    {result.sent ? (
                        <Empty className="rounded-lg border bg-card py-10">
                            <EmptyHeader>
                                <EmptyMedia variant="icon" className="size-14 rounded-full bg-success/15 text-success">
                                    <CheckCircle2 className="size-7" />
                                </EmptyMedia>
                                <EmptyTitle className="text-base">{t('test_email.result_sent_success', 'Test email sent successfully!')}</EmptyTitle>
                                {result.delivery_time_ms && (
                                    <EmptyDescription>
                                        <Badge variant="outline">
                                            <Timer aria-hidden="true" />
                                            {t('test_email.delivered_in', 'Delivered in {{ms}}ms', { ms: result.delivery_time_ms })}
                                        </Badge>
                                    </EmptyDescription>
                                )}
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <Alert variant="destructive">
                            <AlertCircle />
                            <AlertTitle className="text-base">{t('test_email.result_sent_failed', 'Test email failed to send')}</AlertTitle>
                            {(result.error || result.delivery_time_ms) && (
                                <AlertDescription>
                                    {result.error && <p>{result.error}</p>}
                                    {result.delivery_time_ms && (
                                        <Badge variant="outline">
                                            <Timer aria-hidden="true" />
                                            {t('test_email.delivery_time', 'Delivery time: {{ms}}ms', { ms: result.delivery_time_ms })}
                                        </Badge>
                                    )}
                                </AlertDescription>
                            )}
                        </Alert>
                    )}

                    {settings?.show_test_email_console && result.debug_log && result.debug_log.length > 0 && (
                        <Collapsible open={showActivityConsole} onOpenChange={setShowActivityConsole} className="space-y-4">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h2 className="text-base font-semibold">
                                    <CollapsibleTrigger asChild>
                                        <Button variant="ghost" size="sm" className="px-2 text-base font-semibold">
                                            <Terminal className="text-primary" aria-hidden="true" />
                                            {t('test_email.activity_console', 'Activity Console')}
                                            <ChevronDown className={cn('text-muted-foreground transition-transform', showActivityConsole && 'rotate-180')} aria-hidden="true" />
                                        </Button>
                                    </CollapsibleTrigger>
                                </h2>
                                <div className="flex items-center gap-2">
                                    <Button type="button" variant="ghost" size="sm" onClick={copyLogs}>
                                        <FileText aria-hidden="true" />
                                        {t('test_email.copy_logs', 'Copy Logs')}
                                    </Button>
                                    <Separator orientation="vertical" className="h-4" />
                                    <span className="text-xs font-semibold text-muted-foreground">
                                        {t('test_email.entries_count', '{{count}} Entries', { count: result.debug_log.length })}
                                    </span>
                                </div>
                            </div>

                            <CollapsibleContent>
                                <div className="overflow-hidden rounded-lg border bg-foreground text-background">
                                    <div className="flex h-10 items-center border-b border-background/10 px-4">
                                        <div className="flex items-center gap-2" aria-hidden="true">
                                            <span className="size-3 rounded-full bg-destructive opacity-80" />
                                            <span className="size-3 rounded-full bg-warning opacity-80" />
                                            <span className="size-3 rounded-full bg-success opacity-80" />
                                        </div>
                                        <span className="ml-4 flex items-center gap-2 text-xs font-semibold tracking-widest text-background/60 uppercase">
                                            <Info className="size-3" aria-hidden="true" />
                                            {t('test_email.console_filename', 'smtp_handshake.log')}
                                        </span>
                                    </div>
                                    <div className="max-h-125 space-y-1.5 overflow-y-auto p-6 font-mono text-sm leading-relaxed selection:bg-primary/30">
                                        {result.debug_log.map((entry, i) => {
                                            const isLast = i === result.debug_log.length - 1;
                                            const levelColor = LEVEL_COLORS[entry.level] || 'text-background/60';

                                            return (
                                                <div key={i} className="flex gap-4 rounded px-2 py-0.5 transition-colors hover:bg-background/5">
                                                    <div className="flex w-44 shrink-0 gap-3 select-none">
                                                        <span className="pt-0.5 text-xs font-medium text-background/50 tabular-nums">
                                                            {entry.timestamp.split(' ')[1] || entry.timestamp}
                                                        </span>
                                                        <span className={cn('w-12 pt-0.5 text-xs font-semibold tracking-widest uppercase', levelColor)}>
                                                            {entry.level}
                                                        </span>
                                                    </div>
                                                    <div className="flex flex-1 items-start gap-2.5">
                                                        {isSuccessLine(entry.message) && <CheckCircle2 className="mt-0.5 size-3.5 shrink-0 text-success" aria-hidden="true" />}
                                                        <div className="flex-1 whitespace-pre-wrap">
                                                            {formatMessage(entry.message, isLast)}
                                                        </div>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            </CollapsibleContent>
                        </Collapsible>
                    )}

                    {result.api_debug && (
                        <Collapsible open={showApiDebug} onOpenChange={setShowApiDebug} className="space-y-3">
                            <h2 className="text-base font-semibold">
                                <CollapsibleTrigger asChild>
                                    <Button variant="ghost" size="sm" className="px-2 text-base font-semibold">
                                        <Info className="text-primary" aria-hidden="true" />
                                        {t('test_email.api_debug', 'API Debug')}
                                        <ChevronDown className={cn('text-muted-foreground transition-transform', showApiDebug && 'rotate-180')} aria-hidden="true" />
                                    </Button>
                                </CollapsibleTrigger>
                            </h2>
                            <CollapsibleContent>
                                <Card>
                                    <CardContent className="space-y-2 text-sm">
                                        <div><strong>{t('test_email.provider', 'Provider')}:</strong> {result.api_debug.provider || t('test_email.not_available_short', 'n/a')}</div>
                                        <div><strong>{t('test_email.token_expires_at', 'Token Expires At')}:</strong> {formatUnix(result.api_debug.token_expires_at)}</div>
                                        <div><strong>{t('test_email.token_remaining', 'Token Remaining')}:</strong> {formatSeconds(result.api_debug.token_seconds_remaining)}</div>
                                        <div><strong>{t('test_email.token_source', 'Token Source')}:</strong> {result.api_debug.token_source || t('test_email.not_available_short', 'n/a')}</div>
                                        <div><strong>{t('test_email.refresh_attempted', 'Refresh Attempted')}:</strong> {String(Boolean(result.api_debug.refresh_attempted))}</div>
                                        <div><strong>{t('test_email.refresh_succeeded', 'Refresh Succeeded')}:</strong> {String(Boolean(result.api_debug.refresh_succeeded))}</div>
                                        {typeof result.api_debug.refresh_status_code !== 'undefined' && (
                                            <div><strong>{t('test_email.refresh_status', 'Refresh Status')}:</strong> {result.api_debug.refresh_status_code}</div>
                                        )}
                                        {typeof result.api_debug.status !== 'undefined' && (
                                            <div><strong>{t('test_email.provider_status', 'Provider Status')}:</strong> {result.api_debug.status}</div>
                                        )}
                                        {result.api_debug.error_message && (
                                            <div>
                                                <strong>{t('test_email.error_label', 'Error')}:</strong>
                                                <pre className="mt-1 overflow-x-auto rounded-md bg-muted p-2 text-xs whitespace-pre-wrap">{result.api_debug.error_message}</pre>
                                            </div>
                                        )}
                                        {result.api_debug.provider_body && (
                                            <div>
                                                <strong>{t('test_email.provider_body', 'Provider Body')}:</strong>
                                                <pre className="mt-1 overflow-x-auto rounded-md bg-muted p-2 text-xs whitespace-pre-wrap">{result.api_debug.provider_body}</pre>
                                            </div>
                                        )}
                                        {result.api_debug.provider_headers && (
                                            <div>
                                                <strong>{t('test_email.provider_headers', 'Provider Headers')}:</strong>
                                                <pre className="mt-1 overflow-x-auto rounded-md bg-muted p-2 text-xs whitespace-pre-wrap">{JSON.stringify(result.api_debug.provider_headers, null, 2)}</pre>
                                            </div>
                                        )}
                                        <div>
                                            <strong>{t('test_email.full_api_diagnostic', 'Full API Diagnostic')}:</strong>
                                            <pre className="mt-1 max-h-125 overflow-auto rounded-md bg-muted p-2 text-xs whitespace-pre-wrap">{JSON.stringify(result.api_debug, null, 2)}</pre>
                                        </div>
                                    </CardContent>
                                </Card>
                            </CollapsibleContent>
                        </Collapsible>
                    )}
                </div>
            )}
        </div>
    );
}
