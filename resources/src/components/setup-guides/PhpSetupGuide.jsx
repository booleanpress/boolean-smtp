import { AlertTriangle } from 'lucide-react';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { useTranslations } from '@/hooks/useTranslations';
import { GuideDocLink, GuideStep, GuideTimeline } from './JsonVariantSetupGuide';

export default function PhpSetupGuide({ docsUrl = '' }) {
    const { t } = useTranslations();
    return (
        <div className="animate-in fade-in duration-300">
            <GuideTimeline>
                <GuideStep active title={t('php_setup.no_credentials_needed', 'No Credentials Needed')}>
                    <p className="text-sm leading-relaxed text-muted-foreground">
                        {t('php_setup.no_credentials_desc', 'Uses your server\'s built-in mail function — no SMTP host, API key, or password required.')}
                    </p>
                    <Alert variant="warning">
                        <AlertTriangle />
                        <AlertDescription className="text-xs">
                            {t('php_setup.recommended_testing_only', 'Often filtered as spam — recommended for local testing or fallback only.')}
                        </AlertDescription>
                    </Alert>
                </GuideStep>

                <GuideStep last title={t('php_setup.send_test_email', 'Send a Test Email')}>
                    <p className="text-sm leading-relaxed text-muted-foreground">
                        {t('php_setup.send_test_email_desc', 'Set a From Email on your site\'s domain, save, and send a test. If it fails, your host may have disabled the local mail function.')}
                    </p>
                </GuideStep>
            </GuideTimeline>

            <GuideDocLink href={docsUrl || undefined} />
        </div>
    );
}
