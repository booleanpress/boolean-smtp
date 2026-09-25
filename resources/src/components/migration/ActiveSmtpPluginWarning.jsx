import { useEffect, useState } from 'react';
import { TriangleAlert } from 'lucide-react';
import api from '@/services/api';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/useTranslations';
import { pluginsPageUrl } from './importStatus';

/**
 * A dashboard warning while another SMTP plugin is still active on the site and BooleanSMTP
 * has a primary connection: both hook `wp_mail()`, so mail may not go where the dashboard
 * says. Renders nothing until the scan has answered and only when there is something to say —
 * and nothing at all when that other plugin holds `wp_mail()` outright, because the warning
 * every screen carries says so with the plugin named.
 *
 * @since 1.0.0
 *
 * @param {object} props
 * @param {boolean} props.hasPrimary Whether BooleanSMTP has a primary connection.
 */
export default function ActiveSmtpPluginWarning({ hasPrimary }) {
    const { t } = useTranslations();
    const [active, setActive] = useState([]);
    const mailTaken = (typeof window !== 'undefined' && window.BooleanSmtpAdmin?.mailTakeover?.active) === false;

    useEffect(() => {
        if (!hasPrimary) return undefined;
        let cancelled = false;
        api.get('tools/migration/scan', { counts: 'false' })
            .then(res => {
                if (cancelled) return;
                const raw = res?.data || {};
                const sources = Array.isArray(raw) ? raw : Object.values(raw);
                setActive(sources.filter(s => s.is_active));
            })
            .catch(() => {
                if (!cancelled) setActive([]);
            });
        return () => {
            cancelled = true;
        };
    }, [hasPrimary]);

    if (!hasPrimary || mailTaken || active.length === 0) return null;
    const names = active.map(s => s.name).join(', ');

    return (
        <Alert variant="warning" data-testid="active-smtp-plugin-warning">
            <TriangleAlert />
            <AlertTitle>{t('dashboard.other_smtp_active', '{{plugins}} is still active and also handles wp_mail()', { plugins: names })}</AlertTitle>
            <AlertDescription className="flex flex-col gap-2">
                <span>{t('dashboard.other_smtp_active_desc', 'Two plugins sending the same mail is one too many — deactivate it so BooleanSMTP sends your email.')}</span>
                <span>
                    <Button variant="outline" size="sm" asChild>
                        <a href={pluginsPageUrl(active[0].name)} target="_blank" rel="noopener noreferrer">{t('dashboard.other_smtp_deactivate', 'Open the plugins page')}</a>
                    </Button>
                </span>
            </AlertDescription>
        </Alert>
    );
}
