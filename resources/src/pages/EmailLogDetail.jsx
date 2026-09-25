import { useState, useEffect, useRef } from 'react';
import { Link, useParams } from 'react-router';
import api from '../services/api';
import StatusBadge from '../components/StatusBadge';
import { showApiErrorToast } from '../components/ErrorToast';
import { ArrowLeft, XCircle, CheckCircle2, AlertTriangle, Info, Paperclip, FileText } from 'lucide-react';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog"
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from "@/components/ui/empty"
import { Spinner } from "@/components/ui/spinner"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { toast } from "sonner"
import { useProCapability } from '../hooks/useProCapability';
import { useTranslations } from '../hooks/useTranslations';
import { TABLE_HEADER_CLASS } from '@/lib/table';

function formatBytes(bytes) {
    if (!bytes && bytes !== 0) return '';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export default function EmailLogDetail() {
    const { id } = useParams();
    const { t } = useTranslations();
    const { isProLicensed } = useProCapability();
    const [log, setLog] = useState(null);
    const [loading, setLoading] = useState(true);
    const [activeTab, setActiveTab] = useState('preview');
    const [isResending, setIsResending] = useState(false);
    // Only the newest request may write; a refetch (after a resend) keeps the entry on screen.
    const latestRequestRef = useRef(0);

    useEffect(() => {
        loadLog({ initial: true });
    }, [id]);

    async function loadLog({ initial = false } = {}) {
        const requestId = ++latestRequestRef.current;
        if (initial) {
            setLoading(true);
        }
        try {
            const res = await api.get(`logs/${id}`);
            if (latestRequestRef.current !== requestId) return;
            setLog(res.data);
        } catch (err) {
            if (latestRequestRef.current !== requestId) return;
            console.error('Failed to load log:', err);
        } finally {
            if (latestRequestRef.current === requestId && initial) {
                setLoading(false);
            }
        }
    }

    async function handleResend() {
        setIsResending(true);
        try {
            await api.post(`logs/${id}/resend`);
            toast.success(t('email_log_detail.resend_success', 'Email resent successfully!'), {
                description: t('email_log_detail.resend_success_desc', 'The email is currently being processed by the background queue.'),
            });
            loadLog(); // Auto update history and technical logs
        } catch (err) {
            showApiErrorToast(t('email_log_detail.resend_failed', 'Resend failed'), err.message);
            loadLog(); // Refresh to see the failed attempt in history
        } finally {
            setIsResending(false);
        }
    }

    if (loading) {
        return (
            <div className="flex h-64 items-center justify-center">
                <Spinner className="size-8 text-primary" />
            </div>
        );
    }

    if (!log) {
        return (
            <Empty>
                <EmptyHeader>
                    <EmptyMedia variant="icon"><Info /></EmptyMedia>
                    <EmptyTitle>{t('email_log_detail.not_found', 'Log not found.')}</EmptyTitle>
                </EmptyHeader>
            </Empty>
        );
    }

    const tabs = [
        { key: 'preview', label: t('email_log_detail.tab_preview', 'Preview') },
        { key: 'headers', label: t('email_log_detail.tab_headers', 'Raw Headers') },
        { key: 'technical', label: t('email_log_detail.tab_technical', 'Technical Details') },
    ];

    const sectionLabelClass = 'text-xs font-medium';
    const hasError = Boolean(log.error_message);

    return (
        <div className="mx-auto space-y-6 pb-12">
            <Button asChild variant="link" className="h-auto p-0 font-normal text-muted-foreground hover:text-foreground">
                <Link to="/logs">
                    <ArrowLeft />
                    {t('email_log_detail.back_to_logs', 'Back to Logs')}
                </Link>
            </Button>

            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div className="space-y-2">
                    <div className="flex items-center gap-3">
                        <StatusBadge status={log.status} />
                        <Badge variant="outline" className="font-mono text-muted-foreground">
                            {t('email_log_detail.id_label', 'ID: #{{id}}', { id: log.id })}
                        </Badge>
                    </div>
                    <h1 className="text-xl font-semibold tracking-tight text-foreground">{log.subject}</h1>
                </div>

                <AlertDialog>
                    <AlertDialogTrigger asChild>
                        <Button>{t('email_log_detail.resend_email', 'Resend Email')}</Button>
                    </AlertDialogTrigger>
                    <AlertDialogContent className="max-w-md">
                        <AlertDialogHeader>
                            <AlertDialogTitle>{t('email_log_detail.confirm_resend_title', 'Resend this email?')}</AlertDialogTitle>
                            <AlertDialogDescription>
                                {t('email_log_detail.confirm_resend_desc', 'This will queue the email to be sent again using the current system default SMTP connection. A new attempt will be recorded in the history log.')}
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>{t('email_log_detail.cancel', 'Cancel')}</AlertDialogCancel>
                            <AlertDialogAction onClick={handleResend} disabled={isResending}>
                                {isResending ? t('email_log_detail.sending', 'Sending...') : t('email_log_detail.yes_resend_now', 'Yes, resend now')}
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2 lg:items-stretch">
                <Card className="py-4">
                    <CardContent className="space-y-3">
                        <div className="flex items-baseline gap-3">
                            <span className={`${sectionLabelClass} w-28 shrink-0 text-muted-foreground`}>{t('email_log_detail.recipient', 'Recipient')}</span>
                            <span className="truncate text-sm font-medium" title={log.to}>{log.to}</span>
                        </div>
                        <div className="flex items-baseline gap-3">
                            <span className={`${sectionLabelClass} w-28 shrink-0 text-muted-foreground`}>{t('email_log_detail.from', 'From')}</span>
                            <span className="truncate text-sm font-medium" title={log.from_email}>{log.from_email || t('email_log_detail.na', 'N/A')}</span>
                        </div>
                        <div className="flex items-baseline gap-3">
                            <span className={`${sectionLabelClass} w-28 shrink-0 text-muted-foreground`}>{t('email_log_detail.date_created', 'Date Created')}</span>
                            <span className="text-sm font-medium">{log.created_at}</span>
                        </div>
                        <div className="flex items-baseline gap-3">
                            <span className={`${sectionLabelClass} w-28 shrink-0 text-muted-foreground`}>{t('email_log_detail.mailer_driver', 'Mailer Driver')}</span>
                            <span className="flex items-center gap-2 text-sm font-medium">
                                <span className="size-2 animate-pulse rounded-full bg-primary/40" aria-hidden="true" />
                                {log.provider?.toUpperCase() || t('email_log_detail.na', 'N/A')}
                            </span>
                        </div>
                    </CardContent>
                </Card>

                {hasError ? (
                    <Alert variant="destructive" className="mb-0">
                        <XCircle />
                        <AlertTitle className="flex items-center gap-2 text-base">
                            {t('email_log_detail.latest_delivery_error', 'Latest Delivery Attempt Error')}
                            <Badge variant="destructive">{t('email_log_detail.critical', 'Critical')}</Badge>
                        </AlertTitle>
                        <AlertDescription>
                            <p className="text-sm italic">"{log.error_message}"</p>
                        </AlertDescription>
                    </Alert>
                ) : (
                    <Alert variant="success" className="mb-0">
                        <CheckCircle2 />
                        <AlertTitle className="text-base">
                            {t('email_log_detail.no_delivery_issues', 'No Delivery Issues')}
                        </AlertTitle>
                        <AlertDescription>
                            <p className="text-sm">{t('email_log_detail.no_delivery_issues_desc', 'This email was processed without any recorded errors.')}</p>
                        </AlertDescription>
                    </Alert>
                )}
            </div>

            <Tabs value={activeTab} onValueChange={setActiveTab}>
                <TabsList>
                    {tabs.map(tab => (
                        <TabsTrigger key={tab.key} value={tab.key}>{tab.label}</TabsTrigger>
                    ))}
                </TabsList>

                <TabsContent value="preview" className="min-h-150">
                    {log.attachments && log.attachments.length > 0 && (
                        <div className="mb-4 rounded-lg border bg-card p-4">
                            <div className="mb-3 flex items-center gap-2">
                                <Paperclip className="size-4 text-muted-foreground" />
                                <span className="text-sm font-medium text-foreground">
                                    {t('email_log_detail.attachments', 'Attachments')}
                                </span>
                                <Badge variant="secondary">{log.attachments.length}</Badge>
                            </div>
                            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                {log.attachments.map((attachment, i) => (
                                    <div key={i} className="flex items-center gap-3 rounded-md border bg-muted/20 px-3 py-2">
                                        <FileText className="size-4 shrink-0 text-muted-foreground" />
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-medium" title={attachment.name}>{attachment.name}</p>
                                            <p
                                                className="truncate text-xs text-muted-foreground"
                                                title={attachment.type || undefined}
                                            >
                                                {attachment.size != null ? formatBytes(attachment.size) : ''}
                                                {attachment.type ? ` · ${attachment.type}` : ''}
                                            </p>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                    <div className="overflow-hidden rounded-lg border bg-card">
                        {log.body ? (
                            <iframe
                                srcDoc={log.body}
                                className="min-h-150 w-full border-0 bg-white"
                                sandbox=""
                                title={t('email_log_detail.email_preview_title', 'Email preview')}
                            />
                        ) : (
                            <Empty className="border-0">
                                <EmptyHeader>
                                    <EmptyMedia variant="icon"><AlertTriangle /></EmptyMedia>
                                    <EmptyTitle>{t('email_log_detail.body_not_available', 'Email body not available')}</EmptyTitle>
                                    <EmptyDescription>
                                        {isProLicensed
                                            ? t('email_log_detail.enable_log_body_hint_pro', 'This email predates body logging, or "Store Email Message Body" was turned off in Settings when it was sent.')
                                            : t('email_log_detail.enable_log_body_hint_free', 'This email predates automatic body logging and cannot be previewed.')}
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        )}
                    </div>
                </TabsContent>

                <TabsContent value="headers" className="min-h-150">
                    <Card>
                        <CardContent>
                            <pre className="font-mono text-xs leading-relaxed break-all whitespace-pre-wrap text-muted-foreground">
                                {log.headers ? JSON.stringify(log.headers, null, 4) : t('email_log_detail.no_headers_recorded', 'No headers recorded.')}
                            </pre>
                        </CardContent>
                    </Card>
                </TabsContent>

                <TabsContent value="technical" className="min-h-150">
                    <div className="grid grid-cols-1 gap-6 md:grid-cols-3">
                        <Card className="gap-2">
                            <CardHeader>
                                <CardDescription className={sectionLabelClass}>{t('email_log_detail.delivery_time', 'Delivery Time')}</CardDescription>
                                <CardTitle className="text-2xl font-semibold tabular-nums">
                                    {log.delivery_time_ms ? `${log.delivery_time_ms}ms` : '—'}
                                </CardTitle>
                            </CardHeader>
                        </Card>
                        <Card className="gap-2">
                            <CardHeader>
                                <CardDescription className={sectionLabelClass}>{t('email_log_detail.total_retries', 'Total Retries')}</CardDescription>
                                <CardTitle className="text-2xl font-semibold tabular-nums">{log.retries || 0}</CardTitle>
                            </CardHeader>
                        </Card>
                        <Card className="gap-2">
                            <CardHeader>
                                <CardDescription className={sectionLabelClass}>{t('email_log_detail.message_id', 'Message ID')}</CardDescription>
                                <CardTitle className="font-mono text-sm font-medium break-all text-primary">
                                    {log.message_id || t('email_log_detail.not_available', 'Not available')}
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    </div>

                    <h2 className="mt-8 mb-3 text-sm font-medium text-foreground">
                        {t('email_log_detail.attempts_history', 'Attempts / History')}
                    </h2>
                    <div className="overflow-hidden rounded-lg border bg-card">
                        <Table aria-label={t('email_log_detail.attempts_history', 'Attempts / History')}>
                            <TableHeader className={TABLE_HEADER_CLASS}>
                                <TableRow>
                                    <TableHead className="w-44 px-4 sm:px-6">{t('email_log_detail.table_date_time', 'Date & Time')}</TableHead>
                                    <TableHead className="w-28 px-4 sm:px-6">{t('email_log_detail.table_status', 'Status')}</TableHead>
                                    <TableHead className="w-56 px-4 sm:px-6">{t('email_log_detail.table_source_provider', 'Source / Provider')}</TableHead>
                                    <TableHead className="px-4 sm:px-6">{t('email_log_detail.table_result_details', 'Result Details')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {(!log.attempts || log.attempts.length === 0) ? (
                                    <TableRow>
                                        <TableCell colSpan={4} className="px-4 py-12 text-center italic text-muted-foreground sm:px-6">
                                            {t('email_log_detail.no_retry_attempts', 'No retry attempts recorded for this email.')}
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    [...log.attempts].reverse().map((attempt, i) => (
                                        <TableRow key={i}>
                                            <TableCell className="px-4 py-4 text-muted-foreground sm:px-6">{attempt.date}</TableCell>
                                            <TableCell className="px-4 py-4 sm:px-6"><StatusBadge status={attempt.status} /></TableCell>
                                            <TableCell className="px-4 py-4 sm:px-6">
                                                <div className="flex items-center gap-2">
                                                    <Badge variant="secondary" className="w-fit shrink-0 uppercase">{attempt.provider}</Badge>
                                                    <span className="max-w-32 truncate text-xs italic text-muted-foreground" title={attempt.source}>{attempt.source || t('email_log_detail.direct_resend', 'Direct Resend')}</span>
                                                </div>
                                            </TableCell>
                                            <TableCell className="w-full px-4 py-4 whitespace-normal text-xs text-foreground/70 sm:px-6">{attempt.error || t('email_log_detail.request_accepted', 'Request accepted by mailer')}</TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </TabsContent>
            </Tabs>
        </div>
    );
}
