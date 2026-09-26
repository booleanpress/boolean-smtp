import { useEffect, useState, useMemo } from 'react';
import { useNavigate, useSearchParams } from 'react-router';
import { ChevronLeft, Compass, ExternalLink, Star } from 'lucide-react';
import api, { fieldErrors } from '../services/api';
import { MAIL_PROVIDERS } from '../config/mailers';
import ConnectionForm from './ConnectionForm';
import ConnectionFormSkeleton from '../components/connections/ConnectionFormSkeleton';
import { GENERAL_DOCS_URL } from '../components/setup-guides/JsonVariantSetupGuide';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Separator } from '@/components/ui/separator';
import { useTranslations } from '../hooks/useTranslations';

// Curated display order: Amazon SES, Google, Microsoft, then Custom SMTP and PHP mail.
const DISPLAY_ORDER = { ses: 0, google: 1, outlook: 2, smtp: 3, php: 4 };

/**
 * Select a mailer and keep its new-connection form addressable by URL.
 *
 * @since 1.0.0
 */
export default function ConnectionNew() {
    const navigate = useNavigate();
    const [searchParams, setSearchParams] = useSearchParams();
    const { t } = useTranslations();
    const requestedDriver = searchParams.get('provider');
    const hasProviderParam = searchParams.has('provider');
    const selectedDriver = MAIL_PROVIDERS.some(provider => provider.driver === requestedDriver)
        ? requestedDriver
        : null;
    const [transportMetadata, setTransportMetadata] = useState({});
    const selectedMetadata = selectedDriver ? transportMetadata[selectedDriver] : null;
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [validationErrors, setValidationErrors] = useState({});
    const [senderConflict, setSenderConflict] = useState(null);

    const providerName = (provider) => t(`mailers.${provider.driver}.name`, provider.name);
    const providerDescription = (provider) => t(`mailers.${provider.driver}.description`, provider.description);

    const sortedProviders = useMemo(() => {
        return [...MAIL_PROVIDERS].sort((a, b) => (DISPLAY_ORDER[a.driver] ?? 99) - (DISPLAY_ORDER[b.driver] ?? 99));
    }, []);

    useEffect(() => {
        if (!selectedDriver) {
            if (hasProviderParam) {
                setSearchParams({}, { replace: true });
            }
            return;
        }
        if (selectedMetadata) return;

        let current = true;
        api.get(`transports/${selectedDriver}`)
            .then(res => {
                if (!current) return;
                setTransportMetadata(previous => ({ ...previous, [selectedDriver]: res.data || res }));
            })
            .catch(err => {
                if (!current) return;
                setError(t('connection_new.transport_metadata_failed', 'Failed to load transport metadata: {{message}}', {
                    message: err.message,
                }));
                setSearchParams({}, { replace: true });
            });

        return () => { current = false; };
    }, [hasProviderParam, selectedDriver, selectedMetadata, setSearchParams, t]);

    function selectMailer(driver) {
        setError('');
        setValidationErrors({});
        setSenderConflict(null);
        setSearchParams({ provider: driver });
    }

    async function handleSave(formData) {
        if (!formData.name.trim()) {
            setValidationErrors({ name: t('connection_new.connection_name_required', 'Connection name is required.') });
            return;
        }

        setSaving(true);
        setError('');
        setValidationErrors({});
        setSenderConflict(null);
        try {
            if (formData.connection_id) {
                await api.put(`connections/${formData.connection_id}`, {
                    name: formData.name,
                    driver: selectedDriver,
                    settings: formData.settings,
                    is_active: formData.is_active ?? true,
                    priority: formData.priority || 0,
                });
                navigate('/connections');
            } else {
                await api.post('connections', {
                    name: formData.name,
                    driver: selectedDriver,
                    settings: formData.settings,
                    is_active: formData.is_active ?? true,
                    priority: formData.priority || 0,
                });
                navigate('/connections');
            }
        } catch (err) {
            const message = String(err?.message || t('connection_new.failed_save_connection', 'Failed to save connection.'));
            const errors = fieldErrors(err?.errors);
            if (Object.keys(errors).length > 0) {
                setValidationErrors(errors);
            }
            setSenderConflict(err?.payload?.conflict ?? null);
            setError(errors.is_active || message);
        } finally {
            setSaving(false);
        }
    }

    if (!selectedDriver) {
        const backLabel = t('connection_new.back_to_mailers', 'Back to Mailers');
        return (
            <div className="space-y-8">
                {error && <Alert variant="destructive"><AlertDescription>{error}</AlertDescription></Alert>}
                <div className="flex items-start gap-3">
                    <Button
                        variant="ghost"
                        size="icon"
                        className="shrink-0"
                        onClick={() => navigate('/connections')}
                        aria-label={backLabel}
                        title={backLabel}
                    >
                        <ChevronLeft />
                    </Button>
                    <div className="max-w-2xl">
                        <h1 className="text-xl font-semibold tracking-tight">
                            {t('connection_new.choose_mailer', 'Choose a Mailer')}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {t('connection_new.subtitle', 'Select an email service provider to start sending transactional emails reliably.')}
                        </p>
                    </div>
                </div>

                <section className="space-y-4">
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                        {sortedProviders.map(m => (
                            <Button
                                key={m.driver}
                                variant="outline"
                                onClick={() => selectMailer(m.driver)}
                                title={providerDescription(m)}
                                className="group relative h-auto flex-col items-stretch gap-0 overflow-hidden border-foreground/20 bg-card p-0 text-left whitespace-normal shadow-none transition-colors hover:border-primary hover:bg-card"
                            >
                                {m.recommended && (
                                    <div className="absolute top-1.5 left-1.5 z-10">
                                        <span className="flex size-5 shrink-0 items-center justify-center rounded-full border border-primary/30 bg-background/90 text-primary shadow-xs backdrop-blur-sm">
                                            <Star className="size-3 fill-current" aria-hidden="true" />
                                            <span className="sr-only">{t('connection_new.recommended', 'Recommended')}</span>
                                        </span>
                                    </div>
                                )}
                                <div className="relative mx-auto flex aspect-[2/1] w-full items-center justify-center overflow-hidden bg-card p-2.5">
                                    {m.logo ? (
                                        <img src={m.logo} alt={providerName(m)} className="size-full object-contain" />
                                    ) : (
                                        m.icon
                                    )}
                                </div>
                                <Separator />
                                <div className="p-2 text-center">
                                    <span className="text-xs font-semibold tracking-tight">{providerName(m)}</span>
                                </div>
                                <span className="absolute right-1.5 bottom-1.5 size-1.5 rounded-full bg-border transition-colors group-hover:bg-primary" aria-hidden="true" />
                            </Button>
                        ))}
                    </div>
                </section>

                <div className="flex flex-col items-center gap-3 pt-6 text-center">
                    <p className="text-xs text-muted-foreground">
                        {t('connection_new.need_help_deciding', 'Not sure which to choose?')}
                    </p>
                    <a
                        href={GENERAL_DOCS_URL}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="group inline-flex items-center gap-1.5 rounded-full border border-border bg-background px-3 py-1.5 text-xs font-medium text-foreground shadow-xs transition-colors hover:border-primary/40 hover:text-primary"
                    >
                        <Compass className="size-3.5 text-muted-foreground transition-colors group-hover:text-primary" aria-hidden="true" />
                        {t('connection_new.deliverability_guide', 'Deliverability Guide')}
                        <ExternalLink className="size-3 text-muted-foreground transition-colors group-hover:text-primary" aria-hidden="true" />
                    </a>
                </div>
            </div>
        );
    }

    if (!selectedMetadata) {
        return <ConnectionFormSkeleton />;
    }

    return (
        <ConnectionForm
            key={selectedDriver}
            driver={selectedDriver}
            metadata={selectedMetadata}
            onSave={handleSave}
            onCancel={() => {
                setSearchParams({}, { replace: true });
            }}
            saving={saving}
            error={error}
            validationErrors={validationErrors}
            senderConflict={senderConflict}
        />
    );
}
