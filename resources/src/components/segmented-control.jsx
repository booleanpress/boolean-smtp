import { ToggleGroup as ToggleGroupPrimitive } from 'radix-ui';

import { cn } from '@/lib/utils';

/**
 * A single choice between a few options, drawn like a tab strip: "Database / WP Config /
 * Environment", "On / Off". Built on a single-select toggle group, so assistive technology hears
 * a group of radio buttons with one checked — not tabs that point at panels which do not exist.
 * Choosing the selected option again keeps it selected.
 *
 * @since 1.0.0
 *
 * @param {object} props
 * @param {string} props.value The selected option.
 * @param {(value: string) => void} props.onValueChange Called with the newly selected option.
 * @param {Array<{ value: string, label: string }>} props.options The choices, in display order.
 * @param {string} [props.labelledBy] Id of the visible label naming the group.
 * @param {boolean} [props.fullWidth] Stretch the options across the available width.
 * @param {string} [props.className]
 */
export function SegmentedControl({ value, onValueChange, options, labelledBy, fullWidth = false, className }) {
    return (
        <ToggleGroupPrimitive.Root
            type="single"
            value={value}
            onValueChange={next => { if (next) onValueChange(next); }}
            aria-labelledby={labelledBy}
            data-slot="segmented-control"
            className={cn(
                'inline-flex h-9 items-center rounded-lg bg-border p-[3px] text-muted-foreground dark:bg-muted',
                fullWidth ? 'w-full' : 'w-fit',
                className
            )}
        >
            {options.map(option => (
                <ToggleGroupPrimitive.Item
                    key={option.value}
                    value={option.value}
                    className={cn(
                        'inline-flex h-full items-center justify-center rounded-md border border-transparent px-2 py-1 text-sm font-medium whitespace-nowrap text-foreground/60 transition-colors hover:text-foreground',
                        'focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                        'data-[state=on]:bg-card data-[state=on]:text-foreground data-[state=on]:shadow-sm dark:data-[state=on]:border-input dark:data-[state=on]:bg-input/30',
                        fullWidth && 'flex-1'
                    )}
                >
                    {option.label}
                </ToggleGroupPrimitive.Item>
            ))}
        </ToggleGroupPrimitive.Root>
    );
}
