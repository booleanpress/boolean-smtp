import { useState, useEffect, useCallback, useMemo, useRef, lazy, Suspense } from 'react';
import { Link, useNavigate } from 'react-router';
import {
    ShieldCheck, Mail, CheckCircle2, XCircle, Zap, Activity, ChevronRight, X,
} from 'lucide-react';
import { toast } from 'sonner';

import api from '@/services/api';
import StatGroup from '@/components/StatGroup';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Item, ItemActions, ItemContent, ItemGroup, ItemMedia, ItemTitle } from '@/components/ui/item';
import { SectionCard } from '@/components/section-card';
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
import { Progress } from '@/components/ui/progress';
import { DateRangePicker } from '@/components/DateRangePicker';
import { daysInRange, formatDateRange, lastDays, toISODate } from '@/lib/dates';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { onboardingSteps, onboardingStepPath, isOnboardingStepComplete, isOnboardingComplete } from '@/config/onboardingSteps';
import DashboardSkeleton from '@/components/skeletons/DashboardSkeleton';

// Recharts is ~110 KB gzipped; stream it in behind a skeleton instead of blocking first paint.
const TrafficChart = lazy(() => import('@/components/TrafficChart'));
import ActivityHeatmap from '@/components/ActivityHeatmap';
import ActiveSmtpPluginWarning from '@/components/migration/ActiveSmtpPluginWarning';
import RecentActivity from '@/components/RecentActivity';
import { useTranslations } from '@/hooks/useTranslations';
import { useExtensions } from '@/hooks/useExtensions';
import { useAdminExtensions } from '@/hooks/useAdminExtensions';

const EMPTY_STATS = { email: {}, connections: {}, primary_connection: null, fallback_connection: null, heatmap: [] };
const REVIEW_DISMISSED_STORAGE_KEY = 'boolean-smtp.dashboard.review-dismissed';

function OnboardingChecklist({ onboarding, onToggle }) {
    const { t } = useTranslations();
    const completed = onboardingSteps.filter(step => isOnboardingStepComplete(step.id, onboarding)).length;
    const progress = Math.round((completed / onboardingSteps.length) * 100);

    return (
        <SectionCard
            title={(
                <span className="flex items-center gap-2">
                    <Zap className="size-4 text-muted-foreground" />
                    {t('dashboard.quick_setup', 'Quick Setup')}
                </span>
            )}
            action={<Badge variant="outline">{completed}/{onboardingSteps.length}</Badge>}
            contentClassName="space-y-4"
        >
            <Progress value={progress} aria-label={t('dashboard.quick_setup_progress_label', 'Setup progress')} />
            <ItemGroup>
                {onboardingSteps.map(step => {
                    const StepIcon = step.icon;
                    const complete = isOnboardingStepComplete(step.id, onboarding);
                    const title = t(step.titleKey, step.title);
                    const checkboxId = `onboarding-${step.id}`;
                    return (
                        <Item key={step.id} size="sm" className={cn('px-0', complete && 'opacity-60')}>
                            <ItemMedia>
                                <Checkbox
                                    id={checkboxId}
                                    checked={complete}
                                    onCheckedChange={() => onToggle(step.id)}
                                    aria-label={t('dashboard.toggle_step', 'Mark "{{title}}" as {{state}}', { title, state: complete ? t('dashboard.incomplete', 'incomplete') : t('dashboard.complete', 'complete') })}
                                />
                            </ItemMedia>
                            <ItemContent>
                                <ItemTitle>
                                    <Label htmlFor={checkboxId} className={cn('font-medium', complete && 'line-through')}>
                                        <StepIcon className="size-3.5 text-muted-foreground" />
                                        {title}
                                    </Label>
                                </ItemTitle>
                            </ItemContent>
                            <ItemActions>
                                <Button asChild variant="ghost" size="icon-xs" disabled={complete} aria-label={t('dashboard.open_step', 'Open {{title}}', { title })}>
                                    <Link to={onboardingStepPath(step.id, onboarding.draft_connection_id)}><ChevronRight /></Link>
                                </Button>
                            </ItemActions>
                        </Item>
                    );
                })}
            </ItemGroup>
        </SectionCard>
    );
}

