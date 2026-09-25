import { Badge } from '@/components/ui/badge';
import { Field, FieldDescription, FieldLabel, FieldTitle } from '@/components/ui/field';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { cn } from '@/lib/utils';

/**
 * One decision as a set of cards: a `RadioGroup` whose items are wrapped in `FieldLabel`/`Field`
 * so each option is a bordered card that highlights when checked. Keyboard and screen-reader
 * semantics come from the radio primitives.
 *
 * @since 1.0.0
 *
 * @param {object} props
 * @param {string} props.value Selected option value.
 * @param {(value: string) => void} props.onValueChange Selection handler.
 * @param {string} props.ariaLabel Accessible name of the group.
 * @param {Array<{ value: string, title: string, description?: string, badge?: { variant: string, label: string }, mark?: import('react').ReactNode, media?: import('react').ReactNode, disabled?: boolean, note?: string }>} props.options `mark` is a small element shown where the badge goes (an icon with a tooltip).
 * @param {string} [props.className] Grid classes.
 * @param {string} [props.idPrefix] Prefix for the radio ids.
 */
export default function ChoiceCards({ value, onValueChange, ariaLabel, options, className, idPrefix = 'choice' }) {
    return (
        <RadioGroup
            value={value}
            onValueChange={onValueChange}
            aria-label={ariaLabel}
            className={cn('grid gap-3 sm:grid-cols-2', className)}
        >
            {options.map(option => {
                const id = `${idPrefix}-${option.value}`;
                const selected = value === option.value;
                return (
                    <FieldLabel
                        key={option.value}
                        htmlFor={id}
                        className={cn(
                            'h-full bg-card transition-colors has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50',
                            !option.disabled && 'hover:border-primary/40',
                            option.disabled && 'cursor-not-allowed opacity-60'
                        )}
                    >
                        <Field className="h-full gap-3">
                            <div className="flex items-start justify-between gap-3">
                                <div className="flex min-w-0 items-center gap-3">
                                    {option.media}
                                    <FieldTitle id={`${id}-title`} className="text-sm font-semibold">{option.title}</FieldTitle>
                                </div>
                                {option.badge && <Badge variant={option.badge.variant} className="shrink-0">{option.badge.label}</Badge>}
                                {option.mark}
                            </div>
                            {option.description && <FieldDescription id={`${id}-description`} className="text-sm">{option.description}</FieldDescription>}
                            {option.note && <p className="text-xs text-muted-foreground">{option.note}</p>}
                            <RadioGroupItem
                                value={option.value}
                                id={id}
                                // Named by the card's own title: the wrapping label does not name a button-based radio for every screen reader.
                                aria-labelledby={`${id}-title`}
                                aria-describedby={option.description ? `${id}-description` : undefined}
                                className="sr-only"
                                disabled={option.disabled}
                                data-selected={selected || undefined}
                            />
                        </Field>
                    </FieldLabel>
                );
            })}
        </RadioGroup>
    );
}
