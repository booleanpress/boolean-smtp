import {
    FileText,
    HelpCircle,
    Mail,
    MessageCircle,
    Send,
    Star,
} from 'lucide-react';

import { Item, ItemContent, ItemDescription, ItemGroup, ItemMedia, ItemTitle } from '@/components/ui/item';
import { SectionCard } from '@/components/section-card';
import { useTranslations } from '@/hooks/useTranslations';
import { DOCS_URL } from '@/config/docs';

export default function About() {
    const { t } = useTranslations();
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
