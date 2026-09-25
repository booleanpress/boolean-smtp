import JsonVariantSetupGuide from './JsonVariantSetupGuide';
import guide from './zoho-setup-guide.json';

export default function ZohoSetupGuide(props) {
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
