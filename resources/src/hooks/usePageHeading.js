import { useEffect, useRef, useState } from 'react';

/**
 * Keep one `<h1>` per screen and announce navigation to assistive technology.
 *
 * Most screens render their own `<h1>`; a few (the dashboard, a connection's edit screen, some
 * pages other plugins register) rely on the title in the app's header. This watches the page content and reports
 * whether it carries a heading of its own, so the header can render its title as the `<h1>` only
 * when the page does not — including headings that appear after the page has loaded its data.
 *
 * After a route change (not on first load) focus moves to that `<h1>`, so a screen-reader user
 * hears the new page's name instead of staying on the link that was followed.
 *
 * @since 1.0.0
 *
 * @param {string} pathname The current route.
 * @returns {{ contentRef: import('react').RefObject<HTMLElement>, headerHeadingRef: import('react').RefObject<HTMLElement>, pageHasHeading: boolean }}
 */
export function usePageHeading(pathname) {
    const contentRef = useRef(null);
    const headerHeadingRef = useRef(null);
    const [pageHasHeading, setPageHasHeading] = useState(false);
    const firstRouteRef = useRef(true);

    useEffect(() => {
        const content = contentRef.current;
        if (!content) return undefined;
        const check = () => setPageHasHeading(content.querySelector('h1') !== null);
        check();
        const observer = new MutationObserver(check);
        observer.observe(content, { childList: true, subtree: true });
        return () => observer.disconnect();
    }, []);

    useEffect(() => {
        if (firstRouteRef.current) {
            firstRouteRef.current = false;
            return undefined;
        }
        // Wait a frame so the new screen has rendered its heading.
        const frame = requestAnimationFrame(() => {
            const heading = contentRef.current?.querySelector('h1') || headerHeadingRef.current;
            if (!heading || heading.contains(document.activeElement)) return;
            if (!heading.hasAttribute('tabindex')) heading.setAttribute('tabindex', '-1');
            heading.classList.add('outline-none');
            heading.focus({ preventScroll: true });
        });
        return () => cancelAnimationFrame(frame);
    }, [pathname]);

    return { contentRef, headerHeadingRef, pageHasHeading };
}
