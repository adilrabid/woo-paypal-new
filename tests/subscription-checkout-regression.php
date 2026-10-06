<?php
/** Standalone regression checks: php tests/subscription-checkout-regression.php */
namespace TTHQ\WC_PP_PRO\Lib\PayPal {
    // Advance the competing webhook deterministically without real sleeps.
    function usleep($microseconds) {
        $GLOBALS['approval_waits']++;
        if (isset($GLOBALS['during_approval_wait'])) { ($GLOBALS['during_approval_wait'])(); }
    }
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
    require __DIR__ . '/paypal-lock-fixture.php';
    define('ABSPATH', __DIR__);
    define('WC_PP_PRO_ADDON_PATH', dirname(__DIR__) . '/woocommerce-paypal-pro');
    function __($text, ...$args) { return $text; }
    function add_action(...$args) {}
    function wc_get_order_statuses() { return array('wc-pending' => 'Pending', 'wc-completed' => 'Completed'); }
    function WC() { return $GLOBALS['wc']; }
    function wp_strip_all_tags($text) { return strip_tags($text); }
    function is_email($text) { return filter_var($text, FILTER_VALIDATE_EMAIL); }
    function wc_notice_count($type) { return count($GLOBALS['notices']); }
    function wc_get_notices($type) { return $GLOBALS['notices']; }
    function wc_clear_notices() { $GLOBALS['notices'] = array(); }
    function sanitize_text_field($text) { return $text; }
    function absint($value) { return abs((int) $value); }
    function get_current_user_id() { return $GLOBALS['uid'] ?? 1; }
    function wc_get_orders($args) {
        if (($args['type'] ?? '') === 'wcpprog_sub_order') { return $GLOBALS['existing_subscriptions'] ?? array(); }
        return isset($GLOBALS['approval_order']) ? array($GLOBALS['approval_order']) : array();
    }
    function wc_get_order($id) { return $GLOBALS['parent']; }
    function add_option($key, $value, ...$args) { if (isset($GLOBALS['locks'][$key])) { return false; } $GLOBALS['locks'][$key] = $value; return true; }
    function delete_option($key) { unset($GLOBALS['locks'][$key]); }
    class WP_Error { public function __construct(public $code, public $message) {} public function get_error_message() { return $this->message; } public function get_error_code() { return $this->code; } }
    function is_wp_error($value) { return $value instanceof WP_Error; }
    class WCPPROG_Subscription_Order_Handler {
        const STATUS_TRIAL = 'wcpprog-trial';
        const STATUS_ACTIVE = 'wcpprog-active';
        const STATUS_ON_HOLD = 'wcpprog-on-hold';
        const STATUS_PENDING_CANCEL = 'wcpprog-pending-cancel';
        const STATUS_CANCELLED = 'wcpprog-cancelled';
        const STATUS_EXPIRED = 'wcpprog-expired';
        const ORDER_TYPE = 'wcpprog_sub_order'; public static function get_subscription_statuses() { return array('wc-wcpprog-active' => 'Active'); } }
    function wp_json_encode($value) { return json_encode($value); }
    class WC_Payment_Gateway {}
    class WCPPROG_Subscription_Related { const SUBSCRIPTION_PRODUCT_TYPE = 'subscription'; }
    class WCPPROG_Subscription_Product {
        public function get_type() { return 'subscription'; }
        public function is_purchasable() { return true; }
        public function get_wcppprog_sub_recurring_billing_interval() { return 1; }
        public function get_wcppprog_sub_recurring_billing_interval_type() { return 'month'; }
        public function get_wcppprog_sub_trial_period() { return 1; }
        public function get_wcppprog_sub_trial_period_type() { return 'month'; }
    }
    class WC_Validation {
        public static function is_postcode($value, $country) { return (bool) preg_match('/^\d{5}$/', $value); }
        public static function is_phone($value) { return $value !== 'bad'; }
    }
    class TestItem {
        public $props = array('id' => 99, 'order_id' => 10, 'total' => 8, 'taxes' => array('total' => array(1 => 0.8)));
        public $meta = array();
        public function get_data() { return $this->props; }
        public function get_changes() { return array(); }
        public function set_props($data) { $this->props = $data; }
        public function get_meta_data() { return array((object) array('key' => 'label', 'value' => 'Delivery'), (object) array('key' => '_reduced_stock', 'value' => 1)); }
        public function add_meta_data($key, $value) { $this->meta[$key] = $value; }
        public function get_product() { return new WCPPROG_Subscription_Product(); }
    }
    class TestShippingItem extends TestItem {}
    class TestFeeItem extends TestItem {}
    class TestTaxItem extends TestItem {}
    class TestCouponItem extends TestItem {}
    class WC_Order_Item_Product extends TestItem {}
    class WC_Order_Item_Shipping extends TestShippingItem {}
    class WC_Order_Item_Fee extends TestFeeItem {}
    class WC_Order_Item_Tax extends TestTaxItem {}
    class WC_Order_Item_Coupon extends TestCouponItem {}
    class WCPPROG_WC_Subscription_Order {
        public static $last;
        public $values = array();
        public $items = array();
        public $calculated = null;
        public function __construct() { self::$last = $this; }
        public function __call($name, $args) { $this->values[$name] = $args[0]; }
        public function set_props($props) { foreach ($props as $key => $value) { $this->values['set_' . $key] = $value; } }
        public function add_item($item) { $this->items[] = $item; }
        public function calculate_totals($taxes) { $this->calculated = $taxes; }
        public function get_id() { return 123; }
        public function save() {}
    }
    require WC_PP_PRO_ADDON_PATH . '/lib/paypal/class-tthq-paypal-checkout-guard.php';
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
        public function get_meta($key, $single) {
            if ($key === '_wcppprog_recurring_breakdown') {
                return array('props' => array('currency' => 'EUR', 'total' => 28, 'cart_tax' => '2.00', 'shipping_tax' => '1.00'),
                    'items' => array_map(static function ($type) { return array('type' => $type, 'data' => array('total' => 20), 'meta' => array(array('key' => 'label', 'value' => 'Recurring'))); }, array('line_item', 'shipping', 'fee', 'tax', 'coupon')));
            }
 return array('_wcppprog_paypal_subscription_id' => 'I-TEST', '_wcpprog_paypal_plan_id' => 'P-TEST', '_wcpprog_has_trial' => 'no')[$key] ?? ''; }
        public function get_customer_id() { return 1; }
        public function get_payment_method() { return 'paypal_checkout'; }
        public function get_payment_method_title() { return 'My Custom Gateway'; }
        public function get_currency() { return 'EUR'; }
        public function get_total() { return 10; }
        public function get_prices_include_tax() { return true; }
        public function get_cart_tax() { return '9.50'; }
        public function get_shipping_tax() { return '0.80'; }
        public function get_address($type) { return array('country' => 'DE'); }
        public function get_items($types = null) {
            return $types ? array(new TestShippingItem(), new TestFeeItem(), new TestTaxItem(), new TestCouponItem()) : array(new TestItem());
        }
        public function update_meta_data(...$args) {}
        public function add_order_note($note) {}
        public function save() {}
    };
    $GLOBALS['parent'] = $parent;
    \TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Utility_IPN_Related::create_subscription_order($parent, array(), array(), array());
    $subscription = WCPPROG_WC_Subscription_Order::$last;
    check(count($subscription->items) === 5, 'All monetary item types must reach the subscription');
    check($subscription->values['set_currency'] === 'EUR', 'Preserve order currency');
    check($subscription->values['set_payment_method'] === 'paypal_checkout' && $subscription->values['set_payment_method_title'] === 'My Custom Gateway', 'Preserve configured payment method on subscription');
    check($subscription->values['set_prices_include_tax'] === true, 'Preserve inclusive tax display');
    check($subscription->values['set_cart_tax'] === '2.00' && $subscription->values['set_shipping_tax'] === '1.00', 'Use recurring taxes instead of initial taxes');
    check($subscription->values['set_total'] === 28 && $subscription->items[0]->props['total'] === 20, 'Use recurring total and product price from checkout snapshot');
    check($subscription->items[0]->meta['label'] === 'Recurring', 'Preserve recurring item metadata');
    check($subscription->calculated === null, 'Preserve calculated recurring totals without repricing');
    $cart = new class {
        public $items;
        public $emptied = false;
        public function empty_cart() { $this->emptied = true; }
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
    $GLOBALS['wc']->session = new class {
        public $cleared_attempt = false;
        public function get($key) { return 10; }
        public function set($key, $value) { if ($key === 'wcpprog_checkout_attempt_subscription' && $value === null) { $this->cleared_attempt = true; } }
    };
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
    // Both browser approval and webhook recovery use the same creation lock and lookup.
    $existing = WCPPROG_WC_Subscription_Order::$last;
    $GLOBALS['existing_subscriptions'] = array($existing);
    check(\TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Utility_IPN_Related::create_subscription_order($parent, array(), array(), array()) === $existing, 'Reuse subscription after interrupted parent linking');
    $lock = 'wcpprog_subscription_create_' . md5('I-TEST');
    $GLOBALS['locks'][$lock] = time();
    check(is_wp_error(\TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Utility_IPN_Related::create_subscription_order($parent, array(), array(), array())), 'Concurrent creator must retry');
    $utils = \TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Utility_IPN_Related::class;
    $saved_approval_order = $GLOBALS['approval_order'];
    $GLOBALS['approval_order'] = $parent;
    $GLOBALS['approval_waits'] = 0;
    $GLOBALS['existing_subscriptions'] = array();
    $GLOBALS['during_approval_wait'] = static function () use ($lock, $existing) {
        if ($GLOBALS['approval_waits'] === 2) {
            $GLOBALS['existing_subscriptions'] = array($existing);
            unset($GLOBALS['locks'][$lock]);
        }
    };
    $last_created = WCPPROG_WC_Subscription_Order::$last;
    check($utils::complete_post_subscription_payment_processing(array('subscriptionID' => 'I-TEST'), array(), array()) === $parent, 'Approval succeeds after competing webhook finishes');
    check($GLOBALS['approval_waits'] === 2 && $cart->emptied, 'Approval waits and empties cart after recovery');
    check(WC()->session->cleared_attempt, 'Successful checkout clears attempt so a later intentional purchase is allowed');
    check(WCPPROG_WC_Subscription_Order::$last === $last_created, 'Recovery must not construct another subscription');
    unset($GLOBALS['during_approval_wait']);
    $GLOBALS['locks'][$lock] = time();
    $GLOBALS['approval_waits'] = 0;
    $cart->emptied = false;
    WC()->session->cleared_attempt = false;
    $result = $utils::complete_post_subscription_payment_processing(array('subscriptionID' => 'I-TEST'), array(), array());
    check(is_wp_error($result) && $result->get_error_code() === 'subscription_busy', 'Long-running creator retains recoverable error');
    check($GLOBALS['approval_waits'] === 10 && !$cart->emptied, 'Wait is bounded and incomplete checkout keeps cart');
    check(!WC()->session->cleared_attempt, 'Timed-out approval preserves attempt for safe retry');
    check(isset($GLOBALS['locks'][$lock]), 'Waiting approval must not release webhook lock');
    $GLOBALS['approval_order'] = $saved_approval_order;
    unset($GLOBALS['locks'][$lock]);
    $GLOBALS['existing_subscriptions'] = array();
    require WC_PP_PRO_ADDON_PATH . '/lib/paypal/class-tthq-paypal-webhook-event-handler.php';
    function wp_die($message, $title, $args) { throw new \RuntimeException((string) $args['response']); }
    $webhook = new \TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Webhook_Event_Handler();
    $recover = new \ReflectionMethod($webhook, 'recover_subscription_order');
    $recover->setAccessible(true);
    unset($GLOBALS['approval_order']);
    check($recover->invoke($webhook, 'FOREIGN-ID') === null, 'Ignore unrelated subscriptions');
    $sale = new \ReflectionMethod($webhook, 'process_subscription_sale');
    $sale->setAccessible(true);
    check($sale->invoke($webhook, 'sale_completed', array('resource' => array('billing_agreement_id' => 'FOREIGN-ID', 'id' => 'SALE-X')), 'sandbox') === null, 'Unrelated payment is acknowledged without recovery');
    $receive = new \ReflectionMethod($webhook, 'handle_subscription_payment_received');
    $receive->setAccessible(true);
    $foreign_lock = 'wcpprog_payment_lock_' . md5('sandboxFOREIGN-ID');
    $GLOBALS['locks'][$foreign_lock] = 123;
    check($receive->invoke($webhook, 'sale_completed', array('resource' => array('billing_agreement_id' => 'FOREIGN-ID', 'id' => 'SALE-X')), 'sandbox') === null, 'Unknown payment ignores an existing lock instead of returning 503');
    check($GLOBALS['locks'][$foreign_lock] === 123, 'Unknown payment leaves existing lock untouched');
    unset($GLOBALS['locks'][$foreign_lock]);
    $GLOBALS['approval_order'] = $parent;
    $own_lock = 'wcpprog_payment_lock_' . md5('sandboxI-TEST');
    $GLOBALS['locks'][$own_lock] = time();
    $status = null;
    try { $receive->invoke($webhook, 'sale_completed', array('resource' => array('billing_agreement_id' => 'I-TEST', 'id' => 'SALE-1')), 'sandbox'); } catch (\RuntimeException $error) { $status = $error->getMessage(); }
    check($status === '503', 'Our concurrent payment still requests retry');
    unset($GLOBALS['locks'][$own_lock]);
    $GLOBALS['approval_order'] = $parent;
    \TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Request_API_Injector::$details = (object) array('id' => 'I-TEST', 'plan_id' => 'P-TEST', 'status' => 'ACTIVE');
    check($recover->invoke($webhook, 'I-TEST') instanceof WCPPROG_WC_Subscription_Order, 'Recover approved checkout without browser callback');
    $activate = new \ReflectionMethod($webhook, 'handle_subscription_status_update');
    $activate->setAccessible(true);
    \TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Request_API_Injector::$details = (object) array('id' => 'I-TEST', 'plan_id' => 'P-TEST', 'status' => 'CANCELLED');
    $activate->invoke($webhook, 'activated', array('resource' => array('id' => 'I-TEST')), 'sandbox');
    check(WCPPROG_WC_Subscription_Order::$last->values['set_status'] === 'wcpprog-cancelled', 'Delayed activation recovery respects current PayPal state');
    foreach (array(false, (object) array('id' => 'I-TEST', 'plan_id' => 'P-WRONG', 'status' => 'ACTIVE'), (object) array('id' => 'I-TEST', 'plan_id' => 'P-TEST', 'status' => 'APPROVAL_PENDING')) as $details) {
        \TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Request_API_Injector::$details = $details;
        $status = null;
        try { $recover->invoke($webhook, 'I-TEST'); } catch (\RuntimeException $error) { $status = $error->getMessage(); }
        check($status === '503', 'Our unverified checkout requests retry');
    }
    check(empty($GLOBALS['locks']), 'Creation locks released');
    echo "Subscription checkout regression checks passed.\n";
}
