import { useState } from 'react';
import { Check, Pencil, Trash2 } from 'lucide-react';
import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription,
    AlertDialogFooter, AlertDialogHeader, AlertDialogTitle, AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Checkbox } from '@/components/ui/checkbox';
import { Switch } from '@/components/ui/switch';
import { useTranslations } from '@/hooks/useTranslations';
import PreviewCard from './PreviewCard';
import SummaryRows from './SummaryRows';
import StepShell from './StepShell';

/**
 * Step 5 — Review: what will be applied, the three preferences that go with it, and the one
 * action that switches the draft on. Discard deletes the draft after a confirmation.
 *
 * @since 1.0.0
 *
 * @param {object} props
 * @param {string} props.name Connection name (editable).
 * @param {(name: string) => void} props.onNameChange
 * @param {string} props.providerName
 * @param {string} props.methodLine
 * @param {{ fromEmail?: string, fromName?: string }} props.sender
 * @param {Array<{ label: string, value?: string, badge?: object }>} props.credentialRows Credential state rows from the Connect step.
 * @param {{ status: string, at?: string }} props.probe
 * @param {{ status: string, to?: string, at?: string }} props.test
 * @param {boolean} props.hasProbe
 * @param {boolean} props.hasOtherActive Whether another active connection exists (shows the primary switch).
 * @param {{ makePrimary: boolean, retention: number, importLogs?: boolean }} props.review
 * @param {(key: string, value: unknown) => void} props.onReviewChange
 * @param {{ sourceName: string, others: Array<{ name: string, status: string }>, logs: { available: boolean, reason?: string|null, total?: number, rows_in_window?: number }, suggestedRetention: number|null }|null} [props.importInfo] The import branch's facts: the other drafts, the log offer, the source's retention.
 * @param {() => void} props.onApply
 * @param {boolean} props.applyBusy
 * @param {() => void} props.onDiscard
 * @param {boolean} props.discardBusy
 * @param {() => void} props.onBack
 * @param {{ stepNumber: number, stepCount: number, stepName: string }} props.shell
 */
