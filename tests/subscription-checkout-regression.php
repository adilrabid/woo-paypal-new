<?php
/** Standalone regression checks: php tests/subscription-checkout-regression.php */
namespace TTHQ\WC_PP_PRO\Lib\PayPal {
    class PayPal_Request_API_Injector {
        public static $details;
        public function get_paypal_subscription_details($id) { return self::$details; }
    }
    class PayPal_Utils {
        public static $options = array();
        public static function get_option($key) { return self::$options[$key] ?? ''; }
        public static function log(...$args) {}
    }
}
namespace {
    define('ABSPATH', __DIR__);
    define('WC_PP_PRO_ADDON_PATH', dirname(__DIR__) . '/woocommerce-paypal-pro');
    function __($text, ...$args) { return $text; }
    function add_action(...$args) {}
    function WC() { return $GLOBALS['wc']; }
    function wp_strip_all_tags($text) { return strip_tags($text); }
    function is_email($text) { return filter_var($text, FILTER_VALIDATE_EMAIL); }
    function wc_notice_count($type) { return count($GLOBALS['notices']); }
    function wc_get_notices($type) { return $GLOBALS['notices']; }
    function wc_clear_notices() { $GLOBALS['notices'] = array(); }
    function sanitize_text_field($text) { return $text; }
    function absint($value) { return abs((int) $value); }
    function get_current_user_id() { return $GLOBALS['uid'] ?? 1; }
    function wc_get_orders($args) { return array($GLOBALS['approval_order']); }
    function wp_json_encode($value) { return json_encode($value); }
    class WC_Payment_Gateway {}
    class WCPPROG_Subscription_Related { const SUBSCRIPTION_PRODUCT_TYPE = 'subscription'; }
    class WCPPROG_Subscription_Product {
        public function get_type() { return 'subscription'; }
        public function is_purchasable() { return true; }
        public function get_meta($key, $single) { return strpos($key, 'type') !== false ? 'month' : 1; }
    }
    class WC_Validation {
        public static function is_postcode($value, $country) { return (bool) preg_match('/^\d{5}$/', $value); }
        public static function is_phone($value) { return $value !== 'bad'; }
    }
    class TestItem {
        public $props = array('id' => 99, 'order_id' => 10, 'total' => 8, 'taxes' => array('total' => array(1 => 0.8)));
        public $meta = array();
        public function get_data() { return $this->props; }
        public function set_props($data) { $this->props = $data; }
        public function get_meta_data() { return array((object) array('key' => 'label', 'value' => 'Delivery'), (object) array('key' => '_reduced_stock', 'value' => 1)); }
        public function add_meta_data($key, $value) { $this->meta[$key] = $value; }
        public function get_product() { return new WCPPROG_Subscription_Product(); }
    }
    class TestShippingItem extends TestItem {}
    class TestFeeItem extends TestItem {}
    class TestTaxItem extends TestItem {}
    class TestCouponItem extends TestItem {}
    class WCPPROG_WC_Subscription_Order {
        public static $last;
        public $values = array();
        public $items = array();
        public $calculated = null;
        public function __construct() { self::$last = $this; }
        public function __call($name, $args) { $this->values[$name] = $args[0]; }
        public function add_item($item) { $this->items[] = $item; }
        public function calculate_totals($taxes) { $this->calculated = $taxes; }
        public function get_id() { return 123; }
        public function save() {}
    }
    require WC_PP_PRO_ADDON_PATH . '/lib/paypal/class-tthq-paypal-utils-ipn-related.php';
    require WC_PP_PRO_ADDON_PATH . '/lib/paypal/class-tthq-paypal-button-sub-ajax-handler.php';
    require WC_PP_PRO_ADDON_PATH . '/woo-paypal-pro-gateway-paypal-checkout.php';
    function check($condition, $message) { if (!$condition) { throw new \RuntimeException($message); } }
    foreach (array(TestItem::class, TestShippingItem::class, TestFeeItem::class, TestTaxItem::class, TestCouponItem::class) as $class) {
        $original = new $class();
        $copy = \TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Utility_IPN_Related::copy_subscription_line_item($original);
        check(get_class($copy) === $class, 'Item type must survive copying');
        check(!isset($copy->props['id'], $copy->props['order_id']), 'Copied item must not retain persisted identity');
        check($original->props['id'] === 99 && $copy->props['taxes'] === $original->props['taxes'], 'Original and tax data must be preserved');
        check($copy->meta === array('label' => 'Delivery'), 'Stock metadata must not be copied');
    }
    $parent = new class {
        public function get_id() { return 10; }
        public function get_meta($key, $single) { return ''; }
        public function get_customer_id() { return 1; }
        public function get_payment_method() { return 'paypal_checkout'; }
        public function get_payment_method_title() { return 'My Custom Gateway'; }
        public function get_currency() { return 'EUR'; }
        public function get_prices_include_tax() { return true; }
        public function get_cart_tax() { return '9.50'; }
        public function get_shipping_tax() { return '0.80'; }
        public function get_address($type) { return array('country' => 'DE'); }
        public function get_items($types = null) {
            return $types ? array(new TestShippingItem(), new TestFeeItem(), new TestTaxItem(), new TestCouponItem()) : array(new TestItem());
        }
        public function update_meta_data(...$args) {}
        public function save() {}
    };
    \TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Utility_IPN_Related::create_subscription_order($parent, array(), array(), array());
    $subscription = WCPPROG_WC_Subscription_Order::$last;
    check(count($subscription->items) === 5, 'All monetary item types must reach the subscription');
    check($subscription->values['set_currency'] === 'EUR', 'Preserve order currency');
    check($subscription->values['set_payment_method'] === 'paypal_checkout' && $subscription->values['set_payment_method_title'] === 'My Custom Gateway', 'Preserve configured payment method on subscription');
    check($subscription->values['set_prices_include_tax'] === true, 'Preserve inclusive tax display');
    check($subscription->values['set_cart_tax'] === '9.50' && $subscription->values['set_shipping_tax'] === '0.80', 'Preserve tax totals used by WooCommerce total calculation');
    check($subscription->calculated === false, 'Recalculate totals without changing historical tax rates');
    $cart = new class {
        public $items;
        public $stock_error = false;
        public function get_cart() { return $this->items; }
        public function is_empty() { return !$this->items; }
        public function needs_shipping() { return true; }
        public function check_cart_items() { if ($this->stock_error) { $GLOBALS['notices'][] = array('notice' => 'Out of stock'); } }
        public function check_cart_coupons() {}
        public function check_customer_coupons($data) {}
    };
    $cart->items = array(array('data' => new WCPPROG_Subscription_Product(), 'quantity' => 1));
    $GLOBALS['notices'] = array();
    $GLOBALS['wc'] = (object) array('cart' => $cart, 'countries' => new class {
        public function get_allowed_countries() { return array('US' => 'United States'); }
        public function get_shipping_countries() { return $this->get_allowed_countries(); }
        public function get_address_fields($country, $prefix) {
            return array($prefix . 'city' => array('required' => true, 'label' => 'City'), $prefix . 'postcode' => array('required' => true, 'validate' => array('postcode'), 'label' => 'Postcode'));
        }
        public function get_states($country) { return array('CA' => 'California'); }
    });
    $reflection = new ReflectionClass(WC_Gateway_PayPal_Checkout::class);
    $gateway = $reflection->newInstanceWithoutConstructor();
    $sandbox = $reflection->getProperty('sandbox');
    foreach (array(false => 'production', true => 'sandbox') as $mode => $key) {
        $sandbox->setValue($gateway, (bool) $mode);
        \TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Utils::$options = array('paypal_webhook_id_live' => 'wrong-key');
        check($gateway->webhook_missing_notice() !== '', 'Missing webhook must show notice');
        \TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Utils::$options['paypal_webhook_id_' . $key] = 'WH-123';
        check($gateway->webhook_missing_notice() === '', 'Configured webhook must not show notice');
    }
    $handler = new \TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Button_Sub_Ajax_Handler();
    $handler->wc_paypal_ppcp = new class {
        public $notice = '';
        public function is_available() { return true; }
        public function webhook_missing_notice() { return $this->notice; }
    };
    $data = array('billing_country' => 'US', 'billing_city' => 'LA', 'billing_postcode' => '90001', 'billing_state' => 'CA', 'shipping_country' => 'US', 'shipping_city' => 'LA', 'shipping_postcode' => '90001', 'shipping_state' => 'CA');
    $customer = new ReflectionProperty($handler, 'checkout_customer_data');
    $validate = new ReflectionMethod($handler, 'validate_subscription_checkout');
    $customer->setValue($handler, $data);
    $validate->invoke($handler, $cart);
    $reject = function($label) use ($validate, $handler, $cart) {
        try { $validate->invoke($handler, $cart); } catch (InvalidArgumentException $e) { return; }
        throw new RuntimeException('Expected rejection: ' . $label);
    };
    foreach (array('billing_city' => '', 'shipping_postcode' => 'invalid', 'billing_country' => 'XX', 'shipping_state' => 'XX') as $field => $value) {
        $customer->setValue($handler, array_replace($data, array($field => $value)));
        $reject($field);
    }
    $customer->setValue($handler, $data);
    $cart->items[0]['quantity'] = 2;
    $reject('multiple quantity');
    $cart->items[0]['quantity'] = 1;
    $cart->items[] = array('data' => new WCPPROG_Subscription_Product(), 'quantity' => 1);
    $reject('multiple products');
    array_pop($cart->items);
    $cart->stock_error = true;
    $reject('stock');
    $cart->stock_error = false;
    $handler->wc_paypal_ppcp->notice = 'Missing webhook';
    $reject('webhook');
    $GLOBALS['wc']->session = new class { public function get($key) { return 10; } };
    $GLOBALS['approval_order'] = new class {
        public function get_id() { return 10; }
        public function get_customer_id() { return 1; }
        public function get_meta($key, $single) { return $key === '_wcpprog_paypal_plan_id' ? 'P-1' : 'no'; }
    };
    $response = (object) array('id' => 'I-1', 'plan_id' => 'P-1', 'status' => 'EXPIRED', 'billing_info' => (object) array('cycle_executions' => array((object) array('tenure_type' => 'REGULAR', 'total_cycles' => 1, 'cycles_completed' => 1, 'cycles_remaining' => 0))));
    $validate_approval = function($details) use ($handler) {
        \TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Request_API_Injector::$details = $details;
        $posted = array('status' => 'ACTIVE');
        $result = $handler->validate_subscription_checkout_txn_data(array('subscriptionID' => 'I-1'), $posted);
        if ($result === true) { check($posted['status'] === $details->status, 'Use verified server status'); }
        return $result;
    };
    check($validate_approval($response) === true, 'Completed single-cycle subscription is approved');
    foreach (array('APPROVAL_PENDING', 'APPROVED', 'CANCELLED', 'SUSPENDED') as $status) {
        $response->status = $status;
        check($validate_approval($response) !== true, 'Reject unapproved or cancelled subscription');
    }
    $response->status = 'ACTIVE';
    check($validate_approval($response) === true, 'Active subscriptions remain supported');
    $response->status = 'EXPIRED';
    $cycle = $response->billing_info->cycle_executions[0];
    foreach (array('total_cycles' => 0, 'cycles_completed' => 0, 'cycles_remaining' => 1, 'tenure_type' => 'TRIAL') as $field => $value) {
        $original = $cycle->$field;
        $cycle->$field = $value;
        check($validate_approval($response) !== true, 'Reject expiration without completed finite regular cycle');
        $cycle->$field = $original;
    }
    $response->plan_id = 'P-other';
    check($validate_approval($response) !== true, 'Completed subscription still requires matching plan');
    $response->plan_id = 'P-1';
    $response->id = 'I-other';
    check($validate_approval($response) !== true, 'Completed subscription still requires matching ID');
    $response->id = 'I-1';
    $GLOBALS['uid'] = 2;
    check($validate_approval($response) !== true, 'Completed subscription still requires ownership');
    $GLOBALS['uid'] = 1;
    check($validate_approval(false) !== true, 'API failure is rejected');
    \TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Utility_IPN_Related::create_subscription_order($parent, array(), array('status' => 'EXPIRED'), array());
    check(WCPPROG_WC_Subscription_Order::$last->values['set_status'] === 'wcpprog-expired', 'Preserve expired status at creation');
    check(WCPPROG_WC_Subscription_Order::$last->values['set_next_payment_date'] === '', 'Completed subscription has no next payment');
    echo "Subscription checkout regression checks passed.\n";
}
