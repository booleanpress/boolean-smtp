import React, { useState, useEffect } from 'react';
import { AlertCircle, CheckCircle2, Clock, TrendingUp, XCircle } from 'lucide-react';

import api from '../services/api';
import { useTranslations, translate } from '../hooks/useTranslations';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { Item, ItemActions, ItemContent, ItemDescription, ItemGroup, ItemMedia, ItemTitle } from '@/components/ui/item';
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

/**
 * OAuthRefreshHistoryPanel
 *
 * React component displaying OAuth token refresh history and statistics.
 * Shows recent attempts, success rate, response times, and error distribution.
 *
 * Props:
 *   - connectionId: Connection ID to fetch refresh history for
 *   - driver: OAuth provider (google, outlook)
 *   - onRefresh: Callback when history is manually refreshed
 */
function OAuthRefreshHistoryPanelContent({ connectionId, driver, onRefresh }) {
    const { t, locale } = useTranslations();
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [history, setHistory] = useState(null);
    const [activeTab, setActiveTab] = useState('attempts');
    const [refreshingNow, setRefreshingNow] = useState(false);
    const [actionMessage, setActionMessage] = useState('');

    // Fetch refresh history when component mounts or connectionId changes
    useEffect(() => {
        if (!connectionId) {
            setError(t('oauth_refresh_history.connection_id_missing', 'Connection ID is missing'));
            setLoading(false);
            return;
        }
        fetchRefreshHistory();
    }, [connectionId]);

    const fetchRefreshHistory = async () => {
        setLoading(true);
        setError(null);

        try {
            // Use shared API client so nonce/cookie handling matches the rest of the app.
            const endpoint = `connections/${connectionId}/oauth-refresh-history`;

            const data = await Promise.race([
                api.get(endpoint),
                new Promise((_, reject) =>
                    setTimeout(() => reject(new Error(t('oauth_refresh_history.request_timeout', 'Request timeout - server did not respond within 10 seconds. Try refreshing the page.'))), 10000)
                )
            ]);

            // Unwrap the response (API wraps in {success, message, data})
            const actualData = data.data || data;

            if (!actualData) {
                throw new Error(t('oauth_refresh_history.no_data_from_api', 'No data received from API'));
            }

            setHistory(actualData);

            if (typeof actualData.history_warning === 'string' && actualData.history_warning.trim() !== '') {
                setActionMessage(actualData.history_warning);
            }
        } catch (err) {
            const message = err?.message || t('oauth_refresh_history.fetch_failed', 'Failed to fetch refresh history.');

            if (message.includes('rest_cookie_invalid_nonce') || message.includes('Cookie check failed')) {
                setError(t('oauth_refresh_history.nonce_invalid', 'Session nonce is invalid or expired. Please refresh the page and try again.'));
            } else {
                setError(message);
            }
        } finally {
            setLoading(false);
        }
    };

    const refreshTokenNow = async () => {
        setRefreshingNow(true);
        setActionMessage('');

        try {
            const endpoint = `connections/${connectionId}/oauth-refresh-now`;
            const payload = await Promise.race([
                api.post(endpoint, {}),
                new Promise((_, reject) =>
                    setTimeout(() => reject(new Error(t('oauth_refresh_history.manual_timeout', 'Manual refresh timed out after 15 seconds.'))), 15000)
                )
            ]);

            const data = payload?.data || payload;
            const mins = Number.isFinite(Number(data?.token_seconds_remaining))
                ? Math.max(0, Math.floor(Number(data.token_seconds_remaining) / 60))
                : null;

            setActionMessage(mins != null
                ? t('oauth_refresh_history.token_refreshed_with_expiry', 'Token refreshed successfully. New expiry in {{minutes}}m.', { minutes: mins })
                : t('oauth_refresh_history.token_refreshed', 'Token refreshed successfully.'));

            await fetchRefreshHistory();

            if (typeof onRefresh === 'function') {
                onRefresh(data);
            }
        } catch (err) {
            const message = err?.message || t('oauth_refresh_history.manual_refresh_failed', 'Manual token refresh failed.');
            setActionMessage(message);
        } finally {
            setRefreshingNow(false);
        }
    };

    const title = t('oauth_refresh_history.title', 'OAuth Refresh History');

    const refreshButton = (
        <Button type="button" variant="outline" size="sm" onClick={refreshTokenNow} disabled={refreshingNow}>
            {refreshingNow && <Spinner />}
            {refreshingNow ? t('oauth_refresh_history.refreshing_now', 'Refreshing...') : t('oauth_refresh_history.refresh_now', 'Refresh Token Now')}
        </Button>
    );

    const actionNotice = actionMessage ? (
        <Alert>
            <AlertDescription>{actionMessage}</AlertDescription>
        </Alert>
    ) : null;

    if (loading) {
        return (
            <Card className="w-full">
                <CardHeader>
                    <CardTitle>{title}</CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="flex items-center justify-center gap-2 py-8 text-sm text-muted-foreground">
                        <Spinner />
                        <span>{t('oauth_refresh_history.loading', 'Loading refresh history...')}</span>
                    </div>
                </CardContent>
            </Card>
        );
    }

    if (error) {
        return (
            <Card className="w-full">
                <CardHeader>
                    <CardTitle>{title}</CardTitle>
                </CardHeader>
                <CardContent>
                    <Alert variant="destructive">
                        <AlertCircle />
                        <AlertTitle>{t('oauth_refresh_history.api_error', 'API Error')}</AlertTitle>
                        <AlertDescription>
                            <p>{error}</p>
                            <p className="text-xs">{t('oauth_refresh_history.connection_driver', 'Connection ID: {{connectionId}} | Driver: {{driver}}', { connectionId, driver })}</p>
                        </AlertDescription>
                    </Alert>
                </CardContent>
            </Card>
        );
    }

    if (!history || history.total_count === 0) {
        return (
            <Card className="w-full">
                <CardHeader>
                    <CardTitle>{title}</CardTitle>
                    <CardDescription>{t('oauth_refresh_history.no_attempts', 'No refresh attempts recorded yet')}</CardDescription>
                    <CardAction>{refreshButton}</CardAction>
                </CardHeader>
                <CardContent className="space-y-4">
                    {actionNotice}
                    <Empty className="border-0 py-6">
                        <EmptyHeader>
                            <EmptyMedia variant="icon"><Clock /></EmptyMedia>
                            <EmptyTitle>{t('oauth_refresh_history.auto_refresh_info', 'OAuth tokens will be refreshed automatically.')}</EmptyTitle>
                            <EmptyDescription>{t('oauth_refresh_history.history_appears_info', 'Refresh history will appear here once refresh attempts are made.')}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                </CardContent>
            </Card>
        );
    }

    const stats = history.statistics;
    const attempts = history.attempts || [];
    const topErrors = Array.isArray(stats?.top_errors) ? stats.top_errors : [];
    const maxErrorCount = topErrors.reduce((max, err) => Math.max(max, Number(err.error_count) || 0), 0);

    return (
        <Card className="w-full">
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                <CardDescription>{t('oauth_refresh_history.stats_for_driver', 'Token refresh attempts and statistics for {{driver}}', { driver })}</CardDescription>
                <CardAction>{refreshButton}</CardAction>
            </CardHeader>
            <CardContent className="space-y-4">
                {actionNotice}

                <Tabs value={activeTab} onValueChange={setActiveTab}>
                    <TabsList>
                        <TabsTrigger value="stats">{t('oauth_refresh_history.tab_statistics', 'Statistics')}</TabsTrigger>
                        <TabsTrigger value="attempts">{t('oauth_refresh_history.tab_recent_attempts', 'Recent Attempts')}</TabsTrigger>
                    </TabsList>

                    <TabsContent value="stats" className="space-y-4 pt-3">
                        {stats && (
                            <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                                <StatBox
                                    icon={<CheckCircle2 className="text-success" />}
                                    label={t('oauth_refresh_history.success_rate', 'Success Rate')}
                                    value={`${stats.success_rate}%`}
                                    subtext={`${stats.successful}/${stats.total_attempts}`}
                                />
                                <StatBox
                                    icon={<TrendingUp className="text-info" />}
                                    label={t('oauth_refresh_history.avg_response', 'Avg Response')}
                                    value={`${stats.avg_response_time_ms}ms`}
                                    subtext={t('oauth_refresh_history.seven_day_average', '7-day average')}
                                />
                                <StatBox
                                    icon={<AlertCircle className="text-destructive" />}
                                    label={t('oauth_refresh_history.failed', 'Failed')}
                                    value={stats.failed}
                                    subtext={t('oauth_refresh_history.of_attempts', 'of {{count}} attempts', { count: stats.total_attempts })}
                                />
                                <StatBox
                                    icon={<Clock className="text-muted-foreground" />}
                                    label={t('oauth_refresh_history.period', 'Period')}
                                    value={String(stats.period || '').toUpperCase()}
                                    subtext={t('oauth_refresh_history.statistics_window', 'statistics window')}
                                />
                            </div>
                        )}

                        {topErrors.length > 0 && (
                            <div className="space-y-3">
                                <h3 className="text-sm font-semibold">{t('oauth_refresh_history.top_errors', 'Top Errors')}</h3>
                                <ItemGroup className="gap-2">
                                    {topErrors.map((err, idx) => {
                                        const count = Number(err.error_count) || 0;
                                        const percent = maxErrorCount > 0 ? (count / maxErrorCount) * 100 : 0;
                                        const label = getErrorLabel(err.error_code, t);
                                        return (
                                            <Item key={`${err.error_code}-${idx}`} variant="muted" size="sm">
                                                <ItemContent>
                                                    <ItemTitle>{label}</ItemTitle>
                                                </ItemContent>
                                                <ItemActions>
                                                    <Progress
                                                        value={percent}
                                                        aria-label={label}
                                                        className="w-32 [&>[data-slot=progress-indicator]]:bg-destructive"
                                                    />
                                                    <span className="w-12 text-right text-xs font-semibold tabular-nums text-muted-foreground">{count}</span>
                                                </ItemActions>
                                            </Item>
                                        );
                                    })}
                                </ItemGroup>
                            </div>
                        )}
                    </TabsContent>

                    <TabsContent value="attempts" className="pt-3">
                        <ItemGroup className="max-h-96 gap-2 overflow-y-auto">
                            {attempts.map((attempt) => (
                                <AttemptRow key={attempt.id} attempt={attempt} locale={locale} />
                            ))}
                        </ItemGroup>
                    </TabsContent>
                </Tabs>

                <p className="text-right text-xs text-muted-foreground">
                    {t('oauth_refresh_history.last_updated', 'Last updated: {{time}}', { time: new Date().toLocaleTimeString(locale.replace('_', '-')) })}
                </p>
            </CardContent>
        </Card>
    );
}

