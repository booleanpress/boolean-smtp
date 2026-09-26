import { Download, Plus } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { useTranslations } from '@/hooks/useTranslations';
import ChoiceCards from './ChoiceCards';
import PreviewCard from './PreviewCard';
import StepShell from './StepShell';

/**
 * The Preview rows on the import path: the plugins found until one is chosen, then that
 * plugin's own facts — state, connections, email log, support.
 *
 * @since 1.0.0
 *
 * @param {(key: string, fallback: string, replacements?: object) => string} t Translator.
 * @param {Array<object>} sources Scan result.
 * @param {string|null} selected Selected source slug.
 * @returns {Array<{ label: string, value?: string, badge?: { variant: string, label: string }, wrap?: boolean }>}
 */
export function startPreviewRows(t, sources, selected) {
    const source = sources.find(s => s.slug === selected);
    if (!source) {
        return [{
            label: t('onboarding.start_preview_detected', 'Plugins found'),
            value: sources.length ? sources.map(s => s.name).join(', ') : t('onboarding.start_preview_none', 'None'),
            wrap: true,
        }];
    }
    const count = source.preview_connections ?? source.details?.connection_count;
    const logCount = source.preview_logs ?? source.details?.log_count;
    return [
        { label: t('onboarding.start_preview_plugin', 'Plugin'), value: source.name },
        {
            label: t('onboarding.start_preview_state', 'State'),
            value: source.is_active
                ? t('onboarding.source_active', 'Active plugin')
                : source.is_installed ? t('onboarding.source_installed', 'Installed, inactive') : t('onboarding.source_ghost', 'Settings left behind'),
        },
        { label: t('onboarding.start_preview_connections', 'Connections'), value: typeof count === 'number' ? String(count) : t('onboarding.source_settings_found', 'Settings found') },
        {
            label: t('onboarding.start_preview_logs', 'Email logs'),
            value: source.logs_available === false
                ? t('onboarding.source_no_logs_long', 'None — no email log in the free edition')
                : (typeof logCount === 'number' ? t('onboarding.start_preview_log_rows', '{{count}} within your retention', { count: logCount }) : '—'),
        },
        {
            label: t('onboarding.start_preview_support', 'Importer'),
            badge: source.supported
                ? { variant: 'success', label: t('onboarding.source_supported', 'Supported') }
                : { variant: 'outline', label: t('onboarding.source_unsupported', 'Not supported yet') },
        },
    ];
}

/**
 * Step 1 — Start: set up a new connection, or opt into importing another SMTP plugin's settings.
 * The scan for other plugins runs only after the import card is chosen; detected sources are
 * listed with whether their migrator is supported.
 *
 * @since 1.0.0
 *
 * @param {object} props
 * @param {'new'|'import'} props.path Selected card.
 * @param {(path: 'new'|'import') => void} props.onPathChange
 * @param {{ status: 'idle'|'loading'|'done'|'error', sources: Array<object>, error?: string }} props.scan
 * @param {string|null} props.migrationSource Selected supported source slug.
 * @param {(slug: string) => void} props.onMigrationSourceChange
 * @param {() => void} props.onContinue
 * @param {boolean} props.continueBusy
 * @param {{ stepNumber: number, stepCount: number, stepName: string }} props.shell
 */
