/**
 * The pages the Pro add-on registers as routes, as the sidebar lists them. The free plugin names
 * them so its sidebar can show them next to its own and so a direct visit without the add-on can
 * say which page was asked for.
 *
 * @since 1.0.0
 */
import { ArrowDownUp, BarChart3, Globe, Route } from 'lucide-react';

/**
 * Pro pages listed in their own sidebar group.
 *
 * @since 1.0.0
 * @type {Array<{ to: string, labelKey: string, fallback: string, icon: import('react').ComponentType }>}
 */
export const PRO_NAV_ITEMS = [
    { to: '/analytics', labelKey: 'layout.nav_analytics', fallback: 'Analytics', icon: BarChart3 },
    { to: '/routing', labelKey: 'layout.nav_routing', fallback: 'Routing', icon: Route },
];

/**
 * Pro tools listed under Tools, after the plugin's own.
 *
 * @since 1.0.0
 * @type {Array<{ to: string, labelKey: string, fallback: string, icon: import('react').ComponentType }>}
 */
export const PRO_TOOLS_NAV_ITEMS = [
    { to: '/tools/domain', labelKey: 'layout.nav_domain_check', fallback: 'Domain Check', icon: Globe },
    { to: '/tools/import-export', labelKey: 'layout.nav_import_export', fallback: 'Import & Export', icon: ArrowDownUp },
];

/**
 * The Pro page a path belongs to, or null.
 *
 * @since 1.0.0
 *
 * @param {string} pathname Router path, such as `/tools/import-export`.
 * @returns {{ to: string, labelKey: string, fallback: string }|null}
 */
export function proPageFor(pathname) {
    return [...PRO_NAV_ITEMS, ...PRO_TOOLS_NAV_ITEMS].find(item => pathname === item.to || pathname.startsWith(`${item.to}/`)) || null;
}
