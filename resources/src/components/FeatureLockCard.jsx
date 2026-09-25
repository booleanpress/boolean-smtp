import { Lock, ShieldAlert, ExternalLink } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { Item, ItemActions, ItemContent, ItemMedia, ItemTitle } from '@/components/ui/item';
import { ProBadge } from '@/components/ProBadge';
import { useTranslations } from '@/hooks/useTranslations';

/**
 * Lock card for Pro-gated features.
 *  - not_installed: Pro plugin is not installed  → destructive tone; its call to action appears only
 *    when an upgrade URL is set
 *  - not_licensed:  Pro installed, no licence     → warning tone
 *
 * @since 1.0.0
 */
export function FeatureLockCard({ lockState, title, valueProp, upgradeUrl, compact = false }) {
    const { t } = useTranslations();

    const states = {
        not_installed: {
            icon: Lock,
            tone: 'destructive',
            badge: t('pro.badge_required', 'Pro required'),
            heading: t('pro.unlock_heading', 'Unlock with BooleanSMTP Pro'),
            cta: t('pro.get_pro', 'Get BooleanSMTP Pro'),
            href: upgradeUrl,
            external: true,
        },
        not_licensed: {
            icon: ShieldAlert,
            tone: 'warning',
            badge: t('pro.badge_license_required', 'License required'),
            heading: t('pro.activate_heading', 'Activate your license'),
            cta: t('pro.enter_license', 'Enter license key'),
            href: '#/settings',
            external: false,
        },
    };

    const state = states[lockState];
    if (!state) return null;
    const Icon = state.icon;
    const hasAction = Boolean(state.href);
    const linkProps = state.external ? { target: '_blank', rel: 'noreferrer' } : {};

    if (compact) {
        return (
            <Item variant="outline" size="sm">
                <ItemMedia variant="icon">
                    <Icon className={state.tone === 'warning' ? 'text-warning' : 'text-destructive'} />
                </ItemMedia>
                <ItemContent>
                    <ItemTitle className="text-muted-foreground">{state.badge}</ItemTitle>
                </ItemContent>
                {hasAction && (
                    <ItemActions>
                        <Button asChild variant="link" size="sm">
                            <a href={state.href} {...linkProps}>{state.cta}</a>
                        </Button>
                    </ItemActions>
                )}
            </Item>
        );
    }

    return (
        <Empty className="border bg-card">
            <EmptyHeader>
                <EmptyMedia variant="icon">
                    <Icon className={state.tone === 'warning' ? 'text-warning' : 'text-destructive'} />
                </EmptyMedia>
                <div className="flex items-center justify-center gap-2">
                    <ProBadge />
                    <Badge variant={state.tone}>{state.badge}</Badge>
                </div>
                <EmptyTitle>{title || state.heading}</EmptyTitle>
                {valueProp && <EmptyDescription>{valueProp}</EmptyDescription>}
            </EmptyHeader>
            {hasAction && (
                <EmptyContent>
                    <Button asChild size="sm">
                        <a href={state.href} {...linkProps}>
                            {state.cta}
                            <ExternalLink />
                        </a>
                    </Button>
                </EmptyContent>
            )}
        </Empty>
    );
}
