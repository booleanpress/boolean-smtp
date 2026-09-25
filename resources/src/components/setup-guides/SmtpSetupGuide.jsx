import JsonVariantSetupGuide from './JsonVariantSetupGuide';
import guide from './smtp-setup-guide.json';

export default function SmtpSetupGuide(props) {
    return (
        <JsonVariantSetupGuide
            guideData={guide}
            deliveryMode="smtp"
            keyStore={props.keyStore ?? 'db'}
            docsUrl={props.docsUrl}
            providerName={props.providerName || 'Custom SMTP'}
        />
    );
}
