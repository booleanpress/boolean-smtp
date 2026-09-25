/**
 * The page for an address the admin app has no route for.
 *
 * @since 1.0.0
 */
import { useEffect, useState } from 'react';
import { Link, useLocation } from 'react-router';
import { Compass, LayoutDashboard } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { Spinner } from '@/components/ui/spinner';
import { proPageFor } from '@/config/proPages';
import { useProCapability } from '@/hooks/useProCapability';
import { useProExtensions } from '@/hooks/useProExtensions';
import { useTranslations } from '@/hooks/useTranslations';

/**
 * How long a direct visit waits for the installed Pro add-on to register its pages before the
 * address counts as unknown, in milliseconds.
 *
 * @since 1.0.0
 */
const PRO_REGISTRATION_WAIT_MS = 3000;

/**
 * "Page not found", with a way back to the Overview. A Pro page opened without the add-on says
 * which page it is; while an installed add-on has not registered its pages yet, a spinner shows
 * instead, so a direct link to a Pro page never flashes this message.
 *
 * @since 1.0.0
 */
export default function NotFound() {
    const { t } = useTranslations();
    const { pathname } = useLocation();
    const { isProInstalled } = useProCapability();
    const { proRoutes } = useProExtensions();
    const waitingForPro = isProInstalled && proRoutes.length === 0;
    const [waitedOut, setWaitedOut] = useState(false);

    useEffect(() => {
        if (!waitingForPro) return undefined;
        const timer = window.setTimeout(() => setWaitedOut(true), PRO_REGISTRATION_WAIT_MS);
        return () => window.clearTimeout(timer);
    }, [waitingForPro]);

    if (waitingForPro && !waitedOut) {
        return (
            <div className="flex min-h-[50vh] items-center justify-center p-8">
                <Spinner className="size-8 text-primary" />
            </div>
        );
    }

    const proPage = proPageFor(pathname);
    const description = proPage && !isProInstalled
        ? t('not_found.pro_page', '{{page}} is part of BooleanSMTP Pro, which is not installed on this site.', { page: t(proPage.labelKey, proPage.fallback) })
        : t('not_found.description', 'There is no BooleanSMTP page at this address.');

    return (
        <Empty className="min-h-[50vh]" data-testid="not-found">
            <EmptyHeader>
                <EmptyMedia variant="icon"><Compass /></EmptyMedia>
                <EmptyTitle>{t('not_found.title', 'Page not found')}</EmptyTitle>
                <EmptyDescription>{description}</EmptyDescription>
            </EmptyHeader>
            <EmptyContent>
                <Button asChild>
                    <Link to="/">
                        <LayoutDashboard />
                        {t('not_found.go_overview', 'Go to Overview')}
                    </Link>
                </Button>
            </EmptyContent>
        </Empty>
    );
}
