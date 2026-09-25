import JsonVariantSetupGuide from './JsonVariantSetupGuide';
import guide from './outlook-setup-guide.json';

export default function OutlookSetupGuide(props) {
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
