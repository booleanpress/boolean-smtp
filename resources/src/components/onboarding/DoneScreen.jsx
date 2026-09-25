import { useEffect } from 'react';
import { Link } from 'react-router';
import { Bell, CheckCircle2, ChevronRight, Layers, ScrollText, Send, TriangleAlert } from 'lucide-react';
import { pluginsPageUrl } from '@/components/migration/importStatus';
import { Spinner } from '@/components/ui/spinner';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Item, ItemActions, ItemContent, ItemDescription, ItemGroup, ItemMedia, ItemTitle } from '@/components/ui/item';
import { cn } from '@/lib/utils';
import SummaryRows from './SummaryRows';
import { useTranslations } from '@/hooks/useTranslations';

/**
 * The screen after Apply: what is now in place and what is worth doing next. Not a rail step.
 *
 * @since 1.0.0
 *
 * @param {object} props
 * @param {{ connection: { id: number, name: string, driver: string }, made_primary: boolean }} props.result Apply response.
 * @param {string} props.providerName
 * @param {{ fromEmail?: string, fromName?: string }} props.sender
 * @param {number} props.retention Days.
 * @param {{ source: string, sourceName: string, sourceActive?: boolean|null, logs: { available: boolean, reason?: string|null, imported?: number, total?: number, done?: boolean, error?: string }|null, onContinueLogs: () => void }|null} [props.migration] The import branch: which plugin was imported, whether it is still active, the log import's progress and how to continue it.
 */
