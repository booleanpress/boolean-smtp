import { ExternalLink } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useTranslations } from '@/hooks/useTranslations';
import { cn } from '@/lib/utils';
import { DOCS_URL } from '@/config/docs';

// Last-resort fallback for a provider with no docsUrl at all (every MAIL_PROVIDERS entry has
// one via mailerDocsUrl() in config/docs.js, so this should rarely fire in practice).
export const GENERAL_DOCS_URL = DOCS_URL;

function interpolate(text, vars = {}) {
    if (text == null) {
        return text;
    }
    return String(text).replace(/\{\{(\w+)\}\}/g, (_, k) => (vars[k] != null ? String(vars[k]) : ''));
}

/** Closing CTA shared by every setup guide so the card never trails off with no next step. */
export function GuideDocLink({ href = GENERAL_DOCS_URL, label }) {
    const { t } = useTranslations();
    return (
        <div className="mt-4 space-y-3">
            <Separator />
            <Button variant="outline" className="w-full" asChild>
                <a href={href} target="_blank" rel="noopener noreferrer">
                    {label || t('setup_guide.full_documentation', 'Full documentation')}
                    <ExternalLink className="text-muted-foreground" aria-hidden="true" />
                </a>
            </Button>
        </div>
    );
}

export function resolveVariantKey(deliveryMode, keyStore) {
    const mode = deliveryMode === 'api' ? 'api' : 'smtp';
    const store = keyStore === 'wp_config' || keyStore === 'wp-config' || keyStore === 'env' ? 'wp_config' : 'db';
    return `${mode}_${store === 'wp_config' ? 'wp_config' : 'db'}`;
}

/**
 * Numbered setup timeline shared by every setup guide.
 * Compose as <GuideTimeline><GuideStep title …>…</GuideStep></GuideTimeline>.
 */
export function GuideTimeline({ className, children, ...props }) {
    return (
        <ol className={cn('animate-in fade-in duration-300', className)} {...props}>
            {children}
        </ol>
    );
}

/**
 * One step on the timeline. `active` highlights the marker (the first step),
 * `last` hides the connector line below it.
 */
export function GuideStep({ title, active = false, last = false, children }) {
    return (
        <li className={cn('relative pl-8', last ? 'pb-0.5' : 'pb-4')}>
            {!last && <span className="absolute top-5 bottom-0 left-2 w-px bg-border" aria-hidden="true" />}
            <span
                aria-hidden="true"
                className={cn(
                    'absolute top-0.5 left-0 z-10 flex size-4 items-center justify-center rounded-full border bg-background',
                    active ? 'border-primary ring-4 ring-primary/10' : 'border-2 border-muted'
                )}
            >
                <span className={cn('rounded-full', active ? 'size-2 bg-primary' : 'size-1.5 bg-muted-foreground/30')} />
            </span>
            <div className="space-y-1">
                <h4 className="text-sm leading-none font-semibold text-foreground">{title}</h4>
                {children}
            </div>
        </li>
    );
}

export default function JsonVariantSetupGuide({
    guideData,
    deliveryMode = 'api',
    keyStore = 'db',
    docsUrl = '',
    providerName = '',
}) {
    const { t } = useTranslations();
    const vars = { provider: providerName || 'provider', docsUrl: docsUrl || '' };
    const variantKey = resolveVariantKey(deliveryMode, keyStore);
    const variant = guideData?.[variantKey] || guideData?.api_db || guideData?.smtp_db || {};
    const { title, intro, steps = [], fullDoc } = variant;
    const docHref = fullDoc?.url || docsUrl || GENERAL_DOCS_URL;
    const docLabel = fullDoc?.label;

    return (
        <div className="animate-in fade-in duration-300">
            {title && (
                <p className="mb-2 text-xs font-semibold text-primary">{interpolate(title, vars)}</p>
            )}
            {intro && (
                <p className="mb-4 text-sm leading-snug text-muted-foreground">{interpolate(intro, vars)}</p>
            )}

            <GuideTimeline>
                {steps.map((step, index) => (
                    <GuideStep
                        key={`${step.title}-${index}`}
                        title={interpolate(step.title, vars)}
                        active={index === 0}
                        last={index === steps.length - 1}
                    >
                        {(step.paragraphs || []).map((p, i) => (
                            <p key={i} className="text-sm leading-snug text-muted-foreground">{interpolate(p, vars)}</p>
                        ))}
                        {step.link?.href && (
                            <p className="pt-1">
                                <a
                                    href={interpolate(step.link.href, vars)}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
                                >
                                    {interpolate(step.link.label || t('setup_guide.open_link', 'Open link'), vars)}
                                    <ExternalLink className="size-3" aria-hidden="true" />
                                </a>
                            </p>
                        )}
                    </GuideStep>
                ))}
            </GuideTimeline>

            <GuideDocLink href={docHref} label={docLabel} />
        </div>
    );
}
