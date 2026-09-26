import { CheckCircle2, Mail, RefreshCw, XCircle } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/useTranslations';
import PreviewCard from './PreviewCard';
import StepShell from './StepShell';

/**
 * Step 4 — Verify: optional, user-initiated connection and inbox checks before review.
 *
 * @since 1.0.0
 *
 * @param {object} props
 * @param {string} props.providerName
 * @param {string} props.methodLine
 * @param {{ fromEmail?: string, fromName?: string }} props.sender
 * @param {boolean} props.hasProbe Whether the provider has a connection check (PHP mail has none).
 * @param {{ status: 'idle'|'running'|'passed'|'failed', message?: string, at?: string }} props.probe
 * @param {() => void} props.onRunProbe
 * @param {{ status: 'idle'|'sending'|'accepted'|'failed', message?: string, to?: string, at?: string }} props.test
 * @param {string} props.recipient
 * @param {(value: string) => void} props.onRecipientChange
 * @param {() => void} props.onSendTest
 * @param {() => void} props.onBack Also the "Fix in Connect" action.
 * @param {() => void} props.onContinue
 * @param {boolean} props.continueBusy
 * @param {{ stepNumber: number, stepCount: number, stepName: string }} props.shell
 */
export default function VerifyStep({
    providerName,
    methodLine,
    sender,
    hasProbe,
    probe,
    onRunProbe,
    test,
    recipient,
    onRecipientChange,
    onSendTest,
    onBack,
    onContinue,
    continueBusy,
    shell,
}) {
    const { t } = useTranslations();
    const recipientValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(recipient || '').trim());

    const probeRow = !hasProbe
        ? { variant: 'outline', label: t('onboarding.verify_probe_na', 'Not available') }
        : probe.status === 'passed'
            ? { variant: 'success', label: t('onboarding.verify_passed', 'Passed') }
            : probe.status === 'failed'
                ? { variant: 'destructive', label: t('onboarding.verify_failed', 'Failed') }
                : probe.status === 'running'
                    ? { variant: 'outline', label: t('onboarding.verify_running', 'Running…') }
                    : { variant: 'outline', label: t('onboarding.verify_not_run', 'Not run') };
    const testRow = test.status === 'accepted'
        ? { variant: 'success', label: t('onboarding.verify_accepted', 'Accepted') }
        : test.status === 'failed'
            ? { variant: 'destructive', label: t('onboarding.verify_failed', 'Failed') }
            : test.status === 'sending'
                ? { variant: 'outline', label: t('onboarding.verify_sending', 'Sending…') }
                : { variant: 'outline', label: t('onboarding.verify_not_sent', 'Not sent') };

    return (
        <StepShell
            {...shell}
            title={t('onboarding.verify_title', 'Check delivery (optional)')}
            description={t('onboarding.verify_desc', 'You can check the connection or send a test now, or finish setup and test later.')}
            onBack={onBack}
            onContinue={onContinue}
            continueLabel={t('onboarding.verify_continue', 'Continue to review')}
            continueBusy={continueBusy}
            preview={(
                <PreviewCard
                    heading={t('onboarding.verify_preview_heading', 'Verification')}
                    envelope={{ fromEmail: sender?.fromEmail, fromName: sender?.fromName, providerName, methodLine }}
                    rows={[
                        { label: t('onboarding.verify_probe_label', 'Connection check'), value: probe.at, badge: probeRow },
                        { label: t('onboarding.verify_test_label', 'Test email'), value: test.status === 'accepted' && test.to ? t('onboarding.verify_check_inbox_short', 'Check {{to}}', { to: test.to }) : test.at, badge: testRow },
                    ]}
                    notes={[t('onboarding.verify_preview_note', '"Accepted" means the service took the message. It is not proof of delivery — the inbox is.')]}
                />
            )}
        >
            <Card>
                <CardHeader>
                    <CardTitle>{t('onboarding.verify_probe_title', 'Connection check')}</CardTitle>
                    <CardDescription>
                        {hasProbe
                            ? t('onboarding.verify_probe_desc', 'Reaches the service with your credentials without sending anything.')
                            : t('onboarding.verify_probe_php_desc', 'PHP mail has no service to check — the test email below is the only check.')}
                    </CardDescription>
                </CardHeader>
                {hasProbe && (
                    <CardContent className="flex flex-col gap-3" aria-live="polite">
                        {probe.status === 'idle' && (
                            <Button type="button" variant="outline" size="sm" className="self-start" onClick={onRunProbe}>
                                <RefreshCw />
                                {t('onboarding.verify_run_check', 'Check connection')}
                            </Button>
                        )}
                        {probe.status === 'running' && (
                            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                                <Spinner className="size-4" />
                                {t('onboarding.verify_probe_running', 'Checking the connection…')}
                            </p>
                        )}
                        {probe.status === 'passed' && (
                            <Alert variant="success">
                                <CheckCircle2 />
                                <AlertTitle>{t('onboarding.verify_probe_passed', 'Connection check passed')}</AlertTitle>
                                {probe.message && <AlertDescription>{probe.message}</AlertDescription>}
                            </Alert>
                        )}
                        {probe.status === 'failed' && (
                            <Alert variant="destructive">
                                <XCircle />
                                <AlertTitle>{t('onboarding.verify_probe_failed', 'Connection check failed')}</AlertTitle>
                                <AlertDescription>
                                    <p>{probe.message}</p>
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        <Button type="button" variant="outline" size="sm" onClick={onBack}>
                                            {t('onboarding.verify_fix_in_connect', 'Fix in Connect')}
                                        </Button>
                                        <Button type="button" variant="ghost" size="sm" onClick={onRunProbe}>
                                            <RefreshCw />
                                            {t('onboarding.verify_run_again', 'Run again')}
                                        </Button>
                                    </div>
                                </AlertDescription>
                            </Alert>
                        )}
                    </CardContent>
                )}
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{t('onboarding.verify_test_title', 'Test email')}</CardTitle>
                    <CardDescription>{t('onboarding.verify_test_desc', 'Send one message through the draft connection to an address you can check.')}</CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col gap-4">
                    <Field>
                        <FieldLabel htmlFor="onboarding-test-recipient">{t('onboarding.verify_recipient', 'Send to')}</FieldLabel>
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <Input
                                id="onboarding-test-recipient"
                                type="email"
                                value={recipient}
                                onChange={e => onRecipientChange(e.target.value)}
                                placeholder={t('onboarding.recipient_placeholder', 'you@example.com')}
                                className="sm:flex-1"
                                aria-invalid={recipient !== '' && !recipientValid}
                            />
                            <Button type="button" onClick={onSendTest} disabled={!recipientValid || test.status === 'sending'}>
                                {test.status === 'sending' ? <Spinner /> : <Mail />}
                                {test.status === 'accepted' || test.status === 'failed'
                                    ? t('onboarding.verify_send_again', 'Send again')
                                    : t('onboarding.verify_send', 'Send test')}
                            </Button>
                        </div>
                        <FieldDescription>{t('onboarding.verify_recipient_help', 'Your own address is the quickest way to see the result.')}</FieldDescription>
                    </Field>

                    <div aria-live="polite">
                        {test.status === 'accepted' && (
                            <Alert variant="success">
                                <CheckCircle2 />
                                <AlertTitle>{t('onboarding.verify_accepted_title', 'Accepted by {{provider}} — now check that inbox, including spam.', { provider: providerName })}</AlertTitle>
                                <AlertDescription>{t('onboarding.verify_accepted_desc', 'Accepted means the service took the message; it is not proof of delivery.')}</AlertDescription>
                            </Alert>
                        )}
                        {test.status === 'failed' && (
                            <Alert variant="destructive">
                                <XCircle />
                                <AlertTitle>{t('onboarding.verify_test_failed', 'The test was not accepted')}</AlertTitle>
                                <AlertDescription>
                                    <p>{test.message}</p>
                                    <div className="mt-2">
                                        <Button type="button" variant="outline" size="sm" onClick={onBack}>
                                            {t('onboarding.verify_fix_in_connect', 'Fix in Connect')}
                                        </Button>
                                    </div>
                                </AlertDescription>
                            </Alert>
                        )}
                    </div>
                </CardContent>
            </Card>
        </StepShell>
    );
}
