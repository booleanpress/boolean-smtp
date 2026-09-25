import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router';
import { MailCheck, ShieldCheck } from 'lucide-react';

import api from '@/services/api';
import StatusBadge from '@/components/StatusBadge';
import { Button } from '@/components/ui/button';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { SectionCard } from '@/components/section-card';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { formatDateRange, formatRelativeTime, toISODate } from '@/lib/dates';
import { useTranslations } from '@/hooks/useTranslations';

const LIMIT = 5;
const TABS = ['failed', 'delivered'];

/**
 * The dashboard's activity card: the latest failed and delivered messages of the selected
 * period, one tab each. Both lists load together, so switching tabs never waits; a new period
 * keeps the current rows on screen until the new ones arrive.
 *
 * @since 1.0.0
 *
 * @param {object} props
 * @param {{ from: Date, to: Date }} props.range The dashboard's selected period.
 */
export default function RecentActivity({ range }) {
    const { t, locale } = useTranslations();
    const [tab, setTab] = useState('failed');
    const [entries, setEntries] = useState(null);
    const [loadedFor, setLoadedFor] = useState(null);
    const latestRequestRef = useRef(0);
    const from = toISODate(range.from);
    const to = toISODate(range.to);
    const rangeKey = `${from}|${to}`;
    // Rows from the previous period stay on screen, marked busy, until the new ones arrive.
    const busy = entries !== null && loadedFor !== rangeKey;

    useEffect(() => {
        const requestId = ++latestRequestRef.current;
        const query = { from, to, limit: LIMIT };
        Promise.all(TABS.map(status => api.get('dashboard/activity', { ...query, status })))
            .then(responses => {
                if (requestId !== latestRequestRef.current) return;
                setEntries(Object.fromEntries(TABS.map((status, i) => [status, Array.isArray(responses[i]?.data) ? responses[i].data : []])));
                setLoadedFor(`${from}|${to}`);
            })
            .catch(err => {
                if (requestId !== latestRequestRef.current) return;
                console.error('Recent activity load error:', err);
                setEntries(prev => prev || { failed: [], delivered: [] });
                setLoadedFor(`${from}|${to}`);
            });
    }, [from, to]);

    const labels = {
        failed: t('dashboard.activity_failed', 'Failed'),
        delivered: t('dashboard.activity_delivered', 'Delivered'),
    };
    const empty = {
        failed: {
            icon: <ShieldCheck className="text-success" />,
            title: t('dashboard.system_healthy', 'System Healthy'),
            description: t('dashboard.no_failures', 'No failed deliveries in this period.'),
        },
        delivered: {
            icon: <MailCheck />,
            title: t('dashboard.activity_none_delivered', 'Nothing delivered yet'),
            description: t('dashboard.activity_none_delivered_desc', 'No messages were delivered in this period.'),
        },
    };

    return (
        <SectionCard
            title={t('dashboard.recent_activity', 'Recent activity')}
            description={t('dashboard.activity_subtitle', 'Latest entries · {{range}}', { range: formatDateRange(range.from, range.to, locale, { month: 'short', day: 'numeric' }) })}
            footer={(
                <Button variant="outline" size="sm" className="w-full" asChild>
                    <Link to="/logs">{t('dashboard.view_all_logs', 'View all')}</Link>
                </Button>
            )}
            contentClassName="p-0 sm:p-0"
        >
            <Tabs value={tab} onValueChange={setTab} className="gap-0">
                <TabsList variant="line" className="h-11 w-full justify-start gap-5 rounded-none border-b px-4 sm:px-5">
                    {TABS.map(status => (
                        <TabsTrigger key={status} value={status} className="flex-none px-0">
                            {labels[status]}
                        </TabsTrigger>
                    ))}
                </TabsList>
                {TABS.map(status => (
                    <TabsContent key={status} value={status} aria-busy={busy}>
                        {entries === null ? (
                            <div className="space-y-4 px-4 py-4 sm:px-5" data-testid="recent-activity-skeleton">
                                {[0, 1, 2].map(i => (
                                    <div key={i} className="space-y-2">
                                        <Skeleton className="h-4 w-3/4" />
                                        <Skeleton className="h-3 w-1/2" />
                                    </div>
                                ))}
                            </div>
                        ) : entries[status].length === 0 ? (
                            <Empty className="gap-3 border-0 p-4 md:p-6">
                                <EmptyHeader>
                                    <EmptyMedia variant="icon">{empty[status].icon}</EmptyMedia>
                                    <EmptyTitle>{empty[status].title}</EmptyTitle>
                                    <EmptyDescription>{empty[status].description}</EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        ) : (
                            <ul className="divide-y">
                                {entries[status].map(entry => (
                                    <li key={entry.id}>
                                        <Link
                                            to={`/logs/${entry.id}`}
                                            className="block px-4 py-3 outline-none hover:bg-muted/50 focus-visible:bg-muted/50 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:ring-inset sm:px-5"
                                        >
                                            <span className="flex min-w-0 items-center gap-2">
                                                <span className="truncate font-medium">{entry.subject || t('dashboard.no_subject', '(No subject)')}</span>
                                                <StatusBadge status={entry.status} className="shrink-0" />
                                            </span>
                                            <span className="mt-0.5 block truncate text-sm text-muted-foreground">
                                                {[entry.to, formatRelativeTime(entry.created_at, locale)].filter(Boolean).join(' · ')}
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </TabsContent>
                ))}
            </Tabs>
        </SectionCard>
    );
}
