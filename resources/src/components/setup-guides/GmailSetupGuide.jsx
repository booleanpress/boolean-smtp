import JsonVariantSetupGuide from './JsonVariantSetupGuide';
import guide from './google-setup-guide.json';

export default function GmailSetupGuide(props) {
    return (
        <JsonVariantSetupGuide
            guideData={guide}
            deliveryMode={props.deliveryMode ?? 'smtp'}
            keyStore={props.keyStore ?? 'db'}
            docsUrl={props.docsUrl}
            providerName={props.providerName}
        />
    );
}
