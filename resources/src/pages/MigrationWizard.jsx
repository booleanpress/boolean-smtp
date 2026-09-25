import MigrationDiscoveryFlow from '@/components/migration/MigrationDiscoveryFlow';
import { Suspense } from 'react';
import MigrationWizardSkeleton from '@/components/skeletons/MigrationWizardSkeleton';
import { useTranslations } from '@/hooks/useTranslations';

export default function MigrationWizard() {
    const { t } = useTranslations();

    return (
        <div className="max-w-7xl mx-auto space-y-8 animate-in fade-in duration-500">
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h1 className="text-xl font-semibold">{t('migration_wizard.title', 'Migration')}</h1>
                    <p className="text-sm text-muted-foreground">
                        {t('migration_wizard.subtitle', 'Bring the connections and the email log of another SMTP plugin into BooleanSMTP: assess first, then import as inactive drafts.')}
                    </p>
                </div>
            </div>

            <Suspense fallback={<MigrationWizardSkeleton />}>
                <MigrationDiscoveryFlow />
            </Suspense>
        </div>
    );
}
