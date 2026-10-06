const fs = require('fs');
const vm = require('vm');
const assert = require('assert');

const source = fs.readFileSync('woocommerce-paypal-pro/assets/js/woo-pp-pro-ppcp-related.js', 'utf8');
const context = {
    window: {location: {href: ''}},
    document: {addEventListener() {}, dispatchEvent() {}},
    Event: class {},
    jQuery: () => ({on() {}}),
    console,
    wc_paypal_checkout_params: {create_sub_order_ajax_action: 'subscribe'},
};
vm.createContext(context);
vm.runInContext(source + '\nglobalThis.TestSubscription = Woo_PP_Pro_PPCP_Subscription_Btn;', context);
(async () => {
    const subscription = new context.TestSubscription();
    subscription.readCheckoutCustomer = () => null;
    subscription.ppcpAjax = async () => ({subscription_id: 'SUB-EXISTING'});
    assert.strictEqual(await subscription.createSubscription(), 'SUB-EXISTING');
    subscription.ppcpAjax = async () => ({redirect_to: '/order-received/12'});
    subscription.showError = () => { throw new Error('Recovery must not show an error'); };
    let resolved = false;
    subscription.createSubscription().then(() => { resolved = true; });
    await new Promise(setImmediate);
    assert.strictEqual(context.window.location.href, '/order-received/12');
    assert.strictEqual(resolved, false, 'Redirect must not resolve with an invalid/new PayPal subscription ID');
    console.log('Subscription approval recovery regression checks passed.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
