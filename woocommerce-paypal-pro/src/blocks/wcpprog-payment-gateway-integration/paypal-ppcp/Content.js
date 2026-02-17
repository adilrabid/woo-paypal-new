import { decodeEntities } from '@wordpress/html-entities';
import { useEffect } from 'react';
import { getPayPalPPCPSettings } from '../Utils';

/**
 * Render PayPal PPCP checkout buttons.
 * 
 * @param {string} render_to The selector of container element to render the paypal button.
 */
function render_ppcp_btn(render_to) {
    const btn_container = document.querySelector(render_to);

    if (btn_container && typeof paypal !== 'undefined') {
        paypal.Buttons({
            createOrder: function (data, actions) {
                return jQuery.post(wc_paypal_checkout_params.ajax_url, {
                    action: "paypal_checkout_create_order",
                    nonce: wc_paypal_checkout_params.nonce
                }).then(function (response) {
                    if (response.success) {
                        return response.data.order_id;
                    } else {
                        throw new Error(response.data.message || 'Order creation failed');
                    }
                });
            },
            onApprove: function (data, actions) {
                return jQuery.post(wc_paypal_checkout_params.ajax_url, {
                    action: "paypal_checkout_capture_order",
                    paypal_order_id: data.orderID,
                    wc_order_id: data.orderID,
                    nonce: wc_paypal_checkout_params.nonce
                }).then(function (response) {
                    if (response.success) {
                        window.location.href = response.data.redirect;
                    } else {
                        alert("Payment failed: " + (response.data.message || 'Unknown error'));
                    }
                });
            },
            onError: function (err) {
                console.error('PayPal Error:', err);
                alert('An error occurred during payment. Please try again.');
            }
        }).render(btn_container);
    } else {
        console.log('PayPal: SDK not loaded or container not found');
    }
}

export default ({ eventRegistration, activePaymentMethod }) => {
    const description = decodeEntities(getPayPalPPCPSettings('description', ''));

    useEffect(() => {
        render_ppcp_btn('#paypal-checkout-button-container');
    }, [])

    useEffect(() => {
        const placeOrderBtn = document.querySelector(
            '.wc-block-components-checkout-place-order-button'
        );

        if (!placeOrderBtn) return;

        placeOrderBtn.style.display = 'none';

        return () => {
            placeOrderBtn.style.display = '';
        };

    }, []);

    return (
        <>
            <p>{description}</p>
            <div id="paypal-checkout-button-container">
                {/* PayPal Button Renders Here */}
            </div>
        </>
    );
}