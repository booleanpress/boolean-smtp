import SesSetupGuide from './SesSetupGuide';
import SmtpSetupGuide from './SmtpSetupGuide';
import GmailSetupGuide from './GmailSetupGuide';
import OutlookSetupGuide from './OutlookSetupGuide';
import PhpSetupGuide from './PhpSetupGuide';
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
        case 'php':
            return PhpSetupGuide;
        default:
            return DefaultSetupGuide;
    }
}