export default function ReviewStep({
    name,
    onNameChange,
    providerName,
    methodLine,
    sender,
    credentialRows,
    probe,
    test,
    hasProbe,
    hasOtherActive,
    review,
    onReviewChange,
    importInfo = null,
    onApply,
    applyBusy,
    onDiscard,
    discardBusy,
    onBack,
    shell,
}) {
    const { t } = useTranslations();
    const [editingName, setEditingName] = useState(false);

    const probeBadge = !hasProbe
        ? { variant: 'outline', label: t('onboarding.verify_probe_na', 'Not available') }
        : probe.status === 'passed'
            ? { variant: 'success', label: t('onboarding.verify_passed', 'Passed') }
            : probe.status === 'failed'
                ? { variant: 'destructive', label: t('onboarding.verify_failed', 'Failed') }
                : { variant: 'outline', label: t('onboarding.verify_not_run', 'Not run') };
    const testBadge = test.status === 'accepted'
        ? { variant: 'success', label: t('onboarding.verify_accepted', 'Accepted') }
        : test.status === 'failed'
            ? { variant: 'destructive', label: t('onboarding.verify_failed', 'Failed') }
            : { variant: 'warning', label: t('onboarding.review_test_not_sent', 'Test not sent') };

    const willPrimary = review.makePrimary || !hasOtherActive;
    const changes = [
        t('onboarding.review_change_activate', 'Activate 1 connection: {{name}}', { name }),
        ...(willPrimary ? [t('onboarding.review_change_primary', 'Set it as the primary connection')] : [t('onboarding.review_change_not_primary', 'Keep the current primary connection')]),
        t('onboarding.review_change_sender', 'Use its sender as the site default where none is set'),
        t('onboarding.review_change_retention', 'Keep email logs for {{days}} days', { days: review.retention }),
        ...(importInfo?.others?.length ? [t('onboarding.review_change_others', 'Leave {{count}} other imported draft(s) inactive', { count: importInfo.others.length })] : []),
        ...(importInfo?.logs?.available && review.importLogs ? [t('onboarding.review_change_logs', 'Import {{count}} email logs from {{plugin}}', { count: importInfo.logs.total ?? 0, plugin: importInfo.sourceName })] : []),
    ];
    const logsOffer = importInfo?.logs || null;

    return (
        <StepShell
            {...shell}
            title={t('onboarding.review_title', 'Apply this setup?')}
            description={t('onboarding.review_desc', 'Check the summary, choose the preferences that go with it, then apply. Until then nothing has changed.')}
            onBack={onBack}
            onContinue={onApply}
            continueLabel={t('onboarding.review_apply', 'Apply and finish')}
            continueBusy={applyBusy}
            continueDisabled={discardBusy}
            footnote={t('onboarding.review_footnote', 'Apply switches the connection on and saves the preferences above. You can change any of them later in Settings.')}
            secondaryAction={(
                <AlertDialog>
                    <AlertDialogTrigger asChild>
                        <Button type="button" variant="ghost" size="sm" className="text-destructive" disabled={applyBusy || discardBusy}>
                            {discardBusy ? <Spinner /> : <Trash2 />}
                            {t('onboarding.review_discard', 'Discard draft')}
                        </Button>
                    </AlertDialogTrigger>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>{t('onboarding.discard_title', 'Discard this draft?')}</AlertDialogTitle>
                            <AlertDialogDescription>
                                {t('onboarding.discard_desc', 'This deletes the inactive draft "{{name}}" and its stored credentials. Nothing else changes — your site keeps sending the way it does now.', { name })}
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>{t('common.cancel', 'Cancel')}</AlertDialogCancel>
                            <AlertDialogAction variant="destructive" onClick={onDiscard}>
                                {t('onboarding.discard_confirm', 'Discard draft')}
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            )}
            preview={(
                <PreviewCard
                    heading={t('onboarding.review_preview_heading', 'What Apply will change')}
                    envelope={{ fromEmail: sender?.fromEmail, fromName: sender?.fromName, providerName, methodLine }}
                    list={{ title: t('onboarding.review_preview_changes', 'Apply will:'), items: changes }}
                />
            )}
        >
            <Card className="gap-4 py-5">
                <CardHeader className="gap-0.5">
                    <CardTitle className="flex items-center gap-2">
                        {editingName ? (
                            <Field className="flex-1 gap-1.5">
                                <FieldLabel htmlFor="onboarding-connection-name" className="sr-only">{t('onboarding.review_name', 'Connection name')}</FieldLabel>
                                <div className="flex gap-2">
                                    <Input id="onboarding-connection-name" value={name} onChange={e => onNameChange(e.target.value)} autoFocus />
                                    <Button type="button" variant="outline" size="icon-sm" onClick={() => setEditingName(false)} aria-label={t('onboarding.review_name_done', 'Done editing name')}>
                                        <Check />
                                    </Button>
                                </div>
                            </Field>
                        ) : (
                            <>
                                <span>{name}</span>
                                <Button type="button" variant="ghost" size="icon-xs" onClick={() => setEditingName(true)} aria-label={t('onboarding.review_name_edit', 'Edit connection name')} title={t('onboarding.review_name_edit', 'Edit connection name')}>
                                    <Pencil />
                                </Button>
                            </>
                        )}
                    </CardTitle>
                    <CardDescription>{providerName} · {methodLine}</CardDescription>
                </CardHeader>
                <CardContent>
                    <SummaryRows
                        rows={[
                            {
                                label: t('onboarding.review_sender', 'Sender'),
                                value: sender?.fromName ? `${sender.fromName} <${sender.fromEmail}>` : sender?.fromEmail,
                            },
                            ...credentialRows,
                            { label: t('onboarding.verify_probe_label', 'Connection check'), value: probe.at, badge: probeBadge },
                            { label: t('onboarding.verify_test_label', 'Test email'), value: test.status === 'accepted' ? test.to : undefined, badge: testBadge },
                        ]}
                    />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{t('onboarding.review_prefs_title', 'Preferences')}</CardTitle>
                    <CardDescription>{t('onboarding.review_prefs_desc', 'Saved together with the connection when you apply.')}</CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col gap-5">
                    {hasOtherActive && (
                        <Field orientation="horizontal" className="items-start justify-between gap-4">
                            <div className="flex flex-col gap-1">
                                <FieldLabel htmlFor="onboarding-make-primary">{t('onboarding.review_primary', 'Make this the primary connection')}</FieldLabel>
                                <FieldDescription>{t('onboarding.review_primary_help', 'Another connection is active today. Turn this off to keep it as the primary sender.')}</FieldDescription>
                            </div>
                            <Switch id="onboarding-make-primary" checked={review.makePrimary} onCheckedChange={v => onReviewChange('makePrimary', v)} />
                        </Field>
                    )}
                    <Field className="gap-1.5">
                        <FieldLabel htmlFor="onboarding-retention">{t('onboarding.review_retention', 'Keep email logs for')}</FieldLabel>
                        <Select value={String(review.retention)} onValueChange={v => onReviewChange('retention', Number(v))}>
                            <SelectTrigger id="onboarding-retention" className="w-full sm:w-56">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="7">{t('onboarding.retention_7_days', '7 days')}</SelectItem>
                                <SelectItem value="30">{t('onboarding.retention_30_days', '30 days')}</SelectItem>
                                <SelectItem value="90">{t('onboarding.retention_90_days', '90 days')}</SelectItem>
                            </SelectContent>
                        </Select>
                        <FieldDescription>{t('onboarding.review_retention_help', 'Older entries are pruned daily.')}</FieldDescription>
                    </Field>
                </CardContent>
            </Card>

            {importInfo && (
                <Card>
                    <CardHeader>
                        <CardTitle>{t('onboarding.review_import_title', 'From {{plugin}}', { plugin: importInfo.sourceName })}</CardTitle>
                        <CardDescription>{t('onboarding.review_import_desc', 'What else the import brings, and what it leaves alone.')}</CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-5">
                        {importInfo.others.length > 0 && (
                            <div className="flex flex-col gap-1.5">
                                <p className="text-sm font-medium">{t('onboarding.review_import_others', 'Other imported drafts stay inactive')}</p>
                                <ul className="list-disc space-y-1 pl-4 text-sm text-muted-foreground marker:text-muted-foreground/70" data-testid="review-import-others">
                                    {importInfo.others.map(other => <li key={other.name}>{other.name}</li>)}
                                </ul>
                                <p className="text-xs text-muted-foreground">{t('onboarding.review_import_others_help', 'Switch any of them on later under Connections.')}</p>
                            </div>
                        )}
                        {importInfo.suggestedRetention !== null && (
                            <p className="text-sm text-muted-foreground">
                                {t('onboarding.review_import_retention', '{{plugin}} kept its logs for {{days}} days; the retention above follows it.', { plugin: importInfo.sourceName, days: importInfo.suggestedRetention })}
                            </p>
                        )}
                        {logsOffer?.available ? (
                            <Field orientation="horizontal" className="items-start gap-3">
                                <Checkbox id="onboarding-import-logs" checked={Boolean(review.importLogs)} onCheckedChange={v => onReviewChange('importLogs', Boolean(v))} className="mt-0.5" />
                                <div className="flex flex-col gap-1">
                                    <FieldLabel htmlFor="onboarding-import-logs">{t('onboarding.review_import_logs', 'Import {{count}} email logs from {{plugin}}', { count: logsOffer.total ?? 0, plugin: importInfo.sourceName })}</FieldLabel>
                                    <FieldDescription>
                                        {t('onboarding.review_import_logs_help', '{{count}} rows are within the {{days}}-day retention chosen above; older rows are not imported because the nightly prune would remove them. Attachments are listed, not copied.', { count: logsOffer.total ?? 0, days: review.retention })}
                                    </FieldDescription>
                                </div>
                            </Field>
                        ) : (
                            <p className="text-sm text-muted-foreground" data-testid="review-import-no-logs">
                                {logsOffer?.reason || t('onboarding.review_import_no_logs', '{{plugin}} has no email log to import.', { plugin: importInfo.sourceName })}
                            </p>
                        )}
                    </CardContent>
                </Card>
            )}
        </StepShell>
    );
}
