import JsonVariantSetupGuide from './JsonVariantSetupGuide';
import guide from './generic-setup-guide.json';

export default function BrevoSetupGuide(props) {
    return (
        <JsonVariantSetupGuide
            guideData={guide}
            deliveryMode={props.deliveryMode ?? 'api'}
            keyStore={props.keyStore ?? 'db'}
            docsUrl={props.docsUrl}
            providerName={props.providerName}
        />
    );
}
