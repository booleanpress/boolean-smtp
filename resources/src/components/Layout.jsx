import { useMemo } from 'react';
import { NavLink, useLocation } from 'react-router';
import { useTheme } from '@/components/theme-provider';
import { HelpCircle, Settings, Sun, Moon, Plug, Send } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar';
import { GlobalSearch } from './GlobalSearch';
import { AppSidebar } from './app-sidebar';
import { useTranslations } from '@/hooks/useTranslations';
import MailTakeoverWarning from './MailTakeoverWarning';
import { usePageHeading } from '@/hooks/usePageHeading';

const getPageTitle = (pathname, t) => {
    if (pathname === '/') return t('layout.page_overview', 'Overview');
    if (pathname === '/about') return t('layout.page_about', 'About BooleanSMTP');
    if (pathname.startsWith('/connections/new')) return t('layout.page_new_connection', 'New Connection');
    if (pathname.startsWith('/connections/') && pathname !== '/connections') return t('layout.page_edit_connection', 'Edit Connection');
    if (pathname.startsWith('/connections')) return t('layout.page_mailers', 'Mailers');
    if (pathname.startsWith('/logs/') && pathname !== '/logs') return t('layout.page_log_details', 'Email Log Details');
    if (pathname.startsWith('/logs')) return t('layout.page_logs', 'Email Logs');
    if (pathname.startsWith('/tools/test')) return t('layout.page_test_email', 'Email Deliverability Test');
    if (pathname.startsWith('/tools/domain')) return t('layout.page_domain_auth', 'Domain Authentication');
    if (pathname.startsWith('/tools/import')) return t('layout.page_import_export', 'Import/Export');
    if (pathname.startsWith('/tools/migration')) return t('layout.page_migration', 'Migration');
    if (pathname === '/settings') return t('layout.page_settings', 'Settings');
    if (pathname === '/settings/notifications') return t('layout.page_alerts_notifications', 'Alerts & Notifications');
    if (pathname === '/onboard' || pathname === '/setup') return t('layout.page_onboarding', 'Onboarding');
    if (pathname.startsWith('/analytics')) return t('layout.page_analytics', 'Analytics');
    if (pathname.startsWith('/routing')) return t('layout.page_routing', 'Routing');
    return t('layout.page_not_found', 'Page not found');
};

function ThemeToggle() {
    const { t } = useTranslations();
    const { resolvedTheme, setTheme } = useTheme();
    const label = t('layout.toggle_theme', 'Toggle theme');

    return (
        <Button
            variant="outline"
            size="icon-sm"
            onClick={() => setTheme(resolvedTheme === 'dark' ? 'light' : 'dark')}
            aria-label={label}
            title={label}
        >
            <Sun className="scale-100 rotate-0 transition-all dark:scale-0 dark:-rotate-90" />
            <Moon className="absolute scale-0 rotate-90 transition-all dark:scale-100 dark:rotate-0" />
            <span className="sr-only">{label}</span>
        </Button>
    );
}

function HeaderNavLink({ to, label, children }) {
    return (
        <Button
            variant="outline"
            size="icon-sm"
            asChild
            title={label}
            aria-label={label}
        >
            <NavLink
                to={to}
                className={({ isActive }) => isActive ? 'bg-accent text-accent-foreground' : undefined}
            >
                {children}
                <span className="sr-only">{label}</span>
            </NavLink>
        </Button>
    );
}

export default function Layout({ children }) {
    const { t } = useTranslations();
    const location = useLocation();
    const pageTitle = useMemo(() => getPageTitle(location.pathname, t), [location.pathname, t]);
    const { contentRef, headerHeadingRef, pageHasHeading } = usePageHeading(location.pathname);
    // The header's title is the screen's heading only when the screen has none of its own.
    const HeaderTitle = pageHasHeading ? 'p' : 'h1';

    return (
        <SidebarProvider defaultOpen={false} className="min-h-0 h-full" style={{ '--sidebar-width': '13rem' }}>
            <AppSidebar />
            <SidebarInset className="min-h-0 overflow-hidden">
                <header className="sticky top-0 z-40 flex h-12 shrink-0 items-center gap-2 border-b bg-card px-4">
                    <SidebarTrigger className="-ml-1" />
                    <Separator orientation="vertical" className="mr-2 data-[orientation=vertical]:h-4" />
                    <HeaderTitle ref={headerHeadingRef} className="truncate text-base font-semibold">{pageTitle}</HeaderTitle>

                    <div className="ml-auto flex shrink-0 items-center gap-2">
                        <GlobalSearch />
                        <div className="flex items-center gap-1" aria-label={t('layout.quick_navigation', 'Quick navigation')}>
                            <HeaderNavLink to="/connections" label={t('layout.nav_mailers', 'Mailers')}>
                                <Plug />
                            </HeaderNavLink>
                            <HeaderNavLink to="/tools/test" label={t('layout.nav_test_email', 'Test Email')}>
                                <Send />
                            </HeaderNavLink>
                        </div>
                        <Separator orientation="vertical" className="mx-1 data-[orientation=vertical]:h-5" />
                        <ThemeToggle />
                        <HeaderNavLink to="/about" label={t('help.help_button', 'Help & Support')}>
                            <HelpCircle />
                        </HeaderNavLink>
                        <Button variant="outline" size="icon-sm" asChild title={t('layout.settings', 'Settings')}>
                            <NavLink to="/settings" aria-label={t('layout.settings', 'Settings')}>
                                <Settings />
                                <span className="sr-only">{t('layout.settings', 'Settings')}</span>
                            </NavLink>
                        </Button>
                    </div>
                </header>

                <main className="flex-1 overflow-y-auto p-4 md:p-6">
                    <div ref={contentRef} className="mx-auto max-w-7xl">
                        <MailTakeoverWarning />
                        {children}
                    </div>
                </main>
            </SidebarInset>
        </SidebarProvider>
    );
}
