import { Badge } from '@/components/ui/badge';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { useTranslations } from '@/hooks/useTranslations';

const HEALTH_DOT_CLASSES = {
    healthy: 'bg-success',
    // Slow pulse (3s, slower than Tailwind's default 2s animate-pulse) draws the eye to a
    // connection that needs attention without being distracting in a long list.
    error: 'bg-destructive animate-[pulse_3s_ease-in-out_infinite]',
    unknown: 'bg-muted-foreground/50',
};

/**
 * Health is a monitoring signal (last connection test result), distinct from the on/off
 * Status badge. It renders as an outline pill with a colored dot so the two columns don't
 * read as the same information twice.
 */
export default function HealthBadge({ status, detail, className }) {
    const { t } = useTranslations();
    const key = String(status || 'unknown').toLowerCase();
    const dotClass = HEALTH_DOT_CLASSES[key] || HEALTH_DOT_CLASSES.unknown;
    const label = t(`status.${key}`, key.replace(/_/g, ' '));

    const badge = (
        <Badge variant="outline" className={cn('gap-1.5', className)} tabIndex={detail ? 0 : undefined}>
            <span className={cn('size-1.5 shrink-0 rounded-full', dotClass)} aria-hidden="true" />
            <span className="capitalize">{label}</span>
        </Badge>
    );

    if (!detail) {
        return badge;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>{badge}</TooltipTrigger>
            <TooltipContent>{detail}</TooltipContent>
        </Tooltip>
    );
}
