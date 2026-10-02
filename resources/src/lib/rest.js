/**
 * Join an API endpoint, with or without its own query string, to the REST base WordPress printed.
 *
 * With plain permalinks the base is `…/index.php?rest_route=/booleansmtp/v1`, so the endpoint's query
 * joins with `&`: a second `?` makes WordPress look for a route that does not exist ("No route was
 * found"). With pretty permalinks the base has no query and the endpoint's query starts with `?`.
 *
 * @since 1.0.0
 *
 * @param {string} base     REST base, e.g. `https://example.com/wp-json/booleansmtp/v1`.
 * @param {string} endpoint Path below the base, optionally followed by `?query`.
 * @returns {string} The endpoint's full address.
 */
export function endpointUrl(base, endpoint) {
    const text = String(endpoint).replace(/^\/+/, '');
    const at = text.indexOf('?');
    const path = (at === -1 ? text : text.slice(0, at)).replace(/\/{2,}/g, '/');
    const query = at === -1 ? '' : text.slice(at + 1);
    const url = `${String(base).replace(/\/+$/, '')}/${path}`;

    if (!query) return url;

    return `${url}${url.includes('?') ? '&' : '?'}${query}`;
}
