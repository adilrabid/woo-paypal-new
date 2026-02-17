/**
 * External dependencies
 */
import { decodeEntities } from '@wordpress/html-entities';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
/**
 * Internal dependencies
 */
import Content from './Content';
import Edit from './Edit';
import { getPayPalPPCPSettings } from '../Utils';

const labelText = decodeEntities(getPayPalPPCPSettings('title'));

const Label = (props) => {
    const { PaymentMethodLabel, PaymentMethodIcons } = props.components
    const cardIcons = getPayPalPPCPSettings('ppcpIcons').map((icon) => {
        return {
            id: icon.id,
            alt: icon.alt,
            src: icon.src
        }
    });

    return (
        <div style={{ width: '100%', display: "flex", justifyContent: 'space-between' }}>
            <PaymentMethodLabel text={labelText} />
            <PaymentMethodIcons icons={cardIcons} align="right" />
        </div>
    )
}

registerPaymentMethod({
    name: "paypal_checkout",
    label: <Label />,
    content: <Content />,
    edit: <Edit />,
    canMakePayment: () => true,
    ariaLabel: labelText,
    supports: {
        features: getPayPalPPCPSettings('supports', []),
    },
})