<?php
/** Run: php tests/subscription-refund-history-regression.php */
define('ABSPATH', __DIR__);
function wc_get_order_statuses() { return array('wc-wcpprog-active' => 'Active'); }
function wc_get_orders($args) {
    if ($args['type'] !== 'shop_order_refund' || $args['parent'] !== 10 || $args['limit'] !== -1) {
        throw new RuntimeException('Refund query must be scoped to its payment');
    }
    return ($args['status'] ?? array_keys(wc_get_order_statuses())) === 'wc-completed' ? $GLOBALS['refunds'] : array();
}
require dirname(__DIR__) . '/woocommerce-paypal-pro/subscription/class-wcppprog-sub-payment-history.php';
$payment = new class {
    public function get_id() { return 10; }
    public function get_date_paid() { return null; }
    public function get_date_created() { return new DateTime('2026-10-05'); }
    public function get_order_number() { return '10'; }
    public function get_status() { return 'refunded'; }
    public function get_payment_method_title() { return 'PayPal'; }
    public function get_transaction_id() { return 'SALE-1'; }
    public function get_total() { return '20.00'; }
    public function get_currency() { return 'USD'; }
    public function is_paid() { return false; }
    public function get_refunds() { throw new RuntimeException('Do not use the filtered refund cache'); }
};
$subscription = new class { public function get_parent_order_id_ref() { return 10; } };
$refund = new class {
    public $paypal_id = 'REFUND-1';
    public function get_meta($key, $single) { return $this->paypal_id; }
    public function get_id() { return 90; }
    public function get_amount() { return '5.00'; }
    public function get_date_created() { return new DateTime('2026-10-05'); }
};
$GLOBALS['refunds'] = array($refund);
foreach (array('REFUND-1', '') as $paypal_id) {
    $refund->paypal_id = $paypal_id;
    $snapshot = WCPPROG_Subscription_Payment_History::snapshot($payment, $subscription);
    if (!$snapshot['received'] || $snapshot['refunds'][0]['amount'] !== '5.00' || $snapshot['refunds'][0]['id'] !== ($paypal_id ?: '#90')) {
        throw new RuntimeException('Refund must survive subscription-screen status filtering');
    }
}
$GLOBALS['refunds'] = array();
if (WCPPROG_Subscription_Payment_History::snapshot($payment, $subscription)['refunds']) {
    throw new RuntimeException('Deleted refunds must disappear');
}
echo "Subscription refund history regression checks passed.\n";
