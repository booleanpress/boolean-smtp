import { useMemo, useState } from 'react';
import { CheckCircle2, ChevronDown, HelpCircle, KeyRound, ShieldCheck, SlidersHorizontal, TriangleAlert, XCircle } from 'lucide-react';
import DynamicSettingsForm from '@/components/connections/DynamicSettingsForm';
import { getSetupGuideComponent } from '@/components/setup-guides';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { InputGroup, InputGroupAddon, InputGroupButton, InputGroupInput } from '@/components/ui/input-group';
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { cn } from '@/lib/utils';
import { useTranslations } from '@/hooks/useTranslations';
import PreviewCard from './PreviewCard';
import StepShell from './StepShell';
import { FREE_DELIVERY_MODE, OAUTH_DRIVERS, displayValue, isMaskedSecret, isSecretKey, splitWizardSchema } from './providerCatalog';

/** The credential stores, in tab order, with the short label each tab shows. */
const KEY_STORE_TABS = [
    { value: 'db', label: ['onboarding.key_store_db', 'Database'] },
    { value: 'wp_config', label: ['onboarding.key_store_wp_config', 'wp-config.php'] },
    { value: 'env', label: ['onboarding.key_store_env', 'Environment'] },
];

/**
 * A compact single-column list of checkbox settings (the advanced sender options).
 *
 * @since 1.0.0
 *
 * @param {object} props
 * @param {object} props.schema Checkbox fields keyed by setting.
 * @param {object} props.values Current values.
 * @param {(key: string, value: unknown) => void} props.onChange
 * @param {Record<string, string>} props.errors
 */
function CheckboxRows({ schema, values, onChange, errors }) {
    return (
        <div className="flex flex-col gap-2">
            {Object.entries(schema).map(([key, field]) => (
                <Field key={key} orientation="horizontal" data-invalid={Boolean(errors[key]) || undefined} className="items-start gap-2.5">
                    <Checkbox id={key} checked={Boolean(values[key] ?? field.default)} onCheckedChange={checked => onChange(key, checked === true)} aria-invalid={Boolean(errors[key])} className="mt-0.5" />
                    <div className="flex flex-col gap-0.5">
                        <FieldLabel htmlFor={key} className="font-normal leading-snug">{field.label}</FieldLabel>
                        {field.help && <FieldDescription className="text-xs">{field.help}</FieldDescription>}
                        {errors[key] && <FieldError className="text-xs">{errors[key]}</FieldError>}
                    </div>
                </Field>
            ))}
        </div>
    );
}

/**
 * The credential store as tabs — where the keys are read from — with the store's own line
 * (for SMTP it names the constants) as each tab's content.
 *
 * @since 1.0.0
 *
 * @param {object} props
 * @param {object} props.field The `key_store` schema field.
 * @param {string} props.value Current store.
 * @param {(value: string) => void} props.onChange
 */
function KeyStoreTabs({ field, value, onChange }) {
    const { t } = useTranslations();
    const options = field.options || {};
    const tabs = KEY_STORE_TABS.filter(tab => tab.value in options);
    return (
        <div className="flex flex-col gap-1.5">
            <p id="onboarding-key-store-label" className="text-sm text-muted-foreground">{field.label}</p>
            <Tabs value={value || 'db'} onValueChange={onChange}>
                <TabsList aria-labelledby="onboarding-key-store-label">
                    {tabs.map(tab => <TabsTrigger key={tab.value} value={tab.value}>{t(tab.label[0], tab.label[1])}</TabsTrigger>)}
                </TabsList>
                {tabs.map(tab => (
                    <TabsContent key={tab.value} value={tab.value} className="text-xs text-muted-foreground">
                        {tab.value === 'db'
                            ? t('onboarding.key_store_db_help', 'Encrypted in the database. The usual choice.')
                            : t('onboarding.key_store_external_help', '{{store}} — the names are under "How do I get these?".', { store: options[tab.value] })}
                    </TabsContent>
                ))}
            </Tabs>
        </div>
    );
}

/**
 * A disclosure with a small trigger row, used for the advanced options and the setup guide.
 *
 * @since 1.0.0
 */
function Disclosure({ icon: Icon, label, children, defaultOpen = false }) {
    const [open, setOpen] = useState(defaultOpen);
    return (
        <Collapsible open={open} onOpenChange={setOpen} className="flex flex-col gap-3">
            <CollapsibleTrigger asChild>
                <Button type="button" variant="ghost" size="sm" className="w-fit gap-2 px-2 text-muted-foreground">
                    <Icon aria-hidden="true" />
                    {label}
                    <ChevronDown className={cn('transition-transform', open && 'rotate-180')} aria-hidden="true" />
                </Button>
            </CollapsibleTrigger>
            <CollapsibleContent className="flex flex-col gap-4">{children}</CollapsibleContent>
        </Collapsible>
    );
}