/**
 * Stat Box Component
 */
function StatBox({ icon, label, value, subtext }) {
    return (
        <Item variant="outline">
            <ItemContent>
                <ItemDescription>{label}</ItemDescription>
                <p className="text-2xl font-semibold tabular-nums">{value}</p>
                {subtext && <p className="text-xs text-muted-foreground">{subtext}</p>}
            </ItemContent>
            <ItemMedia variant="icon">{icon}</ItemMedia>
        </Item>
    );
}

/**
 * Attempt Row Component
 */
function AttemptRow({ attempt, locale }) {
    const succeeded = attempt.status === 'success';

    return (
        <Item variant="outline" size="sm">
            <ItemContent className="min-w-0">
                <ItemTitle className="flex-wrap">
                    <Badge variant={succeeded ? 'success' : 'destructive'}>
                        {succeeded ? <CheckCircle2 /> : <XCircle />}
                        <span className="capitalize">{attempt.status}</span>
                    </Badge>
                    {attempt.error_code && (
                        <Badge variant="outline" className="font-mono">{attempt.error_code}</Badge>
                    )}
                </ItemTitle>
                {/* Raw provider error bodies are redacted server-side unless a developer opts in
                    via the boolean_smtp_oauth_debug_ui filter (see ConnectionController::
                    getOAuthRefreshHistory()) -- capped here as a second line of defense so an
                    enabled debug payload can't blow out this compact list's layout. */}
                {attempt.error_message && (
                    <ItemDescription className="line-clamp-2 text-xs break-all">{attempt.error_message}</ItemDescription>
                )}
            </ItemContent>
            <ItemActions className="flex-col items-end gap-0.5 text-xs text-muted-foreground">
                <span>{formatTime(attempt.attempted_at, locale)}</span>
                <span className="tabular-nums">{attempt.response_time_ms}ms</span>
            </ItemActions>
        </Item>
    );
}