function Greeting() {
    const { t } = useTranslations();
    const admin = window.BooleanSmtpAdmin || {};
    const user = admin.user || {};
    const email = user.user_email || admin.currentUserEmail || '';
    const name = (user.first_name || user.display_name || '').trim() || email.split('@')[0] || '';
    const site = (admin.siteName || '').trim();
    const initials = (name || email).split(/[\s._-]+/).filter(Boolean).slice(0, 2).map(part => part[0].toUpperCase()).join('') || 'W';

    const greeting = name
        ? t('dashboard.greeting_named', 'Good to see you, {{name}}', { name })
        : t('dashboard.greeting_generic', 'Good to see you');
    const subtitle = site
        ? t('dashboard.greeting_subtitle_site', 'Keep an eye on your entire WordPress email delivery for {{site}}.', { site })
        : t('dashboard.greeting_subtitle', 'Keep an eye on your entire WordPress email delivery.');

    return (
        <div className="flex items-center gap-3">
            <Avatar className="size-10 rounded-lg border bg-muted">
                {user.avatar_url && <AvatarImage src={user.avatar_url} alt="" className="rounded-lg object-cover" />}
                <AvatarFallback className="rounded-lg bg-muted text-sm font-semibold text-muted-foreground">{initials}</AvatarFallback>
            </Avatar>
            <div className="min-w-0 space-y-1">
                <h1 className="truncate text-xl font-semibold tracking-tight">{greeting}</h1>
                <p className="text-sm text-muted-foreground">{subtitle}</p>
            </div>
        </div>
    );
}

function ExtensionDashboardWidgets() {
    const { widgets: registeredWidgets } = useExtensions();
    const widgets = useMemo(() => registeredWidgets.map(widget => ({ id: widget.id, Component: lazy(widget.component) })), [registeredWidgets]);

    if (widgets.length === 0) return null;

    return (
        <>
            {widgets.map(({ id, Component }) => (
                <Suspense key={id} fallback={null}>
                    <Component />
                </Suspense>
            ))}
        </>
    );
}

/**
 * Cards other plugins add through the `boolean_smtp_dashboard_cards` filter: a title, an
 * optional description and a link. Rendered only when at least one card exists.
 *
 * @since 1.0.0
 */
function ExtensionCards() {
    const { t } = useTranslations();
    const { dashboardCards } = useAdminExtensions();

    if (dashboardCards.length === 0) return null;

    return (
        <SectionCard title={t('dashboard.extensions', 'Extensions')}>
            <ItemGroup>
                {dashboardCards.map(card => (
                    <Item key={card.id} asChild>
                        <a href={card.url}>
                            <ItemContent>
                                <ItemTitle>{card.title}</ItemTitle>
                                {card.description && <p className="text-sm text-muted-foreground">{card.description}</p>}
                            </ItemContent>
                            <ItemActions>
                                <ChevronRight className="size-4 text-muted-foreground" />
                            </ItemActions>
                        </a>
                    </Item>
                ))}
            </ItemGroup>
        </SectionCard>
    );
}