/**
 * Step 3 — Connect: the Sender card and the provider's Connection card, both rendered from the
 * transport schema, with the advanced fields and the setup guide behind disclosures.
 *
 * @since 1.0.0
 *
 * @param {object} props
 * @param {string} props.driver Selected driver.
 * @param {object} props.provider Registry entry.
 * @param {string} props.providerName Display name.
 * @param {string} props.methodLine How the free plugin delivers through this provider.
 * @param {object|null} props.metadata `GET transports/{driver}` payload.
 * @param {object} props.settings Current settings values.
 * @param {(key: string, value: unknown) => void} props.onSettingChange
 * @param {Record<string, string>} props.errors Field errors keyed by setting key.
 * @param {object|null} props.draft Saved draft, when one exists.
 * @param {{ status: 'idle'|'running'|'passed'|'failed', message?: string, quota?: string, sent?: string, identity?: object|null }} [props.sesCheck] Result of "Validate with AWS".
 * @param {() => void} [props.onValidateSes]
 * @param {{ connected: boolean, account: string|null, status: 'idle'|'starting'|'waiting'|'exchanging'|'error', message?: string, redirectUri?: string, consentTab?: boolean }} [props.oauth] Account state for Google / Microsoft; `consentTab` when this page is the tab opened for the consent.
 * @param {() => void} [props.onConnectAccount]
 * @param {() => void} [props.onStopWaiting] Stop waiting for the consent tab.
 * @param {(uri: string) => void} [props.onCopyRedirectUri]
 * @param {() => void} props.onBack
 * @param {() => void} props.onContinue
 * @param {boolean} props.continueBusy
 * @param {{ stepNumber: number, stepCount: number, stepName: string }} props.shell
 */
