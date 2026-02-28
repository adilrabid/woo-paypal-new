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
            createOrder: async (data, actions) => {

                const formData = new FormData();
                formData.append('action', wc_paypal_checkout_params.create_order_ajax_action);
                formData.append('nonce', wc_paypal_checkout_params.nonce);

                try {
                    const response = await fetch( wc_paypal_checkout_params.ajax_url, {
                        method: "post",
                        body: formData
                    });
    
                    const response_data = await response.json();
                    
                    if (response_data.success) {
                        return response_data.data.order_id;
                    } else {
                        throw new Error(response_data.data.message || 'Order creation failed');
                    }
                } catch (error) {
                    console.error(error);
                    alert(error.message);
                }
            },
            onApprove: async (data, actions) => {
                const formData = new FormData();
                formData.append('action', wc_paypal_checkout_params.capture_order_ajax_action);
                formData.append('paypal_order_id', data.orderID);
                formData.append('wc_order_id', data.orderID);
                formData.append('nonce', wc_paypal_checkout_params.nonce);

                try {
                    const response = await fetch(wc_paypal_checkout_params.ajax_url, {
                        method: 'post',
                        body: formData,
                    });
                    const response_data = await response.json();
                    
                    if (response_data.success) {
                        window.location.href = response_data.data.redirect;
                    } else {
                       alert("Payment failed: " + (response_data.data.message || 'Unknown error'));
                    }
                } catch (error) {
                    console.error(error);
                    alert(error.message);
                }
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