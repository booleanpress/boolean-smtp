import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

/**
 * A compact key/value list: one line per row — label, value, state badge — separated by
 * hairlines. Values are states or identifiers, never inputs and never secrets.
 *
 * @since 1.0.0
 *
 * @param {object} props
 * @param {Array<{ label: string, value?: string, badge?: { variant: string, label: string }, wrap?: boolean }>} props.rows A row with `wrap` keeps its whole value over several lines instead of clipping it.
 * @param {string} [props.className]
 */
export default function SummaryRows({ rows, className }) {
    if (!rows || rows.length === 0) return null;
    return (
        <dl className={cn('divide-y', className)}>
            {rows.map(row => (
                <div key={row.label} className={cn('flex gap-3 py-1.5', row.wrap ? 'items-start' : 'items-center')}>
                    <dt className="w-32 shrink-0 text-xs text-muted-foreground">{row.label}</dt>
                    <dd
                        className={cn('min-w-0 flex-1 text-sm', row.wrap ? 'break-words' : 'truncate')}
                        title={row.wrap ? undefined : (row.value || undefined)}
                    >
                        {row.value || ''}
                    </dd>
                    {row.badge && <Badge variant={row.badge.variant} className="shrink-0">{row.badge.label}</Badge>}
                </div>
            ))}
        </dl>
    );
}
