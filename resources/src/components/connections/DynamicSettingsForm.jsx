import { useMemo } from 'react';
import { Info } from 'lucide-react';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Combobox } from '@/components/combobox';
import { Input } from '@/components/ui/input';
import { PasswordInput } from '@/components/ui/password-input';
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { useTranslations } from '@/hooks/useTranslations';

// Field types whose schema `help` text is rendered. Originally just 'select'/'password' (kept
// as-is from the pre-migration form); 'text' and 'email' were added 2026-09-19 so fields like
// Outlook's `tenant_id` can carry inline guidance instead of being a bare, unexplained input --
// see office/assessments/010_2026-09-19_microsoft365_outlook_integration_assessment.md (MS-001).
const HELP_TYPES = ['select', 'password', 'text', 'email'];

// A plain Select's content grows to fit every option instead of staying compact -- fine for a
// handful of choices, but a 27-region or 34-preset list either has to scroll a huge box or (with
// item-aligned positioning) overflow the viewport entirely. Past this many options, switch to the
// searchable Combobox instead.
const SEARCHABLE_SELECT_THRESHOLD = 8;

/**
 * DynamicSettingsForm
 *
 * Renders a schema-driven form for mailer transport settings.
 * Supports: text, password, email, number, select, checkbox. `trailing` is rendered as one more
 * grid cell after the fields, bottom-aligned, so an action can sit beside the last input instead
 * of on a row of its own.
 */
