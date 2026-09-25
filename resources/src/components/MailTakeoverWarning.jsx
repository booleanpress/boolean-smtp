import { TriangleAlert } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { pluginsPageUrl } from '@/components/migration/importStatus';
import { useTranslations } from '@/hooks/useTranslations';

/**
 * A warning on every screen while another plugin holds `wp_mail()`: WordPress gives email to
 * whichever plugin claims it first, so until that one is deactivated the site's mail goes out
 * around the email log and the failure alerts. Renders nothing when BooleanSMTP holds it.
 *
 * @since 1.0.0
 */
export default function MailTakeoverWarning() {
    const { t } = useTranslations();
    const takeover = (typeof window !== 'undefined' && window.BooleanSmtpAdmin?.mailTakeover) || null;

    if (!takeover || takeover.active !== false) return null;
    const plugin = takeover.plugin || '';

    return (
        <Alert variant="warning" className="mb-4" data-testid="mail-takeover-warning">
            <TriangleAlert />
            <AlertTitle>
                {plugin
                    ? t('layout.mail_takeover_title', '{{plugin}} is handling this site\'s email', { plugin })
                    : t('layout.mail_takeover_title_unknown', 'Another plugin is handling this site\'s email')}
            </AlertTitle>
            <AlertDescription className="flex flex-col gap-2">
                <span>
                    {t(
                        'layout.mail_takeover_desc',
                        'It claimed WordPress mail first — until it is deactivated, your site\'s email bypasses the email log and failure alerts.'
                    )}
                </span>
                <span>
                    <Button variant="outline" size="sm" asChild>
                        <a href={pluginsPageUrl(plugin)} target="_blank" rel="noopener noreferrer">
                            {t('layout.mail_takeover_action', 'Open the plugins page')}
                        </a>
                    </Button>
                </span>
            </AlertDescription>
        </Alert>
    );
}
