import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router';
import { Download, Package, RefreshCw, ScrollText } from 'lucide-react';
import { toast } from 'sonner';
import api from '../../services/api';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardAction, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useTranslations } from '@/hooks/useTranslations';
import { MAIL_PROVIDERS } from '@/config/mailers';
import { importOutcomeBadge, importStatusBadge, importStatusHint, importSummary, pluginsPageUrl } from './importStatus';
import SenderConflictChoices, { migrationConflicts, senderOutcomeText } from './SenderConflictChoices';
import { TABLE_HEADER_CLASS } from '@/lib/table';
import { formatRelativeTime } from '@/lib/dates';

/**
 * Tools → Migration: every other SMTP plugin found on the site, the assessment of what each
 * one holds, an import of its connections as inactive drafts, and its email log imported in
 * chunks with progress. Nothing is written to the other plugin; a re-run updates the same
 * drafts and skips the log rows already present. A plugin imported before says so, in the list
 * and on its card, and importing it again asks first.
 *
 * @since 1.0.0
 *
 * @param {object} props
 * @param {string} [props.className]
 */
export default function MigrationDiscoveryFlow({ className }) {
    const { t } = useTranslations();
    const [scan, setScan] = useState({ status: 'loading', sources: [] });
    const [selected, setSelected] = useState(null);
    const [assessment, setAssessment] = useState(null);
    const [assessing, setAssessing] = useState(false);
    const [importing, setImporting] = useState(false);
    const [logs, setLogs] = useState(null);
    const [logsRunning, setLogsRunning] = useState(false);
    const [resolutions, setResolutions] = useState({});
    const [confirmAgain, setConfirmAgain] = useState(false);
    const locale = window.BooleanSmtpAdmin?.locale;

    // Keep the plugin list's "imported" note in step with the latest run.
    const rememberLastImport = useCallback((slug, lastImport) => {
        if (lastImport === undefined) return;
        setScan(prev => ({ ...prev, sources: prev.sources.map(source => (source.slug === slug ? { ...source, last_import: lastImport } : source)) }));
    }, []);

    const loadScan = useCallback(async () => {
        setScan(prev => ({ ...prev, status: 'loading' }));
        try {
            const res = await api.get('tools/migration/scan');
            const raw = res?.data || {};
            setScan({ status: 'done', sources: Array.isArray(raw) ? raw : Object.values(raw) });
        } catch (err) {
            // A failed rescan keeps the plugins already listed; the error shows above them.
            setScan(prev => ({ ...prev, status: 'error', error: err?.message || '' }));
        }
    }, []);

    useEffect(() => {
        loadScan();
    }, [loadScan]);

    const assess = useCallback(async (source) => {
        setSelected(source);
        setAssessment(null);
        setLogs(null);
        setResolutions({});
        setAssessing(true);
        try {
            const res = await api.post('tools/migration/import', { source: source.slug, dry_run: true, import_logs: true });
            setAssessment(res?.data || null);
        } catch (err) {
            toast.error(err?.message || t('migration.assess_failed', 'The plugin could not be assessed.'));
            setSelected(null);
        } finally {
            setAssessing(false);
        }
    }, [t]);

    const importConnections = useCallback(async () => {
        if (!selected) return;
        setImporting(true);
        try {
            const res = await api.post('tools/migration/import', { source: selected.slug, resolutions });
            const data = res?.data || {};
            setAssessment(prev => (prev ? { ...prev, connections: data.connections || prev.connections, last_import: data.last_import ?? prev.last_import } : data));
            rememberLastImport(selected.slug, data.last_import);
            setResolutions({});
            toast.success(importSummary(t, data.connections || []));
        } catch (err) {
            toast.error(err?.message || t('migration.import_failed', 'The import did not run.'));
        } finally {
            setImporting(false);
        }
    }, [selected, resolutions, t, rememberLastImport]);

    const importLogs = useCallback(async (restart = false) => {
        if (!selected) return;
        setLogsRunning(true);
        try {
            let progress = null;
            let first = true;
            do {
                const res = await api.post('tools/migration/import', { source: selected.slug, import_connections: false, import_logs: true, restart_logs: first && restart });
                progress = res?.data?.logs || { done: true };
                first = false;
                setLogs(progress);
                rememberLastImport(selected.slug, res?.data?.last_import);
                setAssessment(prev => (prev && res?.data?.last_import !== undefined ? { ...prev, last_import: res.data.last_import } : prev));
            } while (progress && progress.available && !progress.done);
        } catch (err) {
            toast.error(err?.message || t('migration.logs_failed', 'The log import stopped.'));
        } finally {
            setLogsRunning(false);
        }
    }, [selected, t, rememberLastImport]);

    const driverName = (driver) => {
        const provider = MAIL_PROVIDERS.find(p => p.driver === driver);
        return provider ? t(`mailers.${provider.driver}.name`, provider.name) : '—';
    };

    const importable = (assessment?.connections || []).filter(c => c.status !== 'unsupported');
    const logsOffer = assessment?.logs || null;
    const lastImport = assessment?.last_import?.connections_at ? assessment.last_import : null;
    const lastImportWhen = lastImport ? formatRelativeTime(lastImport.connections_at, locale) : '';
    const logsImportedBefore = Boolean(assessment?.last_import?.logs_at);

    return (
        <div className={className}>
            <div className="flex flex-col gap-6">
                {scan.status === 'error' && (
                    <Alert variant="destructive">
                        <AlertTitle>{t('migration.scan_failed', 'The scan did not run')}</AlertTitle>
                        <AlertDescription>{scan.error}</AlertDescription>
                    </Alert>
                )}

                {scan.status === 'done' && scan.sources.length === 0 && (
                    <Empty className="border border-dashed bg-muted/20">
                        <EmptyHeader>
                            <EmptyMedia variant="icon"><Package /></EmptyMedia>
                            <EmptyTitle>{t('migration.none_title', 'No other SMTP plugin was found')}</EmptyTitle>
                            <EmptyDescription>{t('migration.none_desc', 'The import looks for the settings other SMTP plugins leave on this site, whether they are still installed or not.')}</EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>
                            <Button variant="outline" onClick={loadScan}><RefreshCw />{t('migration.rescan', 'Scan again')}</Button>
                        </EmptyContent>
                    </Empty>
                )}

                {scan.sources.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('migration.sources_title', 'SMTP plugins on this site')}</CardTitle>
                            <CardDescription>{t('migration.sources_desc', 'Assess a plugin to see what its connections map to before anything is written.')}</CardDescription>
                            <CardAction>
                                <Button variant="outline" size="sm" onClick={loadScan} disabled={scan.status === 'loading'} aria-busy={scan.status === 'loading'}>
                                    <RefreshCw />{t('migration.rescan', 'Scan again')}
                                </Button>
                            </CardAction>
                        </CardHeader>
                        <CardContent>
                            <Table aria-label={t('migration.sources_title', 'SMTP plugins on this site')}>
                                <TableHeader className={TABLE_HEADER_CLASS}>
                                    <TableRow>
                                        <TableHead>{t('migration.col_plugin', 'Plugin')}</TableHead>
                                        <TableHead>{t('migration.col_state', 'State')}</TableHead>
                                        <TableHead>{t('migration.col_found', 'Found')}</TableHead>
                                        <TableHead className="text-right">{t('migration.col_action', 'Action')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {scan.sources.map(source => {
                                        const state = source.is_active
                                            ? { variant: 'success', label: t('onboarding.source_active', 'Active plugin') }
                                            : source.is_installed
                                                ? { variant: 'outline', label: t('onboarding.source_installed', 'Installed, inactive') }
                                                : { variant: 'outline', label: t('onboarding.source_ghost', 'Settings left behind') };
                                        const count = source.preview_connections ?? source.details?.connection_count;
                                        const logCount = source.preview_logs ?? source.details?.log_count;
                                        return (
                                            <TableRow key={source.slug} data-state={selected?.slug === source.slug ? 'selected' : undefined}>
                                                <TableCell className="font-medium">{source.name}</TableCell>
                                                <TableCell><Badge variant={state.variant}>{state.label}</Badge></TableCell>
                                                <TableCell className="text-muted-foreground">
                                                    {typeof count === 'number' ? t('onboarding.source_connections', '{{count}} connection(s) found', { count }) : t('onboarding.source_settings_found', 'Settings found')}
                                                    {source.logs_available === false
                                                        ? ` · ${t('onboarding.source_no_logs', 'no email log in the free edition')}`
                                                        : (typeof logCount === 'number' ? ` · ${t('onboarding.source_logs', '{{count}} log(s)', { count: logCount })}` : '')}
                                                    {source.last_import?.connections_at && (
                                                        <span className="ml-2 inline-flex">
                                                            <Badge variant="outline">{t('migration.source_imported', 'Imported {{when}}', { when: formatRelativeTime(source.last_import.connections_at, locale) })}</Badge>
                                                        </span>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {source.supported ? (
                                                        <Button size="sm" variant={selected?.slug === source.slug ? 'default' : 'outline'} onClick={() => assess(source)} disabled={assessing}>
                                                            {assessing && selected?.slug === source.slug ? <Spinner /> : null}
                                                            {t('migration.assess', 'Assess')}
                                                        </Button>
                                                    ) : (
                                                        <Badge variant="outline">{t('onboarding.source_unsupported', 'Not supported yet')}</Badge>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}

                {selected && assessment && (
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('migration.assessment_title', 'What {{plugin}} holds', { plugin: selected.name })}</CardTitle>
                            <CardDescription>{t('migration.assessment_desc', 'Every connection becomes an inactive draft with its credentials, unless the row says otherwise. Nothing is switched on.')}</CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <Table aria-label={t('migration.assessment_title', 'What {{plugin}} holds', { plugin: selected.name })}>
                                <TableHeader className={TABLE_HEADER_CLASS}>
                                    <TableRow>
                                        <TableHead>{t('migration.col_connection', 'Source connection')}</TableHead>
                                        <TableHead>{t('migration.col_target', 'Target')}</TableHead>
                                        <TableHead>{t('migration.col_status', 'Status')}</TableHead>
                                        <TableHead>{t('migration.col_note', 'Note')}</TableHead>
                                        <TableHead className="text-right">{t('migration.col_draft', 'Draft')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {(assessment.connections || []).map(connection => {
                                        const badge = importOutcomeBadge(t, connection.outcome) || importStatusBadge(t, connection.status, connection.conversion);
                                        return (
                                            <TableRow key={connection.source_key}>
                                                <TableCell className="font-medium">
                                                    {connection.name}
                                                    {connection.was_default && <span className="ml-2 text-xs text-muted-foreground">{t('migration.was_default', 'default')}</span>}
                                                </TableCell>
                                                <TableCell>{driverName(connection.driver)}</TableCell>
                                                <TableCell><Badge variant={badge.variant}>{badge.label}</Badge></TableCell>
                                                <TableCell className="max-w-md text-muted-foreground">{senderOutcomeText(t, connection) || importStatusHint(t, connection)}</TableCell>
                                                <TableCell className="text-right">
                                                    {connection.connection_id
                                                        ? <Button variant="link" size="sm" className="h-auto p-0" asChild><Link to={`/connections/${connection.connection_id}`}>#{connection.connection_id}</Link></Button>
                                                        : <span className="text-muted-foreground">—</span>}
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>

                            {lastImport && (
                                <Alert data-testid="migration-imported-before">
                                    <AlertTitle>{t('migration.imported_before_title', '{{plugin}} was imported {{when}}', { plugin: selected.name, when: lastImportWhen })}</AlertTitle>
                                    <AlertDescription>
                                        {t('migration.imported_before_desc', 'That import made {{imported}} new draft(s), replaced {{replaced}} and kept {{kept}} of your connections. Importing again updates those drafts instead of adding copies.', { imported: lastImport.imported, replaced: lastImport.replaced, kept: lastImport.kept })}
                                    </AlertDescription>
                                </Alert>
                            )}

                            <SenderConflictChoices
                                conflicts={migrationConflicts(assessment.connections)}
                                value={resolutions}
                                onChange={(key, answer) => setResolutions(prev => ({ ...prev, [key]: answer }))}
                            />

                            <div className="flex flex-col gap-2 rounded-lg border bg-muted/30 p-4" data-testid="migration-logs">
                                <p className="flex items-center gap-2 text-sm font-medium"><ScrollText className="size-4 text-muted-foreground" aria-hidden="true" />{t('migration.logs_title', 'Email log')}</p>
                                {!logsOffer?.available ? (
                                    <p className="text-sm text-muted-foreground">{logsOffer?.reason || t('migration.logs_none', 'No email log to import.')}</p>
                                ) : (
                                    <>
                                        <p className="text-sm text-muted-foreground">
                                            {t('migration.logs_offer', '{{count}} rows are within your retention window ({{rows}} in total); attachments are listed, not copied. Rows already imported are skipped.', { count: logsOffer.total ?? 0, rows: logsOffer.rows_in_window ?? logsOffer.total ?? 0 })}
                                        </p>
                                        {logs && logs.available && (
                                            <div className="flex flex-col gap-1.5" aria-live="polite">
                                                <Progress value={logs.total ? Math.min(100, Math.round(((logs.imported ?? 0) / logs.total) * 100)) : 100} />
                                                <p className="text-xs text-muted-foreground">
                                                    {logs.done
                                                        ? t('migration.logs_done', 'Done: {{done}} imported, {{skipped}} skipped.', { done: logs.imported ?? 0, skipped: Object.values(logs.skipped || {}).reduce((a, b) => a + Number(b || 0), 0) })
                                                        : t('migration.logs_progress', '{{done}} of {{total}} imported…', { done: logs.imported ?? 0, total: logs.total ?? 0 })}
                                                </p>
                                            </div>
                                        )}
                                    </>
                                )}
                            </div>
                        </CardContent>
                        <CardFooter className="flex-wrap gap-3">
                            {lastImport ? (
                                <Button variant="outline" onClick={() => setConfirmAgain(true)} disabled={importing || importable.length === 0} aria-busy={importing}>
                                    {importing ? <Spinner /> : <Download />}
                                    {t('migration.import_again', 'Import again…')}
                                </Button>
                            ) : (
                                <Button onClick={importConnections} disabled={importing || importable.length === 0} aria-busy={importing}>
                                    {importing ? <Spinner /> : <Download />}
                                    {t('migration.import_drafts', 'Import as inactive drafts')}
                                </Button>
                            )}
                            {logsOffer?.available && (
                                <Button variant="outline" onClick={() => importLogs(false)} disabled={logsRunning} aria-busy={logsRunning}>
                                    {logsRunning ? <Spinner /> : <ScrollText />}
                                    {(logs && logs.done) || logsImportedBefore ? t('migration.import_logs_again', 'Import logs again') : t('migration.import_logs', 'Import logs')}
                                </Button>
                            )}
                            {logsOffer?.available && logs && !logs.done && !logsRunning && (
                                <Button variant="ghost" onClick={() => importLogs(true)}>{t('migration.restart_logs', 'Start the log import over')}</Button>
                            )}
                            {selected.is_active && (
                                <Button variant="link" size="sm" className="ml-auto h-auto p-0" asChild>
                                    <a href={pluginsPageUrl(selected.name)} target="_blank" rel="noopener noreferrer">{t('migration.deactivate', 'Deactivate {{plugin}}', { plugin: selected.name })}</a>
                                </Button>
                            )}
                        </CardFooter>
                    </Card>
                )}
            </div>

            <AlertDialog open={confirmAgain} onOpenChange={setConfirmAgain}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{t('migration.import_again_title', 'Import {{plugin}} again?', { plugin: selected?.name || '' })}</AlertDialogTitle>
                        <AlertDialogDescription>
                            {t('migration.import_again_desc', 'Its connections were imported {{when}}. Importing again updates the drafts that import made instead of adding copies, and follows your Keep or Replace answers above for every sender this site already has.', { when: lastImportWhen })}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('common.cancel', 'Cancel')}</AlertDialogCancel>
                        <AlertDialogAction onClick={() => { setConfirmAgain(false); importConnections(); }}>{t('migration.import_again_action', 'Import again')}</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