export default function DynamicSettingsForm({
    schema = {},
    values = {},
    onChange,
    errors = {},
    onFieldBlur = null,
    trailing = null
}) {
    const { t } = useTranslations();

    const groupedSchema = useMemo(() => {
        const groups = [];
        let currentGroup = null;

        Object.entries(schema).forEach(([key, field]) => {
            // A checkbox normally attaches under the preceding non-checkbox field (e.g.
            // "Force From email" under "From Email"). `field.standalone` opts a checkbox out of
            // that so it gets its own grid cell instead -- e.g. Outlook's "Send as a shared
            // mailbox" sitting beside Tenant ID rather than nested under it.
            if (field.type !== 'checkbox') {
                if (currentGroup) groups.push(currentGroup);
                currentGroup = { parent: [key, field], children: [] };
            } else if (field.standalone) {
                if (currentGroup) groups.push(currentGroup);
                currentGroup = null;
                groups.push({ parent: [key, field], children: [] });
            } else if (currentGroup) {
                currentGroup.children.push([key, field]);
            } else {
                // Checkbox with no preceding non-checkbox field
                groups.push({ parent: [key, field], children: [] });
            }
        });
        if (currentGroup) groups.push(currentGroup);
        return groups;
    }, [schema]);

    const renderControl = (key, field, value, error) => {
        const invalid = Boolean(error);

        switch (field.type) {
            case 'select': {
                const options = Object.entries(field.options || {});
                if (options.length > SEARCHABLE_SELECT_THRESHOLD) {
                    return (
                        <Combobox
                            id={key}
                            value={value}
                            onValueChange={(val) => onChange(key, val)}
                            options={options.map(([optVal, optLabel]) => ({ value: optVal, label: optLabel }))}
                            placeholder={field.placeholder || t('connection_form.select_field', 'Select {{field}}', { field: field.label })}
                            invalid={invalid}
                        />
                    );
                }
                return (
                    <Select value={value} onValueChange={(val) => onChange(key, val)}>
                        <SelectTrigger id={key} className="w-full" aria-invalid={invalid}>
                            <SelectValue placeholder={field.placeholder || t('connection_form.select_field', 'Select {{field}}', { field: field.label })} />
                        </SelectTrigger>
                        <SelectContent>
                            {options.map(([optVal, optLabel]) => (
                                <SelectItem key={optVal} value={optVal}>{optLabel}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                );
            }

            case 'password':
                return (
                    <PasswordInput
                        id={key}
                        value={value}
                        onChange={(e) => onChange(key, e.target.value)}
                        onBlur={() => onFieldBlur?.(key)}
                        className="font-mono"
                        placeholder={field.label}
                        aria-invalid={invalid}
                    />
                );

            case 'number':
                return (
                    <Input
                        id={key}
                        type="number"
                        value={value}
                        min={field.min}
                        max={field.max}
                        onChange={(e) => onChange(key, e.target.value)}
                        onBlur={() => onFieldBlur?.(key)}
                        placeholder={field.placeholder || field.label}
                        aria-invalid={invalid}
                    />
                );

            default: // text, email
                return (
                    <Input
                        id={key}
                        type={field.type || 'text'}
                        value={value}
                        onChange={(e) => onChange(key, e.target.value)}
                        onBlur={() => onFieldBlur?.(key)}
                        placeholder={field.placeholder || field.label}
                        aria-invalid={invalid}
                    />
                );
        }
    };

    // A field's `help` normally renders as a paragraph below it. `helpDisplay: 'tooltip'` opts a
    // field into a compact info icon next to its label instead -- for a description short enough
    // to work as a tooltip, without pushing the field below it further down the form.
    const helpIcon = (help) => (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-xs"
                    className="text-muted-foreground/70 hover:text-foreground"
                    aria-label={t('connection_form.more_info', 'More info')}
                >
                    <Info className="size-3.5" aria-hidden="true" />
                </Button>
            </TooltipTrigger>
            <TooltipContent className="max-w-xs">{help}</TooltipContent>
        </Tooltip>
    );

    const renderField = (key, field, isChild = false) => {
        const value = values[key] ?? field.default ?? '';
        const error = errors[key];
        const useTooltipHelp = field.helpDisplay === 'tooltip';

        if (field.visible_when) {
            const { key: depKey, value: depValue } = field.visible_when;
            if (values[depKey] !== depValue) return null;
        }

        if (field.type === 'checkbox') {
            return (
                <Field key={key} orientation="horizontal" data-invalid={Boolean(error) || undefined} className={cn('gap-2.5', isChild ? 'py-1' : 'py-2')}>
                    <Checkbox
                        id={key}
                        checked={!!value}
                        onCheckedChange={(checked) => onChange(key, checked)}
                        aria-invalid={Boolean(error)}
                    />
                    <div className="space-y-1">
                        {/* The tooltip trigger sits beside the <label>, not inside it -- a control
                            nested inside a <label> becomes implicitly (and ambiguously) associated
                            with it, which broke getByLabelText() matching for the wrapped input/checkbox. */}
                        <div className="inline-flex items-center gap-1.5">
                            <FieldLabel htmlFor={key} className="font-normal">{field.label}</FieldLabel>
                            {field.help && useTooltipHelp && helpIcon(field.help)}
                        </div>
                        {field.help && !useTooltipHelp && <FieldDescription className="text-xs">{field.help}</FieldDescription>}
                    </div>
                    {error && <FieldError className="text-xs">{error}</FieldError>}
                </Field>
            );
        }

        return (
            <Field key={key} data-invalid={Boolean(error) || undefined} className="gap-1.5">
                {/* The tooltip trigger sits beside the <label>, not inside it -- see the checkbox
                    branch above for why. */}
                <div className="inline-flex items-center gap-1.5">
                    <FieldLabel htmlFor={key} className="text-muted-foreground">{field.label}</FieldLabel>
                    {field.help && useTooltipHelp && helpIcon(field.help)}
                </div>
                {renderControl(key, field, value, error)}
                {field.help && !useTooltipHelp && HELP_TYPES.includes(field.type) && <FieldDescription className="text-xs">{field.help}</FieldDescription>}
                {error && <FieldError className="text-xs">{error}</FieldError>}
            </Field>
        );
    };

    return (
        <div className="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
            {groupedSchema.map((group) => {
                const [pkey, pfield] = group.parent;
                const isFullWidth = pfield.fullWidth || pfield.type === 'textarea';
                // A standalone checkbox (see groupedSchema above) is a single-line row -- next to
                // a taller label+input sibling it would otherwise sit pinned to the top of the
                // grid row instead of alongside the input it's paired with. Center it in the row
                // instead of stretching/top-aligning it.
                const isStandaloneCheckbox = pfield.type === 'checkbox' && group.children.length === 0;
                return (
                    <div key={pkey} className={cn('space-y-3', isFullWidth && 'md:col-span-2', isStandaloneCheckbox && 'md:self-center')}>
                        {renderField(pkey, pfield)}
                        {group.children.length > 0 && (
                            <div className="space-y-1 pl-1">
                                {group.children.map(([ckey, cfield]) => renderField(ckey, cfield, true))}
                            </div>
                        )}
                    </div>
                );
            })}
            {trailing && <div className="md:self-end">{trailing}</div>}
        </div>
    );
}
