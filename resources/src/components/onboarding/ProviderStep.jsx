import { GENERAL_DOCS_URL } from '@/components/setup-guides/JsonVariantSetupGuide';
import { TriangleAlert } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useTranslations } from '@/hooks/useTranslations';
import ChoiceCards from './ChoiceCards';
import PreviewCard from './PreviewCard';
import StepShell from './StepShell';
import { wizardProviders } from './providerCatalog';

/**
 * Per-provider copy the Provider step and the Preview use. Keys are translation keys with English
 * fallbacks; the method line names how the free plugin delivers through that provider.
 *
 * @since 1.0.0
 *
 * @param {(key: string, fallback: string) => string} t Translator.
 * @returns {Record<string, { method: string, fit: string, needs: string[] }>}
 */
export function providerCopy(t) {
    return {
        ses: {
            method: t('onboarding.provider_method_ses', 'HTTPS API · access keys'),
            fit: t('onboarding.provider_fit_ses', 'Best for high volume at low cost.'),
            needs: [
                t('onboarding.provider_needs_ses_1', 'An AWS account with SES enabled in a region'),
                t('onboarding.provider_needs_ses_2', 'An IAM access key pair allowed to send'),
                t('onboarding.provider_needs_ses_3', 'A From address or domain verified in SES'),
            ],
        },
        google: {
            method: t('onboarding.provider_method_google', 'HTTPS API · OAuth (your own client)'),
            fit: t('onboarding.provider_fit_google', 'Best for a Google Workspace or Gmail mailbox.'),
            needs: [
                t('onboarding.provider_needs_google_1', 'An OAuth client from Google Cloud (the guide walks you through it)'),
                t('onboarding.provider_needs_google_2', 'Signing in to the mailbox that will send'),
            ],
        },
        outlook: {
            method: t('onboarding.provider_method_outlook', 'HTTPS API · OAuth (your own app registration)'),
            fit: t('onboarding.provider_fit_outlook', 'Best for a Microsoft 365 or Outlook mailbox.'),
            needs: [
                t('onboarding.provider_needs_outlook_1', 'An app registration in Microsoft Entra with Mail.Send'),
                t('onboarding.provider_needs_outlook_2', 'Signing in to the mailbox that will send'),
            ],
        },
        smtp: {
            method: t('onboarding.provider_method_smtp', 'SMTP'),
            fit: t('onboarding.provider_fit_smtp', 'Works with any mail server or SMTP relay.'),
            needs: [
                t('onboarding.provider_needs_smtp_1', 'The server host and port'),
                t('onboarding.provider_needs_smtp_2', 'A username and password, unless the relay needs none'),
            ],
        },
        php: {
            method: t('onboarding.provider_method_php', "WordPress mail() — the server's own mail"),
            fit: t('onboarding.provider_fit_php', 'No account needed; delivery depends on the hosting server.'),
            needs: [t('onboarding.provider_needs_php_1', 'Nothing — but expect more mail in spam folders')],
        },
    };
}

/**
 * Step 2 — Provider: the five providers as choice cards.
 *
 * @since 1.0.0
 *
 * @param {object} props
 * @param {string|null} props.driver Selected driver.
 * @param {(driver: string) => void} props.onDriverChange
 * @param {() => void} props.onBack
 * @param {() => void} props.onContinue
 * @param {boolean} props.continueBusy
 * @param {{ fromEmail?: string, fromName?: string }} props.sender Sender prefill for the preview envelope.
 * @param {{ stepNumber: number, stepCount: number, stepName: string }} props.shell
 */
export default function ProviderStep({ driver, onDriverChange, onBack, onContinue, continueBusy, sender, shell }) {
    const { t } = useTranslations();
    const copy = providerCopy(t);
    const providers = wizardProviders();
    const selected = providers.find(p => p.driver === driver) || null;

    const options = providers.map(p => ({
        value: p.driver,
        title: t(`mailers.${p.driver}.name`, p.name),
        description: copy[p.driver]?.method,
        note: copy[p.driver]?.fit,
        badge: p.driver !== 'php' && p.recommended ? { variant: 'default', label: t('onboarding.provider_recommended', 'Recommended') } : undefined,
        mark: p.driver === 'php' ? (
            <Tooltip>
                <TooltipTrigger asChild>
                    <span className="inline-flex shrink-0 text-warning" role="img" aria-label={t('onboarding.provider_not_recommended', 'Not recommended')}>
                        <TriangleAlert className="size-4" aria-hidden="true" />
                    </span>
                </TooltipTrigger>
                <TooltipContent className="max-w-xs">
                    {t('onboarding.provider_not_recommended_why', 'Not recommended: mail leaves from the web server itself, with no authentication. Many hosts throttle or block it and inbox providers trust it less.')}
                </TooltipContent>
            </Tooltip>
        ) : undefined,
        media: (
            <span className="flex h-9 w-14 shrink-0 items-center justify-center rounded-md bg-background p-1.5">
                {(p.compactLogo || p.logo) ? <img src={p.compactLogo || p.logo} alt="" className="h-full w-auto object-contain" /> : p.icon}
            </span>
        ),
    }));

    return (
        <StepShell
            {...shell}
            title={t('onboarding.provider_title', 'How should this site send email?')}
            description={t('onboarding.provider_desc', 'Pick the service your mail will go through. You can add more connections later.')}
            onBack={onBack}
            onContinue={onContinue}
            continueDisabled={!driver}
            continueBusy={continueBusy}
            preview={(
                <PreviewCard
                    heading={t('onboarding.provider_preview_heading', 'What this site will send')}
                    envelope={{
                        fromEmail: sender?.fromEmail,
                        fromName: sender?.fromName,
                        providerName: selected ? t(`mailers.${selected.driver}.name`, selected.name) : '',
                        methodLine: selected ? copy[selected.driver]?.method : '',
                    }}
                    list={selected ? { title: t('onboarding.provider_preview_needs', 'You will need:'), items: copy[selected.driver]?.needs || [] } : null}
                    notes={selected ? [] : [t('onboarding.provider_preview_choose', 'Choose a provider to see what it needs.')]}
                />
            )}
        >
            <ChoiceCards
                idPrefix="onboarding-provider"
                value={driver || ''}
                onValueChange={onDriverChange}
                ariaLabel={t('onboarding.provider_title', 'How should this site send email?')}
                options={options}
            />
            <p className="text-sm text-muted-foreground">
                {t('onboarding.provider_relay_hint', 'Using SendGrid, Mailgun, Postmark or another service? Most offer an SMTP relay — choose Custom SMTP.')}{' '}
                <Button variant="link" size="sm" className="h-auto p-0" asChild>
                    <a href={GENERAL_DOCS_URL} target="_blank" rel="noopener noreferrer">{t('onboarding.provider_docs', 'Provider guides')}</a>
                </Button>
            </p>
        </StepShell>
    );
}
