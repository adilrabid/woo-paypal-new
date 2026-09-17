/* global wc_paypal_checkout_params, wc_order_attribution */

/**
 * Render PayPal PPCP checkout buttons.
 * 
 * NOTE: This functions is only for the checkout shortcode, not for checkout block.
 * 
 * @param {string} render_to The selector of container element to render the paypal button.
 */



/**
 * Base wrapper around paypal.Buttons — handles container binding and render.
 * Subclasses supply createOrder/createSubscription + onApprove via btnConfig.
 */
class Woo_PP_Pro_PPCP_Btn {
    btnContainer = null;
    btnConfig = {};

    constructor() {
        this.btnConfig = {
            onInit: this.onInit,
            onClick: this.onClick,
            onError: this.onError,
        };
    }

    onInit = () => {};

    onClick = (data, actions) => {
        document.getElementById('wcpprog-paypal-error')?.remove();
        const form = document.querySelector('form.checkout');
        if (!form) {
            return actions.resolve();
        }
        if (form.classList.contains('processing')) {
            return actions.reject();
        }

        // Classic WooCommerce binds its field validator to this jQuery event.
        // Include Select2's hidden select, but skip inactive address sections.
        const fields = Array.from(form.querySelectorAll('.form-row input, .form-row select, .form-row textarea'))
            .filter((field) => !field.disabled && field.type !== 'hidden' && field.closest('.form-row').getClientRects().length);
        fields.forEach((field) => jQuery(field).trigger('validate'));
        const invalid = fields.find((field) => field.closest('.form-row').classList.contains('woocommerce-invalid'));
        if (invalid) {
            invalid.focus();
            invalid.closest('.form-row').scrollIntoView({block: 'center', behavior: 'smooth'});
            return actions.reject();
        }

        if (!form.reportValidity()) {
            return actions.reject();
        }
        return actions.resolve();
    };

    onError = (err) => {
        console.error('PayPal Error:', err);
        this.showError(err);
    };

    showError = (error) => {
        let notice = document.getElementById('wcpprog-paypal-error');
        if (!notice) {
            notice = document.createElement('ul');
            notice.id = 'wcpprog-paypal-error';
            notice.className = 'woocommerce-error';
            notice.setAttribute('role', 'alert');
            notice.tabIndex = -1;
            const target = document.querySelector('form.checkout, form#order_review, .woocommerce-notices-wrapper') || this.btnContainer;
            if (!target) {
                return;
            }
            target.prepend(notice);
        }
        const message = document.createElement('li');
        // Server messages are text, never trusted HTML.
        message.textContent = error?.message || 'An error occurred during payment. Please try again.';
        notice.replaceChildren(message);
        notice.focus();
        notice.scrollIntoView({block: 'center', behavior: 'smooth'});
    };

    /**
     * Read billing/shipping customer data off the on-page checkout form.
     */
    readCheckoutCustomer = () => {
        const checkoutForm = document.querySelector('form.checkout');
        if (!checkoutForm) {
            return null;
        }

        const fields = new FormData(checkoutForm);
        const shipsToDifferentAddress = fields.has('ship_to_different_address');
        const shippingPrefix = shipsToDifferentAddress ? 'shipping_' : 'billing_';

        const billing = {};
        const shipping = {};

        const CUSTOMER_FIELD_KEYS = [
            'first_name', 'last_name', 'company', 'address_1', 'address_2',
            'city', 'state', 'postcode', 'country', 'email', 'phone',
        ];

        for (const key of CUSTOMER_FIELD_KEYS) {
            billing[key] = fields.get(`billing_${key}`) || '';
            shipping[key] = fields.get(`${shippingPrefix}${key}`) || '';
        }

        const shippingFullName = [
            fields.get(`${shippingPrefix}first_name`),
            fields.get(`${shippingPrefix}last_name`),
        ].filter(Boolean).join(' ').trim();

        return { billing, shipping, shippingFullName };
    }

    getBtnConfig = () => this.btnConfig;

    button = () => paypal.Buttons(this.getBtnConfig());

    /**
     * Shared AJAX helper — posts FormData to admin-ajax and returns the
     * parsed `data` payload on success, or throws with the server's
     * message on failure. Available to this class and its subclasses.
     *
     * @param {string} action  wc_paypal_checkout_params ajax action name
     * @param {Record<string, string>} [fields] extra fields to append
     * @returns {Promise<any>}
     */
    ppcpAjax = async (action, fields = {}) => {
        const formData = new FormData();
        formData.append('action', action);
        formData.append('nonce', wc_paypal_checkout_params.nonce);

        const wcOrderAttributions = this.getAttributionData();
        formData.append('attributions', JSON.stringify(wcOrderAttributions));

        for (const [key, value] of Object.entries(fields)) {
            formData.append(key, value);
        }

        const response = await fetch(wc_paypal_checkout_params.ajax_url, {
            method: 'POST',
            body: formData,
        });

        if (!response.ok) {
            throw new Error(`Request failed (HTTP ${response.status})`);
        }

        const result = await response.json();

        if (!result.success) {
            throw new Error(result.data?.message || 'Request failed');
        }

        return result.data;
    };

