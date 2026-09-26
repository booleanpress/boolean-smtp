import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';

/**
 * Where a mode sorts: the plugin's own API and SMTP modes first, then any mode another plugin
 * registers, in the order the transport lists them.
 *
 * @param {string} key Delivery mode key.
 * @returns {number}
 */
function rank(key) {
    return key === 'api' ? 0 : key === 'smtp' ? 1 : 2;
}

/**
 * Full-width delivery mode tabs for a mailer with more than one mode, drawn at the same height and
 * inset as the form's segmented controls. Lists every mode the transport offers.
 *
 * @since 1.0.0
 *
 * @param {{ modes?: Record<string, string>, value?: string, onChange: (mode: string) => void }} props
 */
export default function MailerModeTabs({ modes = {}, value, onChange }) {
    const active = value || 'api';
    const entries = Object.entries(modes).sort((a, b) => rank(a[0]) - rank(b[0]));
    if (entries.length < 2) {
        return null;
    }

    return (
        <Tabs value={active} onValueChange={onChange} className="w-full">
            <TabsList className="w-full">
                {entries.map(([key, label]) => (
                    <TabsTrigger key={key} value={key} className="flex-1">
                        {label}
                    </TabsTrigger>
                ))}
            </TabsList>
        </Tabs>
    );
}
