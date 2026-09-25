import { Suspense, useState, useEffect } from 'react';
import { Link } from 'react-router';
import {
    Trash2,
    Edit2,
    Plus,
    RefreshCw,
    Zap,
    Clock,
    Info,
    Send,
    Mail,
    Star,
    LifeBuoy,
    Columns3,
    ChevronLeft,
    ChevronRight,
} from 'lucide-react';
import api from '../services/api';
import StatusBadge from '../components/StatusBadge';
import HealthBadge from '../components/HealthBadge';
import ConnectionsTableSkeleton from '../components/connections/ConnectionsTableSkeleton';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import { Card, CardContent } from "@/components/ui/card";
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from "@/components/ui/empty";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Pagination, PaginationContent, PaginationItem } from "@/components/ui/pagination";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { toast } from "sonner";
import { MAIL_PROVIDERS } from '../config/mailers';
import { useTranslations } from '../hooks/useTranslations';
import { useProExtensions } from '../hooks/useProExtensions';
import { TABLE_HEADER_CLASS } from '@/lib/table';

function getCurrentUserEmail() {
    if (typeof window === 'undefined') {
        return '';
    }
    return String(window.BooleanSmtpAdmin?.currentUserEmail || '').trim();
}

export default function Connections() {
    const { t } = useTranslations();
    const [connections, setConnections] = useState([]);
    // Panels an add-on shows under the list (sender groups, for example).
    const { proConnectionPanels } = useProExtensions();
    const listPanels = proConnectionPanels.filter(panel => panel.slot === 'mailers-list');
    const [routingSettings, setRoutingSettings] = useState({});
    const [loading, setLoading] = useState(true);
    const [refreshing, setRefreshing] = useState(false);
    const [selectedIds, setSelectedIds] = useState([]);
    const [deleteId, setDeleteId] = useState(null);
    const [isBulkDeleting, setIsBulkDeleting] = useState(false);
    const [testingId] = useState(null);
    const [testDialogOpen, setTestDialogOpen] = useState(false);
    const [testConnectionId, setTestConnectionId] = useState(null);
    const [testRecipient, setTestRecipient] = useState(() => getCurrentUserEmail());
    const [isSending, setIsSending] = useState(false);

    // Optional column visibility (Provider, Status and Actions are always shown)
    const [visibleColumns, setVisibleColumns] = useState({ sender: true, mode: true, health: true });

    // Client-side pagination: connection lists are small enough to load in full,
    // so this just slices the already-fetched array instead of round-tripping to the API.
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(10);

    useEffect(() => {
        loadConnections();
    }, []);

    // `refresh: true` is the manual "Refresh Status" path: the table stays mounted and the
    // button shows a spinner instead of swapping the whole page to the skeleton.
    async function loadConnections({ refresh = false } = {}) {
        if (refresh) setRefreshing(true); else setLoading(true);
        try {
            const res = await api.get('connections');
            setConnections(res.data || []);
        } catch (err) {
            console.error('Failed to load connections:', err);
            toast.error(t('connections.load_failed', 'Failed to load connections'));
        } finally {
            if (refresh) setRefreshing(false); else setLoading(false);
        }

        // Best-effort: only used to flag the Default/Fallback connection in the table, so a
        // failure here shouldn't block the connections list itself.
        try {
            const settingsRes = await api.get('settings');
            setRoutingSettings(settingsRes.data || {});
        } catch (err) {
            console.error('Failed to load routing settings:', err);
        }
    }

    async function confirmDelete() {
        if (!deleteId) return;
        const id = deleteId;
        setDeleteId(null);
        try {
            await api.delete(`connections/${id}`);
            setConnections(prev => prev.filter(c => c.id !== id));
            setSelectedIds(prev => prev.filter(selectedId => selectedId !== id));
            toast.success(t('connections.deleted', 'Connection deleted'));
        } catch (err) {
            toast.error(t('connections.delete_failed', 'Failed to delete connection: {{message}}', { message: err.message }));
        }
    }

    async function confirmBulkDelete() {
        if (selectedIds.length === 0) return;
        setIsBulkDeleting(false);
        try {
            await api.delete('connections', { ids: selectedIds });
            setConnections(prev => prev.filter(c => !selectedIds.includes(c.id)));
            setSelectedIds([]);
            toast.success(t('connections.deleted_multiple', '{{count}} connections deleted', { count: selectedIds.length }));
        } catch (err) {
            toast.error(t('connections.bulk_delete_failed', 'Failed to delete connections: {{message}}', { message: err.message }));
        }
    }

    function handleQuickTest(id) {
        if (!testRecipient) {
            setTestRecipient(getCurrentUserEmail());
        }
        setTestConnectionId(id);
        setTestDialogOpen(true);
    }

    async function sendTestEmail() {
        if (!testConnectionId) return;
        const recipient = String(testRecipient || '').trim();
        if (!recipient) {
            toast.error(t('connections.recipient_required', 'Recipient email is required.'));
            return;
        }

        setIsSending(true);
        try {
            const res = await api.post('test-email', {
                to: recipient,
                connection_id: Number(testConnectionId),
            });
            const payload = res.data ?? res;
            const sent = Boolean(payload?.sent);

            if (sent) {
                toast.success(t('connections.test_sent', 'Test email sent to {{email}}.', { email: payload?.to || recipient }));
                setTestDialogOpen(false);
                setTestConnectionId(null);
                await loadConnections();
            } else {
                toast.error(payload?.error || t('connections.test_failed', 'Test email failed to send.'));
            }
        } catch (err) {
            toast.error(String(err?.message || t('connections.test_failed', 'Test email failed to send.')));
        } finally {
            setIsSending(false);
        }
    }

    const toggleSelect = (id) => {
        setSelectedIds(prev =>
            prev.includes(id) ? prev.filter(i => i !== id) : [...prev, id]
        );
    };

    const toggleSelectAll = () => {
        if (selectedIds.length === connections.length) {
            setSelectedIds([]);
        } else {
            setSelectedIds(connections.map(c => c.id));
        }
    };

    const pageHeader = (
        <div>
            <h1 className="text-xl font-semibold tracking-tight">{t('connections.title', 'Mailer Connections')}</h1>
            <p className="mt-1 text-sm text-muted-foreground">
                {t('connections.subtitle', 'Manage your SMTP configurations and monitor their health status.')}
            </p>
        </div>
    );

    if (loading) {
        return (
            <div className="space-y-6">
                <div className="flex flex-col justify-between gap-4 md:flex-row md:items-center">
                    {pageHeader}
                </div>

                <ConnectionsTableSkeleton />
            </div>
        );
    }

    const formatOauthStatus = (conn) => {
        const isOauthDriver = ['google', 'gmail', 'outlook', 'zoho'].includes(conn.driver);
        if (!isOauthDriver) return null;

        const mode = String(conn.settings?.delivery_mode || '');
        if (conn.driver === 'outlook' && mode === 'one_click') {
            const status = String(conn.settings?.one_click_status || '').trim();
            if (status !== '') {
                return t('connections.one_click_status', 'One Click: {{status}}', {
                    status: status.replace(/_/g, ' '),
                });
            }
            const hasToken = String(conn.settings?.one_click_bearer_token || '').trim() !== '';
            return hasToken
                ? t('connections.one_click_connected', 'One Click connected')
                : t('connections.one_click_not_connected', 'One Click not connected');
        }

        // Application Permission (client-credentials) never has a refresh token by design -- the
        // generic "No refresh token" fallback below would be permanently wrong for this mode, so
        // report configuration completeness instead (mirrors the one_click branch above).
        if (conn.driver === 'outlook' && mode === 'app_permission') {
            const configured = ['app_client_id', 'app_client_secret', 'app_tenant_id']
                .every((key) => String(conn.settings?.[key] || '').trim() !== '');
            return configured
                ? t('connections.app_permission_configured', 'Application Permission configured')
                : t('connections.app_permission_not_configured', 'Application Permission incomplete');
        }

        const remaining = Number(conn.oauth_token_seconds_remaining);
        const hasExpiry = Number.isFinite(remaining);
        const hasRefresh = Boolean(conn.oauth_refresh_available);

        if (!hasRefresh) return t('connections.no_refresh_token', 'No refresh token');
        if (!hasExpiry) return t('connections.token_expiry_unknown', 'Token expiry unknown');
        if (remaining <= 0) return t('connections.token_expired', 'Token expired');

        const mins = Math.floor(remaining / 60);
        if (mins < 60) {
            return t('connections.token_valid_minutes', 'Token valid ({{minutes}}m left)', { minutes: mins });
        }
        const hours = Math.floor(mins / 60);
        return t('connections.token_valid_hours', 'Token valid ({{hours}}h left)', { hours });
    };

    const tableHeadClass = 'px-4';

    const toggleableColumns = [
        { id: 'sender', label: t('connections.table_sender', 'Sender') },
        { id: 'mode', label: t('connections.table_mode', 'Mode') },
        { id: 'health', label: t('connections.table_health', 'Health') },
    ];

    const totalConnections = connections.length;
    const lastPage = Math.max(1, Math.ceil(totalConnections / perPage));
    const currentPage = Math.min(page, lastPage);
    const pagedConnections = connections.slice((currentPage - 1) * perPage, currentPage * perPage);
    const showPagination = totalConnections > perPage;

    return (
        <div className="space-y-6">
            <div className="flex flex-col justify-between gap-4 md:flex-row md:items-center">
                {pageHeader}
                <div className="flex items-center gap-3">
                    <Button
                        variant="outline"
                        disabled={refreshing}
                        onClick={() => loadConnections({ refresh: true })}
                    >
                        {refreshing ? <Spinner /> : <RefreshCw />}
                        {t('connections.refresh_status', 'Refresh Status')}
                    </Button>
                    <Button asChild>
                        <Link to="/connections/new">
                            <Plus />
                            {t('connections.add_connection', 'Add Connection')}
                        </Link>
                    </Button>
                </div>
            </div>

            {connections.length === 0 ? (
                <Card className="border-dashed">
                    <CardContent>
                        <Empty className="border-0">
                            <EmptyHeader>
                                <EmptyMedia variant="icon"><Info /></EmptyMedia>
                                <EmptyTitle>{t('connections.none_title', 'No connections found')}</EmptyTitle>
                                <EmptyDescription>
                                    {t('connections.none_subtitle', "You haven't configured any SMTP connections yet. Add one to start sending emails.")}
                                </EmptyDescription>
                            </EmptyHeader>
                            <EmptyContent>
                                <Button asChild>
                                    <Link to="/connections/new">
                                        <Plus />
                                        {t('connections.add_first', 'Add Your First Connection')}
                                    </Link>
                                </Button>
                            </EmptyContent>
                        </Empty>
                    </CardContent>
                </Card>
            ) : (
                <div className="overflow-hidden rounded-lg border bg-card">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b p-3">
                        {selectedIds.length > 0 ? (
                            <>
                                <div className="flex items-center gap-3">
                                    <Badge>{selectedIds.length}</Badge>
                                    <span className="text-sm font-medium text-foreground">
                                        {t('connections.selected_count', '{{count}} Connections Selected', { count: selectedIds.length })}
                                    </span>
                                </div>
                                <div className="flex items-center gap-3">
                                    <Button variant="destructive" size="sm" onClick={() => setIsBulkDeleting(true)}>
                                        <Trash2 />
                                        {t('connections.delete_selected', 'Delete Selected')}
                                    </Button>
                                    <Button variant="ghost" size="sm" onClick={() => setSelectedIds([])}>
                                        {t('common.cancel', 'Cancel')}
                                    </Button>
                                </div>
                            </>
                        ) : (
                            <>
                                <div />
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button variant="outline" size="sm">
                                            <Columns3 />
                                            {t('connections.columns', 'Columns')}
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuLabel>{t('connections.toggle_columns', 'Toggle columns')}</DropdownMenuLabel>
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

                    <Table aria-label={t('connections.table_label', 'Mailer connections')}>
                        <TableHeader className={TABLE_HEADER_CLASS}>
                            <TableRow>
                                <TableHead className="h-9 w-10 px-4">
                                    <Checkbox
                                        checked={selectedIds.length === connections.length && connections.length > 0}
                                        onCheckedChange={toggleSelectAll}
                                        aria-label={t('connections.select_all', 'Select all connections')}
                                    />
                                </TableHead>
                                <TableHead className={tableHeadClass}>{t('connections.table_provider', 'Provider')}</TableHead>
                                {visibleColumns.sender && <TableHead className={tableHeadClass}>{t('connections.table_sender', 'Sender')}</TableHead>}
                                {visibleColumns.mode && <TableHead className={tableHeadClass}>{t('connections.table_mode', 'Mode')}</TableHead>}
                                <TableHead className={tableHeadClass}>{t('connections.table_status', 'Status')}</TableHead>
                                {visibleColumns.health && <TableHead className={tableHeadClass}>{t('connections.table_health', 'Health')}</TableHead>}
                                <TableHead className={`${tableHeadClass} text-right`}>{t('connections.table_actions', 'Actions')}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {pagedConnections.map(conn => {
                                const provider = MAIL_PROVIDERS.find(p => p.driver === conn.driver);
                                const providerName = provider ? t(`mailers.${provider.driver}.name`, provider.name) : conn.driver;
                                const isPhp = conn.driver === 'php';
                                const mode = String(conn.settings?.delivery_mode || '');
                                const isApi = !isPhp && (mode === 'api' || mode === 'one_click' || mode === 'app_permission' || ['google', 'gmail', 'zoho'].includes(conn.driver));
                                const modeLabel = isPhp
                                    ? t('connections.mode_php', 'PHP')
                                    : mode === 'one_click'
                                        ? t('connections.mode_api_one_click', 'API (One Click)')
                                        : mode === 'app_permission'
                                            ? t('connections.mode_api_app_permission', 'API (App Permission)')
                                            : isApi
                                                ? t('connections.mode_api', 'API')
                                                : t('connections.mode_smtp', 'SMTP');
                                const modeVariant = isPhp ? 'secondary' : isApi ? 'info' : 'outline';
                                const ModeIcon = isPhp ? Mail : isApi ? Zap : Clock;
                                const oauthStatus = formatOauthStatus(conn);
                                const isSelected = selectedIds.includes(conn.id);
                                // IDs round-trip through the REST API as numeric strings, so compare numerically.
                                const isDefaultConnection = Number(routingSettings.default_connection_id) === Number(conn.id);
                                const isFallbackConnection = Number(routingSettings.fallback_connection_id) === Number(conn.id);
                                const fallbackDisabled = isFallbackConnection && routingSettings.fallback_enabled === false;

                                return (
                                    <TableRow key={conn.id} data-state={isSelected ? 'selected' : undefined}>
                                        <TableCell className="px-4 py-1.5">
                                            <Checkbox
                                                checked={isSelected}
                                                onCheckedChange={() => toggleSelect(conn.id)}
                                                aria-label={t('connections.select_row', 'Select {{name}}', { name: conn.name })}
                                            />
                                        </TableCell>
                                        <TableCell className="max-w-87.5 px-4 py-1.5">
                                            <div className="flex items-center gap-3">
                                                <div className="flex size-8 shrink-0 items-center justify-center rounded-lg border bg-background p-1">
                                                    {(provider?.compactLogo || provider?.logo) ? (
                                                        <img src={provider.compactLogo || provider.logo} alt={providerName} className="size-full object-contain" />
                                                    ) : (
                                                        <Mail className="size-4 text-muted-foreground" />
                                                    )}
                                                </div>
                                                <div className="flex min-w-0 flex-col">
                                                    <div className="flex items-center gap-1.5">
                                                        <span className="truncate text-sm font-medium text-foreground">{conn.name}</span>
                                                        {isDefaultConnection ? (
                                                            <Tooltip>
                                                                <TooltipTrigger asChild>
                                                                    <span
                                                                        className="inline-flex shrink-0 text-warning"
                                                                        role="img"
                                                                        aria-label={t('connections.badge_default', 'Default connection')}
                                                                    >
                                                                        <Star className="size-3.5 fill-current" />
                                                                    </span>
                                                                </TooltipTrigger>
                                                                <TooltipContent>{t('connections.badge_default_tooltip', 'Used as the default connection in Settings > Delivery Architecture.')}</TooltipContent>
                                                            </Tooltip>
                                                        ) : null}
                                                        {isFallbackConnection ? (
                                                            <Tooltip>
                                                                <TooltipTrigger asChild>
                                                                    <span
                                                                        className={`inline-flex shrink-0 text-info ${fallbackDisabled ? 'opacity-50' : ''}`}
                                                                        role="img"
                                                                        aria-label={t('connections.badge_fallback', 'Fallback connection')}
                                                                    >
                                                                        <LifeBuoy className="size-3.5" />
                                                                    </span>
                                                                </TooltipTrigger>
                                                                <TooltipContent>
                                                                    {fallbackDisabled
                                                                        ? t('connections.badge_fallback_disabled_tooltip', 'Set as the fallback connection in Settings, but fallback is currently disabled.')
                                                                        : t('connections.badge_fallback_tooltip', 'Used as the fallback connection in Settings > Delivery Architecture.')}
                                                                </TooltipContent>
                                                            </Tooltip>
                                                        ) : null}
                                                    </div>
                                                    <span className="text-xs text-muted-foreground">{providerName}</span>
                                                </div>
                                            </div>
                                        </TableCell>

                                        {visibleColumns.sender && (
                                            <TableCell className="max-w-62.5 px-4 py-1.5">
                                                <div className="flex flex-col gap-0.5">
                                                    <span className="truncate text-xs font-medium leading-none">{conn.settings?.from_email || t('connections.not_configured', 'Not configured')}</span>
                                                    <span className="max-w-35 truncate text-xs italic text-muted-foreground">
                                                        {conn.settings?.from_name || ''}
                                                    </span>
                                                    {conn.sender_notice ? (
                                                        <span className="whitespace-normal text-xs text-warning" data-testid="sender-notice">
                                                            {conn.sender_notice}
                                                        </span>
                                                    ) : null}
                                                </div>
                                            </TableCell>
                                        )}

                                        {visibleColumns.mode && (
                                            <TableCell className="px-4 py-1.5">
                                                <div className="flex flex-col gap-0.5">
                                                    <Badge variant={modeVariant}>
                                                        <ModeIcon />
                                                        {modeLabel}
                                                    </Badge>
                                                    {oauthStatus ? (
                                                        <span className="text-xs text-muted-foreground">
                                                            {oauthStatus}
                                                        </span>
                                                    ) : null}
                                                </div>
                                            </TableCell>
                                        )}

                                        <TableCell className="px-4 py-1.5">
                                            <StatusBadge status={conn.is_active ? 'active' : 'inactive'} />
                                        </TableCell>

                                        {visibleColumns.health && (
                                            <TableCell className="px-4 py-1.5">
                                                <HealthBadge
                                                    status={conn.health_status}
                                                    detail={conn.health_status === 'error' ? conn.last_error : null}
                                                />
                                            </TableCell>
                                        )}

                                        <TableCell className="px-4 py-1.5 text-right">
                                            <div className="flex items-center justify-end gap-1">
                                                <Tooltip>
                                                    <TooltipTrigger asChild>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon-sm"
                                                            disabled={testingId === conn.id}
                                                            onClick={() => handleQuickTest(conn.id)}
                                                            aria-label={t('connections.quick_test', 'Quick Test')}
                                                        >
                                                            {testingId === conn.id ? <Spinner /> : <Send />}
                                                        </Button>
                                                    </TooltipTrigger>
                                                    <TooltipContent>{t('connections.quick_test', 'Quick Test')}</TooltipContent>
                                                </Tooltip>
                                                <Tooltip>
                                                    <TooltipTrigger asChild>
                                                        <Button asChild variant="ghost" size="icon-sm" aria-label={t('connections.edit_connection', 'Edit Connection')}>
                                                            <Link to={`/connections/${conn.id}`}><Edit2 /></Link>
                                                        </Button>
                                                    </TooltipTrigger>
                                                    <TooltipContent>{t('connections.edit_connection', 'Edit Connection')}</TooltipContent>
                                                </Tooltip>
                                                <Tooltip>
                                                    <TooltipTrigger asChild>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon-sm"
                                                            className="text-destructive hover:text-destructive"
                                                            onClick={() => setDeleteId(conn.id)}
                                                            aria-label={t('connections.delete_connection', 'Delete Connection')}
                                                        >
                                                            <Trash2 />
                                                        </Button>
                                                    </TooltipTrigger>
                                                    <TooltipContent>{t('connections.delete_connection', 'Delete Connection')}</TooltipContent>
                                                </Tooltip>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>

                    {showPagination && (
                        <div className="flex flex-col items-center justify-between gap-4 border-t px-4 py-3 sm:flex-row">
                            <div className="flex items-center gap-3">
                                <Label htmlFor="connections-per-page" className="text-xs text-muted-foreground">{t('connections.rows_per_page', 'Rows per page:')}</Label>
                                <Select
                                    value={perPage.toString()}
                                    onValueChange={(val) => { setPerPage(Number(val)); setPage(1); }}
                                >
                                    <SelectTrigger id="connections-per-page" size="sm" className="w-20">
                                        <SelectValue placeholder="10" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="10">10</SelectItem>
                                        <SelectItem value="25">25</SelectItem>
                                        <SelectItem value="50">50</SelectItem>
                                        <SelectItem value="100">100</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="flex items-center gap-4">
                                <span className="text-xs text-muted-foreground">
                                    {t('connections.page_of', 'Page {{current}} of {{last}}', { current: currentPage, last: lastPage })}
                                </span>
                                <Pagination className="mx-0 w-auto" aria-label={t('connections.pagination', 'Connection pages')}>
                                    <PaginationContent>
                                        <PaginationItem>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                disabled={currentPage <= 1}
                                                onClick={() => setPage(currentPage - 1)}
                                            >
                                                <ChevronLeft />
                                                {t('common.previous', 'Previous')}
                                            </Button>
                                        </PaginationItem>
                                        <PaginationItem>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                disabled={currentPage >= lastPage}
                                                onClick={() => setPage(currentPage + 1)}
                                            >
                                                {t('common.next', 'Next')}
                                                <ChevronRight />
                                            </Button>
                                        </PaginationItem>
                                    </PaginationContent>
                                </Pagination>
                            </div>
                        </div>
                    )}
                </div>
            )}

            {listPanels.map(panel => {
                const Panel = panel.component;
                return (
                    <Suspense key={panel.id} fallback={null}>
                        <Panel connections={connections} />
                    </Suspense>
                );
            })}

            {/* Single Delete Confirmation */}
            <AlertDialog open={!!deleteId} onOpenChange={(open) => !open && setDeleteId(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{t('connections.dialog_delete_title', 'Delete Connection?')}</AlertDialogTitle>
                        <AlertDialogDescription>
                            {t('connections.dialog_delete_desc', 'Are you sure you want to delete this mailer connection? This action cannot be undone.')}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('common.cancel', 'Cancel')}</AlertDialogCancel>
                        <AlertDialogAction variant="destructive" onClick={confirmDelete}>
                            {t('common.delete', 'Delete')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* Bulk Delete Confirmation */}
            <AlertDialog open={isBulkDeleting} onOpenChange={setIsBulkDeleting}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{t('connections.dialog_bulk_delete_title', 'Delete {{count}} Connections?', { count: selectedIds.length })}</AlertDialogTitle>
                        <AlertDialogDescription>
                            {t('connections.dialog_bulk_delete_desc', 'Are you sure you want to delete the selected mailer connections? This action cannot be undone.')}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('common.cancel', 'Cancel')}</AlertDialogCancel>
                        <AlertDialogAction variant="destructive" onClick={confirmBulkDelete}>
                            {t('connections.delete_all', 'Delete All')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* Test Email Dialog */}
            <Dialog open={testDialogOpen} onOpenChange={setTestDialogOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('connections.test_dialog_title', 'Send Test Email')}</DialogTitle>
                        <DialogDescription>
                            {t('connections.test_dialog_desc', 'Enter a recipient email address. This sends through the selected connection and is recorded in Email Logs.')}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-2">
                        <Label htmlFor="connection-list-test-recipient">{t('connections.recipient_email', 'Recipient Email')}</Label>
                        <Input
                            id="connection-list-test-recipient"
                            type="email"
                            placeholder={t('connections.recipient_placeholder', 'name@example.com')}
                            value={testRecipient}
                            onChange={(e) => setTestRecipient(e.target.value)}
                            disabled={isSending}
                        />
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setTestDialogOpen(false)} disabled={isSending}>
                            {t('common.cancel', 'Cancel')}
                        </Button>
                        <Button onClick={sendTestEmail} disabled={isSending}>
                            {isSending && <Spinner />}
                            {t('connections.send_test_email', 'Send Test Email')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
