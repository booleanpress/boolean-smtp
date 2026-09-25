import { useState, useEffect } from 'react';
import { useNavigate, useParams } from 'react-router';
import { AlertCircle } from 'lucide-react';
import { toast } from 'sonner';
import api, { fieldErrors } from '../services/api';
import ConnectionForm from './ConnectionForm';
import ConnectionFormSkeleton from '../components/connections/ConnectionFormSkeleton';
import { Button } from '../components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '../components/ui/dialog';
import { Alert, AlertDescription, AlertTitle } from '../components/ui/alert';
import { Input } from '../components/ui/input';
import { Label } from '../components/ui/label';
import { Spinner } from '../components/ui/spinner';
import { useTranslations } from '../hooks/useTranslations';

function getCurrentUserEmail() {
    if (typeof window === 'undefined') {
        return '';
    }
    return String(window.BooleanSmtpAdmin?.currentUserEmail || '').trim();
}

export default function ConnectionEdit() {
    const { id } = useParams();
    const navigate = useNavigate();
    const { t } = useTranslations();
    const [connection, setConnection] = useState(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [testing, setTesting] = useState(false);
    const [testResult, setTestResult] = useState(null);
    const [testDialogOpen, setTestDialogOpen] = useState(false);
    const [testRecipient, setTestRecipient] = useState(() => getCurrentUserEmail());
    const [error, setError] = useState('');
    const [validationErrors, setValidationErrors] = useState({});
    const [senderConflict, setSenderConflict] = useState(null);

    useEffect(() => {
        loadConnection();
    }, [id]);

    async function loadConnection() {
        setLoading(true);
        try {
            const res = await api.get(`connections/${id}`);
            const conn = res.data || res;
            setConnection(conn);
        } catch (err) {
            setError(err.message);
        } finally {
            setLoading(false);
        }
    }

    async function handleSave(formData) {
        setSaving(true);
        setError('');
        setValidationErrors({});
        setSenderConflict(null);
        try {
            await api.put(`connections/${id}`, formData);
            navigate('/connections');
        } catch (err) {
            const message = String(err?.message || t('connection_edit.failed_update_connection', 'Failed to update connection.'));
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

    async function refreshConnection() {
        try {
            const res = await api.get(`connections/${id}`);
            setConnection(res.data);
        } catch (e) {
            console.error(e);
        }
    }

    async function sendTestEmail() {
        const recipient = String(testRecipient || '').trim();
        if (!recipient) {
            setTestResult({ success: false, error: t('connections.recipient_required', 'Recipient email is required.') });
            toast.error(t('connections.recipient_required', 'Recipient email is required.'));
            return;
        }

        setTesting(true);
        setTestResult(null);
        try {
            const res = await api.post('test-email', {
                to: recipient,
                connection_id: Number(id),
            });
            const payload = res.data ?? res;
            const sent = Boolean(payload?.sent);

            setTestResult({
                success: sent,
                message: res?.message || (sent ? t('connection_edit.test_sent_success', 'Test email sent successfully.') : t('connections.test_failed', 'Test email failed to send.')),
                error: sent ? '' : (payload?.error || ''),
                to: payload?.to || recipient,
            });

            if (sent) {
                toast.success(t('connections.test_sent', 'Test email sent to {{email}}.', { email: payload?.to || recipient }));
                setTestDialogOpen(false);
            } else {
                toast.error(payload?.error || t('connections.test_failed', 'Test email failed to send.'));
            }

            await refreshConnection();
        } catch (err) {
            const message = String(err?.message || t('connections.test_failed', 'Test email failed to send.'));
            setTestResult({ success: false, error: message, message });
            toast.error(message);
            await refreshConnection();
        } finally {
            setTesting(false);
        }
    }

    function handleTest() {
        if (!testRecipient) {
            setTestRecipient(getCurrentUserEmail());
        }
        setTestDialogOpen(true);
    }

    if (loading) {
        return <ConnectionFormSkeleton />;
    }

    if (!connection && error) {
        return (
            <Alert variant="destructive">
                <AlertCircle />
                <AlertTitle>{t('connection_edit.load_failed', 'Failed to load connection')}</AlertTitle>
                <AlertDescription>{error}</AlertDescription>
            </Alert>
        );
    }

    if (!connection) return null;

    return (
        <>
            <ConnectionForm
                driver={connection.driver}
                initialData={connection}
                onSave={handleSave}
                onCancel={() => navigate('/connections')}
                onRemoteSettingsUpdated={refreshConnection}
                onTest={handleTest}
                saving={saving}
                testing={testing}
                testResult={testResult}
                error={error}
                validationErrors={validationErrors}
                senderConflict={senderConflict}
            />

            <Dialog open={testDialogOpen} onOpenChange={setTestDialogOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('connections.test_dialog_title', 'Send Test Email')}</DialogTitle>
                        <DialogDescription>
                            {t('connections.test_dialog_desc', 'Enter a recipient email address. This sends through the selected connection and is recorded in Email Logs.')}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="connection-test-recipient">{t('connections.recipient_email', 'Recipient Email')}</Label>
                        <Input
                            id="connection-test-recipient"
                            type="email"
                            placeholder={t('connections.recipient_placeholder', 'name@example.com')}
                            value={testRecipient}
                            onChange={(e) => setTestRecipient(e.target.value)}
                            disabled={testing}
                        />
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setTestDialogOpen(false)} disabled={testing}>
                            {t('common.cancel', 'Cancel')}
                        </Button>
                        <Button onClick={sendTestEmail} disabled={testing}>
                            {testing && <Spinner />}
                            {t('connections.send_test_email', 'Send Test Email')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
