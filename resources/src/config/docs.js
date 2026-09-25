/**
 * The public documentation site every "documentation" link in the admin points at.
 *
 * @since 1.0.0
 */
export const DOCS_URL = 'https://docs.booleansmtp.com';

/**
 * Mail drivers with their own page under `/mailers/` on the documentation site. Add a driver here when
 * its page is published; until then its links go to the mailers overview.
 *
 * @since 1.0.0
 */
const MAILERS_WITH_DOCS = ['google', 'outlook', 'php', 'ses', 'smtp'];

/**
 * Alert channels with their own page under `/alerts/` on the documentation site.
 *
 * @since 1.0.0
 */
const ALERT_CHANNELS_WITH_DOCS = ['slack', 'discord', 'telegram'];

/**
 * The setup page for one mail driver: `/mailers/<driver>/`, or the mailers overview (`/mailers/`) for a
 * driver without its own page. The trailing slash is the page's exact address on the site, so the link
 * never redirects.
 *
 * @since 1.0.0
 *
 * @param {string} driver Driver slug from the transport registry (`ses`, `google`, `sendgrid`, …).
 * @returns {string}
 */
export function mailerDocsUrl(driver) {
    return MAILERS_WITH_DOCS.includes(driver) ? `${DOCS_URL}/mailers/${driver}/` : `${DOCS_URL}/mailers/`;
}

/**
 * The setup page for one alert channel: `/alerts/<channel>/`, or the alerts overview (`/alerts/`) for a
 * channel without its own page.
 *
 * @since 1.0.0
 *
 * @param {string} type Channel type (`slack`, `discord`, `telegram`).
 * @returns {string}
 */
export function alertDocsUrl(type) {
    return ALERT_CHANNELS_WITH_DOCS.includes(type) ? `${DOCS_URL}/alerts/${type}/` : `${DOCS_URL}/alerts/`;
}
