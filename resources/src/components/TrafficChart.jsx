import { memo, useMemo } from 'react';
import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from 'recharts';
import { Mail } from 'lucide-react';

import { ChartContainer, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { formatDate, parseISODate } from '@/lib/dates';
import { useTranslations } from '@/hooks/useTranslations';

/** Daily email volume bar chart (shadcn Chart + Recharts). Lazy-loaded by the Dashboard. */
function TrafficChart({ data }) {
    const { t, locale } = useTranslations();
    const chartConfig = useMemo(() => ({
        count: { label: t('dashboard.emails', 'Emails'), color: 'var(--chart-1)' },
    }), [t]);
    // Up to a week: weekday names; longer ranges: "Sep 10". Recharts thins overlapping ticks.
    const tickOptions = data.length <= 7 ? { weekday: 'short' } : { month: 'short', day: 'numeric' };
    const formatTick = value => formatDate(parseISODate(value), locale, tickOptions);
    const formatTooltipLabel = value => formatDate(parseISODate(value), locale, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });

    if (data.length === 0) {
        return (
            <Empty className="h-64 border-0">
                <EmptyHeader>
                    <EmptyMedia variant="icon"><Mail /></EmptyMedia>
                    <EmptyTitle>{t('dashboard.no_traffic_title', 'No traffic yet')}</EmptyTitle>
                    <EmptyDescription>{t('dashboard.no_traffic_data', 'No traffic data available for this period')}</EmptyDescription>
                </EmptyHeader>
            </Empty>
        );
    }

    return (
        <div>
            <ChartContainer config={chartConfig} className="h-64 w-full">
                <BarChart accessibilityLayer data={data} margin={{ left: 0, right: 8 }}>
                    <CartesianGrid vertical={false} strokeDasharray="3 3" />
                    <XAxis dataKey="date" tickLine={false} axisLine={false} tickMargin={8} interval="preserveStartEnd" minTickGap={24} tickFormatter={formatTick} />
                    <YAxis allowDecimals={false} tickLine={false} axisLine={false} width={32} />
                    <ChartTooltip isAnimationActive={false} cursor={false} content={<ChartTooltipContent labelFormatter={formatTooltipLabel} />} />
                    <Bar dataKey="count" isAnimationActive={false} fill="var(--color-count)" radius={4} />
                </BarChart>
            </ChartContainer>
        </div>
    );
}

export default memo(TrafficChart);
