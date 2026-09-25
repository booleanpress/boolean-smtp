/**
 * Navigation entries and overview cards other plugins add through the
 * `boolean_smtp_admin_menu_items` and `boolean_smtp_dashboard_cards` filters.
 *
 * Reads `window.BooleanSmtpAdmin.extensions` (set by AppServiceProvider bootstrap props).
 * Every entry is a plain link: `{ slug|id, label|title, url, description? }`; the PHP side
 * drops anything else before it reaches the page.
 *
 * @since 1.0.0
 */
const EMPTY = Object.freeze([]);

function entries(list, idKey, labelKey) {
    if (!Array.isArray(list)) return EMPTY;
    return list.filter(entry => entry && typeof entry[idKey] === 'string' && typeof entry[labelKey] === 'string' && typeof entry.url === 'string');
}

export function useAdminExtensions() {
    const extensions = window.BooleanSmtpAdmin?.extensions || {};
    return {
        menuItems: entries(extensions.menuItems, 'slug', 'label'),
        dashboardCards: entries(extensions.dashboardCards, 'id', 'title'),
    };
}
