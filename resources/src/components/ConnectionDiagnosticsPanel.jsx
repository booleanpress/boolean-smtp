import { AlertCircle, CheckCircle2, Info } from 'lucide-react';

import { useTranslations } from '@/hooks/useTranslations';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Item, ItemActions, ItemContent, ItemDescription, ItemGroup, ItemMedia, ItemTitle } from '@/components/ui/item';
import { Separator } from '@/components/ui/separator';

/**
 * Phase C1: Displays enhanced SES connection diagnostics
 * Shows configuration set, VPC endpoint, keep-alive, and static tags status
 */
export default function ConnectionDiagnosticsPanel({ diagnostics, hints }) {
    const { t } = useTranslations();
    if (!diagnostics) {
        return null;
    }

    const {
        configuration_set_active,
        configuration_set_name,
        vpc_private_link_active,
        vpc_endpoint,
        smtp_keep_alive_enabled,
        static_tags_active,
        static_tags_count,
        static_tags_preview,
    } = diagnostics;

    const rows = [
        {
            id: 'configuration_set',
            active: Boolean(configuration_set_active),
            title: t('connection_diagnostics.configuration_set', 'Configuration Set'),
            status: configuration_set_active
                ? t('connection_diagnostics.active_with_name', 'Active: {{name}}', { name: configuration_set_name })
                : t('connection_diagnostics.not_configured', 'Not configured'),
            description: t('connection_diagnostics.event_tracking_desc', 'Event tracking for bounces, complaints, and delivery'),
            badge: configuration_set_active ? t('connection_diagnostics.status_active', 'Active') : t('connection_diagnostics.status_disabled', 'Disabled'),
        },
        {
            id: 'vpc_private_link',
            active: Boolean(vpc_private_link_active),
            title: t('connection_diagnostics.vpc_privatelink', 'VPC PrivateLink Endpoint'),
            status: vpc_private_link_active
                ? t('connection_diagnostics.endpoint_with_value', 'Endpoint: {{endpoint}}', { endpoint: vpc_endpoint })
                : t('connection_diagnostics.using_public_ses', 'Using default public SES endpoint'),
            description: t('connection_diagnostics.enterprise_egress_desc', 'Enterprise deployment with custom egress gateway'),
            badge: vpc_private_link_active ? t('connection_diagnostics.status_active', 'Active') : t('connection_diagnostics.status_public', 'Public'),
        },
        {
            id: 'smtp_keep_alive',
            active: Boolean(smtp_keep_alive_enabled),
            title: t('connection_diagnostics.smtp_keep_alive', 'SMTP Keep-Alive'),
            status: smtp_keep_alive_enabled
                ? t('connection_diagnostics.persistent_enabled', 'Persistent connections enabled')
                : t('connection_diagnostics.new_connection_per_send', 'New connection per send'),
            description: t('connection_diagnostics.batch_send_recommended', 'Recommended for batch sends and high volume'),
            badge: smtp_keep_alive_enabled ? t('connection_diagnostics.status_enabled', 'Enabled') : t('connection_diagnostics.status_disabled', 'Disabled'),
        },
        {
            id: 'static_tags',
            active: Boolean(static_tags_active),
            title: t('connection_diagnostics.static_message_tags', 'Static Message Tags'),
            status: static_tags_active && static_tags_preview?.length > 0
                ? (
                    <span className="flex flex-wrap gap-1">
                        {static_tags_preview.map((tag) => (
                            <Badge key={tag} variant="secondary">{tag}</Badge>
                        ))}
                    </span>
                )
                : t('connection_diagnostics.no_tags_configured', 'No tags configured'),
            description: t('connection_diagnostics.ses_tracking_desc', 'For SES event tracking and email categorization'),
            badge: static_tags_active
                ? t('connection_diagnostics.tags_count', '{{count}} Tags', { count: static_tags_count })
                : t('connection_diagnostics.none', 'None'),
        },
    ];

    return (
        <Alert variant="info" className="mt-6">
            <Info />
            <AlertTitle>{t('connection_diagnostics.title', 'Connection Diagnostics (Phase C1)')}</AlertTitle>
            <AlertDescription className="w-full gap-4 pt-2">
                <ItemGroup className="w-full gap-2">
                    {rows.map((row) => (
                        <Item key={row.id} variant="outline" size="sm" className="bg-card">
                            <ItemMedia>
                                {row.active
                                    ? <CheckCircle2 className="size-5 text-success" aria-hidden="true" />
                                    : <AlertCircle className="size-5 text-muted-foreground" aria-hidden="true" />}
                            </ItemMedia>
                            <ItemContent className="gap-0.5">
                                <ItemTitle className="text-foreground">{row.title}</ItemTitle>
                                <ItemDescription className="line-clamp-none text-xs">{row.status}</ItemDescription>
                                <ItemDescription className="line-clamp-none text-xs">{row.description}</ItemDescription>
                            </ItemContent>
                            <ItemActions>
                                <Badge variant={row.active ? 'success' : 'outline'}>{row.badge}</Badge>
                            </ItemActions>
                        </Item>
                    ))}
                </ItemGroup>

                {hints && hints.length > 0 && (
                    <div className="w-full space-y-2">
                        <Separator />
                        <p className="text-xs font-semibold text-foreground">{t('connection_diagnostics.notes', 'Notes:')}</p>
                        <ul className="list-disc space-y-1 pl-4 text-xs">
                            {hints.map((hint, idx) => (
                                <li key={idx}>{hint}</li>
                            ))}
                        </ul>
                    </div>
                )}
            </AlertDescription>
        </Alert>
    );
}
