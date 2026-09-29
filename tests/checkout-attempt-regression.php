<?php
/** Run with: php tests/checkout-attempt-regression.php */
namespace TTHQ\WC_PP_PRO\Lib\PayPal {
    class PayPal_Request_API_Injector {
        public static $response;
        public static $error = array();
        public function get_paypal_order_details($id) { return self::$response; }
        public function get_paypal_subscription_details($id) { return self::$response; }
        public function get_last_error_from_api_call() { return self::$error; }
    }
}
namespace {
    function __($text, ...$args) { return $text; }
    function absint($value) { return abs((int) $value); }
    function wp_json_encode($value) { return json_encode($value); }
    function get_current_user_id() { return $GLOBALS['user_id']; }
    function get_woocommerce_currency() { return $GLOBALS['currency']; }
    function WC() { return $GLOBALS['wc']; }
    function wc_get_order($id) { return $GLOBALS['orders'][$id] ?? false; }
    class WP_Error { public function __construct(public $code, public $message) {} }
    class TestOrder {
        public $meta = array();
        public $status = 'pending';
        public $customer = 0;
        public $paid = null;
        public $transaction = '';
        public $method = 'paypal_checkout';
        public function __construct(public $id) {}
        public function get_id() { return $this->id; }
        public function get_type() { return 'shop_order'; }
        public function get_payment_method() { return $this->method; }
        public function get_customer_id() { return $this->customer; }
        public function has_status($status) { return $status === $this->status; }
        public function get_date_paid() { return $this->paid; }
        public function get_transaction_id() { return $this->transaction; }
        public function get_meta($key, $single) { return $this->meta[$key] ?? ''; }
        public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
        public function save() { $GLOBALS['orders'][$this->id] = $this; }
    }
    $GLOBALS['orders'] = array();
    $GLOBALS['user_id'] = 0;
    $GLOBALS['currency'] = 'USD';
    $GLOBALS['wc'] = new class {
        public $session;
        public $cart;
        public $customer;
        public $checkout;
        public function checkout() { return $this->checkout; }
    };
    WC()->session = new class {
        public $values = array();
        public function get($key, $default = null) { return $this->values[$key] ?? $default; }
        public function set($key, $value) { $this->values[$key] = $value; }
    };
    WC()->cart = new class {
        public $hash = 'cart-1';
        public function get_cart_hash() { return $this->hash; }
    };
    WC()->customer = new class {
        public $address = 'First address';
        public function get_billing() { return array('address_1' => $this->address); }
        public function get_shipping() { return $this->get_billing(); }
    };
    WC()->checkout = new class {
        public $seen;
        public $fail = false;
        public function create_order($data) {
            $this->seen = WC()->session->get('order_awaiting_payment');
            if ($this->fail) { throw new RuntimeException('Create failed'); }
            return $this->seen ?: 42;
        }
    };
    $gateway = new class {
        public $sandbox = 'yes';
        public function get_option($key) { return $key === 'sandbox' ? $this->sandbox : 'client'; }
    };
    require dirname(__DIR__) . '/woocommerce-paypal-pro/lib/paypal/class-tthq-paypal-checkout-attempt.php';
    function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
    $attempt = \TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Checkout_Attempt::class;
    $api = \TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Request_API_Injector::class;
    $fingerprint = $attempt::fingerprint($gateway);
    $order = new TestOrder(12);
    $attempt::remember($order, 'payment', $fingerprint);
    check($attempt::get_order('payment', $fingerprint) === $order, 'Guest can resume own session attempt');
    check(!$attempt::get_order('subscription', $fingerprint), 'Payment and subscription attempts stay separate');
    WC()->session->set('order_awaiting_payment', 777);
    check($attempt::create_order(array(), 'payment', $fingerprint) === 12, 'Reuse order after PayPal creation failed');
    check(WC()->session->get('order_awaiting_payment') === 777, 'Restore unrelated checkout session');
    WC()->checkout->fail = true;
    try { $attempt::create_order(array(), 'payment', $fingerprint); } catch (RuntimeException $e) {}
    check(WC()->session->get('order_awaiting_payment') === 777, 'Restore session after exception');
    WC()->checkout->fail = false;
    $order->meta['_paypal_order_id'] = 'PAYPAL-12';
    $api::$response = (object) array('id' => 'PAYPAL-12', 'status' => 'CREATED');
    for ($retry = 0; $retry < 5; ++$retry) {
        $existing = $attempt::get_order('payment', $fingerprint);
        check($attempt::get_approval_id($existing, 'payment') === 'PAYPAL-12', 'Cancel/retry returns original PayPal ID');
        check(count($GLOBALS['orders']) === 1, 'Cancel/retry does not add orders');
    }
    foreach (array('COMPLETED', 'SAVED', 'UNKNOWN') as $status) {
        $api::$response->status = $status;
        check($attempt::get_approval_id($order, 'payment') instanceof WP_Error, 'Do not overwrite payment in progress');
    }
    $api::$response->status = 'VOIDED';
    check($attempt::get_approval_id($order, 'payment') === '', 'Closed approval allows fresh attempt');
    $api::$response = false;
    $api::$error = array('http_code' => 503);
    check($attempt::get_approval_id($order, 'payment') instanceof WP_Error, 'API outage must not create a duplicate');
    $api::$error = array('http_code' => 404);
    check($attempt::get_approval_id($order, 'payment') === '', 'Expired remote order allows fresh attempt');
    $attempt::create_order(array(), 'payment', $fingerprint);
    check(WC()->checkout->seen === 0, 'Never rebuild order already linked to PayPal');
    foreach (array('status' => 'processing', 'paid' => 'today', 'transaction' => 'CAPTURE', 'customer' => 9, 'method' => 'other') as $property => $value) {
        $original = $order->$property;
        $order->$property = $value;
        check(!$attempt::get_order('payment', $fingerprint), 'Reject unsafe order: ' . $property);
        $order->$property = $original;
    }
    WC()->session->set('wcpprog_checkout_attempt_payment', 999);
    check(!$attempt::get_order('payment', $fingerprint), 'Another session cannot find the order');
    WC()->session->set('wcpprog_checkout_attempt_payment', 12);
    WC()->customer->address = 'Changed address';
    check(!$attempt::get_order('payment', $attempt::fingerprint($gateway)), 'Address changes require fresh checkout');
    WC()->customer->address = 'First address';
    $gateway->sandbox = 'no';
    check(!$attempt::get_order('payment', $attempt::fingerprint($gateway)), 'Environment changes require fresh checkout');
    $gateway->sandbox = 'yes';
    WC()->cart->hash = 'changed-cart';
    check(!$attempt::get_order('payment', $attempt::fingerprint($gateway)), 'Cart changes require fresh checkout');
    $attempt::remember($order, 'subscription', 'subscription-fingerprint');
    $order->meta['_paypal_subscription_id'] = 'I-SUB';
    $api::$response = (object) array('id' => 'I-SUB', 'status' => 'APPROVAL_PENDING');
    check($attempt::get_approval_id($order, 'subscription') === 'I-SUB', 'Reuse unapproved subscription');
    $api::$response->status = 'ACTIVE';
    check($attempt::get_approval_id($order, 'subscription') instanceof WP_Error, 'Never replace active subscription while waiting for payment');
    $order->meta['_wcpprog_subscription_order_id'] = 55;
    check(!$attempt::get_order('subscription', 'subscription-fingerprint'), 'Approved local subscription is not reusable');
    echo "Checkout attempt regression checks passed.\n";
}
