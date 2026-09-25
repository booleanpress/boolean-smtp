import { useId } from 'react';

import { Card, CardAction, CardContent, CardDescription, CardHeader } from '@/components/ui/card';
import { cn } from '@/lib/utils';

/**
 * A page block: one flat card whose header holds the section title, an optional one-line
 * subtitle and an optional right-aligned action, above a single divider and the content.
 *
 * The `<section>` is labelled by its `h2`, so each block is a navigable region.
 *
 * @since 1.0.0
 *
 * @param {object}          props
 * @param {React.ReactNode} props.title            Section title, rendered as the block's `h2`.
 * @param {React.ReactNode} [props.description]    One-line subtitle under the title.
 * @param {React.ReactNode} [props.action]         Control aligned to the right of the title.
 * @param {React.ReactNode} [props.footer]         Content of a bordered footer row.
 * @param {string}          [props.className]      Extra classes for the `<section>`.
 * @param {string}          [props.contentClassName] Extra classes for the content area.
 * @param {React.ReactNode} [props.children]      The block's content; without it the card holds only its header and footer.
 */
export function SectionCard({ title, description, action, footer, className, contentClassName, children }) {
    const headingId = useId();

    return (
        <section className={cn('min-w-0', className)} aria-labelledby={headingId}>
            <Card className="gap-0 overflow-hidden py-0">
                <CardHeader className="gap-0.5 border-b px-4 py-3 sm:px-5 [.border-b]:pb-3">
                    <h2 id={headingId} data-slot="card-title" className="min-w-0 text-base leading-6 font-semibold">{title}</h2>
                    {description && <CardDescription className="leading-5">{description}</CardDescription>}
                    {action && <CardAction className="self-center">{action}</CardAction>}
                </CardHeader>
                {children != null && children !== false && (
                    <CardContent className={cn('px-4 py-5 sm:px-5 sm:py-6', contentClassName)}>{children}</CardContent>
                )}
                {footer && <div className="flex items-center border-t px-4 py-4 sm:px-5">{footer}</div>}
            </Card>
        </section>
    );
}
