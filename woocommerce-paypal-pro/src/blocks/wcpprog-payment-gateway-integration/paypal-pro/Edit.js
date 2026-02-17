/**
 * External dependencies
 */
import { decodeEntities } from '@wordpress/html-entities';
import { getPayPalProSettings } from '../Utils';

export default () => {
    return decodeEntities(getPayPalProSettings('description', __('Credit / Debit card accept form will render in the front-end.', 'woocommerce-paypal-pro-payment-gateway')));
}
