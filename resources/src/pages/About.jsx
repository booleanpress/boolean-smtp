import { Link } from 'react-router';
import {
    BarChart3,
    Bell,
    ClipboardList,
    ExternalLink,
    FileText,
    HelpCircle,
    Gauge,
    Mail,
    MessageCircle,
    Repeat,
    Route as RouteIcon,
    Send,
    ShieldCheck,
    Star,
} from 'lucide-react';

import { ProBadge } from '@/components/ProBadge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Item, ItemContent, ItemDescription, ItemGroup, ItemMedia, ItemTitle } from '@/components/ui/item';
import { SectionCard } from '@/components/section-card';
import { proFeatureValueProp, useProCapability } from '@/hooks/useProCapability';
import { useTranslations } from '@/hooks/useTranslations';
import { DOCS_URL } from '@/config/docs';

const proHighlights = [
    { key: 'routing.advanced', icon: RouteIcon, title: 'Advanced routing rules' },
    { key: 'analytics.dashboard', icon: BarChart3, title: 'Real-time analytics' },
    { key: 'deliverability.scoring', icon: Gauge, title: 'Deliverability scoring' },
    { key: 'queue.intelligence', icon: Repeat, title: 'Smart retry queue' },
    { key: 'governance.audit', icon: ClipboardList, title: 'Audit trail' },
    { key: 'notifications.advanced', icon: Bell, title: 'Proactive connection alerts' },
];

// Keep the Pro card and its license-aware actions ready to re-enable on this page.
const SHOW_ABOUT_PRO_SECTION = false;

function ProCta({ t }) {
    const { isProInstalled, isProLicensed, upgradeUrl } = useProCapability();

    if (isProInstalled && isProLicensed) {
        return (
            <div className="flex flex-wrap items-center gap-3">
                <Badge variant="success">
                    <ShieldCheck />
                    {t('about.pro_active', 'Pro is active')}
                </Badge>
                <Button variant="outline" size="sm" asChild>
                    <Link to="/settings">{t('about.pro_manage', 'Manage in Settings')}</Link>
                </Button>
            </div>
        );
    }

    if (isProInstalled) {
        return (
            <Button asChild>
                <Link to="/settings">{t('about.pro_cta_connect', 'Connect your free account')}</Link>
            </Button>
        );
    }

    if (!upgradeUrl) {
        return null;
    }

    return (
        <Button asChild>
            <a href={upgradeUrl} target="_blank" rel="noreferrer">
                {t('about.pro_cta_get', 'Get free Pro access')}
                <ExternalLink />
            </a>
        </Button>
    );
}

