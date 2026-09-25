import JsonVariantSetupGuide from './JsonVariantSetupGuide';
import guide from './ses-setup-guide.json';

export default function SesSetupGuide(props) {
    return (
        <JsonVariantSetupGuide
            guideData={guide}
            deliveryMode={props.sesDeliveryMode ?? props.deliveryMode ?? 'api'}
            keyStore={props.sesKeyStore ?? props.keyStore ?? 'db'}
            docsUrl={props.docsUrl}
            providerName={props.providerName}
        />
    );
}
