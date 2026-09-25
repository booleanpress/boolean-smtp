import { Link } from 'react-router';
import { Download } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { useProCapability } from '@/hooks/useProCapability';
import { useTranslations } from '@/hooks/useTranslations';

/**
 * The keep-or-replace question for every imported connection whose sender the site already
 * uses. "Keep" is preselected; "Replace" overwrites the site's connection in place and cannot be
 * undone, so a warning appears as soon as one row is set to replace — with a link to the Pro
 * Import & Export tool, to download a settings export first, when the add-on is licensed.
 *
 * @since 1.0.0
 *
 * @param {object} props
 * @param {Array<{ key: string, name: string, sender: string, existingName: string, replaceAllowed?: boolean, reason?: string|null }>} props.conflicts
 * @param {Record<string, 'keep'|'replace'>} props.value Answer per conflict key.
 * @param {(key: string, answer: 'keep'|'replace') => void} props.onChange
 * @param {() => void} [props.onDownloadExport] Downloads a settings export in place; given, the
 *        warning offers it as a button instead of the link to the Import & Export tool.
 */
export default function SenderConflictChoices({ conflicts, value, onChange, onDownloadExport }) {
    const { t } = useTranslations();
    const { isProLicensed } = useProCapability();

    if (!conflicts || conflicts.length === 0) {
        return null;
    }

    const anyReplace = conflicts.some(conflict => value[conflict.key] === 'replace');

    return (
        <div className="flex flex-col gap-3" data-testid="sender-conflicts">
            {anyReplace && (
                <Alert variant="warning">
                    <AlertTitle>{t('migration.replace_warning_title', 'Replacing cannot be undone')}</AlertTitle>
                    {onDownloadExport ? (
                        <AlertDescription>
                            <Button type="button" variant="outline" size="sm" onClick={onDownloadExport}>
                                <Download />
                                {t('migration.download_export_first', 'Download a settings export first')}
                            </Button>
                        </AlertDescription>
                    ) : isProLicensed ? (
                        <AlertDescription>
                            <Link to="/tools/import-export" className="font-medium underline underline-offset-4">
                                {t('migration.download_export_first', 'Download a settings export first')}
                            </Link>
                        </AlertDescription>
                    ) : null}
                </Alert>
            )}
            {conflicts.map(conflict => {
                const answer = value[conflict.key] === 'replace' ? 'replace' : 'keep';
                const idKeep = `conflict-${conflict.key}-keep`;
                const idReplace = `conflict-${conflict.key}-replace`;
                return (
                    <div key={conflict.key} className="flex flex-col gap-2 rounded-lg border p-4" data-testid="sender-conflict-row">
                        <p className="text-sm">
                            {t('migration.conflict_text', "{{sender}} is already sent by '{{existing}}' on this site. The imported '{{name}}' uses the same sender.", { sender: conflict.sender, existing: conflict.existingName, name: conflict.name })}
                        </p>
                        <RadioGroup
                            value={answer}
                            onValueChange={next => onChange(conflict.key, next)}
                            aria-label={t('migration.conflict_choice', 'What to do with {{name}}', { name: conflict.name })}
                        >
                            <div className="flex items-center gap-2">
                                <RadioGroupItem value="keep" id={idKeep} />
                                <Label htmlFor={idKeep} className="font-normal">
                                    {t('migration.conflict_keep', "Keep '{{existing}}' — don't import this connection", { existing: conflict.existingName })}
                                </Label>
                            </div>
                            <div className="flex items-center gap-2">
                                <RadioGroupItem value="replace" id={idReplace} disabled={conflict.replaceAllowed === false} />
                                <Label htmlFor={idReplace} className="font-normal">
                                    {t('migration.conflict_replace', "Replace '{{existing}}' with the imported settings — this cannot be undone", { existing: conflict.existingName })}
                                </Label>
                            </div>
                        </RadioGroup>
                        {conflict.replaceAllowed === false && conflict.reason ? (
                            <p className="text-xs text-muted-foreground">{conflict.reason}</p>
                        ) : null}
                        {!isProLicensed ? (
                            <p className="text-xs text-muted-foreground">
                                {t('migration.conflict_pro', '(With BooleanSMTP Pro, both can be kept and used together.)')}
                            </p>
                        ) : null}
                    </div>
                );
            })}
        </div>
    );
}

/**
 * The conflicts of a migration assessment in the shape {@link SenderConflictChoices} takes.
 *
 * @since 1.0.0
 *
 * @param {Array<object>} connections Assessed connections from a dry run.
 * @returns {Array<object>}
 */
export function migrationConflicts(connections) {
    return (connections || [])
        .filter(connection => connection.sender_conflict && !connection.outcome)
        .map(connection => ({
            key: connection.source_key,
            name: connection.name,
            sender: connection.sender_conflict.sender,
            existingName: connection.sender_conflict.existing_name,
            replaceAllowed: connection.sender_conflict.replace_allowed !== false,
        }));
}

/**
 * One line on what an import did with a connection whose sender the site already had, or null.
 *
 * @since 1.0.0
 *
 * @param {(key: string, fallback: string, replacements?: object) => string} t Translator.
 * @param {object} connection Assessed connection from a run.
 * @returns {string|null}
 */
export function senderOutcomeText(t, connection) {
    const conflict = connection.sender_conflict;
    switch (connection.outcome) {
        case 'kept':
            return t('migration.outcome_kept', "Kept '{{existing}}' — not imported.", { existing: conflict?.existing_name || '' });
        case 'replaced':
            return connection.test
                ? t('migration.outcome_replaced_tested', "Replaced '{{existing}}' — test: {{result}}.", { existing: conflict?.existing_name || '', result: connection.test.healthy ? t('migration.test_healthy', 'healthy') : (connection.test.error || t('migration.test_failed', 'failed')) })
                : t('migration.outcome_replaced', "Replaced '{{existing}}'.", { existing: conflict?.existing_name || '' });
        case 'skipped':
            return t('migration.outcome_skipped', "Not imported: same sender as '{{name}}' in this plugin.", { name: connection.skipped_reason || '' });
        default:
            return null;
    }
}
