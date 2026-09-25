import { Zap } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { useTranslations } from '@/hooks/useTranslations';

/**
 * "Pro" marker rendered on Pro-gated UI elements regardless of license state.
 * Locked → destructive, unlocked → success (semantic tokens).
 */
export function ProBadge({ unlocked = false, className }) {
    const { t } = useTranslations();
    return (
        <Badge variant={unlocked ? 'success' : 'destructive'} className={cn('uppercase', className)}>
            <Zap className="fill-current" />
            {t('common.pro', 'Pro')}
        </Badge>
    );
}
