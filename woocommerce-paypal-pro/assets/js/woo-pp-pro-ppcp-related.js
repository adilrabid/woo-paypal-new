/**
 * Render PayPal PPCP checkout buttons.
 * 
 * NOTE: This functions is only for the checkout shortcode, not for checkout block.
 * 
 * @param {string} render_to The selector of container element to render the paypal button.
 */
function woo_pp_pro_render_ppcp_btn(render_to) {
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

var woo_pp_pro_render_ppcp_retry_count = 0;

function woo_pp_pro_render_ppcp_btn_with_retry(btn_container_id = 'paypal-checkout-button-container', retry_count = 3, retry_interval = 1000){
    if ((woo_pp_pro_render_ppcp_retry_count + 1) >= retry_count) {
        woo_pp_pro_render_ppcp_retry_count = 0; // Clear retry count.
        return;
    }
    
    const btn_container = document.getElementById(btn_container_id);

    if (! btn_container ) {
        woo_pp_pro_render_ppcp_retry_count++;
        setTimeout(function(){
            woo_pp_pro_render_ppcp_btn_with_retry();
        }, retry_interval);
        return;
    } 
    
    if(!btn_container.children.length){   
        woo_pp_pro_render_ppcp_btn('#' + btn_container_id);
    }
}

function woo_pp_pro_toggle_place_order_btn(target_methods) {
    if (!Array.isArray(target_methods)) {
        console.log('PayPal: target_methods is not an array.');
        return;
    }

    const selected_input = document.querySelector('input[name="payment_method"]:checked');
    const selected_method = selected_input ? selected_input.value : null;

    const place_order_btn = document.getElementById('place_order');

    if (!place_order_btn) {
        return;
    }

    if (target_methods.includes(selected_method)) {
        place_order_btn.style.display = 'none';
    } else {
        place_order_btn.style.display = '';
    }
}

/**
 * Toggle 'Place Order' button when specific payment methods get selected.
 */
jQuery(function ($) {
    if (!$('form.checkout').length) {
        return;
    }

    // Payment method ids, for which the 'Place Order' button should be hidden.
    const target_methods = ['paypal_checkout']

    $(document.body).on('change', 'input[name="payment_method"]', () => {
        woo_pp_pro_toggle_place_order_btn(target_methods);
    }).on('updated_checkout', () => {// When Woo updates checkout
        woo_pp_pro_toggle_place_order_btn(target_methods);
    });
});

function woo_pp_pro_inject_btn_for_cart_block() {
    // Check if we're on cart page
    if (document.querySelector('.wc-block-cart')) {
        woo_pp_pro_inject_cart_page_btn();
    }
}

function woo_pp_pro_inject_cart_page_btn() {
    // Don't inject if already exists
    const btn_container_id = 'paypal-checkout-button-container';
    if (document.getElementById(btn_container_id) ) {
        console.log('PayPal: Already exists'); 
        return;
    }

    // Try multiple selectors for cart totals area
    var selectors = [
        '.wc-block-cart__totals-wrapper',
        '.cart-collaterals',
        '.cart_totals',
        '.wc-block-cart-totals',
        '.woocommerce-cart-form + .cart-collaterals',
        '.wp-block-woocommerce-cart-totals-block'
    ];

    var targetElement = null;
    for (var i = 0; i < selectors.length; i++) {
        targetElement = document.querySelector(selectors[i]);
        if (targetElement) {
            console.log('PayPal: Found cart target with selector: ' + selectors[i]);
            break;
        }
    }

    if (targetElement) {
        var buttonContainer = document.createElement('div');
        buttonContainer.id = btn_container_id;
        buttonContainer.style.cssText = 'border: 1px solid #ddd; padding: 15px; margin: 15px 0; border-radius: 5px; background: #f9f9f9;';
        buttonContainer.innerHTML = '<h3>Or pay with PayPal</h3><div id="paypal-checkout-button-container" style="margin: 20px 0;"></div>';

        targetElement.appendChild(buttonContainer);
        woo_pp_pro_render_ppcp_btn('#' + btn_container_id);
    } else {
        console.log('PayPal: Could not find cart target element');
    }
}

function woo_pp_pro_re_inject_btn_on_cart_update(){
    woo_pp_pro_render_ppcp_btn_with_retry();
}

jQuery( document.body ).on( 'updated_cart_totals updated_wc_div', woo_pp_pro_re_inject_btn_on_cart_update);