/**
 * Utility Functions
 */
function formatTime(dateString, locale = 'en-US') {
    const date = new Date(dateString);
    return date.toLocaleString(locale.replace('_', '-'), {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function getErrorLabel(errorCode, t) {
    const labels = {
        'oauth_refresh_expired': t('oauth_refresh_history.error_token_expired', 'Token Expired'),
        'oauth_refresh_invalid_grant': t('oauth_refresh_history.error_invalid_grant', 'Invalid Grant'),
        'oauth_refresh_rate_limit': t('oauth_refresh_history.error_rate_limited', 'Rate Limited'),
        'oauth_refresh_network_timeout': t('oauth_refresh_history.error_network_timeout', 'Network Timeout'),
        'oauth_refresh_provider_error': t('oauth_refresh_history.error_provider_error', 'Provider Error'),
        'oauth_refresh_unknown': t('oauth_refresh_history.error_unknown', 'Unknown Error'),
    };
    return labels[errorCode] || errorCode;
}

/**
 * Error Boundary Wrapper for OAuthRefreshHistoryPanel
 */
class OAuthRefreshHistoryPanelErrorBoundary extends React.Component {
    constructor(props) {
        super(props);
        this.state = { hasError: false, error: null };
    }

    static getDerivedStateFromError(error) {
        return { hasError: true, error };
    }

    componentDidCatch(error, errorInfo) {
        console.error('[OAuthRefreshHistoryPanel] Error boundary caught:', error, errorInfo);
    }

    render() {
        if (this.state.hasError) {
            return (
                <Card className="w-full">
                    <CardHeader>
                        <CardTitle>{translate('oauth_refresh_history.title', 'OAuth Refresh History')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Alert variant="warning">
                            <AlertCircle />
                            <AlertTitle>{translate('oauth_refresh_history.component_error', 'Component Error')}</AlertTitle>
                            <AlertDescription>
                                <p>{this.state.error?.message || translate('oauth_refresh_history.component_failed_render', 'Component failed to render')}</p>
                                <p className="text-xs">{translate('oauth_refresh_history.connection_driver_short', 'Connection: {{connectionId}} | Driver: {{driver}}', { connectionId: this.props.connectionId, driver: this.props.driver })}</p>
                            </AlertDescription>
                        </Alert>
                    </CardContent>
                </Card>
            );
        }

        return <OAuthRefreshHistoryPanelContent {...this.props} />;
    }
}

export default OAuthRefreshHistoryPanelErrorBoundary;
