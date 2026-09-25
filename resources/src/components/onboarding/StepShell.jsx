import { useEffect, useRef } from 'react';
import { ArrowLeft, ArrowRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/useTranslations';

/**
 * The two-column shell every wizard step renders into: eyebrow, question heading, one sentence,
 * the options column, the sticky Preview column, and the Back / Continue footer with the
 * "nothing is switched on" footnote. The heading takes focus when the step changes so keyboard
 * and screen-reader users land at the top of the new step.
 *
 * @since 1.0.0
 *
 * @param {object} props
 * @param {number} props.stepNumber 1-based step number.
 * @param {number} props.stepCount Number of rail steps.
 * @param {string} props.stepName Rail title, rendered in the eyebrow.
 * @param {string} props.title The step's question.
 * @param {string} [props.description] One sentence under the question.
 * @param {import('react').ReactNode} props.children The options column.
 * @param {import('react').ReactNode} props.preview The Preview column.
 * @param {() => void} [props.onBack] Back handler; the button is hidden when absent.
 * @param {() => void} [props.onContinue] Continue handler; the button is hidden when absent.
 * @param {string} [props.continueLabel] Label of the primary action.
 * @param {boolean} [props.continueDisabled] Disable the primary action.
 * @param {boolean} [props.continueBusy] Show a spinner on the primary action.
 * @param {import('react').ReactNode} [props.secondaryAction] Optional link-style action beside Back (e.g. Skip).
 * @param {string} [props.footnote] Replaces the default footnote when given.
 */
export default function StepShell({
    stepNumber,
    stepCount,
    stepName,
    title,
    description,
    children,
    preview,
    onBack,
    onContinue,
    continueLabel,
    continueDisabled = false,
    continueBusy = false,
    secondaryAction = null,
    footnote,
}) {
    const { t } = useTranslations();
    const headingRef = useRef(null);

    useEffect(() => {
        headingRef.current?.focus();
    }, [stepNumber]);

    return (
        <div className="grid gap-8 lg:grid-cols-12">
            <section className="flex flex-col gap-6 lg:col-span-7" aria-labelledby="onboarding-step-title">
                <div className="flex flex-col gap-2">
                    <p className="text-xs font-medium text-muted-foreground">
                        {t('onboarding.eyebrow', 'Step {{number}} of {{count}} / {{name}}', { number: stepNumber, count: stepCount, name: stepName })}
                    </p>
                    <h1 id="onboarding-step-title" ref={headingRef} tabIndex={-1} className="text-xl font-semibold tracking-tight outline-none">
                        {title}
                    </h1>
                    {description && <p className="text-sm text-muted-foreground">{description}</p>}
                </div>

                <div className="flex flex-col gap-6">{children}</div>

                {(onBack || onContinue || secondaryAction) && (
                    <div className="flex flex-col gap-3">
                        <Separator />
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="flex items-center gap-2">
                                {onBack && (
                                    <Button type="button" variant="ghost" onClick={onBack}>
                                        <ArrowLeft />
                                        {t('common.back', 'Back')}
                                    </Button>
                                )}
                                {secondaryAction}
                            </div>
                            {onContinue && (
                                <Button type="button" size="lg" onClick={onContinue} disabled={continueDisabled || continueBusy}>
                                    {continueBusy && <Spinner />}
                                    {continueLabel || t('common.continue', 'Continue')}
                                    {!continueBusy && <ArrowRight />}
                                </Button>
                            )}
                        </div>
                        <p className="text-right text-xs text-muted-foreground">
                            {footnote || t('onboarding.footnote', 'Nothing is switched on until you apply it in the last step. Your site keeps sending the way it does now.')}
                        </p>
                    </div>
                )}
            </section>

            <aside className="lg:col-span-5 lg:sticky lg:top-6 lg:self-start" aria-label={t('onboarding.preview_label', 'Preview')}>
                {preview}
            </aside>
        </div>
    );
}
