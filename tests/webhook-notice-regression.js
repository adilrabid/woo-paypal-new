// Run with: node tests/webhook-notice-regression.js
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const container = {
    children: [],
    querySelector() { return this.children[0] || null; },
    appendChild(node) { this.children.push(node); node.remove = () => { this.children = this.children.filter(child => child !== node); }; },
};
const context = vm.createContext({
    console, Event,
    jQuery() { return { on() {} }; },
    paypal: {},
    wc_paypal_checkout_params: { btn_type: 'subscription', webhook_missing_notice: "Webhook isn't configured & must be created." },
    document: {
        querySelector: () => container,
        createElement: () => ({ style: {}, setAttribute() {} }),
        dispatchEvent() {},
    },
});
vm.runInContext(fs.readFileSync('woocommerce-paypal-pro/assets/js/woo-pp-pro-ppcp-related.js', 'utf8'), context);
context.woo_pp_pro_render_ppcp_btn('#checkout');
context.woo_pp_pro_render_ppcp_btn(container);
assert.equal(container.children.length, 1, 'Repeated rendering must not duplicate the notice');
assert.equal(container.children[0].textContent, context.wc_paypal_checkout_params.webhook_missing_notice);
let rendered = 0;
context.paypal.Buttons = () => ({ render: () => { rendered++; } });
context.wc_paypal_checkout_params.webhook_missing_notice = '';
context.woo_pp_pro_render_ppcp_btn(container);
assert.equal(container.children.length, 0, 'A resolved missing webhook must remove the notice');
assert.equal(rendered, 1, 'A configured webhook must allow button rendering');
console.log('Webhook notice regression checks passed.');