export default function DoneScreen({ result, providerName, sender, retention, migration = null }) {
    const { t } = useTranslations();
    const logs = migration?.logs || null;
    const logsRunning = Boolean(logs && logs.available && !logs.done);

    // The log import continues one chunk at a time while this screen is open; leaving is safe.
    useEffect(() => {
        if (!logsRunning || !migration?.onContinueLogs) return undefined;
        const timer = setTimeout(() => migration.onContinueLogs(), 250);
        return () => clearTimeout(timer);
    }, [logsRunning, logs?.cursor, migration]);

    const next = [
        ...(migration ? [
            // Only a plugin that is still running can take mail away from this connection; an
            // inactive one is already out of the way and gets no warning.
            ...(migration.sourceActive ? [{
                key: 'deactivate',
                icon: TriangleAlert,
                tone: 'warning',
                title: t('onboarding.done_next_deactivate', 'Deactivate {{plugin}}', { plugin: migration.sourceName }),
                description: t('onboarding.done_next_deactivate_desc', 'Two plugins handling wp_mail() is one too many — with the imported connection active, the old plugin can go.'),
                href: pluginsPageUrl(migration.sourceName),
            }] : []),
            ...(logs && logs.available ? [{
                key: 'logs',
                icon: ScrollText,
                title: t('onboarding.done_next_logs', 'View the imported logs'),
                description: t('onboarding.done_next_logs_desc', 'They sit in the email log with the source "Imported: {{plugin}}".', { plugin: migration.sourceName }),
                to: '/logs',
            }] : []),
        ] : []),
        {
            key: 'test',
            icon: Send,
            title: t('onboarding.done_next_test', 'Send a real message'),
            description: t('onboarding.done_next_test_desc', 'The Test Email tool sends through your primary connection with full diagnostics.'),
            to: '/tools/test',
        },
        {
            key: 'alerts',
            icon: Bell,
            title: t('onboarding.done_next_alerts', 'Add Slack, Telegram or Discord alerts'),
            description: t('onboarding.done_next_alerts_desc', 'Get failures where your team already looks.'),
            to: '/settings/notifications',
        },
        {
            key: 'fallback',
            icon: Layers,
            title: t('onboarding.done_next_fallback', 'Add a fallback connection'),
            description: t('onboarding.done_next_fallback_desc', 'A second provider takes over when the first one fails.'),
            to: '/connections/new',
        },
    ];

    // Two cards a row: the warning one takes the whole row, and so does the last card when an odd
    // number would otherwise leave a gap beside it.
    const fullWidth = (next.length - next.filter(item => item.tone === 'warning').length) % 2 === 1;

    return (
        <div className="mx-auto flex max-w-3xl flex-col gap-6">
            <div className="flex flex-col items-center gap-3 text-center">
                <CheckCircle2 className="size-12 text-success" aria-hidden="true" />
                <h1 className="text-xl font-semibold tracking-tight">
                    {t('onboarding.done_title', "You're sending through {{provider}}", { provider: providerName })}
                </h1>
                <p className="text-sm text-muted-foreground">
                    {t('onboarding.done_desc', 'The connection is active and WordPress mail now goes through it.')}
                </p>
            </div>

            <Card className="gap-4 py-5">
                <CardHeader className="gap-0.5">
                    <CardTitle>{t('onboarding.done_applied', 'Applied')}</CardTitle>
                    <CardDescription>{result.connection.name}</CardDescription>
                </CardHeader>
                <CardContent>
                    <SummaryRows
                        rows={[
                            { label: t('onboarding.review_sender', 'Sender'), value: sender?.fromName ? `${sender.fromName} <${sender.fromEmail}>` : sender?.fromEmail },
                            {
                                label: t('onboarding.done_primary', 'Primary connection'),
                                badge: { variant: result.made_primary ? 'success' : 'outline', label: result.made_primary ? t('common.yes', 'Yes') : t('onboarding.done_primary_kept', 'Kept the existing one') },
                            },
                            { label: t('onboarding.done_retention', 'Log retention'), badge: { variant: 'outline', label: t('onboarding.done_retention_days', '{{days}} days', { days: retention }) } },
                            ...(migration ? [{
                                label: t('onboarding.done_imported_logs', 'Logs from {{plugin}}', { plugin: migration.sourceName }),
                                value: !logs
                                    ? t('onboarding.done_imported_logs_skipped', 'Not imported — the box on Review was left unticked; Tools → Migration can import them later')
                                    : !logs.available
                                        ? (logs.reason || t('onboarding.done_imported_logs_none', 'No log to import'))
                                        : logsRunning
                                            ? t('onboarding.done_imported_logs_progress', '{{done}} of {{total}} imported…', { done: logs.imported ?? 0, total: logs.total ?? 0 })
                                            : t('onboarding.done_imported_logs_done', '{{done}} imported', { done: logs.imported ?? 0 }),
                                badge: logsRunning ? undefined : (logs && logs.available ? { variant: 'success', label: t('onboarding.done_imported_logs_badge', 'Done') } : undefined),
                                // The value is a sentence when nothing was imported, not a count.
                                wrap: true,
                            }] : []),
                        ]}
                    />
                </CardContent>
            </Card>

            {logsRunning && (
                <p className="flex items-center justify-center gap-2 text-sm text-muted-foreground" aria-live="polite" data-testid="done-logs-progress">
                    <Spinner className="size-4" />
                    {t('onboarding.done_logs_running', 'Importing logs from {{plugin}} — {{done}} of {{total}}. You can leave; it resumes next time.', { plugin: migration.sourceName, done: logs.imported ?? 0, total: logs.total ?? 0 })}
                </p>
            )}

            <Card className="gap-4 py-5">
                <CardHeader className="gap-0.5">
                    <CardTitle>{t('onboarding.done_next_title', 'Worth doing next')}</CardTitle>
                </CardHeader>
                <CardContent>
                    <ItemGroup className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        {next.map((item, index) => {
                            const Icon = item.icon;
                            const warn = item.tone === 'warning';
                            const body = (
                                <>
                                    <ItemMedia variant="icon" className={cn(warn && 'border-warning/40 bg-warning/10 text-warning')}><Icon /></ItemMedia>
                                    <ItemContent>
                                        <ItemTitle>{item.title}</ItemTitle>
                                        <ItemDescription className="line-clamp-none">{item.description}</ItemDescription>
                                    </ItemContent>
                                    <ItemActions><ChevronRight className="size-4 text-muted-foreground" aria-hidden="true" /></ItemActions>
                                </>
                            );
                            return (
                                <Item
                                    key={item.key}
                                    variant="outline"
                                    size="sm"
                                    className={cn(
                                        'items-start',
                                        (warn || (fullWidth && index === next.length - 1)) && 'sm:col-span-2',
                                        warn && 'border-warning/40 bg-warning/10'
                                    )}
                                    asChild
                                >
                                    {item.href
                                        ? <a href={item.href} target="_blank" rel="noopener noreferrer">{body}</a>
                                        : <Link to={item.to}>{body}</Link>}
                                </Item>
                            );
                        })}
                    </ItemGroup>
                </CardContent>
            </Card>

            <div className="flex justify-center">
                <Button asChild size="lg">
                    <Link to="/">{t('onboarding.done_dashboard', 'Go to the dashboard')}</Link>
                </Button>
            </div>
        </div>
    );
}
