/**
 * External dependencies
 */
import { decodeEntities } from '@wordpress/html-entities';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
/**
 * Internal dependencies
 */
import Content, { PayPalButton } from './Content';
import Edit from './Edit';
import { getPayPalPPCPSettings } from '../Utils';

import { registerPlugin } from '@wordpress/plugins';
import { useSelect } from '@wordpress/data';
import { cartStore } from '@woocommerce/block-data';
import { __ } from '@wordpress/i18n';
import { RawHTML } from '@wordpress/element';
import { ExperimentalOrderMeta as SubPlanOrderMeta } from '@woocommerce/blocks-checkout';

const SubscriptionPlan = () => {
    const items = useSelect((select) => select(cartStore).getCartData().items, []);
    const subscriptions = (items || []).filter((item) => item.extensions?.wcpprog?.subscription_plan_html);
    if (getPayPalPPCPSettings('checkoutType', 'capture') !== 'subscription' || !subscriptions.length) {
        return null;
    }

    return (
        <SubPlanOrderMeta>
            <div className="wc-block-components-totals-wrapper wcpprog-subscription-plan" style={{padding: '16px'}}>
                <div style={{
                    fontWeight: 400,
                    marginBottom: '8px'
                }}>{__('Subscription Plan', 'woocommerce-paypal-pro-payment-gateway')}</div>
                {subscriptions.map((item) => (
                    <RawHTML key={item.key}>{item.extensions.wcpprog.subscription_plan_html}</RawHTML>
                ))}
            </div>
        </SubPlanOrderMeta>
    );
};

registerPlugin('wcpprog-subscription-plan', {
    render: SubscriptionPlan,
    scope: 'woocommerce-checkout',
});

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
    placeOrderButton: PayPalButton,
    supports: {
        features: getPayPalPPCPSettings('supports', []),
    },
})
