<?php
/** Run with: php tests/subscription-refund-webhook-regression.php. */
namespace TTHQ\WC_PP_PRO\Lib\PayPal {
    class PayPal_Utils { public static function log(...$args) {} }
}
namespace {
    function add_action(...$args) {}
    function sanitize_text_field($value) { return $value; }
    function wp_parse_url($url, $component) { return parse_url($url, $component); }
    function __($value, ...$args) { return $value; }
    function is_wp_error($value) { return false; }
    function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
    class TestRefund {
        public $meta = array();
        public function __construct(public $id) {}
        public function get_id() { return $this->id; }
        public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
        public function save() {}
    }
    function wc_get_orders($args) {
        // WooCommerce's default view-orders query does not include refunds.
        if (($args['type'] ?? 'shop_order') === 'shop_order_refund') {
            return array_values(array_filter($GLOBALS['refunds'], static function($refund) use ($args) {
                return ($refund->meta[$args['meta_key']] ?? '') === $args['meta_value'];
            }));
        }
        if ($args['meta_key'] === '_paypal_transaction_id' && $args['meta_value'] === 'SALE-1') {
            return array(new class { public function get_id() { return 10; } });
        }
        return array();
    }
    function wc_create_refund($args) {
        check($args['order_id'] === 10, 'Refund belongs to original payment');
        check($args['refund_payment'] === false && $args['restock_items'] === false, 'Webhook must not issue another PayPal refund or restock');
        $GLOBALS['created'][] = $args;
        $refund = new TestRefund(count($GLOBALS['created']) + 90);
        $GLOBALS['refunds'][] = $refund;
        return $refund;
    }
    require dirname(__DIR__) . '/woocommerce-paypal-pro/lib/paypal/class-tthq-paypal-webhook-event-handler.php';
    $handler = new \TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Webhook_Event_Handler();
    $method = new ReflectionMethod($handler, 'handle_payment_refunded');
    $method->setAccessible(true);
    $GLOBALS['refunds'] = $GLOBALS['created'] = array();
    $event = array('resource' => array('id' => 'REFUND-1', 'sale_id' => 'SALE-1', 'amount' => array('total' => '5.00'), 'note_to_payer' => 'Partial refund'));
    $method->invoke($handler, 'sale_refunded', $event, 'sandbox');
    check(count($GLOBALS['created']) === 1 && $GLOBALS['created'][0]['amount'] === 5.0, 'Record sale refund amount');
    check($GLOBALS['refunds'][0]->meta['_wcppprog_paypal_refund_id'] === 'REFUND-1', 'Store PayPal refund ID');
    $method->invoke($handler, 'sale_refunded', $event, 'sandbox');
    check(count($GLOBALS['created']) === 1, 'Repeated webhook must not duplicate partial refund');
    $event['resource'] = array('id' => 'REFUND-2', 'links' => array(array('rel' => 'up', 'href' => 'https://api.paypal.com/v2/payments/captures/SALE-1')), 'amount' => array('value' => '3.00'), 'description' => 'Second partial refund');
    $method->invoke($handler, 'capture_refunded', $event, 'sandbox');
    check(count($GLOBALS['created']) === 2 && $GLOBALS['created'][1]['amount'] === 3.0, 'Distinct capture refund uses parent link and amount value');
    $method->invoke($handler, 'capture_refunded', $event, 'sandbox');
    check(count($GLOBALS['created']) === 2, 'Repeated capture refund is ignored');
    $event['resource']['id'] = 'REFUND-3';
    $event['resource']['links'] = array();
    $method->invoke($handler, 'capture_refunded', $event, 'sandbox');
    check(count($GLOBALS['created']) === 2, 'Unknown original payment is ignored');
    echo "Subscription refund webhook regression checks passed.\n";
}
