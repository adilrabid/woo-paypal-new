/**
 * External dependencies
 */
import { decodeEntities } from '@wordpress/html-entities';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
/**
 * Internal dependencies
 */
import Content from './Content.js';
import Edit from './Edit.js';
import { getPayPalProSettings } from '../Utils.js';
import { PAYMENT_METHOD_NAME } from '../Constants.js';

// console.log("WooCommerce PayPal Pro gateway bBlock script loaded!");

const labelText = decodeEntities(getPayPalProSettings('title'));
const Label = (props) => {
    const { PaymentMethodLabel, PaymentMethodIcons } = props.components
    const cardIcons = getPayPalProSettings('cardIcons').map((icon) => {
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
    name: PAYMENT_METHOD_NAME,
    label: <Label />,
    content: <Content />,
    edit: <Edit />,
    canMakePayment: () => true,
    ariaLabel: labelText,
    supports: {
        features: getPayPalProSettings('supports', []),
    }
    // placeOrderButtonLabel: __('Pay With PayPal Pro', 'woocommerce-paypal-pro-payment-gateway'),
});