    /**
     * @param {string|HTMLElement} render_to
     * @returns {this}
     */
    container = (render_to) => {
        this.btnContainer = typeof render_to === 'string'
            ? document.querySelector(render_to)
            : render_to;

        return this;
    };

    render = () => {
        if (!this.btnContainer) {
            console.error('Invalid container! Failed to render PayPal buttons!');
            return;
        }

        this.button().render(this.btnContainer);
    };

    getAttributionData = () => {
        return typeof wc_order_attribution !== 'undefined' ? wc_order_attribution.getAttributionData() : {};
    }
}

/** One-off "buy now" purchase button. */
class Woo_PP_Pro_PPCP_Buy_Now_Btn extends Woo_PP_Pro_PPCP_Btn {
    type = 'buy_now';

    constructor() {
        super();
        this.btnConfig.createOrder = this.createOrder;
        this.btnConfig.onApprove = this.onApprove;
    }

    createOrder = async () => {
        try {
            const data = await this.ppcpAjax(wc_paypal_checkout_params.create_order_ajax_action);
            return data.order_id;
        } catch (error) {
            console.error(error);
            this.showError(error);
            throw error;
        }
    };

    onApprove = async (data) => {
        try {
            const result = await this.ppcpAjax(wc_paypal_checkout_params.capture_order_ajax_action, {
                paypal_order_id: data.orderID,
                wc_order_id: data.orderID,
            });
            window.location.href = result.redirect;
        } catch (error) {
            console.error(error);
            this.showError(error);
        }
    };
}

/** Recurring subscription button. */
class Woo_PP_Pro_PPCP_Subscription_Btn extends Woo_PP_Pro_PPCP_Btn {
    type = 'subscription';

    constructor() {
        super();
        this.btnConfig.createSubscription = this.createSubscription;
        this.btnConfig.onApprove = this.onApprove;
    }

    createSubscription = async () => {
        const customer = this.readCheckoutCustomer();
        const fields = customer
            ? {
                checkout_customer: JSON.stringify({ billing: customer.billing, shipping: customer.shipping }),
                shipping_full_name: customer.shippingFullName,
            }
            : {};

        try {
            const data = await this.ppcpAjax(wc_paypal_checkout_params.create_sub_order_ajax_action, fields);
            return data?.subscription_id;
        } catch (error) {
            console.error(error);
            this.showError(error);
            throw error;
        }
    };

    onApprove = async (data, actions) => {
        try {
            const txn_data = await actions.subscription.get();
            const result = await this.ppcpAjax(wc_paypal_checkout_params.onapprove_sub_order_ajax_action, {
                data: JSON.stringify(data),
                txn_data: JSON.stringify(txn_data),
            });
            window.location.href = result.redirect_to;
        } catch (error) {
            console.error(error);
            this.showError(error);
        }
    };
}

/**
 * Render the PayPal PPCP checkout button.
 * @param {string|HTMLElement} render_to
 */
function woo_pp_pro_render_ppcp_btn(render_to) {
    if (typeof paypal === 'undefined') {
        console.error('PayPal: SDK not loaded!');
        return;
    }

    const buttonType = wc_paypal_checkout_params.btn_type || 'buy_now';
    const button = buttonType === 'subscription'
        ? new Woo_PP_Pro_PPCP_Subscription_Btn()
        : new Woo_PP_Pro_PPCP_Buy_Now_Btn();

    button.container(render_to).render();
}

// The script is enqueued after the PayPal SDK. Dispatch only after the button
// classes and renderer above have been initialized.
document.dispatchEvent(new Event('wcpprog_paypal_sdk_ready'));

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
    // Payment method ids, for which the 'Place Order' button should be hidden.
    const target_methods = ['paypal_checkout']

    $(document.body).on('change', 'input[name="payment_method"]', () => {
        woo_pp_pro_toggle_place_order_btn(target_methods);
    }).on('updated_checkout', () => {// When Woo updates checkout
        woo_pp_pro_toggle_place_order_btn(target_methods);
    });

    const renderCheckoutButton = () => {
        const container = document.getElementById('paypal-checkout-button-container');

        if (container && !container.children.length && typeof paypal !== 'undefined') {
            woo_pp_pro_render_ppcp_btn('#paypal-checkout-button-container');
        }
    };

    // This script depends on the PayPal SDK, so it runs only after paypal.Buttons
    // is available. WooCommerce can replace the payment fields during checkout
    // updates, hence the second render attempt.
    renderCheckoutButton();
    $(document.body).on('updated_checkout', renderCheckoutButton);
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
