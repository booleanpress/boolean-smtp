import JsonVariantSetupGuide from './JsonVariantSetupGuide';
import guide from './generic-setup-guide.json';

export default function DefaultSetupGuide(props) {
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
