import { useState, useEffect, useRef } from 'react';
import { Link } from 'react-router';
import { cn } from "@/lib/utils";
import { Mail, CheckCircle2, XCircle, Clock, Trash2, Send, Search, Eye, RefreshCcw, RefreshCw, FlaskConical, ChevronLeft, ChevronRight, Columns3 } from 'lucide-react';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../components/ui/select';
import { Switch } from "../components/ui/switch";
import api from '../services/api';
import StatusBadge from '../components/StatusBadge';
import { showApiErrorToast } from '../components/ErrorToast';
import { toast } from 'sonner';
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
} from "@/components/ui/alert-dialog";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from "@/components/ui/empty";
import { InputGroup, InputGroupAddon, InputGroupInput } from "@/components/ui/input-group";
import { Label } from "@/components/ui/label";
import { Pagination, PaginationContent, PaginationItem } from "@/components/ui/pagination";
import { Spinner } from "@/components/ui/spinner";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { useTranslations } from '../hooks/useTranslations';
import { TABLE_HEADER_CLASS } from '@/lib/table';

export default function EmailLogs() {
    const { t } = useTranslations();
    const [logs, setLogs] = useState([]);
    const [pagination, setPagination] = useState({});
    // `loaded` flips once after the first response: only that first load may replace the list with a
    // spinner. Every later fetch (tab, search, page size, refresh, 60s poll) keeps the current rows on
    // screen and only dims them (`fetching`), so the page never "splashes" on interaction.
    const [loaded, setLoaded] = useState(false);
    const [fetching, setFetching] = useState(true);
    const [filters, setFilters] = useState({ status: '', search: '', page: 1, per_page: 10 });
    // The search box is controlled by its own state and only pushed into `filters` after a short
    // pause, so typing does not fire one request (and one re-render of the table) per keystroke.
    const [searchInput, setSearchInput] = useState('');
    // Monotonic id of the latest request; a slower, older response must not overwrite newer rows.
    const latestRequestRef = useRef(0);

    // Bulk selection state
    const [selectedIds, setSelectedIds] = useState([]);
    const [selectAllMatching, setSelectAllMatching] = useState(false);

    // Optional column visibility (Status, Recipient and Actions are always shown)
    const [visibleColumns, setVisibleColumns] = useState({ subject: true, mailer: true, dateTime: true, attempts: true });

    // Dialog states
    const [deleteId, setDeleteId] = useState(null);
    const [showBulkDelete, setShowBulkDelete] = useState(false);
    const [showBulkResend, setShowBulkResend] = useState(false);

    // Guards against double-firing a resend (e.g. a slow request + a second click)
    const [resendingIds, setResendingIds] = useState(() => new Set());
    const [bulkResending, setBulkResending] = useState(false);

    useEffect(() => {
        loadLogs();
        // Clear selection when filters or pagination changes
        setSelectedIds([]);
        setSelectAllMatching(false);

        // Auto-refresh every 60 seconds
        const interval = setInterval(() => {
            loadLogs();
        }, 60000);

        return () => clearInterval(interval);
    }, [filters]);

    // Debounce free-text search into the filters (300 ms of quiet before a request goes out).
    useEffect(() => {
        if (searchInput === filters.search) return undefined;
        const timer = setTimeout(() => {
            setFilters(prev => ({ ...prev, search: searchInput, page: 1 }));
        }, 300);
        return () => clearTimeout(timer);
    }, [searchInput, filters.search]);

    async function loadLogs() {
        const requestId = ++latestRequestRef.current;
        setFetching(true);
        try {
            const params = { per_page: filters.per_page, page: filters.page };
            if (filters.status) {
                // Map logical tabs to API statuses
                if (filters.status === 'successful') {
                    params.status = 'delivered,simulated';
                } else if (filters.status === 'pending') {
                    params.status = 'queued,pending';
                } else {
                    params.status = filters.status;
                }
            }
            if (filters.search) params.search = filters.search;

            const res = await api.get('logs', params);
            if (requestId !== latestRequestRef.current) return;
            setLogs(res.data?.data || res.data || []);
            setPagination({
                total: res.data?.meta?.total || res.data?.total || 0,
                current_page: res.data?.meta?.current_page || res.data?.current_page || 1,
                last_page: res.data?.meta?.last_page || res.data?.last_page || 1,
            });
        } catch (err) {
            if (requestId !== latestRequestRef.current) return;
            console.error('Failed to load logs:', err);
            toast.error(t('email_logs.load_failed', 'Failed to load logs'));
        } finally {
            if (requestId === latestRequestRef.current) {
                setFetching(false);
                setLoaded(true);
            }
        }
    }

    async function handleResend(id) {
        if (resendingIds.has(id)) return;
        setResendingIds(prev => new Set(prev).add(id));
        try {
            await api.post(`logs/${id}/resend`);
            toast.success(t('email_logs.resend_success', 'Email resent successfully.'));
        } catch (err) {
            showApiErrorToast(t('email_logs.resend_failed', 'Resend failed'), err.message);
        } finally {
            setResendingIds(prev => {
                const next = new Set(prev);
                next.delete(id);
                return next;
            });
            loadLogs(); // Refresh so the row reflects the new status/attempt either way
        }
    }

    // Bulk Actions
    // Radix Checkbox reports `true | false | 'indeterminate'`; only an explicit `true` selects the page.
    const handleSelectAll = (checked) => {
        if (checked === true) {
            setSelectedIds(logs.map(log => log.id));
        } else {
            setSelectedIds([]);
            setSelectAllMatching(false);
        }
    };

    const handleSelectOne = (checked, id) => {
        if (checked === true) {
            setSelectedIds(prev => [...prev, id]);
        } else {
            setSelectedIds(prev => prev.filter(i => i !== id));
            setSelectAllMatching(false);
        }
    };

    const handleBulkDelete = async () => {
        const count = selectAllMatching ? pagination.total : selectedIds.length;
        try {
            const payload = selectAllMatching ? { select_all: true, filters } : { ids: selectedIds };
            await api.delete('logs', payload);
            toast.success(t('email_logs.bulk_deleted', 'Deleted {{count}} logs.', { count }));
            setSelectedIds([]);
            setSelectAllMatching(false);
            loadLogs();
            setShowBulkDelete(false);
        } catch (err) {
            toast.error(t('email_logs.bulk_delete_failed', 'Bulk delete failed: {{message}}', { message: err.message }));
        }
    };

    const handleSingleDelete = async () => {
        if (!deleteId) return;
        try {
            await api.delete(`logs/${deleteId}`);
            toast.success(t('email_logs.deleted', 'Log deleted.'));
            loadLogs();
            setDeleteId(null);
        } catch (err) {
            toast.error(t('email_logs.delete_failed', 'Delete failed: {{message}}', { message: err.message }));
        }
    };

    const handleBulkResend = async () => {
        if (bulkResending) return;
        setBulkResending(true);
        const count = selectAllMatching ? pagination.total : selectedIds.length;
        toast.loading(t('email_logs.bulk_resend_loading', 'Resending {{count}} emails...', { count }), { id: 'bulk-resend' });
        try {
            const payload = selectAllMatching ? { select_all: true, filters } : { ids: selectedIds };
            const res = await api.post('logs/resend', payload);

            const successMsg = t('email_logs.bulk_resend_success', 'Successfully sent {{count}} emails.', { count: res.success });
            const failMsg = res.failed > 0 ? t('email_logs.bulk_resend_failed_suffix', ' ({{count}} failed)', { count: res.failed }) : '';

            if (res.failed > 0) {
                toast.warning(successMsg + failMsg, { id: 'bulk-resend' });
            } else {
                toast.success(successMsg, { id: 'bulk-resend' });
            }

            setSelectedIds([]);
            setSelectAllMatching(false);
            loadLogs();
            setShowBulkResend(false);
        } catch (err) {
            showApiErrorToast(t('email_logs.bulk_resend_failed', 'Bulk resend completely failed'), err.message, { id: 'bulk-resend' });
        } finally {
            setBulkResending(false);
        }
    };

    const tabs = [
        { id: '', label: t('email_logs.tab_all', 'All'), icon: Mail },
        { id: 'successful', label: t('email_logs.tab_successful', 'Successful'), icon: CheckCircle2 },
        { id: 'simulated', label: t('email_logs.tab_simulated', 'Simulated'), icon: FlaskConical },
        { id: 'failed', label: t('email_logs.tab_failed', 'Failed'), icon: XCircle },
        { id: 'pending', label: t('email_logs.tab_pending', 'Pending'), icon: Clock },
    ];

    const toggleableColumns = [
        { id: 'subject', label: t('common.subject', 'Subject') },
        { id: 'mailer', label: t('email_logs.mailer', 'Mailer') },
        { id: 'dateTime', label: t('email_logs.date_time', 'Date & Time') },
        { id: 'attempts', label: t('email_logs.attempts', 'Attempts') },
    ];

    const isAllSelected = logs.length > 0 && selectedIds.length === logs.length;
    const isIndeterminate = selectedIds.length > 0 && selectedIds.length < logs.length;
    const selectedCount = selectAllMatching ? pagination.total : selectedIds.length;

    return (
        <div className="space-y-6">
            <div className="flex flex-col gap-1">
                <div className="flex items-center gap-3">
                    <h1 className="text-xl font-semibold tracking-tight">{t('email_logs.title', 'Email Logs')}</h1>
                    {pagination.total !== undefined && (
                        <Badge variant="secondary">
                            {t('email_logs.total_records', '{{count}} Total Records', { count: pagination.total })}
                        </Badge>
                    )}
                </div>
                <p className="text-sm text-muted-foreground">
                    {t('email_logs.subtitle', 'View and manage your sent email history')}
                </p>
            </div>

            {/* The status tabs filter the single logs table, so one TabsContent panel follows the active tab. */}
            <Tabs
                value={filters.status}
                onValueChange={value => setFilters(prev => ({ ...prev, status: value, page: 1 }))}
                className="gap-4"
            >
                {/* Top Toolbar: status tabs only */}
                <div className="flex items-center gap-2">
                    {/* On a narrow screen the strip scrolls sideways inside itself instead of running off the page. */}
                    <div className="min-w-0 overflow-x-auto">
                        <TabsList>
                            {tabs.map(tab => (
                                <TabsTrigger key={tab.id} value={tab.id}>
                                    <tab.icon />
                                    {tab.label}
                                </TabsTrigger>
                            ))}
                        </TabsList>
                    </div>

                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Button
                                variant="outline"
                                size="icon"
                                className="shrink-0"
                                aria-label={t('email_logs.refresh', 'Refresh logs')}
                                onClick={() => {
                                    toast.loading(t('email_logs.refreshing', 'Refreshing logs...'), { id: 'manual-refresh' });
                                    loadLogs().finally(() => toast.success(t('email_logs.refreshed', 'Logs refreshed'), { id: 'manual-refresh' }));
                                }}
                            >
                                <RefreshCw className={cn(fetching && "animate-spin")} />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>{t('email_logs.refresh', 'Refresh logs')}</TooltipContent>
                    </Tooltip>
                </div>

                {/* Main Table Area */}
                <TabsContent value={filters.status}>
                    <div className="overflow-hidden rounded-lg border bg-card">
                        {/* Toolbar row: Search + Columns, swapped for bulk actions once rows are selected */}
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b p-3">
                            {selectedIds.length > 0 ? (
                                <>
                                    <div className="flex flex-wrap items-center gap-3">
                                        {pagination.total > logs.length && (
                                            <div className="flex items-center gap-2">
                                                <Switch
                                                    id="email-logs-select-all-matching"
                                                    checked={selectAllMatching}
                                                    onCheckedChange={(checked) => {
                                                        setSelectAllMatching(checked);
                                                        if (checked) setSelectedIds(logs.map(log => log.id));
                                                    }}
                                                />
                                                <Label htmlFor="email-logs-select-all-matching" className="text-sm font-medium">
                                                    {t('email_logs.select_all_switch', 'Select All')}
                                                </Label>
                                            </div>
                                        )}

                                        <AlertDialog open={showBulkResend} onOpenChange={setShowBulkResend}>
                                            <AlertDialogTrigger asChild>
                                                <Button size="sm">
                                                    <Send />
                                                    {t('email_logs.resend_selected', 'Resend Selected')}
                                                </Button>
                                            </AlertDialogTrigger>
                                            <AlertDialogContent>
                                                <AlertDialogHeader>
                                                    <AlertDialogTitle>{t('email_logs.resend_selected_title', 'Resend Selected Emails?')}</AlertDialogTitle>
                                                    <AlertDialogDescription>
                                                        {t('email_logs.resend_selected_description', 'This will attempt to resend {{count}} selected emails using the current default connection.', { count: selectedCount })}
                                                    </AlertDialogDescription>
                                                </AlertDialogHeader>
                                                <AlertDialogFooter>
                                                    <AlertDialogCancel>{t('common.cancel', 'Cancel')}</AlertDialogCancel>
                                                    <AlertDialogAction onClick={handleBulkResend} disabled={bulkResending}>{t('email_logs.yes_resend_all', 'Yes, Resend All')}</AlertDialogAction>
                                                </AlertDialogFooter>
                                            </AlertDialogContent>
                                        </AlertDialog>

                                        <AlertDialog open={showBulkDelete} onOpenChange={setShowBulkDelete}>
                                            <AlertDialogTrigger asChild>
                                                <Button variant="outline" size="sm" className="border-destructive/30 text-destructive hover:bg-destructive/10 hover:text-destructive">
                                                    <Trash2 />
                                                    {t('common.delete', 'Delete')}
                                                </Button>
                                            </AlertDialogTrigger>
                                            <AlertDialogContent>
                                                <AlertDialogHeader>
                                                    <AlertDialogTitle>{t('email_logs.delete_selected_title', 'Delete Selected Logs?')}</AlertDialogTitle>
                                                    <AlertDialogDescription>
                                                        {t('email_logs.delete_selected_description', 'Are you sure you want to permanently delete {{count}} logs? This action cannot be undone.', { count: selectedCount })}
                                                    </AlertDialogDescription>
                                                </AlertDialogHeader>
                                                <AlertDialogFooter>
                                                    <AlertDialogCancel>{t('common.cancel', 'Cancel')}</AlertDialogCancel>
                                                    <AlertDialogAction variant="destructive" onClick={handleBulkDelete}>{t('email_logs.delete_logs', 'Delete Logs')}</AlertDialogAction>
                                                </AlertDialogFooter>
                                            </AlertDialogContent>
                                        </AlertDialog>
                                    </div>

                                    <div className="flex flex-wrap items-center gap-3">
                                        <span className="text-sm font-medium text-foreground">
                                            {t('email_logs.records_selected', '{{count}} record{{suffix}} selected', { count: selectedCount, suffix: selectedIds.length > 1 || selectAllMatching ? 's' : '' })}
                                        </span>

                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => { setSelectedIds([]); setSelectAllMatching(false); }}
                                        >
                                            {t('common.cancel', 'Cancel')}
                                        </Button>
                                    </div>
                                </>
                            ) : (
                                <>
                                    <div className="w-full sm:w-64">
                                        <Label htmlFor="email-logs-search" className="sr-only">{t('email_logs.search_label', 'Search logs')}</Label>
                                        <InputGroup>
                                            <InputGroupAddon><Search /></InputGroupAddon>
                                            <InputGroupInput
                                                id="email-logs-search"
                                                type="search"
                                                value={searchInput}
                                                onChange={e => setSearchInput(e.target.value)}
                                                placeholder={t('email_logs.search_placeholder', 'Search recipient, subject...')}
                                            />
                                        </InputGroup>
                                    </div>

                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button variant="outline" size="sm">
                                                <Columns3 />
                                                {t('email_logs.columns', 'Columns')}
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuLabel>{t('email_logs.toggle_columns', 'Toggle columns')}</DropdownMenuLabel>
                                            <DropdownMenuSeparator />
                                            {toggleableColumns.map(col => (
                                                <DropdownMenuCheckboxItem
                                                    key={col.id}
                                                    checked={visibleColumns[col.id]}
                                                    onSelect={e => e.preventDefault()}
                                                    onCheckedChange={checked => setVisibleColumns(prev => ({ ...prev, [col.id]: checked }))}
                                                >
                                                    {col.label}
                                                </DropdownMenuCheckboxItem>
                                            ))}
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </>
                            )}
                        </div>

                        {!loaded ? (
                            <div className="flex h-52 items-center justify-center">
                                <Spinner className="size-8 text-primary" />
                            </div>
                        ) : logs.length === 0 ? (
                            <Empty>
                                <EmptyHeader>
                                    <EmptyMedia variant="icon"><Search /></EmptyMedia>
                                    <EmptyTitle>{t('email_logs.none_title', 'No logs found')}</EmptyTitle>
                                    <EmptyDescription>
                                        {filters.search
                                            ? t('email_logs.none_with_search', 'Try adjusting your search or filters.')
                                            : t('email_logs.none_default', 'There are no email logs matching this view.')}
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        ) : (
                            <div aria-busy={fetching || undefined} className={cn('transition-opacity', fetching && 'opacity-60')}>
                            <Table aria-label={t('email_logs.table_label', 'Email logs')}>
                                <TableHeader className={TABLE_HEADER_CLASS}>
                                    <TableRow>
                                        <TableHead className="w-10 px-4">
                                            <Checkbox
                                                checked={isAllSelected ? true : isIndeterminate ? 'indeterminate' : false}
                                                onCheckedChange={handleSelectAll}
                                                aria-label={t('email_logs.select_all', 'Select all logs on this page')}
                                            />
                                        </TableHead>
                                        <TableHead className="px-4 text-muted-foreground">{t('common.status', 'Status')}</TableHead>
                                        <TableHead className="px-4 text-muted-foreground">{t('email_logs.recipient', 'Recipient')}</TableHead>
                                        {visibleColumns.subject && <TableHead className="px-4 text-muted-foreground">{t('common.subject', 'Subject')}</TableHead>}
                                        {visibleColumns.mailer && <TableHead className="px-4 text-muted-foreground">{t('email_logs.mailer', 'Mailer')}</TableHead>}
                                        {visibleColumns.dateTime && <TableHead className="px-4 text-muted-foreground">{t('email_logs.date_time', 'Date & Time')}</TableHead>}
                                        {visibleColumns.attempts && (
                                            <TableHead className="px-4 text-muted-foreground">
                                                <Tooltip>
                                                    <TooltipTrigger asChild>
                                                        <span className="cursor-help underline decoration-dotted underline-offset-4">
                                                            {t('email_logs.attempts', 'Attempts')}
                                                        </span>
                                                    </TooltipTrigger>
                                                    <TooltipContent>{t('email_logs.attempts_tooltip', 'Delivered attempts / Total attempts made')}</TooltipContent>
                                                </Tooltip>
                                            </TableHead>
                                        )}
                                        <TableHead className="w-28 px-4 text-muted-foreground">{t('common.actions', 'Actions')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {logs.map(log => {
                                        const isSelected = selectedIds.includes(log.id);
                                        const subject = log.subject || t('email_logs.no_subject', '(No subject)');
                                        const deliveredAttempts = (log.attempts || []).filter(a => a.status === 'delivered').length;
                                        const totalAttempts = Math.max(1, log.attempts?.length || 1);
                                        return (
                                            <TableRow key={log.id} data-state={isSelected ? 'selected' : undefined}>
                                                <TableCell className="px-4">
                                                    <Checkbox
                                                        checked={isSelected}
                                                        onCheckedChange={(checked) => handleSelectOne(checked, log.id)}
                                                        aria-label={t('email_logs.select_row', 'Select log for {{recipient}}', { recipient: log.to })}
                                                    />
                                                </TableCell>
                                                <TableCell className="px-4"><StatusBadge status={log.status} /></TableCell>
                                                <TableCell className="px-4 py-2">
                                                    <p className="font-medium text-foreground">{log.to}</p>
                                                    {log.error_message && <p className="mt-0.5 max-w-48 truncate text-xs text-destructive" title={log.error_message}>{log.error_message}</p>}
                                                </TableCell>
                                                {visibleColumns.subject && (
                                                    <TableCell className="px-4">
                                                        <Button asChild variant="link" className="block h-auto max-w-64 truncate p-0 font-normal text-muted-foreground hover:text-foreground">
                                                            <Link to={`/logs/${log.id}`}>{subject}</Link>
                                                        </Button>
                                                    </TableCell>
                                                )}
                                                {visibleColumns.mailer && (
                                                    <TableCell className="px-4">
                                                        <Badge variant="secondary" className="uppercase">{log.provider}</Badge>
                                                    </TableCell>
                                                )}
                                                {visibleColumns.dateTime && (
                                                    <TableCell className="px-4 text-xs font-medium tabular-nums text-muted-foreground" title={t('email_logs.created_at', 'Created: {{time}}', { time: log.created_at })}>
                                                        {log.last_attempted_at || log.created_at}
                                                    </TableCell>
                                                )}
                                                {visibleColumns.attempts && (
                                                    <TableCell className="px-4 text-xs font-medium tabular-nums text-muted-foreground">
                                                        {deliveredAttempts}/{totalAttempts}
                                                    </TableCell>
                                                )}
                                                <TableCell className="px-4">
                                                    <div className="flex items-center gap-1">
                                                            <Tooltip>
                                                                <TooltipTrigger asChild>
                                                                    <Button asChild variant="ghost" size="icon-sm" aria-label={t('email_logs.view_log_details', 'View Log Details')}>
                                                                        <Link to={`/logs/${log.id}`}><Eye /></Link>
                                                                    </Button>
                                                                </TooltipTrigger>
                                                                <TooltipContent>{t('email_logs.view_log_details', 'View Log Details')}</TooltipContent>
                                                            </Tooltip>
                                                            {log.status === 'failed' && (
                                                                <Tooltip>
                                                                    <TooltipTrigger asChild>
                                                                        <Button
                                                                            variant="ghost"
                                                                            size="icon-sm"
                                                                            className="text-primary hover:text-primary"
                                                                            aria-label={t('email_logs.resend_email', 'Resend Email')}
                                                                            onClick={() => handleResend(log.id)}
                                                                            disabled={resendingIds.has(log.id)}
                                                                        >
                                                                            {resendingIds.has(log.id) ? <Spinner className="size-4" /> : <RefreshCcw />}
                                                                        </Button>
                                                                    </TooltipTrigger>
                                                                    <TooltipContent>{t('email_logs.resend_email', 'Resend Email')}</TooltipContent>
                                                                </Tooltip>
                                                            )}
                                                            <AlertDialog open={deleteId === log.id} onOpenChange={(open) => !open && setDeleteId(null)}>
                                                                <Tooltip>
                                                                    <TooltipTrigger asChild>
                                                                        <AlertDialogTrigger asChild>
                                                                            <Button
                                                                                variant="ghost"
                                                                                size="icon-sm"
                                                                                className="text-destructive hover:text-destructive"
                                                                                aria-label={t('email_logs.delete_log', 'Delete Log')}
                                                                                onClick={() => setDeleteId(log.id)}
                                                                            >
                                                                                <Trash2 />
                                                                            </Button>
                                                                        </AlertDialogTrigger>
                                                                    </TooltipTrigger>
                                                                    <TooltipContent>{t('email_logs.delete_log', 'Delete Log')}</TooltipContent>
                                                                </Tooltip>
                                                                <AlertDialogContent>
                                                                    <AlertDialogHeader>
                                                                        <AlertDialogTitle>{t('email_logs.delete_this_log', 'Delete this log?')}</AlertDialogTitle>
                                                                        <AlertDialogDescription>
                                                                            {t('email_logs.delete_this_log_description', 'This will permanently delete the email log entry for {{recipient}}.', { recipient: log.to })}
                                                                        </AlertDialogDescription>
                                                                    </AlertDialogHeader>
                                                                    <AlertDialogFooter>
                                                                        <AlertDialogCancel>{t('common.cancel', 'Cancel')}</AlertDialogCancel>
                                                                        <AlertDialogAction variant="destructive" onClick={handleSingleDelete}>
                                                                            {t('common.delete', 'Delete')}
                                                                        </AlertDialogAction>
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

                                {/* Footer / Pagination Toolbar */}
                                <div className="flex flex-col items-center justify-between gap-4 border-t px-4 py-3 sm:flex-row">
                                    {/* Per Page Selection */}
                                    <div className="flex items-center gap-3">
                                        <Label htmlFor="email-logs-per-page" className="text-xs text-muted-foreground">{t('email_logs.rows_per_page', 'Rows per page:')}</Label>
                                        <Select
                                            value={filters.per_page.toString()}
                                            onValueChange={(val) => setFilters(prev => ({ ...prev, per_page: Number(val), page: 1 }))}
                                        >
                                            <SelectTrigger id="email-logs-per-page" size="sm" className="w-20">
                                                <SelectValue placeholder="10" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="10">10</SelectItem>
                                                <SelectItem value="50">50</SelectItem>
                                                <SelectItem value="100">100</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    {/* Paginator */}
                                    <div className="flex items-center gap-4">
                                        <span className="text-xs text-muted-foreground">
                                            {t('email_logs.page_of', 'Page {{current}} of {{last}}', { current: pagination.current_page || 1, last: pagination.last_page || 1 })}
                                        </span>
                                        <Pagination className="mx-0 w-auto" aria-label={t('email_logs.pagination', 'Log pages')}>
                                            <PaginationContent>
                                                <PaginationItem>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        disabled={filters.page <= 1}
                                                        onClick={() => setFilters(prev => ({ ...prev, page: prev.page - 1 }))}
                                                    >
                                                        <ChevronLeft />
                                                        {t('common.previous', 'Previous')}
                                                    </Button>
                                                </PaginationItem>
                                                <PaginationItem>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        disabled={filters.page >= pagination.last_page}
                                                        onClick={() => setFilters(prev => ({ ...prev, page: prev.page + 1 }))}
                                                    >
                                                        {t('common.next', 'Next')}
                                                        <ChevronRight />
                                                    </Button>
                                                </PaginationItem>
                                            </PaginationContent>
                                        </Pagination>
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>
                </TabsContent>
            </Tabs>
        </div>
    );
}
