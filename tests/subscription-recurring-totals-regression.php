<?php
/** Standalone calculation/snapshot checks; no WordPress database required. */
define('ABSPATH', __DIR__);
define('WC_PP_PRO_ADDON_PATH', dirname(__DIR__) . '/woocommerce-paypal-pro');
function WC() { return $GLOBALS['wc']; }
function get_woocommerce_currency() { return 'EUR'; }
function wc_prices_include_tax() { return true; }
class WCPPROG_Subscription_Product {
    public $trial = true;
    public $price = 5;
    public function is_trial_enabled() { return $this->trial; }
    public function set_wcppprog_sub_trial_period($value) { $this->trial = (bool) $value; }
    public function get_due_today_amount() { return $this->trial ? 5 : 20; }
    public function set_price($value) { $this->price = $value; }
}
class SnapshotCart {
    public $cart_contents;
    public $total;
    public function __construct($product) { $this->cart_contents = array(array('data' => $product)); $this->calculate_totals(); }
    public function calculate_totals() { $this->total = $this->cart_contents[0]['data']->price + 3; }
    public function get_total($context) { return $this->total; }
    public function fees_api() { return $this; }
    public function remove_all_fees() {}
}
class WC_Cart_Totals { public function __construct($cart) { $cart->calculate_totals(); } }
class SnapshotItem {
    public function __construct(public $type, public $total) {}
    public function get_type() { return $this->type; }
    // Match WC_Data: unsaved setter values live in changes, not get_data().
    public function get_data() { return array('id' => 0, 'order_id' => 0, 'total' => 0, 'name' => '', 'product_id' => 0); }
    public function get_changes() { return array('total' => $this->total, 'name' => 'Subscription product', 'product_id' => 42); }
    public function get_meta_data() { return array((object) array('key' => 'label', 'value' => 'Kept')); }
}
class WC_Order {
    public $props = array();
    public $items = array();
    public function __call($name, $args) {
        $key = substr($name, 4);
        if (str_starts_with($name, 'set_')) { $this->props[$key] = $args[0]; }
        else { return $this->props[$key] ?? 0; }
    }
    public function get_items($types) { return $this->items; }
}
$GLOBALS['wc'] = new class {
    public $cart;
    public function checkout() { return $this; }
    public function set_data_from_cart(&$order) {
        $order->set_total($this->cart->get_total('edit'));
        foreach (array('line_item', 'shipping', 'fee', 'tax', 'coupon') as $type) {
            $order->items[] = new SnapshotItem($type, $type === 'line_item' ? $this->cart->cart_contents[0]['data']->price : 0);
        }
    }
};
require WC_PP_PRO_ADDON_PATH . '/subscription/class-wcppprog-sub-related.php';
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
foreach (array(true, false) as $trial) {
    $product = new WCPPROG_Subscription_Product();
    $product->trial = $trial;
    $product->price = $product->get_due_today_amount();
    $cart = new SnapshotCart($product);
    WC()->cart = $cart;
    $totals = WCPPROG_Subscription_Related::get_subscription_checkout_totals($cart, $product, true);
    check($totals['initial'] === ($trial ? 8 : 23), 'Initial charge stays unchanged');
    check($totals['recurring'] === 23 && $totals['breakdown']['props']['total'] === 23, 'Snapshot matches PayPal recurring total');
    check($totals['breakdown']['items'][0]['data']['total'] === 20, 'Snapshot contains regular product price');
    check($totals['breakdown']['items'][0]['data']['name'] === 'Subscription product' && $totals['breakdown']['items'][0]['data']['product_id'] === 42, 'Preserve unsaved product identity for name, image and product link');
    check(count($totals['breakdown']['items']) === 5, 'All monetary item types are captured');
    check($totals['breakdown']['props']['currency'] === 'EUR', 'Snapshot captures checkout currency');
    check(WC()->cart === $cart && $product->trial === $trial && $cart->total === $totals['initial'], 'Initial cart and product are restored');
    check(!isset($totals['breakdown']['items'][0]['data']['id']), 'Snapshot has no persisted item IDs');
}
echo "Subscription recurring totals regression checks passed.\n";
