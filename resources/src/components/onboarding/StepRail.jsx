import { Check } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { cn } from '@/lib/utils';
import { useTranslations } from '@/hooks/useTranslations';

/**
 * The numbered step rail across the top of the wizard: done steps are check badges and links, the
 * current step is filled, upcoming steps are outlined. A step counts as done when the progress
 * record says so or when it lies before the current one, so walking back never un-ticks what was
 * completed. Titles hide below `md`; the numbers and a screen-reader progress line remain.
 *
 * @since 1.0.0
 *
 * @param {object} props
 * @param {Array<{ id: string, title: string }>} props.steps Rail steps.
 * @param {number} props.current Index of the current step.
 * @param {boolean[]} [props.completed] Per-step completion from the progress record.
 * @param {(index: number) => void} [props.onNavigate] Called with a done step's index; when absent, done steps are not links.
 * @param {(index: number) => boolean} [props.canNavigate] Which steps may be jumped to; defaults to the steps behind the current one.
 */
export default function StepRail({ steps, current, completed = [], onNavigate, canNavigate }) {
    const { t } = useTranslations();
    const doneCount = steps.filter((_, index) => index < current || completed[index]).length;
    const progress = Math.round((Math.max(doneCount, current + 1) / steps.length) * 100);

    return (
        <nav aria-label={t('onboarding.steps_nav_label', 'Setup steps')} className="border-b pb-4">
            <Progress value={progress} className="sr-only" aria-label={t('onboarding.progress_label', 'Setup progress')} />
            <ol className="flex items-center justify-start gap-2 overflow-x-auto px-1 md:gap-3">
                {steps.map((step, index) => {
                    const isCurrent = index === current;
                    const done = !isCurrent && (index < current || Boolean(completed[index]));
                    // Any step the wizard would let the user reach is a link — done ones behind the
                    // current step, and steps ahead that were already unlocked (a saved draft, an
                    // accepted test); `canNavigate` is the page's word on what is unlocked.
                    const linkable = !isCurrent && Boolean(onNavigate) && (canNavigate ? canNavigate(index) : index < current);
                    const label = t('onboarding.rail_step', 'Step {{number}}: {{title}}', { number: index + 1, title: step.title });
                    const content = (
                        <>
                            <Badge
                                variant={done ? 'success' : isCurrent ? 'default' : 'outline'}
                                className="size-7 shrink-0 rounded-full px-0 text-xs tabular-nums [&>svg]:size-3.5"
                                aria-hidden="true"
                            >
                                {done ? <Check /> : index + 1}
                            </Badge>
                            <span className={cn('hidden text-sm md:inline', isCurrent ? 'font-semibold text-foreground' : 'text-muted-foreground')}>
                                {step.title}
                            </span>
                        </>
                    );

                    return (
                        <li key={step.id} className="flex items-center gap-2 md:gap-3">
                            {index > 0 && <span className="h-px w-3 bg-border md:w-6" aria-hidden="true" />}
                            {linkable ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="h-auto gap-2 px-1.5 py-1"
                                    onClick={() => onNavigate(index)}
                                    aria-label={t('onboarding.rail_go_to', 'Go to step {{number}}: {{title}}', { number: index + 1, title: step.title })}
                                >
                                    {content}
                                </Button>
                            ) : (
                                <span
                                    className="flex items-center gap-2 px-1.5 py-1"
                                    aria-current={isCurrent ? 'step' : undefined}
                                    aria-label={label}
                                >
                                    {content}
                                </span>
                            )}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
