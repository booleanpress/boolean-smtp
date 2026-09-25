import { 
    Mail, 
    Send, 
    Globe, 
    Cloud, 
    Zap, 
    Rocket, 
    PenTool,
    Shield,
    HardDrive,
    Terminal,
    Cpu
} from 'lucide-react';

import { mailerDocsUrl } from './docs';
import sesLogo from '../assets/mailers/provider-aws-ses.svg';
import googleLogo from '../assets/mailers/provider-gmail-google-workspace.svg';
import outlookLogo from '../assets/mailers/provider-microsoft.svg';
import sendgridLogo from '../assets/mailers/provider-sendgrid.svg';
import brevoLogo from '../assets/mailers/provider-sendinblue.svg';
import mailgunLogo from '../assets/mailers/provider-mailgun.svg';
import postmarkLogo from '../assets/mailers/provider-postmark.svg';
import smtpLogo from '../assets/mailers/provider-smtp.svg';
import smtpCompactLogo from '../assets/mailers/provider-smtp-mark.svg';
import sparkpostLogo from '../assets/mailers/provider-sparkpost.svg';
import netcoreLogo from '../assets/mailers/provider-netcore.svg';
import smtp2goLogo from '../assets/mailers/provider-smtp2go.svg';
import phpLogo from '../assets/mailers/provider-php.svg';
import elasticEmailLogo from '../assets/mailers/provider-elastic-email.svg';
import zohoLogo from '../assets/mailers/provider-zoho.svg';
import mailersendLogo from '../assets/mailers/provider-mailersend.svg';
import mandrillLogo from '../assets/mailers/provider-mandrill.svg';
import sendlayerLogo from '../assets/mailers/provider-sendlayer.svg';
import smtpcomLogo from '../assets/mailers/provider-smtpcom.svg';

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
        launched: true,
        icon: <Mail className="size-8 text-muted-foreground" />,
        color: '#64748b'
    },
    {
        driver: 'ses',
        name: 'Amazon SES',
        description: 'Perfect for high-volume bulk email campaigns requiring high deliverability and low cost.',
        logo: sesLogo,
        launched: true,
        recommended: true,
        icon: <Cloud className="w-8 h-8 text-[#FF9900]" />,
        color: '#FF9900'
    },
    {
        driver: 'google',
        name: 'Google Workspace',
        description: 'Ideal for professional business emails with easy setup and moderate daily volume limits.',
        logo: googleLogo,
        launched: true,
        recommended: true,
        icon: <Mail className="w-8 h-8 text-[#4285F4]" />,
        color: '#4285F4'
    },
    {
        driver: 'outlook',
        name: 'Microsoft Outlook',
        description: 'Deep integration with Office 365 services.',
        logo: outlookLogo,
        launched: true,
        icon: <Cloud className="w-8 h-8 text-[#00A4EF]" />,
        color: '#00A4EF'
    },
    {
        driver: 'zoho',
        name: 'Zoho Mail',
        description: 'Secure email with SMTP and OAuth integration for business.',
        logo: zohoLogo,
        icon: <Mail className="w-8 h-8 text-[#C8202C]" />,
        color: '#C8202C'
    },
    {
        driver: 'mailgun',
        name: 'Mailgun',
        description: 'Powerful APIs for developers.',
        logo: mailgunLogo,
        icon: <Rocket className="w-8 h-8 text-[#C82333]" />,
        color: '#C82333'
    },
    {
        driver: 'postmark',
        name: 'Postmark',
        description: 'Lightning fast delivery for apps.',
        logo: postmarkLogo,
        icon: <Send className="w-8 h-8 text-[#FFDE59]" />,
        color: '#FFDE59'
    },
    {
        driver: 'sendgrid',
        name: 'SendGrid',
        description: 'Leader in marketing & transactional email.',
        logo: sendgridLogo,
        icon: <Zap className="w-8 h-8 text-[#1A82E2]" />,
        color: '#1A82E2'
    },
    {
        driver: 'brevo',
        name: 'Brevo',
        description: 'Formerly Sendinblue. Great all-in-one suite.',
        logo: brevoLogo,
        icon: <Globe className="w-8 h-8 text-[#0092FF]" />,
        color: '#0092FF'
    },
    {
        driver: 'sparkpost',
        name: 'SparkPost',
        description: 'High-performance email delivery for large volumes.',
        logo: sparkpostLogo,
        icon: <Zap className="w-8 h-8 text-[#FA6400]" />,
        color: '#FA6400'
    },
    {
        driver: 'netcore',
        name: 'Netcore',
        description: 'Global cloud email service with robust delivery.',
        logo: netcoreLogo,
        icon: <Cloud className="w-8 h-8 text-[#0066FF]" />,
        color: '#0066FF'
    },
    {
        driver: 'smtp2go',
        name: 'SMTP2GO',
        description: 'Reliable SMTP and API service with detailed reporting.',
        logo: smtp2goLogo,
        icon: <Globe className="w-8 h-8 text-[#2563EB]" />,
        color: '#2563EB'
    },
    {
        driver: 'mailersend',
        name: 'MailerSend',
        description: 'Transactional email service with intuitive API and analytics.',
        logo: mailersendLogo,
        icon: <Send className="w-8 h-8 text-[#24C1AC]" />,
        color: '#24C1AC'
    },
    {
        driver: 'mandrill',
        name: 'Mandrill',
        description: 'Mailchimp Transactional Email with powerful deliverability.',
        logo: mandrillLogo,
        icon: <PenTool className="w-8 h-8 text-[#FFE01B]" />,
        color: '#FFE01B'
    },
    {
        driver: 'sendlayer',
        name: 'SendLayer',
        description: 'Simple and reliable transactional email delivery.',
        logo: sendlayerLogo,
        icon: <Send className="w-8 h-8 text-[#5850EC]" />,
        color: '#5850EC'
    },
    {
        driver: 'smtpcom',
        name: 'SMTP.com',
        description: 'Enterprise-grade SMTP relay with channel-based sending.',
        logo: smtpcomLogo,
        icon: <Globe className="w-8 h-8 text-[#45B7D1]" />,
        color: '#45B7D1'
    },
    {
        driver: 'elasticemail',
        name: 'Elastic Email',
        description: 'Cost-effective email delivery for transactional and marketing.',
        logo: elasticEmailLogo,
        icon: <Zap className="w-8 h-8 text-[#4B53BC]" />,
        color: '#4B53BC'
    },
    {
        driver: 'php',
        name: 'PHP Mail',
        description: 'Default server mail. Not recommended.',
        logo: phpLogo,
        launched: true,
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


