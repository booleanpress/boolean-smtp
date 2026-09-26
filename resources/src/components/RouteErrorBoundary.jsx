/**
 * Keeps a screen that fails to load or to render from blanking the whole admin app.
 *
 * @since 1.0.0
 */
import { Component } from 'react';
import { RefreshCw, TriangleAlert } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { Spinner } from '@/components/ui/spinner';
import { translate } from '@/hooks/useTranslations';

/** Session key holding when the app last reloaded itself to fetch a screen's files again. */
const RELOAD_MARK = 'boolean-smtp:reloaded-for-files';

/** A second automatic reload within this many milliseconds would be a loop. */
const RELOAD_WINDOW_MS = 60 * 1000;

/**
 * Whether an error means a screen's code file could not be fetched: the plugin was updated (or its
 * files rebuilt) while this page stayed open, so the file the page knows about no longer exists.
 *
 * @since 1.0.0
 * @param {unknown} error The error a screen threw.
 * @returns {boolean}
 */
export function isMissingFileError(error) {
    const text = `${error?.name ?? ''} ${error?.message ?? ''}`;
    return /Failed to fetch dynamically imported module|Importing a module script failed|error loading dynamically imported module|ChunkLoadError|Loading chunk \S+ failed/i.test(text);
}

/**
 * Whether the app may reload itself now, and if so remember that it did. Storage can be missing or
 * blocked; without it the app never reloads by itself, so it can never loop.
 *
 * @since 1.0.0
 * @param {number} now Current time in milliseconds.
 * @returns {boolean}
 */
function claimAutomaticReload(now) {
    try {
        const last = Number(window.sessionStorage.getItem(RELOAD_MARK) || 0);
        if (now - last < RELOAD_WINDOW_MS) {
            return false;
        }
        window.sessionStorage.setItem(RELOAD_MARK, String(now));
        return true;
    } catch {
        return false;
    }
}

/**
 * The title and explanation for a screen that failed.
 *
 * @since 1.0.0
 * @param {boolean} missingFile Whether the screen's code file could not be fetched.
 * @returns {{ title: string, description: string }}
 */
function errorTexts(missingFile) {
    return {
        title: translate('route_error.title', 'This screen could not be shown'),
        description: missingFile
            ? translate('route_error.updated', 'BooleanSMTP was updated while this page was open. Reload the page to continue.')
            : translate('route_error.failed', 'Something went wrong while showing this screen. Reload the page to try again.'),
    };
}

/**
 * What the screen area shows instead of a screen that failed.
 *
 * @since 1.0.0
 * @param {object} props
 * @param {boolean} props.missingFile Whether the screen's code file could not be fetched.
 * @param {() => void} props.onReload Reloads the page.
 */
function RouteErrorFallback({ missingFile, onReload }) {
    const { title, description } = errorTexts(missingFile);

    return (
        <Empty className="min-h-[50vh]" data-testid="route-error">
            <EmptyHeader>
                <EmptyMedia variant="icon"><TriangleAlert /></EmptyMedia>
                <EmptyTitle>{title}</EmptyTitle>
                <EmptyDescription>{description}</EmptyDescription>
            </EmptyHeader>
            <EmptyContent>
                <Button type="button" onClick={onReload}>
                    <RefreshCw />
                    {translate('common.reload_page', 'Reload page')}
                </Button>
            </EmptyContent>
        </Empty>
    );
}

/**
 * Catches an error from the screen it wraps. A screen whose code file is gone (the plugin was updated
 * while the page was open) reloads the page once, which fetches the current files; any other failure,
 * or a missing file again within a minute of such a reload, shows a message with a Reload button and
 * a toast. The sidebar and header stay usable, and moving to another screen clears the error.
 *
 * @since 1.0.0
 */
export default class RouteErrorBoundary extends Component {
    static defaultProps = {
        onReload: () => window.location.reload(),
        now: () => Date.now(),
    };

    constructor(props) {
        super(props);
        this.state = { error: null, reloading: false };
    }

    static getDerivedStateFromError(error) {
        return { error };
    }

    componentDidCatch(error) {
        const missingFile = isMissingFileError(error);
        if (missingFile && claimAutomaticReload(this.props.now())) {
            this.setState({ reloading: true });
            this.props.onReload();
            return;
        }

        // A toast as well as the message, so the failure is noticed even below the fold.
        const { title, description } = errorTexts(missingFile);
        toast.error(title, { id: 'boolean-smtp-route-error', description });
    }

    componentDidUpdate(previousProps) {
        if (this.state.error && previousProps.resetKey !== this.props.resetKey) {
            this.setState({ error: null, reloading: false });
        }
    }

    render() {
        if (this.state.error && this.state.reloading) {
            return (
                <div className="flex min-h-[50vh] items-center justify-center p-8">
                    <Spinner className="size-8 text-primary" />
                </div>
            );
        }

        if (this.state.error) {
            return <RouteErrorFallback missingFile={isMissingFileError(this.state.error)} onReload={this.props.onReload} />;
        }

        return this.props.children;
    }
}
