import { cn } from '@/lib/utils';

/**
 * A row of figures in one block, split by hairlines: each figure is a muted label with a line
 * icon above a large number. Rendered as a description list so each number is read with its label.
 *
 * The grid draws its dividers with a 1 px gap over the border colour, so the lines stay correct
 * when the figures wrap to two columns on narrow screens.
 *
 * @since 1.0.0
 *
 * @param {object} props
 * @param {Array<{id: string, label: string, value: React.ReactNode, icon?: React.ReactNode}>} props.items Figures, in display order.
 * @param {string} [props.className] Extra classes for the list.
 */
export default function StatGroup({ items, className }) {
    return (
        <dl
            data-slot="stat-group"
            className={cn('grid grid-cols-2 gap-px bg-border @3xl:grid-cols-4', className)}
        >
            {items.map(item => (
                <div key={item.id} className="flex min-w-0 flex-col gap-2 bg-card px-4 py-4 sm:px-5">
                    <dt className="flex min-w-0 items-center gap-1.5 text-sm text-muted-foreground">
                        {item.icon && <span aria-hidden="true" className="shrink-0 [&>svg]:size-4">{item.icon}</span>}
                        <span className="truncate">{item.label}</span>
                    </dt>
                    <dd className="text-2xl font-semibold tracking-tight tabular-nums">{item.value}</dd>
                </div>
            ))}
        </dl>
    );
}
