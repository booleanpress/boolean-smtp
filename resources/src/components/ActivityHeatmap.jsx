import { useMemo } from 'react';
import { Activity } from 'lucide-react';
import { SectionCard } from '@/components/section-card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { useTranslations } from '@/hooks/useTranslations';

const LEVELS = ['bg-muted', 'bg-primary/20', 'bg-primary/40', 'bg-primary/60', 'bg-primary/80', 'bg-primary'];

function levelFor(value, max) {
    if (value <= 0 || max <= 0) return LEVELS[0];
    const ratio = value / max;
    if (ratio < 0.2) return LEVELS[1];
    if (ratio < 0.4) return LEVELS[2];
    if (ratio < 0.6) return LEVELS[3];
    if (ratio < 0.8) return LEVELS[4];
    return LEVELS[5];
}

export default function ActivityHeatmap({ data = [] }) {
    const { t } = useTranslations();

    const dayLabels = [
        t('common.days.sun', 'Sun'), t('common.days.mon', 'Mon'), t('common.days.tue', 'Tue'),
        t('common.days.wed', 'Wed'), t('common.days.thu', 'Thu'), t('common.days.fri', 'Fri'), t('common.days.sat', 'Sat'),
    ];

    // 7 × 24 grid; MySQL DAYOFWEEK is 1–7.
    const { matrix, max } = useMemo(() => {
        const grid = Array.from({ length: 7 }, () => Array(24).fill(0));
        let peak = 0;
        for (const item of data) {
            const d = parseInt(item.day, 10) - 1;
            const h = parseInt(item.hour, 10);
            const count = parseInt(item.count, 10) || 0;
            if (d >= 0 && d < 7 && h >= 0 && h < 24) {
                grid[d][h] = count;
                peak = Math.max(peak, count);
            }
        }
        return { matrix: grid, max: peak };
    }, [data]);

    return (
        <SectionCard
            title={(
                <span className="flex items-center gap-2">
                    <Activity className="size-4 text-muted-foreground" />
                    {t('dashboard.activity_heatmap', 'Sending Activity')}
                </span>
            )}
            description={t('dashboard.heatmap_desc', 'Peak email activity by hour and day of the week.')}
            action={(
                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                    <span>{t('dashboard.low', 'Low')}</span>
                    <div className="flex gap-1" aria-hidden="true">
                        {[LEVELS[0], LEVELS[2], LEVELS[4], LEVELS[5]].map(level => (
                            <span key={level} className={cn('size-2.5 rounded-xs', level)} />
                        ))}
                    </div>
                    <span>{t('dashboard.high', 'High')}</span>
                </div>
            )}
            contentClassName="overflow-x-auto"
        >
            <Table className="min-w-[640px] table-fixed border-separate border-spacing-1" aria-label={t('dashboard.activity_heatmap', 'Sending Activity')}>
                <TableHeader>
                    <TableRow className="border-0 hover:bg-transparent">
                        <TableHead className="h-auto w-10 p-0" />
                        {Array.from({ length: 24 }, (_, hour) => (
                            <TableHead key={hour} className="h-auto p-0 text-center text-xs font-medium text-muted-foreground">
                                {hour % 4 === 0 ? `${hour}h` : ''}
                            </TableHead>
                        ))}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {matrix.map((row, d) => (
                        <TableRow key={d} className="border-0 hover:bg-transparent">
                            <TableHead scope="row" className="h-auto p-0 pr-1 text-right text-xs font-medium text-muted-foreground">{dayLabels[d]}</TableHead>
                            {row.map((value, hour) => {
                                const label = t('dashboard.heatmap_cell', '{{count}} emails on {{day}} at {{hour}}:00', { count: value, day: dayLabels[d], hour });
                                const cell = (
                                    <div
                                        tabIndex={value > 0 ? 0 : undefined}
                                        role={value > 0 ? 'img' : undefined}
                                        aria-label={value > 0 ? label : undefined}
                                        aria-hidden={value > 0 ? undefined : true}
                                        className={cn('aspect-square w-full rounded-xs transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none', levelFor(value, max))}
                                    />
                                );
                                return (
                                    <TableCell key={hour} className="p-0">
                                        {value > 0 ? (
                                            <Tooltip>
                                                <TooltipTrigger asChild>{cell}</TooltipTrigger>
                                                <TooltipContent side="top">{label}</TooltipContent>
                                            </Tooltip>
                                        ) : cell}
                                    </TableCell>
                                );
                            })}
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </SectionCard>
    );
}
