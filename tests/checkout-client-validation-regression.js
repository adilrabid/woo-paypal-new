const fs = require('fs');
const vm = require('vm');
const assert = require('assert');

const source = fs.readFileSync('woocommerce-paypal-pro/assets/js/woo-pp-pro-ppcp-related.js', 'utf8');
let ajaxCalls = 0;
const field = {disabled: false, value: '', focus() {this.focused = true;}};
const row = {
    classList: {contains: (name) => name === 'woocommerce-invalid' && !field.value},
    getClientRects: () => [1],
    closest: () => null,
    querySelector: () => field,
    scrollIntoView() {},
};
const form = {
    classList: {contains: () => false},
    querySelectorAll: () => [row],
    reportValidity: () => !!field.value,
};
const context = {
    document: {querySelector: (selector) => selector === 'form.checkout' ? form : null, getElementById: () => null, dispatchEvent() {}, addEventListener() {}},
    Event: class {},
    jQuery: () => ({trigger() {}, on() {}}),
    FormData: class {},
    console: {error() {}},
    wc_paypal_checkout_params: {create_order_ajax_action: 'create', create_sub_order_ajax_action: 'subscribe'},
};
vm.createContext(context);
vm.runInContext(source + '\nglobalThis.TestOneTime = Woo_PP_Pro_PPCP_Buy_Now_Btn; globalThis.TestSubscription = Woo_PP_Pro_PPCP_Subscription_Btn;', context);
const actions = {resolve: () => 'resolved', reject: () => 'rejected'};
(async () => {
    for (const Button of [context.TestOneTime, context.TestSubscription]) {
        const button = new Button();
        button.ppcpAjax = async () => { ajaxCalls++; return {order_id: 'ORDER', subscription_id: 'SUB'}; };
        button.showError = () => {};
        assert.strictEqual(button.onClick(null, actions), 'rejected');
        await assert.rejects(() => Button === context.TestOneTime ? button.createOrder() : button.createSubscription());
        assert.strictEqual(ajaxCalls, 0, 'Invalid checkout must not create a PayPal resource');
        field.value = 'valid';
        assert.strictEqual(button.onClick(null, actions), 'resolved');
        field.value = '';
    }
    console.log('Checkout client validation regression checks passed.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
