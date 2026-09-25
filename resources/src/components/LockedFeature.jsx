import { useProCapability } from '@/hooks/useProCapability';
import { FeatureLockCard } from './FeatureLockCard';

/**
 * Gates children behind a Pro feature check.
 * Unlocked → children. Locked → blurred preview with a FeatureLockCard overlay
 * (or a compact inline lock when `inline`).
 */
export function LockedFeature({ featureKey, title, valueProp, inline = false, children }) {
    const { hasFeature, lockState, upgradeUrl, valueProp: defaultValueProp } = useProCapability(featureKey);

    if (hasFeature) {
        return <>{children}</>;
    }

    const resolvedValueProp = valueProp || defaultValueProp;

    if (inline) {
        return (
            <FeatureLockCard lockState={lockState} title={title} valueProp={resolvedValueProp} upgradeUrl={upgradeUrl} compact />
        );
    }

    return (
        <div className="relative min-h-72 overflow-hidden rounded-lg border">
            <div className="pointer-events-none absolute inset-0 select-none opacity-30 blur-[3px] grayscale" aria-hidden="true">
                {children}
            </div>
            <div className="relative z-10 flex min-h-72 items-center justify-center p-6">
                <div className="w-full max-w-sm">
                    <FeatureLockCard lockState={lockState} title={title} valueProp={resolvedValueProp} upgradeUrl={upgradeUrl} />
                </div>
            </div>
        </div>
    );
}
