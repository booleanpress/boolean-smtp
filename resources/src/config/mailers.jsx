import { Cloud, Cpu, Mail } from 'lucide-react';

import { mailerDocsUrl } from './docs';
import sesLogo from '../assets/mailers/provider-aws-ses.svg';
import googleLogo from '../assets/mailers/provider-gmail-google-workspace.svg';
import outlookLogo from '../assets/mailers/provider-microsoft.svg';
import smtpLogo from '../assets/mailers/provider-smtp.svg';
import smtpCompactLogo from '../assets/mailers/provider-smtp-mark.svg';
import phpLogo from '../assets/mailers/provider-php.svg';

function providerText(driver, key, fallback) {
    if (typeof window === 'undefined') {
        return fallback;
    }

    return window.BooleanSmtpAdmin?.i18n?.providers?.[driver]?.[key] || fallback;
}

const BASE_MAIL_PROVIDERS = [
    {
        driver: 'smtp',
        name: 'Custom SMTP',
        description: 'Connect any custom SMTP server.',
        logo: smtpLogo,
        compactLogo: smtpCompactLogo,
        icon: <Mail className="size-8 text-muted-foreground" />,
        color: '#64748b'
    },
    {
        driver: 'ses',
        name: 'Amazon SES',
        description: 'Perfect for high-volume bulk email campaigns requiring high deliverability and low cost.',
        logo: sesLogo,
        recommended: true,
        icon: <Cloud className="w-8 h-8 text-[#FF9900]" />,
        color: '#FF9900'
    },
    {
        driver: 'google',
        name: 'Google Workspace',
        description: 'Ideal for professional business emails with easy setup and moderate daily volume limits.',
        logo: googleLogo,
        recommended: true,
        icon: <Mail className="w-8 h-8 text-[#4285F4]" />,
        color: '#4285F4'
    },
    {
        driver: 'outlook',
        name: 'Microsoft Outlook',
        description: 'Deep integration with Office 365 services.',
        logo: outlookLogo,
        icon: <Cloud className="w-8 h-8 text-[#00A4EF]" />,
        color: '#00A4EF'
    },
    {
        driver: 'php',
        name: 'PHP Mail',
        description: 'Default server mail. Not recommended.',
        logo: phpLogo,
        icon: <Cpu className="size-8 text-muted-foreground" />,
        color: '#94a3b8'
    }
];

export const MAIL_PROVIDERS = BASE_MAIL_PROVIDERS.map((provider) => ({
    ...provider,
    name: providerText(provider.driver, 'name', provider.name),
    description: providerText(provider.driver, 'description', provider.description),
    docsUrl: provider.docsUrl || mailerDocsUrl(provider.driver),
}));