export default function About() {
    const { t } = useTranslations();
    const { isProLicensed } = useProCapability();
    const admin = typeof window !== 'undefined' ? window.BooleanSmtpAdmin || {} : {};
    const faqs = [
        [t('help.faq_1_question', 'Why are my emails not being sent?'), t('help.faq_1_answer', "Check your connection settings in the 'Connections' tab. Ensure your API keys or SMTP credentials are correct and that the connection is marked as 'Operational'.")],
        [t('help.faq_alerts_question', 'Where do failure alerts go?'), t('help.faq_alerts_answer', 'To Slack, Discord or Telegram — set them up under Settings → Alerts & Notifications. Alerts are never sent by email: when a message fails, the mail path itself is what is broken.')],
        [t('help.faq_4_question', 'Can I log all outgoing emails?'), t('help.faq_4_answer', "Yes, email logging is enabled by default. You can view, search, and resend logged emails in the 'Logs' tab.")],
    ];
    const details = [
        [t('about.plugin_version', 'Plugin version'), admin.version || '—'],
        [t('about.wordpress_version', 'WordPress version'), admin.wpVersion || '—'],
        [t('about.php_version', 'PHP version'), admin.phpVersion || '—'],
    ];

    return (
        <div className="space-y-6">
            <header className="flex items-center gap-4">
                <div className="relative flex size-12 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                    <Send className="size-6" />
                    <span className="absolute -right-1 -top-1 flex size-3.5">
                        <span className="absolute inline-flex size-full animate-ping rounded-full bg-success/60" />
                        <span className="relative inline-flex size-3.5 rounded-full bg-success" />
                    </span>
                </div>
                <div className="min-w-0">
                    <p className="text-base font-semibold text-foreground">
                        {t('about.subtitle', 'Reliable email delivery tools for WordPress.')}
                    </p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {t('about.tagline', 'Free, open, and actively delivering for WordPress sites everywhere.')}
                    </p>
                </div>
            </header>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <SectionCard
                        title={t('about.developer_message_title', 'A message from the developer')}
                        description={t(
                            'about.developer_message_description',
                            'Experience behind dependable WordPress email.'
                        )}
                    >
                        <div className="max-w-3xl space-y-4 text-sm leading-6 text-muted-foreground">
                            <p>{t('about.developer_message_greeting', 'Hello, and thank you for using BooleanSMTP.')}</p>
                            <p>{t(
                                'about.developer_message_experience',
                                'For more than 15 years, I have worked with WordPress websites and plugins, and for over 10 years I have focused on email deliverability. My work has covered e-commerce, email servers, email marketing, and automation flows—areas where dependable email is essential to daily operations.'
                            )}</p>
                            <p>{t(
                                'about.developer_message_scale',
                                'I have operated private email infrastructure that handled up to 30 million emails a day. That experience taught me that email delivery is more than sending a message: it depends on careful configuration, trustworthy systems, and attention to the details that help critical emails reach their intended recipients.'
                            )}</p>
                            <p>{t(
                                'about.developer_message_mission',
                                'I built BooleanSMTP as one way to share that practical knowledge with the WordPress community. My goal is to help you create a more reliable email experience for your customers, team, and business, and to keep improving the plugin in ways that make dependable delivery easier to achieve.'
                            )}</p>
                        </div>
                    </SectionCard>

                    <SectionCard
                        title={(
                            <span className="flex items-center gap-2">
                                <HelpCircle className="size-4 text-muted-foreground" aria-hidden="true" />
                                {t('help.faq', 'Frequently Asked Questions')}
                            </span>
                        )}
                    >
                        <dl className="divide-y">
                            {faqs.map(([question, answer]) => (
                                <div key={question} className="space-y-1 py-4 first:pt-0 last:pb-0">
                                    <dt className="font-medium">{question}</dt>
                                    <dd className="text-sm leading-6 text-muted-foreground">{answer}</dd>
                                </div>
                            ))}
                        </dl>
                    </SectionCard>

                    {SHOW_ABOUT_PRO_SECTION && (
                        <SectionCard
                            title={t('about.pro_title', 'BooleanSMTP Pro')}
                            description={t(
                                'about.pro_description',
                                'Unlock every feature with a free BooleanPress account.'
                            )}
                            action={<ProBadge unlocked={isProLicensed} />}
                            contentClassName="grid items-stretch gap-3 sm:grid-cols-2 lg:grid-cols-3"
                            footer={<ProCta t={t} />}
                        >
                            {proHighlights.map(({ key, icon: Icon, title }) => (
                                <div key={key} className="flex min-w-0 flex-col items-start gap-3 rounded-lg border p-4">
                                    <div className="flex size-8 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
                                        <Icon className="size-4" />
                                    </div>
                                    <div className="min-w-0 space-y-1">
                                        <p className="font-medium">{t(`about.pro_feature_${key}_title`, title)}</p>
                                        <p className="text-sm text-muted-foreground">{proFeatureValueProp(key)}</p>
                                    </div>
                                </div>
                            ))}
                        </SectionCard>
                    )}
                </div>

                <div className="space-y-6 lg:col-span-1">
                    <SectionCard
                        title={t('about.system_title', 'System information')}
                        description={t('about.system_description', 'Versions detected for this WordPress installation.')}
                    >
                        <dl className="grid grid-cols-1 divide-y">
                            {details.map(([label, value]) => (
                                <div key={label} className="flex items-baseline justify-between gap-4 py-3 first:pt-0 last:pb-0">
                                    <dt className="text-xs font-medium text-muted-foreground">{label}</dt>
                                    <dd className="font-mono text-sm font-medium tabular-nums">{value}</dd>
                                </div>
                            ))}
                        </dl>
                    </SectionCard>

                    <SectionCard
                        title={t('about.community_title', 'Community & Support')}
                        description={t('about.community_description', "Get help, share feedback, or see what's new.")}
                    >
                        <ItemGroup className="gap-2">
                            <Item variant="outline" size="sm" asChild>
                                <a href={DOCS_URL} target="_blank" rel="noreferrer">
                                    <ItemMedia variant="icon"><FileText /></ItemMedia>
                                    <ItemContent>
                                        <ItemTitle>{t('help.documentation', 'Documentation')}</ItemTitle>
                                        <ItemDescription>{t('help.documentation_desc', 'Comprehensive setup guides and API docs')}</ItemDescription>
                                    </ItemContent>
                                </a>
                            </Item>
                            <Item variant="outline" size="sm" asChild>
                                <a href="https://wordpress.org/support/plugin/boolean-smtp/" target="_blank" rel="noreferrer">
                                    <ItemMedia variant="icon"><MessageCircle /></ItemMedia>
                                    <ItemContent>
                                        <ItemTitle>{t('help.support_forum', 'Support Forum')}</ItemTitle>
                                        <ItemDescription>{t('help.support_forum_desc', 'Ask a question on WordPress.org')}</ItemDescription>
                                    </ItemContent>
                                </a>
                            </Item>
                            <Item variant="outline" size="sm" asChild>
                                <a href="mailto:contact@booleansmtp.com">
                                    <ItemMedia variant="icon"><Mail /></ItemMedia>
                                    <ItemContent>
                                        <ItemTitle>{t('help.email_us', 'Email Us')}</ItemTitle>
                                        <ItemDescription>contact@booleansmtp.com</ItemDescription>
                                    </ItemContent>
                                </a>
                            </Item>
                            <Item variant="outline" size="sm" asChild>
                                <a href="https://wordpress.org/plugins/boolean-smtp/" target="_blank" rel="noreferrer">
                                    <ItemMedia variant="icon"><Star /></ItemMedia>
                                    <ItemContent>
                                        <ItemTitle>{t('dashboard.write_review', 'Write a Review')}</ItemTitle>
                                        <ItemDescription>{t('about.write_review_desc', 'Tell other WordPress admins what you think')}</ItemDescription>
                                    </ItemContent>
                                </a>
                            </Item>
                        </ItemGroup>
                    </SectionCard>
                </div>
            </div>
        </div>
    );
}
