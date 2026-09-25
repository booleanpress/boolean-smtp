import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { cn } from '@/lib/utils';
import { useTranslations } from '@/hooks/useTranslations';
import { importStatusBadge, importStatusHint } from '@/components/migration/importStatus';
import SenderConflictChoices, { migrationConflicts } from '@/components/migration/SenderConflictChoices';
import { MAIL_PROVIDERS } from '@/config/mailers';
import PreviewCard from './PreviewCard';
import StepShell from './StepShell';

/**
 * The display name of a target driver.
 *
 * @since 1.0.0
 *
 * @param {(key: string, fallback: string) => string} t Translator.
 * @param {string|null} driver Target driver id.
 * @returns {string}
 */
export function driverName(t, driver) {
    const provider = MAIL_PROVIDERS.find(p => p.driver === driver);
    return provider ? t(`mailers.${provider.driver}.name`, provider.name) : '';
}

/**
 * Step 2 in the import branch: the connections found in the chosen plugin, each with what the
 * assessment made of it. The user picks the one to set up first; the others become inactive
 * drafts alongside it.
 *
 * @since 1.0.0
 *
 * @param {object} props
 * @param {string} props.sourceName The source plugin's display name.
 * @param {Array<object>} props.connections Assessed connections from the dry run.
 * @param {string|null} props.picked Source key of the chosen connection.
 * @param {(sourceKey: string) => void} props.onPick
 * @param {() => void} props.onBack
 * @param {() => void} props.onContinue
 * @param {boolean} props.continueBusy
 * @param {{ stepNumber: number, stepCount: number, stepName: string }} props.shell
 * @param {Record<string, 'keep'|'replace'>} [props.resolutions] Answer per connection whose sender the site already uses.
 * @param {(key: string, answer: 'keep'|'replace') => void} [props.onResolve]
 */
export default function ImportPickStep({ sourceName, connections, picked, onPick, onBack, onContinue, continueBusy, shell, resolutions = {}, onResolve = () => {} }) {
    const { t } = useTranslations();
    const chosen = connections.find(c => c.source_key === picked) || null;
    const importable = connections.filter(c => c.status !== 'unsupported');
    const others = importable.filter(c => c !== chosen);

    const previewRows = chosen ? [
        { label: t('onboarding.import_preview_status', 'Status'), badge: importStatusBadge(t, chosen.status, chosen.conversion) },
        ...(chosen.missing?.length ? [{ label: t('onboarding.import_preview_missing', 'To enter again'), value: chosen.missing.join(', ') }] : []),
    ] : [];
    const previewList = chosen ? {
        title: t('onboarding.import_preview_next', 'Next:'),
        items: [
            t('onboarding.import_preview_next_1', 'Connect shows its settings, prefilled from {{plugin}}', { plugin: sourceName }),
            t('onboarding.import_preview_next_2', 'Verify tests it the same way as a fresh set-up'),
            others.length > 0
                ? t('onboarding.import_preview_next_3', '{{count}} other connection(s) become inactive drafts you can switch on later', { count: others.length })
                : t('onboarding.import_preview_next_3_none', 'Nothing is switched on until you apply'),
        ],
    } : null;

    return (
        <StepShell
            {...shell}
            title={t('onboarding.import_title', 'Which connection should this site send through?')}
            description={t('onboarding.import_desc', 'These are the connections {{plugin}} holds. Pick the one to set up first — the rest come along as inactive drafts.', { plugin: sourceName })}
            onBack={onBack}
            onContinue={onContinue}
            continueDisabled={!chosen}
            continueBusy={continueBusy}
            preview={(
                <PreviewCard
                    heading={t('onboarding.import_preview_heading', 'What this site will send')}
                    envelope={chosen ? {
                        fromEmail: chosen.settings?.from_email,
                        fromName: chosen.settings?.from_name,
                        providerName: driverName(t, chosen.driver),
                        methodLine: chosen.conversion ? t('onboarding.import_relay_line', 'Converted to the provider\'s SMTP relay') : '',
                    } : null}
                    rows={previewRows}
                    list={previewList}
                    notes={chosen ? [] : [t('onboarding.import_preview_choose', 'Choose a connection to see what comes across.')]}
                />
            )}
        >
            <RadioGroup
                value={picked || ''}
                onValueChange={onPick}
                aria-label={t('onboarding.import_list_label', 'Connections found in {{plugin}}', { plugin: sourceName })}
                className="gap-0 divide-y overflow-hidden rounded-lg border bg-card"
            >
                {connections.map(connection => {
                    const id = `onboarding-import-${connection.source_key}`;
                    const disabled = connection.status === 'unsupported';
                    const badge = importStatusBadge(t, connection.status, connection.conversion);
                    const selected = picked === connection.source_key;
                    return (
                        <Label
                            key={connection.source_key}
                            htmlFor={id}
                            className={cn(
                                'flex items-start gap-3 px-3 py-3 text-sm font-normal transition-colors',
                                disabled ? 'cursor-not-allowed text-muted-foreground' : 'cursor-pointer hover:bg-muted/50',
                                selected && 'bg-primary/5'
                            )}
                        >
                            <RadioGroupItem value={connection.source_key} id={id} disabled={disabled} className="mt-0.5" />
                            <span className="flex min-w-0 flex-1 flex-col gap-1">
                                <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <span className={cn('font-medium', !disabled && 'text-foreground')}>{connection.name}</span>
                                    {connection.driver && <span className="text-xs text-muted-foreground">{driverName(t, connection.driver)}</span>}
                                    <Badge variant={badge.variant}>{badge.label}</Badge>
                                    {connection.was_default && (
                                        <Badge variant="outline">{t('onboarding.import_was_default', 'This was your default connection')}</Badge>
                                    )}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {connection.settings?.from_email ? `${connection.settings.from_email} · ` : ''}{importStatusHint(t, connection)}
                                </span>
                            </span>
                        </Label>
                    );
                })}
            </RadioGroup>
            <SenderConflictChoices conflicts={migrationConflicts(connections)} value={resolutions} onChange={onResolve} />
            {importable.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    {t('onboarding.import_none_importable', 'None of these connections can be imported. Go back and set up a new connection instead.')}
                </p>
            )}
        </StepShell>
    );
}
