<?php
/** Run: php tests/subscription-review-email-regression.php. No email is sent. */
define('ABSPATH', __DIR__);
define('WC_PP_PRO_ADDON_PATH', dirname(__DIR__) . '/woocommerce-paypal-pro');
require WC_PP_PRO_ADDON_PATH . '/subscription/class-wcppprog-sub-related.php';
class WC_Order {
    public $gateway = 'paypal_checkout';
    public $meta = array();
    public function get_payment_method() { return $this->gateway; }
    public function get_meta($key, $single) { return $this->meta[$key] ?? ''; }
}
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
$related = (new ReflectionClass(WCPPROG_Subscription_Related::class))->newInstanceWithoutConstructor();
$order = new WC_Order();
check($related->review_payment_on_hold_email_enabled(true, $order), 'Normal PayPal email remains enabled');
check(!$related->review_payment_on_hold_email_enabled(false, $order), 'Respect disabled email setting');
check($related->review_payment_on_hold_email_enabled(true, false), 'Missing email order is harmless');
$order->meta['_wcpprog_payment_review_required'] = 'yes';
check($related->review_payment_on_hold_email_enabled(true, $order), 'Review marker alone is not proof of payment');
$order->meta['_paypal_transaction_id'] = 'SALE-REVIEW';
check(!$related->review_payment_on_hold_email_enabled(true, $order), 'Suppress misleading email for verified review receipts');
$order->gateway = 'bacs';
check($related->review_payment_on_hold_email_enabled(true, $order), 'Other gateways retain their email');
echo "Subscription review email regression checks passed.\n";
