<?php
/** Run: php tests/subscription-next-payment-regression.php. */
namespace TTHQ\WC_PP_PRO\Lib\PayPal {
    class PayPal_Utils { public static function log(...$args) {} }
    class PayPal_Request_API_Injector {
        public static $details;
        public function get_paypal_subscription_details($id) { return self::$details; }
    }
}
namespace {
    define('ABSPATH', __DIR__);
    function wc_get_order_statuses() { return array(); }
    function wc_get_orders($args) {
        if (($args['type'] ?? '') === 'shop_order') { return $GLOBALS['payments'] ?? array(); }
        return isset($GLOBALS['test_order']) ? array($GLOBALS['test_order']) : array();
    }
    function wc_get_order($id) { return false; }
    function wc_format_decimal($value, $decimals) { return number_format((float) $value, $decimals, '.', ''); }
    function wc_get_price_decimals() { return 2; }
    function add_option($key, $value, ...$args) { if (isset($GLOBALS['locks'][$key])) { return false; } $GLOBALS['locks'][$key] = $value; return true; }
    function delete_option($key) { unset($GLOBALS['locks'][$key]); }

    function __($text, ...$args) { return $text; }
    function add_action(...$args) {}
    function wp_die($message, $title = '', $args = array()) { throw new RuntimeException((string) ($args['response'] ?? $message)); }
    class WCPPROG_Subscription_Order_Handler {
        const ORDER_TYPE = 'wcpprog_sub_order';
        const STATUS_TRIAL = 'wcpprog-trial';
        const STATUS_ACTIVE = 'wcpprog-active';
        const STATUS_ON_HOLD = 'wcpprog-on-hold';
        const STATUS_CANCELLED = 'wcpprog-cancelled';
        const STATUS_EXPIRED = 'wcpprog-expired';
        public static function get_subscription_statuses() { return array(); }
    }
    require dirname(__DIR__) . '/woocommerce-paypal-pro/lib/paypal/class-tthq-paypal-webhook-event-handler.php';
    $handler = (new ReflectionClass(\TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Webhook_Event_Handler::class))->newInstanceWithoutConstructor();
    $update = new ReflectionMethod($handler, 'update_subscription_billing_schedule');
    $order = new class {
        public $status;
        public $meta = array();
        public $next_date;
        public $saves = 0;
        public function get_id() { return 7; }
        public function get_parent_order_id_ref() { return 10; }
        public function get_currency() { return 'USD'; }
        public function get_total() { return 20; }
        public function add_order_note($note) {}
        public function save() { ++$this->saves; }
        public function get_meta($key, $single) { return $this->meta[$key] ?? ''; }
        public function set_status($status) { $this->status = $status; }
        public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
        public function set_next_payment_date($date) { $this->next_date = $date; }
    };
    foreach (array(
        array('ACTIVE', '2026-11-05T14:30:00+02:00', 'wcpprog-active', '2026-11-05 12:30:00'),
        array('ACTIVE', null, 'wcpprog-active', ''),
        array('SUSPENDED', '2026-11-05T14:30:00+02:00', 'wcpprog-on-hold', ''),
        array('CANCELLED', '2026-11-05T14:30:00+02:00', 'wcpprog-cancelled', ''),
        array('EXPIRED', '2026-11-05T14:30:00+02:00', 'wcpprog-expired', ''),
    ) as [$status, $next, $expected_status, $expected_date]) {
        $details = (object) array('status' => $status, 'billing_info' => (object) array('next_billing_time' => $next));
        $update->invoke($handler, $order, $details);
        if ($order->status !== $expected_status || $order->next_date !== $expected_date || $order->meta['_paypal_subscription_status'] !== $status) {
            throw new RuntimeException('Incorrect PayPal schedule synchronization for ' . $status);
        }
    }
    // A delayed activation must follow PayPal's current terminal state.
    $GLOBALS['test_order'] = $order;
    $status_event = new ReflectionMethod($handler, 'handle_subscription_status_update');
    $order->meta['_wcpprog_has_trial'] = 'yes';
    $order->meta['_wcpprog_regular_payment_received'] = 'no';
    \TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Request_API_Injector::$details = (object) array('id' => 'I-TEST', 'status' => 'EXPIRED');
    $status_event->invoke($handler, 'activated', array('resource' => array('id' => 'I-TEST', 'billing_info' => array('next_billing_time' => '2030-01-01T00:00:00Z'))), 'sandbox');
    if ($order->status !== 'wcpprog-expired' || $order->next_date !== '') { throw new RuntimeException('Delayed activation reopened expired subscription'); }
    // A delayed suspension must not overwrite a renewed active trial.
    \TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Request_API_Injector::$details = (object) array('id' => 'I-TEST', 'status' => 'ACTIVE', 'billing_info' => (object) array('next_billing_time' => '2026-12-01T00:00:00Z'));
    $status_event->invoke($handler, 'suspended', array('resource' => array('id' => 'I-TEST')), 'sandbox');
    if ($order->status !== 'wcpprog-trial' || $order->next_date !== '2026-12-01 00:00:00') { throw new RuntimeException('Delayed suspension overwrote current active trial'); }
    $order->meta['_wcpprog_regular_payment_received'] = 'yes';
    $status_event->invoke($handler, 'activated', array('resource' => array('id' => 'I-TEST')), 'sandbox');
    if ($order->status !== 'wcpprog-active') { throw new RuntimeException('Regular payment did not end trial status'); }
    $saves = $order->saves;
    \TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Request_API_Injector::$details = false;
    try { $status_event->invoke($handler, 'cancelled', array('resource' => array('id' => 'I-TEST')), 'sandbox'); throw new RuntimeException('Expected retry'); }
    catch (RuntimeException $error) { if ($error->getMessage() !== '503') { throw $error; } }
    if ($order->saves !== $saves || $order->status !== 'wcpprog-active') { throw new RuntimeException('Unverified state changed subscription'); }
    $lock = 'wcpprog_payment_lock_' . md5('sandboxI-TEST');
    $GLOBALS['locks'][$lock] = 1;
    try { $status_event->invoke($handler, 'activated', array('resource' => array('id' => 'I-TEST')), 'sandbox'); throw new RuntimeException('Expected lock retry'); }
    catch (RuntimeException $error) { if ($error->getMessage() !== '503') { throw $error; } }
    unset($GLOBALS['locks'][$lock]);
    $sale = new ReflectionMethod($handler, 'process_subscription_sale');
    $event = array('resource' => array('id' => 'SALE-1', 'billing_agreement_id' => 'I-TEST', 'amount' => array('total' => '20.00', 'currency' => 'USD')));
    $GLOBALS['payments'] = array(new class { public function get_id() { return 10; } });
    $order->meta['_wcpprog_regular_payment_received'] = 'no';
    \TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Request_API_Injector::$details = (object) array('id' => 'I-TEST', 'status' => 'EXPIRED');
    $sale->invoke($handler, 'sale_completed', $event, 'sandbox');
    if ($order->status !== 'wcpprog-expired' || $order->meta['_wcpprog_regular_payment_received'] !== 'no') { throw new RuntimeException('Initial payment replay must synchronize without ending trial marker'); }
    $GLOBALS['payments'] = array(new class { public function get_id() { return 11; } });
    $sale->invoke($handler, 'sale_completed', $event, 'sandbox');
    if ($order->meta['_wcpprog_regular_payment_received'] !== 'yes') { throw new RuntimeException('Renewal replay must repair regular-payment marker'); }
    $GLOBALS['payments'] = array();
    foreach (array(array('total' => '25.00', 'currency' => 'USD'), array('total' => '20.00', 'currency' => 'EUR')) as $amount) {
        $event['resource']['amount'] = $amount;
        try { $sale->invoke($handler, 'sale_completed', $event, 'sandbox'); throw new RuntimeException('Expected mismatch rejection before order creation'); }
        catch (RuntimeException $error) { if ($error->getMessage() !== '503') { throw $error; } }
    }
    if (!empty($GLOBALS['locks'])) { throw new RuntimeException('Lock leaked'); }
    echo "Subscription next-payment regression checks passed.\n";
}
