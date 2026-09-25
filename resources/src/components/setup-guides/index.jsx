import SesSetupGuide from './SesSetupGuide';
import SmtpSetupGuide from './SmtpSetupGuide';
import GmailSetupGuide from './GmailSetupGuide';
import OutlookSetupGuide from './OutlookSetupGuide';
import ZohoSetupGuide from './ZohoSetupGuide';
import PhpSetupGuide from './PhpSetupGuide';
import SendgridSetupGuide from './SendgridSetupGuide';
import BrevoSetupGuide from './BrevoSetupGuide';
import DefaultSetupGuide from './DefaultSetupGuide';

export function getSetupGuideComponent(driver) {
    switch (driver) {
        case 'ses':
            return SesSetupGuide;
        case 'smtp':
            return SmtpSetupGuide;
        case 'gmail':
        case 'google':
            return GmailSetupGuide;
        case 'outlook':
            return OutlookSetupGuide;
        case 'zoho':
            return ZohoSetupGuide;
        case 'sendgrid':
            return SendgridSetupGuide;
        case 'brevo':
        case 'sendinblue':
            return BrevoSetupGuide;
        case 'php':
            return PhpSetupGuide;
        default:
            return DefaultSetupGuide;
    }
}
