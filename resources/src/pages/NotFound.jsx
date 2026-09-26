/**
 * The page for an address the admin app has no route for.
 *
 * @since 1.0.0
 */
import { useEffect, useState } from 'react';
import { Link } from 'react-router';
import { Compass, LayoutDashboard } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/useTranslations';

/**
 * Whether every script on the page has run, and with it every extension that registers routes.
 *
 * @since 1.0.0
 * @returns {boolean}
 */
function usePageLoaded() {
    const [loaded, setLoaded] = useState(() => typeof document === 'undefined' || document.readyState === 'complete');

    useEffect(() => {
        if (loaded) return undefined;
        const done = () => setLoaded(true);
        window.addEventListener('load', done);
        return () => window.removeEventListener('load', done);
    }, [loaded]);

    return loaded;
}

/**
 * "Page not found", with a way back to the Overview. Until the page has finished loading a spinner
 * shows instead, so a direct link to a page another plugin registers never flashes this message.
 *
 * @since 1.0.0
 */
export default function NotFound() {
    const { t } = useTranslations();
    const loaded = usePageLoaded();

    if (!loaded) {
        return (
            <div className="flex min-h-[50vh] items-center justify-center p-8">
                <Spinner className="size-8 text-primary" />
            </div>
        );
    }

    return (
        <Empty className="min-h-[50vh]" data-testid="not-found">
            <EmptyHeader>
                <EmptyMedia variant="icon"><Compass /></EmptyMedia>
                <EmptyTitle>{t('not_found.title', 'Page not found')}</EmptyTitle>
                <EmptyDescription>{t('not_found.description', 'This page is not available.')}</EmptyDescription>
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
