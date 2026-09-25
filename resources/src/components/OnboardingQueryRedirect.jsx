import { useEffect } from 'react';
import { useNavigate } from 'react-router';
import { OAUTH_RETURN_MARKER } from '@/components/onboarding/providerCatalog';

/**
 * Two moments the admin URL (outside the hash) sends the user into the wizard:
 *
 * - After plugin activation, PHP redirects with `?booleansmtp_onboard=1`; the flag is removed and
 *   the wizard opens.
 * - After an OAuth consent started from the wizard, the hosted relay sends the browser back to
 *   `admin.php?…&oauth_connection_id=<id>&oauth_code=…#/connections/<id>`. When that id is the
 *   draft the wizard left for consent (a per-tab marker set just before leaving), the wizard's
 *   Connect step takes over — with the relay's query left in place for it to exchange the code.
 *
 * @since 1.0.0
 */
export default function OnboardingQueryRedirect() {
    const navigate = useNavigate();

    useEffect(() => {
        const params = new URLSearchParams(window.location.search);

        if (params.get('booleansmtp_onboard') === '1') {
            params.delete('booleansmtp_onboard');
            const qs = params.toString();
            const newUrl = window.location.pathname + (qs ? `?${qs}` : '') + (window.location.hash || '');
            window.history.replaceState({}, '', newUrl);
            navigate('/onboard', { replace: true });
            return;
        }

        const returnedId = params.get('oauth_connection_id');
        if (!returnedId || (!params.get('oauth_code') && !params.get('oauth_status'))) {
            return;
        }
        let marker;
        try {
            marker = window.sessionStorage.getItem(OAUTH_RETURN_MARKER);
        } catch {
            marker = null;
        }
        if (marker !== returnedId) {
            return;
        }
        try {
            window.sessionStorage.removeItem(OAUTH_RETURN_MARKER);
        } catch {
            // The marker is a convenience; nothing depends on removing it.
        }
        navigate(`/onboard?step=connect&connection=${encodeURIComponent(returnedId)}`, { replace: true });
    }, [navigate]);

    return null;
}