export default function Dashboard() {
    const navigate = useNavigate();
    const { t, locale } = useTranslations();
    const [stats, setStats] = useState(EMPTY_STATS);
    const [chart, setChart] = useState([]);
    const [onboarding, setOnboarding] = useState({});
    const [loading, setLoading] = useState(true);
    const [range, setRange] = useState(() => lastDays(7));
    const [reviewDismissed, setReviewDismissed] = useState(() => {
        try {
            return window.localStorage.getItem(REVIEW_DISMISSED_STORAGE_KEY) === '1';
        } catch {
            return false;
        }
    });
    const [reviewDismissDialogOpen, setReviewDismissDialogOpen] = useState(false);

    const allOnboardingFinished = isOnboardingComplete(onboarding);
    const latestLoadRef = useRef(0);

    const loadData = useCallback(async period => {
        const requestId = latestLoadRef.current + 1;
        latestLoadRef.current = requestId;
        const query = { from: toISODate(period.from), to: toISODate(period.to) };
        try {
            const [statsRes, chartRes] = await Promise.all([
                api.get('dashboard/stats', query),
                api.get('dashboard/chart', query),
            ]);

            if (requestId !== latestLoadRef.current) return;

            setStats(statsRes.data || EMPTY_STATS);
            setChart(Array.isArray(chartRes.data) ? chartRes.data : []);

            const onboardingRes = await api.get('dashboard/onboarding');

            if (requestId !== latestLoadRef.current) return;

            setOnboarding(onboardingRes.data || {});
        } catch (err) {
            if (requestId !== latestLoadRef.current) return;
            console.error('Dashboard load error:', err);
            toast.error(t('dashboard.load_failed', 'Failed to load dashboard data'));
        } finally {
            if (requestId === latestLoadRef.current) {
                setLoading(false);
            }
        }
    }, [t]);

    // First load shows the skeleton; later period changes keep the existing chart visible until the new data arrives.
    useEffect(() => {
        loadData(range);
    }, [range, loadData]);

    async function toggleOnboarding(id) {
        // The checklist's Verify row is the "a test email was accepted" flag the wizard and the
        // Test Email tool both set; every other row is the wizard step of the same name.
        const key = id === 'verify' ? 'send_test' : id;
        const next = { ...onboarding, [key]: !isOnboardingStepComplete(id, onboarding) };
        setOnboarding(next);
        try {
            const res = await api.post('dashboard/onboarding', { onboarding: next });
            if (res?.data) setOnboarding(res.data);
        } catch (err) {
            console.error('Failed to update onboarding:', err);
            toast.error(t('dashboard.update_failed', 'Failed to update progress'));
        }
    }

    function dismissReviewCard() {
        setReviewDismissed(true);
        setReviewDismissDialogOpen(false);
        try {
            window.localStorage.setItem(REVIEW_DISMISSED_STORAGE_KEY, '1');
        } catch {
            // Dismissal still applies for the current render when storage is unavailable.
        }
    }

    if (loading) {
        return <DashboardSkeleton />;
    }

    const email = stats?.email || {};
    const primary = stats?.primary_connection;
    const fallback = stats?.fallback_connection;

    // KPI derivations (client-side; the API only sends the three counters).
    const totalSent = Number(email.total_sent) || 0;
    const delivered = Number(email.delivered) || 0;
    const failed = Number(email.failed) || 0;
    const periodDays = daysInRange(range.from, range.to);
    const conn = stats?.connections || {};
    const connHealthy = Number(conn.healthy) || 0;

    return (
        <div className="space-y-6">
            <Greeting />
            <ActiveSmtpPluginWarning hasPrimary={Boolean(primary)} />
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-4">
                <div className="@container space-y-6 lg:col-span-3">
                    <SectionCard
                        title={t('dashboard.delivery', 'Delivery')}
                        description={t('dashboard.delivery_desc', 'Totals for {{range}}. The period applies to every block on this page.', { range: formatDateRange(range.from, range.to, locale) })}
                        action={<DateRangePicker value={range} onChange={setRange} />}
                        contentClassName="p-0 sm:p-0"
                    >
                        <StatGroup
                            items={[
                                { id: 'total_sent', label: t('dashboard.total_sent', 'Total Sent'), value: totalSent.toLocaleString(), icon: <Mail /> },
                                { id: 'delivered', label: t('dashboard.delivered', 'Delivered'), value: delivered.toLocaleString(), icon: <CheckCircle2 /> },
                                { id: 'failed', label: t('dashboard.failed', 'Failed'), value: failed.toLocaleString(), icon: <XCircle /> },
                                { id: 'healthy_connections', label: t('dashboard.healthy_connections', 'Healthy Connections'), value: connHealthy.toLocaleString(), icon: <ShieldCheck /> },
                            ]}
                        />
                    </SectionCard>

                    <SectionCard
                        title={t('dashboard.traffic_volume', 'Email Traffic Volume')}
                        description={t('dashboard.traffic_volume_range_desc', 'Daily email volume, {{range}} ({{count}} days).', { range: formatDateRange(range.from, range.to, locale), count: periodDays })}
                    >
                        <Suspense fallback={<Skeleton className="h-64 w-full" />}>
                            <TrafficChart data={chart} />
                        </Suspense>
                    </SectionCard>

                    <ActivityHeatmap data={stats?.heatmap || []} />

                    <ExtensionDashboardWidgets />

                    <ExtensionCards />
                </div>

                <div className="space-y-6 lg:col-span-1">
                    {!allOnboardingFinished && <OnboardingChecklist onboarding={onboarding} onToggle={toggleOnboarding} />}
                    {primary && (
                        <SectionCard
                            title={(
                                <span className="flex items-center gap-2">
                                    <Activity className="size-4 text-muted-foreground" />
                                    {t('dashboard.system_status', 'System Status')}
                                </span>
                            )}
                            action={(
                                <span
                                    className="relative flex size-3"
                                    role="status"
                                    aria-label={t('dashboard.healthy', 'Healthy')}
                                >
                                    <span
                                        className="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-75"
                                        style={{ animationDuration: '4s' }}
                                    />
                                    <span className="relative inline-flex size-3 rounded-full bg-success" />
                                </span>
                            )}
                            footer={(
                                <Button variant="outline" size="sm" className="w-full" onClick={() => navigate('/connections')}>
                                    {t('dashboard.connection_manager', 'Connection Manager')}
                                </Button>
                            )}
                        >
                            <div className="space-y-4">
                                <div>
                                    <p className="text-xs text-muted-foreground">{t('dashboard.primary_connection', 'Primary Connection')}</p>
                                    <p className="truncate font-medium">{primary.name}</p>
                                    <p className="truncate text-sm text-muted-foreground">
                                        {primary.sender || t('dashboard.sender_not_configured', 'Sender not configured')}
                                    </p>
                                </div>

                                {fallback && (
                                    <div className="border-t pt-4">
                                        <p className="text-xs text-muted-foreground">{t('dashboard.fallback_connection', 'Fallback Connection')}</p>
                                        <p className="truncate font-medium">{fallback.name}</p>
                                        <p className="truncate text-sm text-muted-foreground">
                                            {fallback.sender || t('dashboard.sender_not_configured', 'Sender not configured')}
                                        </p>
                                    </div>
                                )}
                            </div>
                        </SectionCard>
                    )}
                    <RecentActivity range={range} />
                    {!reviewDismissed && (
                        <SectionCard
                            title={t('dashboard.love_booleansmtp', 'Love BooleanSMTP?')}
                            action={(
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    className="shrink-0"
                                    aria-label={t('dashboard.dismiss_review', 'Dismiss review request')}
                                    onClick={() => setReviewDismissDialogOpen(true)}
                                >
                                    <X />
                                </Button>
                            )}
                            footer={(
                                <Button asChild className="w-full">
                                    <a href="https://wordpress.org/plugins/boolean-smtp/" target="_blank" rel="noreferrer">
                                        {t('dashboard.write_review', 'Write a Review')}
                                    </a>
                                </Button>
                            )}
                        />
                    )}
                </div>
                </div>
            <AlertDialog open={reviewDismissDialogOpen} onOpenChange={setReviewDismissDialogOpen}>
                <AlertDialogContent size="sm">
                    <AlertDialogHeader>
                        <AlertDialogTitle>{t('dashboard.review_thank_you_title', 'Thank you for using BooleanSMTP')}</AlertDialogTitle>
                        <AlertDialogDescription>
                            {t('dashboard.review_thank_you_description', 'We appreciate having you with us. You can always leave a review later from the dashboard.')}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('dashboard.keep_review_request', 'Keep it')}</AlertDialogCancel>
                        <AlertDialogAction onClick={dismissReviewCard}>{t('dashboard.dismiss_review_confirm', 'Dismiss')}</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