export default function StartStep({ path, onPathChange, scan, migrationSource, onMigrationSourceChange, onContinue, continueBusy, shell }) {
    const { t } = useTranslations();

    // The plugins named on the card are exactly the ones a migrator exists for; the list comes
    // from the same catalog the scan answers with, so the card never promises more than it does.
    const importable = (window.BooleanSmtpAdmin?.migrationSources || []).filter(source => source.supported).map(source => source.name);
    const importNames = importable.length > 1
        ? t('onboarding.start_import_names_or', '{{list}} or {{last}}', { list: importable.slice(0, -1).join(', '), last: importable[importable.length - 1] })
        : (importable[0] || '');

    const options = [
        {
            value: 'new',
            title: t('onboarding.start_new_title', 'Set up a new connection'),
            description: t('onboarding.start_new_desc', 'Choose a provider and connect it. You can test delivery after setup.'),
            media: <Plus className="size-5 text-muted-foreground" aria-hidden="true" />,
        },
        {
            value: 'import',
            title: t('onboarding.start_import_title', 'Import from another SMTP plugin'),
            description: importable.length > 0
                ? t('onboarding.start_import_desc', 'We look for {{names}} settings on this site and bring over what can be brought safely.', { names: importNames })
                : t('onboarding.start_import_none_desc', 'No importer is available in this version yet. Set up a new connection instead.'),
            media: <Download className="size-5 text-muted-foreground" aria-hidden="true" />,
            disabled: importable.length === 0,
        },
    ];

    const supported = scan.sources.filter(s => s.supported);
    const canContinue = path === 'new' || (path === 'import' && Boolean(migrationSource));

    const previewNotes = path === 'import'
        ? [
            t('onboarding.start_preview_import_1', 'The other plugin is only read, never changed: what comes over is an inactive draft you test and apply like a fresh set-up.'),
        ]
        : [
            t('onboarding.start_preview_new_1', 'You will need an account with the provider and its credentials.'),
            t('onboarding.start_preview_new_2', 'Google Workspace and Microsoft 365 need an OAuth client you create in their consoles — the guides show how.'),
        ];

    return (
        <StepShell
            {...shell}
            title={t('onboarding.start_title', 'How do you want to begin?')}
            description={t('onboarding.start_desc', 'Most sites connect one provider; if another SMTP plugin already runs here, its settings can be brought over first.')}
            onContinue={onContinue}
            continueDisabled={!canContinue}
            continueBusy={continueBusy}
            preview={(
                <PreviewCard
                    heading={path === 'import' ? t('onboarding.start_preview_import_heading', 'What an import does') : t('onboarding.start_preview_new_heading', 'What you will need')}
                    notes={previewNotes}
                    rows={path === 'import' && scan.status === 'done' ? startPreviewRows(t, scan.sources, migrationSource) : []}
                />
            )}
        >
            <ChoiceCards
                idPrefix="onboarding-path"
                value={path}
                onValueChange={onPathChange}
                ariaLabel={t('onboarding.start_title', 'How do you want to begin?')}
                options={options}
            />

            {path === 'import' && (
                <div className="flex flex-col gap-3" aria-live="polite">
                    {scan.status === 'loading' && (
                        <p className="flex items-center gap-2 text-sm text-muted-foreground">
                            <Spinner className="size-4" />
                            {t('onboarding.start_scanning', 'Looking for other SMTP plugins…')}
                        </p>
                    )}
                    {scan.status === 'error' && (
                        <Alert variant="destructive">
                            <AlertTitle>{t('onboarding.start_scan_failed', 'The scan did not run')}</AlertTitle>
                            <AlertDescription>{scan.error}</AlertDescription>
                        </Alert>
                    )}
                    {scan.status === 'done' && scan.sources.length === 0 && (
                        <Alert variant="info">
                            <AlertTitle>{t('onboarding.start_none_title', 'No supported SMTP plugin settings were found on this site')}</AlertTitle>
                            <AlertDescription>{t('onboarding.start_none_desc', 'Choose "Set up a new connection" to continue.')}</AlertDescription>
                        </Alert>
                    )}
                    {scan.status === 'done' && scan.sources.length > 0 && (
                        <RadioGroup
                            value={migrationSource || ''}
                            onValueChange={onMigrationSourceChange}
                            aria-label={t('onboarding.start_sources_label', 'Detected SMTP plugins')}
                            className="gap-0 divide-y overflow-hidden rounded-lg border bg-card"
                        >
                            {scan.sources.map(source => {
                                const id = `onboarding-source-${source.slug}`;
                                const state = source.is_active
                                    ? { variant: 'success', label: t('onboarding.source_active', 'Active plugin') }
                                    : source.is_installed
                                        ? { variant: 'outline', label: t('onboarding.source_installed', 'Installed, inactive') }
                                        : { variant: 'outline', label: t('onboarding.source_ghost', 'Settings left behind') };
                                const count = source.preview_connections ?? source.details?.connection_count;
                                const logCount = source.preview_logs ?? source.details?.log_count;
                                const logsLine = source.logs_available === false
                                    ? t('onboarding.source_no_logs', 'no email log in the free edition')
                                    : (typeof logCount === 'number' ? t('onboarding.source_logs', '{{count}} log(s)', { count: logCount }) : '');
                                const selected = migrationSource === source.slug;
                                return (
                                    <Label
                                        key={source.slug}
                                        htmlFor={id}
                                        className={cn(
                                            'flex items-center gap-3 px-3 py-2.5 text-sm font-normal transition-colors',
                                            source.supported ? 'cursor-pointer hover:bg-muted/50' : 'cursor-not-allowed text-muted-foreground',
                                            selected && 'bg-primary/5'
                                        )}
                                    >
                                        <RadioGroupItem value={source.slug} id={id} disabled={!source.supported} />
                                        <span className="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-1">
                                            <span className={cn('font-medium', source.supported && 'text-foreground')}>{source.name}</span>
                                            <Badge variant={state.variant}>{state.label}</Badge>
                                            <span className="text-xs text-muted-foreground">
                                                {typeof count === 'number'
                                                    ? t('onboarding.source_connections', '{{count}} connection(s) found', { count })
                                                    : t('onboarding.source_settings_found', 'Settings found')}
                                                {logsLine ? ` · ${logsLine}` : ''}
                                            </span>
                                        </span>
                                        <Badge variant={source.supported ? 'success' : 'outline'} className="shrink-0">
                                            {source.supported
                                                ? t('onboarding.source_supported', 'Supported')
                                                : t('onboarding.source_unsupported', 'Not supported yet')}
                                        </Badge>
                                    </Label>
                                );
                            })}
                        </RadioGroup>
                    )}
                    {scan.status === 'done' && scan.sources.length > 0 && supported.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            {t('onboarding.start_no_supported', 'None of the plugins found can be imported yet. Choose "Set up a new connection" to continue.')}
                        </p>
                    )}
                </div>
            )}
        </StepShell>
    );
}
