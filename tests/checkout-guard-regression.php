<?php
namespace TTHQ\WC_PP_PRO\Lib\PayPal {
    class PayPal_Utils { public static function auto_prefix($value) { return $value; } }
}
namespace {
require __DIR__ . '/../woocommerce-paypal-pro/lib/paypal/class-tthq-paypal-checkout-guard.php';
use TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Checkout_Guard as Guard;
function __($value, ...$args) { return $value; }
function wp_strip_all_tags($value) { return strip_tags($value); }
function is_email($value) { return filter_var($value, FILTER_VALIDATE_EMAIL); }
function WC() { return $GLOBALS['wc']; }
function is_wc_endpoint_url($endpoint) { return $GLOBALS['order_pay'] ?? false; }
function get_query_var($name) { return 7; }
function absint($value) { return abs((int) $value); }
function wc_get_order($id) { return $GLOBALS['order']; }
function wcs_order_contains_subscription($order, $types) { return $order->renewal; }
function wcs_cart_contains_renewal() { return $GLOBALS['renewal'] ?? false; }
class WC_Subscriptions_Cart { public static $contains = false; public static function cart_contains_subscription() { return self::$contains; } }
class WC_Validation {
    public static function is_postcode($value, $country) { return (bool) preg_match('/^[0-9]{5}$/', $value); }
    public static function is_phone($value) { return (bool) preg_match('/^[0-9]+$/', $value); }
}
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function rejects($callback) { try { $callback(); } catch (InvalidArgumentException $e) { return true; } return false; }
$cart = new class { public $physical = true; public function needs_shipping() { return $this->physical; } public function calculate_shipping() { return $GLOBALS['packages']; } };
$GLOBALS['wc'] = new class($cart) {
    public $cart;
    public $countries;
    public $session;
    public function __construct($cart) {
        $this->cart = $cart;
        $this->countries = new class {
            public function get_allowed_countries() { return array('US' => 'United States'); }
            public function get_shipping_countries() { return $this->get_allowed_countries(); }
            public function get_states($country) { return array('CA' => 'California'); }
            public function get_address_fields($country, $prefix) { return array($prefix . 'address_1' => array('required' => true), $prefix . 'postcode' => array('required' => true, 'validate' => array('postcode'))); }
        };
        $this->session = new class { public function get($key, $default) { return $GLOBALS['chosen']; } };
    }
    public function shipping() { return new class { public function get_packages() { return $GLOBALS['packages']; } }; }
};
check(!Guard::unsupported_subscription(), 'Ordinary and own-subscription carts remain eligible');
WC_Subscriptions_Cart::$contains = true;
check(Guard::unsupported_subscription(), 'Foreign subscription cart blocked');
WC_Subscriptions_Cart::$contains = false;
$GLOBALS['renewal'] = true;
check(Guard::unsupported_subscription(), 'Renewal cart blocked');
$GLOBALS['renewal'] = false;
$GLOBALS['order'] = new class { public $renewal = true; public function get_items() { return array(); } };
check(Guard::unsupported_subscription($GLOBALS['order']), 'Saved renewal blocked before capture');
$GLOBALS['order_pay'] = true;
$GLOBALS['wc']->cart = null;
check(Guard::unsupported_subscription(), 'Renewal pay page blocked without a cart');
$GLOBALS['order_pay'] = false;
$GLOBALS['wc']->cart = $cart;
$data = array();
foreach (array('billing', 'shipping') as $type) { foreach (array('country' => 'US', 'state' => 'CA', 'address_1' => '123 Main Street', 'postcode' => '90210') as $key => $value) { $data[$type . '_' . $key] = $value; } }
Guard::validate_addresses($data, true);
foreach (array('shipping_country' => 'ZZ', 'shipping_state' => 'INVALID', 'shipping_address_1' => '', 'shipping_postcode' => 'invalid') as $field => $value) {
    $bad = array_replace($data, array($field => $value));
    check(rejects(fn() => Guard::validate_addresses($bad, true)), 'Reject ' . $field);
}
$GLOBALS['packages'] = array();
$GLOBALS['chosen'] = array();
check(rejects(fn() => Guard::validate_shipping($cart)), 'Reject unavailable shipping');
$GLOBALS['packages'] = array(array('rates' => array('free_shipping:1' => (object) array('cost' => 0))));
$GLOBALS['chosen'] = array('missing-rate');
check(rejects(fn() => Guard::validate_shipping($cart)), 'Reject stale selected shipping');
$GLOBALS['chosen'] = array('free_shipping:1');
Guard::validate_shipping($cart);
$cart->physical = false;
$GLOBALS['packages'] = array();
Guard::validate_shipping($cart);
function check_ajax_referer(...$args) { return true; }
function sanitize_text_field($value) { return $value; }
function get_current_user_id() { return 1; }
function wc_get_orders($args) { return array($GLOBALS['order']); }
function wp_send_json_error($data, $status = null) { throw new InvalidArgumentException($data['message']); }
require __DIR__ . '/../woocommerce-paypal-pro/lib/paypal/class-tthq-paypal-button-ajax-handler.php';
$handler = (new ReflectionClass('TTHQ\\WC_PP_PRO\\Lib\\PayPal\\PayPal_Button_Ajax_Handler'))->newInstanceWithoutConstructor();
$GLOBALS['order'] = new class {
    public $renewal = true;
    public function get_payment_method() { return 'paypal_checkout'; }
    public function get_customer_id() { return 1; }
    public function get_meta(...$args) { return ''; }
    public function get_items() { return array(); }
    public function get_address($type) { return array('country' => 'ZZ'); }
};
$_POST['paypal_order_id'] = 'PAYPAL-1';
check(rejects(fn() => $handler->pp_capture_order()), 'Capture rejects renewal before calling PayPal');
$GLOBALS['order']->renewal = false;
check(rejects(fn() => $handler->pp_capture_order()), 'Capture rejects saved invalid address before calling PayPal');
echo "Checkout guard regression checks passed.\n";
}
