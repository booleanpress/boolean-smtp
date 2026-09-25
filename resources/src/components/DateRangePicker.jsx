import { Suspense, lazy, useMemo, useState } from 'react';
import { CalendarIcon } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { daysInRange, formatDateRange, isSameRange, lastDays, lastMonth, parseISODate, startOfDay, thisMonth, toISODate } from '@/lib/dates';
import { useIsMobile } from '@/hooks/use-mobile';
import { useTranslations } from '@/hooks/useTranslations';

// react-day-picker only loads once the popover is opened.
const Calendar = lazy(() => import('@/components/ui/calendar').then(m => ({ default: m.Calendar })));

const CalendarFallback = () => (
    <div className="flex gap-4 p-3">
        <Skeleton className="h-72 w-64" />
        <Skeleton className="hidden h-72 w-64 md:block" />
    </div>
);

/**
 * Calendar date-range picker with quick presets.
 *
 * `value` / `onChange` carry `{ from: Date, to: Date }` (local calendar days, inclusive).
 * Presets apply immediately; a range picked on the calendar is applied with the Apply button.
 */
export function DateRangePicker({ value, onChange, maxDays = 366, align = 'end', className }) {
    const { t, locale } = useTranslations();
    const isMobile = useIsMobile();
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState(value);

    // Keyed by the calendar day so the presets only rebuild at midnight.
    const todayKey = toISODate(new Date());
    const today = useMemo(() => parseISODate(todayKey), [todayKey]);
    const presets = useMemo(() => [
        { id: 'last_7', label: t('date_range.last_7_days', 'Last 7 days'), range: lastDays(7, today) },
        { id: 'last_14', label: t('date_range.last_14_days', 'Last 14 days'), range: lastDays(14, today) },
        { id: 'last_30', label: t('date_range.last_30_days', 'Last 30 days'), range: lastDays(30, today) },
        { id: 'last_90', label: t('date_range.last_90_days', 'Last 90 days'), range: lastDays(90, today) },
        { id: 'this_month', label: t('date_range.this_month', 'This month'), range: thisMonth(today) },
        { id: 'last_month', label: t('date_range.last_month', 'Last month'), range: lastMonth(today) },
    ], [t, today]);

    const activePreset = presets.find(preset => isSameRange(preset.range, value));
    const rangeEnd = draft?.to || draft?.from || value.to;
    const endMonth = new Date(rangeEnd.getFullYear(), rangeEnd.getMonth(), 1);
    const draftComplete = !!(draft?.from && draft?.to);
    const draftDays = draftComplete ? daysInRange(draft.from, draft.to) : 0;
    const draftTooLong = draftDays > maxDays;

    const openChange = next => {
        if (next) setDraft(value);
        setOpen(next);
    };

    const apply = range => {
        if (!isSameRange(value, range)) {
            onChange(range);
        }
        setOpen(false);
    };

    // With a complete range on screen (a preset or the current value), the first click starts a
    // new range instead of nudging the nearest edge, which is what people expect from a picker.
    const handleSelect = (next, clickedDay) => {
        if (draft?.from && draft?.to) {
            setDraft({ from: clickedDay, to: undefined });
            return;
        }
        setDraft(next);
    };

    const triggerLabel = activePreset
        ? activePreset.label
        : formatDateRange(value.from, value.to, locale);

    return (
        <Popover open={open} onOpenChange={openChange}>
            <PopoverTrigger asChild>
                <Button
                    variant="outline"
                    size="sm"
                    className={cn('justify-start font-normal', className)}
                    aria-label={t('date_range.aria_label', 'Change date range')}
                >
                    <CalendarIcon className="text-muted-foreground" />
                    <span className="truncate">{triggerLabel}</span>
                    {activePreset && (
                        <span className="hidden text-muted-foreground sm:inline">· {formatDateRange(value.from, value.to, locale)}</span>
                    )}
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align={align}
                disableAnimation
                className="w-auto max-w-[calc(100vw-2rem)] p-0"
            >
                <div className="flex flex-col md:flex-row">
                    <div className="flex flex-row flex-wrap gap-1 p-2 md:w-40 md:flex-col md:flex-nowrap md:py-3" role="group" aria-label={t('date_range.presets', 'Quick ranges')}>
                        {presets.map(preset => (
                            <Button
                                key={preset.id}
                                variant="ghost"
                                size="sm"
                                className={cn('justify-start font-normal', isSameRange(preset.range, draft) && 'bg-accent text-accent-foreground')}
                                aria-pressed={isSameRange(preset.range, value)}
                                onClick={() => apply(preset.range)}
                            >
                                {preset.label}
                            </Button>
                        ))}
                    </div>
                    <div className="flex flex-col border-t md:border-t-0 md:border-l">
                        <Suspense fallback={<CalendarFallback />}>
                            <Calendar
                                mode="range"
                                numberOfMonths={isMobile ? 1 : 2}
                                // Two months ending with the range's last month; no navigating into the future.
                                defaultMonth={isMobile ? endMonth : new Date(endMonth.getFullYear(), endMonth.getMonth() - 1, 1)}
                                endMonth={today}
                                selected={draft}
                                onSelect={handleSelect}
                                disabled={{ after: today }}
                                required
                                autoFocus
                            />
                        </Suspense>
                        <Separator />
                        <div className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                            <p className={cn('text-xs', draftTooLong ? 'text-destructive' : 'text-muted-foreground')} aria-live="polite">
                                {draftComplete
                                    ? draftTooLong
                                        ? t('date_range.too_long', 'Choose at most {{max}} days', { max: maxDays })
                                        : draftDays === 1
                                            ? t('date_range.summary_one', '{{range}} · 1 day', { range: formatDateRange(draft.from, draft.to, locale) })
                                            : t('date_range.summary', '{{range}} · {{count}} days', { range: formatDateRange(draft.from, draft.to, locale), count: draftDays })
                                    : t('date_range.pick_end', 'Pick a start and an end date')}
                            </p>
                            <div className="ml-auto flex gap-2">
                                <Button variant="ghost" size="sm" onClick={() => setOpen(false)}>{t('common.cancel', 'Cancel')}</Button>
                                <Button size="sm" disabled={!draftComplete || draftTooLong} onClick={() => apply({ from: startOfDay(draft.from), to: startOfDay(draft.to) })}>
                                    {t('date_range.apply', 'Apply')}
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </PopoverContent>
        </Popover>
    );
}
