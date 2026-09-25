import { Lock, ShieldAlert, ExternalLink } from 'lucide-react';
import { useProCapability } from '@/hooks/useProCapability';
import { useTranslations } from '@/hooks/useTranslations';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';

const MODE_FEATURE_MAP = {
    one_click: 'connections.oneclick.save',
    app_permission: 'connections.app_permission.save',
};

/**
 * Full-width delivery mode tabs (SMTP vs API) for multi-mode mailers, drawn at the same height
 * and inset as the form's segmented controls.
 *
 * SMTP and API are the plugin's own modes. One Click and Application Permission are modes the
 * add-on provides: switchable (preview content visible) while it is installed but unlicensed,
 * with a CTA banner shown via ProModeGateBanner; the add-on refuses to save them.
 *
 * When BooleanSMTP Pro is not installed, Pro-only modes are left out of the tab list
 * entirely rather than shown locked -- the free WordPress.org listing should never
 * render an upsell tab/badge for a plugin that isn't there. The tab (and its normal
 * lock/banner treatment) reappears on its own once Pro is installed, no separate
 * "Pro injects this" wiring needed.
 */
export default function MailerModeTabs({ modes = {}, value, onChange }) {
    const { isProInstalled } = useProCapability();
    const active = value || 'api';
    const entries = Object.entries(modes)
        .filter(([key]) => isProInstalled || !MODE_FEATURE_MAP[key])
        .sort((a, b) => {
            const rank = (k) => (k === 'api' ? 0 : k === 'smtp' ? 1 : k === 'one_click' ? 2 : k === 'app_permission' ? 3 : 9);
            return rank(a[0]) - rank(b[0]);
        });
    if (entries.length < 2) {
        return null;
    }

    return (
        <Tabs value={active} onValueChange={onChange} className="w-full">
            <TabsList className="w-full">
                {entries.map(([key, label]) => (
                    <ModeTab key={key} modeKey={key} label={label} />
                ))}
            </TabsList>
        </Tabs>
    );
}

function ModeTab({ modeKey, label }) {
    const featureKey = MODE_FEATURE_MAP[modeKey];
    const { hasFeature } = useProCapability(featureKey);

    const isProFeature = Boolean(featureKey);
    const isLocked = isProFeature && !hasFeature;

    return (
        <TabsTrigger value={modeKey} className="flex-1">
            {label}
            {isLocked && <Lock className="size-3.5 opacity-70" aria-hidden="true" />}
        </TabsTrigger>
    );
}

/**
 * Banner shown above form fields when the active delivery mode requires Pro.
 * The form fields remain visible for preview but save is blocked.
 */
export function ProModeGateBanner({ activeMode }) {
    const { t } = useTranslations();
    const featureKey = MODE_FEATURE_MAP[activeMode];
    const { hasFeature, lockState, upgradeUrl } = useProCapability(featureKey);

    if (!featureKey || hasFeature) return null;

    const isNotInstalled = lockState === 'not_installed';
    const actionHref = isNotInstalled ? upgradeUrl : '#/settings';

    return (
        <Alert variant="warning">
            <ShieldAlert />
            <AlertDescription className="flex flex-wrap items-center gap-3">
                <span className="flex-1 font-medium">
                    {isNotInstalled
                        ? t('connection_form.pro_mode_requires_pro', 'This delivery mode requires BooleanSMTP Pro. You can preview the settings below.')
                        : t('connection_form.pro_mode_activate_license', 'Activate your Pro license to save connections using this delivery mode.')}
                </span>
                {actionHref && (
                    <Button variant="outline" size="sm" asChild className="shrink-0">
                        <a
                            href={actionHref}
                            target={isNotInstalled ? '_blank' : undefined}
                            rel={isNotInstalled ? 'noreferrer' : undefined}
                        >
                            {isNotInstalled ? t('connection_form.get_pro', 'Get Pro') : t('connection_form.activate_pro', 'Activate Pro')}
                            {isNotInstalled && <ExternalLink />}
                        </a>
                    </Button>
                )}
            </AlertDescription>
        </Alert>
    );
}

/**
 * Hook to check if the active delivery mode is Pro-locked.
 * Used by ConnectionForm to disable the save button.
 */
export function useIsModeLocked(activeMode) {
    const featureKey = MODE_FEATURE_MAP[activeMode];
    const { hasFeature } = useProCapability(featureKey);
    return featureKey ? !hasFeature : false;
}
