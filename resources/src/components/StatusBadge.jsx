import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { useTranslations } from '@/hooks/useTranslations';

// Tonal (light fill + accent text) chips instead of solid pills — reuses the
// same semantic color tokens as the rest of the app, just softer.
const STATUS_STYLES = {
    delivered: 'bg-success/15 text-success-strong dark:bg-success/20',
    sent: 'bg-success/15 text-success-strong dark:bg-success/20',
    healthy: 'bg-success/15 text-success-strong dark:bg-success/20',
    active: 'bg-success/15 text-success-strong dark:bg-success/20',
    failed: 'bg-destructive/10 text-destructive-strong dark:bg-destructive/20',
    error: 'bg-destructive/10 text-destructive-strong dark:bg-destructive/20',
    pending: 'bg-warning/20 text-warning-foreground dark:bg-warning/25',
    queued: 'bg-info/10 text-info-strong dark:bg-info/20',
    sending: 'bg-info/10 text-info-strong dark:bg-info/20',
    simulated: 'bg-muted text-muted-foreground',
    inactive: 'bg-muted text-muted-foreground',
    unknown: 'bg-muted text-muted-foreground',
};

export default function StatusBadge({ status, className }) {
    const { t } = useTranslations();
    const key = String(status || 'unknown').toLowerCase();
    const style = STATUS_STYLES[key] || STATUS_STYLES.unknown;
    const label = t(`status.${key}`, key.replace(/_/g, ' '));

    return (
        <Badge className={cn('rounded-sm border-transparent font-semibold', style, className)}>
            <span className="capitalize">{label}</span>
        </Badge>
    );
}
