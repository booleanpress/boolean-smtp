import { Eye, Mail, Send } from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { useTranslations } from '@/hooks/useTranslations';
import SummaryRows from './SummaryRows';

/**
 * The wizard's live preview: an envelope block (who the site sends as and through what), then
 * key/value rows whose values are states — `Stored`, `Connected`, `Passed` — never inputs and
 * never secrets. Updates as the user makes choices.
 *
 * @since 1.0.0
 *
 * @param {object} props
 * @param {string} props.heading Line under the "Preview" title.
 * @param {{ fromName?: string, fromEmail?: string, providerName?: string, methodLine?: string }|null} [props.envelope]
 * @param {Array<{ label: string, value?: string, badge?: { variant: string, label: string } }>} [props.rows]
 * @param {string[]} [props.notes] Short lines under the rows.
 * @param {{ title?: string, items: string[] }|null} [props.list] A titled bullet list under the rows (what the user will need, what Apply changes).
 * @param {import('react').ReactNode} [props.children] Extra content between the envelope and the rows.
 */
export default function PreviewCard({ heading, envelope = null, rows = [], notes = [], list = null, children = null }) {
    const { t } = useTranslations();
    const fromEmail = envelope?.fromEmail || '';
    const fromName = envelope?.fromName || '';

    return (
        <Card className="gap-4 py-5">
            <CardHeader className="gap-0.5">
                <CardTitle className="flex items-center gap-2 text-xs font-medium text-muted-foreground">
                    <Eye className="size-3.5" aria-hidden="true" />
                    {t('onboarding.preview', 'Preview')}
                </CardTitle>
                <CardDescription className="text-base font-semibold text-foreground">{heading}</CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                {envelope && (
                    <div className="flex flex-col gap-3 rounded-lg border bg-muted/50 p-4">
                        <div className="flex items-start gap-3">
                            <span className="flex size-8 shrink-0 items-center justify-center rounded-md border bg-background text-muted-foreground" aria-hidden="true">
                                <Mail className="size-4" />
                            </span>
                            <div className="min-w-0">
                                <p className="text-xs font-medium text-muted-foreground">{t('onboarding.preview_from', 'From')}</p>
                                <p className="truncate text-sm font-semibold" data-testid="preview-from">
                                    {fromEmail
                                        ? (fromName ? `${fromName} <${fromEmail}>` : fromEmail)
                                        : t('onboarding.preview_from_unset', 'Not set yet')}
                                </p>
                            </div>
                        </div>
                        <Separator />
                        <div className="flex items-start gap-3">
                            <span className="flex size-8 shrink-0 items-center justify-center rounded-md border bg-background text-muted-foreground" aria-hidden="true">
                                <Send className="size-4" />
                            </span>
                            <div className="min-w-0">
                                <p className="text-xs font-medium text-muted-foreground">{t('onboarding.preview_via', 'Sent via')}</p>
                                <p className="text-sm font-semibold" data-testid="preview-via">
                                    {envelope.providerName || t('onboarding.preview_via_unset', 'No provider chosen')}
                                </p>
                                {envelope.methodLine && <p className="text-xs text-muted-foreground">{envelope.methodLine}</p>}
                            </div>
                        </div>
                    </div>
                )}

                {children}

                <SummaryRows rows={rows} />

                {list && list.items.length > 0 && (
                    <div className="flex flex-col gap-1.5">
                        {list.title && <p className="text-xs font-medium text-foreground">{list.title}</p>}
                        <ul className="list-disc space-y-1 pl-4 text-xs text-muted-foreground marker:text-muted-foreground/70">
                            {list.items.map(item => <li key={item}>{item}</li>)}
                        </ul>
                    </div>
                )}

                {notes.length > 0 && (
                    <ul className="flex flex-col gap-1 text-xs text-muted-foreground">
                        {notes.map(note => <li key={note}>{note}</li>)}
                    </ul>
                )}

                <p className="text-xs text-muted-foreground">{t('onboarding.preview_updates', 'Updates as you make your choices.')}</p>
            </CardContent>
        </Card>
    );
}