export default function ConnectStep({
    driver,
    provider,
    providerName,
    methodLine,
    metadata,
    settings,
    onSettingChange,
    errors,
    draft,
    sesCheck = { status: 'idle' },
    onValidateSes = null,
    oauth = { connected: false, account: null, status: 'idle' },
    onConnectAccount = null,
    onStopWaiting = null,
    onCopyRedirectUri = null,
    onBack,
    onContinue,
    continueBusy,
    shell,
}) {
    const { t } = useTranslations();
    const schema = useMemo(() => splitWizardSchema(driver, metadata?.settings_schema || {}), [driver, metadata]);
    const keyStoreField = schema.advanced.key_store || null;
    const advancedFields = useMemo(() => {
        const rest = { ...schema.advanced };
        delete rest.key_store;
        return rest;
    }, [schema]);
    const SetupGuide = useMemo(() => getSetupGuideComponent(driver), [driver]);
    const isPhp = driver === 'php';
    const isSes = driver === 'ses';
    const isOAuth = OAUTH_DRIVERS.includes(driver);
    const accountMismatch = isOAuth && oauth.connected && oauth.account && settings.from_email
        && String(oauth.account).trim().toLowerCase() !== String(settings.from_email).trim().toLowerCase();
    const sesKeysEntered = isSes && ['api_access_key', 'api_secret'].every(key => settings[key] && String(settings[key]).trim() !== '');
    const oauthKeysEntered = isOAuth && ['client_id', 'client_secret'].every(key => settings[key] && String(settings[key]).trim() !== '');

    const credentialRows = Object.entries(schema.connection)
        .filter(([, field]) => !field.visible_when || settings[field.visible_when.key] === field.visible_when.value)
        .map(([key, field]) => {
            const value = settings[key];
            const filled = value !== undefined && value !== null && String(value).trim() !== '';
            if (isSecretKey(key)) {
                const stored = draft && (isMaskedSecret(value) || filled);
                return {
                    label: field.label,
                    badge: stored
                        ? { variant: 'success', label: t('onboarding.secret_stored', 'Stored') }
                        : filled
                            ? { variant: 'default', label: t('onboarding.secret_entered', 'Entered') }
                            : { variant: 'outline', label: t('onboarding.secret_missing', 'Not entered') },
                };
            }
            if (field.type === 'checkbox') {
                return { label: field.label, value: value ? t('common.on', 'On') : t('common.off', 'Off') };
            }
            return { label: field.label, value: displayValue(field, value) };
        });

    return (
        <StepShell
            {...shell}
            title={t('onboarding.connect_title', 'Connect {{provider}}', { provider: providerName })}
            description={methodLine}
            onBack={onBack}
            onContinue={onContinue}
            continueLabel={isPhp ? t('onboarding.connect_php_continue', 'Use PHP mail anyway') : undefined}
            continueDisabled={isOAuth && !oauth.connected}
            continueBusy={continueBusy}
            preview={(
                <PreviewCard
                    heading={t('onboarding.connect_preview_heading', 'What this site will send')}
                    envelope={{ fromEmail: settings.from_email, fromName: settings.from_name, providerName, methodLine }}
                    rows={[
                        ...credentialRows,
                        ...(isOAuth ? [{
                            label: t('onboarding.preview_account', 'Account'),
                            value: oauth.connected ? (oauth.account || t('onboarding.account_connected_short', 'Connected')) : undefined,
                            badge: oauth.connected
                                ? { variant: 'success', label: t('onboarding.account_connected', 'Connected') }
                                : { variant: 'outline', label: t('onboarding.account_not_connected', 'Not connected') },
                        }] : []),
                        ...(isSes ? [{
                            label: t('onboarding.preview_aws_check', 'AWS check'),
                            value: sesCheck.status === 'passed' && sesCheck.quota
                                ? t('onboarding.preview_aws_quota', '24 h quota {{quota}} · sent today {{sent}}', { quota: sesCheck.quota, sent: sesCheck.sent ?? '0' })
                                : undefined,
                            badge: sesCheck.status === 'passed'
                                ? { variant: 'success', label: t('onboarding.verify_passed', 'Passed') }
                                : sesCheck.status === 'failed'
                                    ? { variant: 'destructive', label: t('onboarding.verify_failed', 'Failed') }
                                    : sesCheck.status === 'running'
                                        ? { variant: 'outline', label: t('onboarding.verify_running', 'Running…') }
                                        : { variant: 'outline', label: t('onboarding.verify_not_run', 'Not run') },
                        }, ...(sesCheck.status === 'passed' && sesCheck.identity ? [{
                            label: t('onboarding.preview_ses_identity', 'SES identity'),
                            value: sesCheck.identity.verified
                                ? (sesCheck.identity.address_status === 'Success' ? sesCheck.identity.address : sesCheck.identity.domain)
                                : sesCheck.identity.address,
                            badge: sesCheck.identity.verified
                                ? { variant: 'success', label: t('onboarding.ses_identity_verified', 'Verified') }
                                : { variant: 'warning', label: t('onboarding.ses_identity_unverified', 'Not verified') },
                        }] : [])] : []),
                    ]}
                    notes={draft ? [t('onboarding.connect_preview_draft_note', 'Saved as an inactive draft — not used for site mail.')] : []}
                />
            )}
        >
            <Card className="gap-4 py-5">
                <CardHeader className="gap-0.5">
                    <CardTitle>{t('onboarding.sender_card_title', 'Sender')}</CardTitle>
                    <CardDescription>{t('onboarding.sender_card_desc', 'Who your emails come from. Most providers require this address to belong to the account you connect.')}</CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col gap-3">
                    <DynamicSettingsForm schema={schema.sender} values={settings} onChange={onSettingChange} errors={errors} />
                    {Object.keys(schema.senderAdvanced).length > 0 && (
                        <Disclosure icon={SlidersHorizontal} label={t('onboarding.sender_advanced', 'Advanced sender options')}>
                            <CheckboxRows schema={schema.senderAdvanced} values={settings} onChange={onSettingChange} errors={errors} />
                        </Disclosure>
                    )}
                </CardContent>
            </Card>

            {isPhp ? (
                <Alert variant="warning">
                    <TriangleAlert />
                    <AlertTitle>{t('onboarding.php_warning_title', 'PHP mail sends through the web server itself')}</AlertTitle>
                    <AlertDescription>
                        {t('onboarding.php_warning_desc', 'There is no provider and no authentication: mail leaves from your hosting server\'s own IP address. Many shared hosts throttle or block it, and inbox providers trust it less. It is fine for a test site or a low-volume site whose host permits it — for anything you rely on, choose a provider.')}
                    </AlertDescription>
                </Alert>
            ) : (
                <Card className="gap-4 py-5">
                    <CardHeader className="gap-0.5">
                        <CardTitle>{t('onboarding.connection_card_title', '{{provider}} connection', { provider: providerName })}</CardTitle>
                        <CardDescription>{t('onboarding.connection_card_desc', 'Credentials are encrypted before they are stored.')}</CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        <DynamicSettingsForm
                            schema={schema.connection}
                            values={settings}
                            onChange={onSettingChange}
                            errors={errors}
                            trailing={isSes ? (
                                <Button type="button" variant="outline" onClick={onValidateSes} disabled={!sesKeysEntered || sesCheck.status === 'running' || !onValidateSes}>
                                    {sesCheck.status === 'running' ? <Spinner /> : <ShieldCheck />}
                                    {t('onboarding.validate_aws', 'Validate with AWS')}
                                </Button>
                            ) : null}
                        />
                        {isOAuth && oauth.redirectUri && (
                            <Field className="gap-1.5">
                                <FieldLabel htmlFor="onboarding-redirect-uri">{t('onboarding.redirect_uri', 'Redirect URI')}</FieldLabel>
                                <InputGroup>
                                    <InputGroupInput id="onboarding-redirect-uri" value={oauth.redirectUri} readOnly className="font-mono text-xs" />
                                    <InputGroupAddon align="inline-end">
                                        <InputGroupButton type="button" size="xs" onClick={() => onCopyRedirectUri?.(oauth.redirectUri)}>
                                            {t('common.copy', 'Copy')}
                                        </InputGroupButton>
                                    </InputGroupAddon>
                                </InputGroup>
                                <FieldDescription>
                                    {driver === 'google'
                                        ? t('onboarding.redirect_uri_help_google', 'Add this as an authorized redirect URI on the OAuth client in Google Cloud Console before connecting.')
                                        : t('onboarding.redirect_uri_help_microsoft', 'Add this as a Web redirect URI on the app registration in Microsoft Entra before connecting.')}
                                </FieldDescription>
                            </Field>
                        )}

                        {isSes && (sesCheck.status === 'passed' || sesCheck.status === 'failed') && (
                            <div className="flex flex-col gap-3" aria-live="polite">
                                {sesCheck.status === 'passed' && (
                                    <Alert variant={sesCheck.identity && !sesCheck.identity.verified ? 'warning' : 'success'}>
                                        {sesCheck.identity && !sesCheck.identity.verified ? <TriangleAlert /> : <CheckCircle2 />}
                                        <AlertTitle>
                                            {t('onboarding.ses_valid_title', 'AWS accepted these credentials — 24 h quota {{quota}}, sent today {{sent}}.', { quota: sesCheck.quota ?? '?', sent: sesCheck.sent ?? '0' })}
                                        </AlertTitle>
                                        <AlertDescription>
                                            {sesCheck.identity
                                                ? (sesCheck.identity.verified
                                                    ? t('onboarding.ses_identity_ok', '{{identity}} is a verified SES identity in {{region}}.', { identity: sesCheck.identity.address_status === 'Success' ? sesCheck.identity.address : sesCheck.identity.domain, region: settings.api_region || settings.region || '' })
                                                    : t('onboarding.ses_identity_missing', 'Neither {{address}} nor {{domain}} is a verified identity in {{region}}. Verify one of them in the SES console before sending, or the test will be rejected.', { address: sesCheck.identity.address, domain: sesCheck.identity.domain, region: settings.api_region || settings.region || '' }))
                                                : t('onboarding.ses_identity_unknown', 'The sender identity could not be checked; SES will refuse the send if it is not verified.')}
                                        </AlertDescription>
                                    </Alert>
                                )}
                                {sesCheck.status === 'failed' && (
                                    <Alert variant="destructive">
                                        <XCircle />
                                        <AlertTitle>{t('onboarding.ses_invalid_title', 'AWS rejected these credentials')}</AlertTitle>
                                        <AlertDescription>{sesCheck.message}</AlertDescription>
                                    </Alert>
                                )}
                            </div>
                        )}

                        {isOAuth && (
                            <div className="flex flex-col gap-3" aria-live="polite">
                                <div>
                                    <Button type="button" variant={oauth.connected ? 'outline' : 'default'} onClick={onConnectAccount} disabled={!oauthKeysEntered || oauth.status === 'starting' || oauth.status === 'waiting' || oauth.status === 'exchanging' || !onConnectAccount}>
                                        {oauth.status === 'starting' ? <Spinner /> : <KeyRound />}
                                        {oauth.connected
                                            ? t('onboarding.reconnect_account', 'Reconnect account')
                                            : driver === 'google'
                                                ? t('onboarding.connect_google', 'Connect Google account')
                                                : t('onboarding.connect_microsoft', 'Connect Microsoft account')}
                                    </Button>
                                    <p className="mt-1.5 text-xs text-muted-foreground">
                                        {t('onboarding.connect_opens_new_tab', 'Opens the sign-in in a new tab; this page stays here and updates when you are done.')}
                                    </p>
                                </div>
                                {oauth.status === 'waiting' && (
                                    <Alert>
                                        <Spinner className="size-4" />
                                        <AlertTitle>{t('onboarding.oauth_waiting_title', 'Finish signing in in the other tab')}</AlertTitle>
                                        <AlertDescription>
                                            <p>{t('onboarding.oauth_waiting_desc', 'Approve the access there; this page picks it up by itself. If the tab did not open, allow pop-ups for this site and try again.')}</p>
                                            <Button type="button" variant="outline" size="sm" onClick={onStopWaiting}>
                                                {t('onboarding.oauth_waiting_cancel', 'Stop waiting')}
                                            </Button>
                                        </AlertDescription>
                                    </Alert>
                                )}
                                {oauth.consentTab && (oauth.connected || oauth.status === 'error') && (
                                    <Alert>
                                        <CheckCircle2 />
                                        <AlertTitle>{t('onboarding.consent_tab_done_title', 'You can close this tab')}</AlertTitle>
                                        <AlertDescription>
                                            <p>{t('onboarding.consent_tab_done_desc', 'The setup continues in the tab you started from. This one closes by itself in a moment.')}</p>
                                            <Button type="button" variant="outline" size="sm" onClick={() => window.close()}>
                                                {t('onboarding.consent_tab_close', 'Close this tab')}
                                            </Button>
                                        </AlertDescription>
                                    </Alert>
                                )}
                                {oauth.status === 'exchanging' && (
                                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                                        <Spinner className="size-4" />
                                        {t('onboarding.oauth_exchanging', 'Finishing the connection…')}
                                    </p>
                                )}
                                {oauth.connected && (
                                    <Alert variant="success">
                                        <CheckCircle2 />
                                        <AlertTitle>
                                            {oauth.account
                                                ? t('onboarding.account_connected_as', 'Connected as {{account}}', { account: oauth.account })
                                                : t('onboarding.account_connected_title', 'Account connected')}
                                        </AlertTitle>
                                    </Alert>
                                )}
                                {oauth.status === 'error' && (
                                    <Alert variant="destructive">
                                        <XCircle />
                                        <AlertTitle>{t('onboarding.account_not_connected_title', 'The account was not connected')}</AlertTitle>
                                        <AlertDescription>{oauth.message}</AlertDescription>
                                    </Alert>
                                )}
                                {accountMismatch && (
                                    <Alert variant="warning">
                                        <TriangleAlert />
                                        <AlertTitle>{t('onboarding.account_mismatch_title', 'The From address is not the connected account')}</AlertTitle>
                                        <AlertDescription>
                                            {driver === 'google'
                                                ? t('onboarding.account_mismatch_google', '{{from}} must be a verified alias of {{account}}, or Gmail will rewrite the sender.', { from: settings.from_email, account: oauth.account })
                                                : t('onboarding.account_mismatch_microsoft', '{{from}} must be a mailbox {{account}} may send as (a shared mailbox needs the option under Advanced), or Microsoft will reject the send.', { from: settings.from_email, account: oauth.account })}
                                        </AlertDescription>
                                    </Alert>
                                )}
                            </div>
                        )}

                        {Object.keys(schema.advanced).length > 0 && (
                            <Disclosure icon={SlidersHorizontal} label={t('onboarding.connection_advanced', 'Advanced')}>
                                {keyStoreField && <KeyStoreTabs field={keyStoreField} value={settings.key_store} onChange={value => onSettingChange('key_store', value)} />}
                                {Object.keys(advancedFields).length > 0 && (
                                    <DynamicSettingsForm schema={advancedFields} values={settings} onChange={onSettingChange} errors={errors} />
                                )}
                            </Disclosure>
                        )}
                    </CardContent>
                </Card>
            )}

            {SetupGuide && (
                <Disclosure icon={HelpCircle} label={t('onboarding.how_do_i_get_these', 'How do I get these?')}>
                    <Card>
                        <CardContent>
                            <SetupGuide
                                providerName={providerName}
                                docsUrl={provider?.docsUrl || ''}
                                deliveryMode={FREE_DELIVERY_MODE[driver] || undefined}
                                keyStore={settings.key_store || 'db'}
                                sesDeliveryMode={driver === 'ses' ? 'api' : undefined}
                                sesKeyStore={driver === 'ses' ? (settings.key_store || 'db') : undefined}
                            />
                        </CardContent>
                    </Card>
                </Disclosure>
            )}
        </StepShell>
    );
}
