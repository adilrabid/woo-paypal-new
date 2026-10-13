<?php
/** Run: php tests/paypal-subscription-retry-regression.php */
namespace TTHQ\WC_PP_PRO\Lib\PayPal {
    class PayPal_Request_API_Injector {
        public function get_paypal_subscription_details($id) {
            if (isset($GLOBALS['during_lookup'])) { ($GLOBALS['during_lookup'])(); }
            return $GLOBALS['remote'];
        }
        public function get_paypal_order_details($id) { return $GLOBALS['remote']; }
        public function get_last_error_from_api_call() { return array('http_code' => $GLOBALS['http_code']); }
        public function cancel_paypal_subscription($id) { throw new \RuntimeException('Pending subscriptions must never be cancelled'); }
        public function create_paypal_subscription_for_billing_plan(...$args) { throw new \RuntimeException('Recovery must not create a remote subscription'); }
    }
    class PayPal_Utils {
        public static function log(...$args) { $GLOBALS['retry_logs'][] = $args[0]; }
    }
}
namespace {
    require __DIR__ . '/paypal-lock-fixture.php';
    class WP_Error {
        public function __construct(public $code, public $message) {}
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
    }
    function is_wp_error($value) { return $value instanceof WP_Error; }
    function __($message, $domain) { return $message; }
    function absint($value) { return abs((int) $value); }
    function get_current_user_id() { return $GLOBALS['uid'] ?? 7; }
    function WC() { return $GLOBALS['wc']; }
    function wc_get_order($id) { return isset($GLOBALS['orders'][$id]) ? clone $GLOBALS['orders'][$id] : false; }
    function wc_get_orders($args) {
        if ($args['type'] === 'wcpprog_sub_order') { return $GLOBALS['local_subscriptions'] ?? array(); }
        $found = array();
        foreach ($GLOBALS['orders'] as $order) {
            if ($order->get_meta($args['meta_key']) == $args['meta_value']) { $found[] = clone $order; }
        }
        return $args['limit'] === 1 ? array_slice($found, 0, 1) : $found;
    }
    function sanitize_text_field($value) { return $value; }
    function wp_die($message, $title = '', $args = array()) { throw new RuntimeException('HTTP ' . ($args['response'] ?? 500)); }
    function wp_json_encode($value) { return json_encode($value); }
    function wc_get_order_statuses() { return array('wc-pending' => 'Pending'); }
    class WCPPROG_Subscription_Order_Handler {
        const ORDER_TYPE = 'wcpprog_sub_order';
        public static function get_subscription_statuses() { return array('wc-wcpprog-active' => 'Active'); }
    }
    class Retry_Order {
        public $status = 'pending';
        public $paid = null;
        public $transaction = '';
        public $customer = 7;
        public $meta = array('_wcpprog_checkout_fingerprint' => 'matching-cart', '_wcppprog_paypal_subscription_id' => 'I-TEST',
            '_wcpprog_paypal_plan_id' => 'P-TEST', '_wcpprog_has_trial' => 'no', '_wcpprog_initial_payment_pending' => 'yes',
            '_wcppprog_recurring_breakdown' => array('props' => array('currency' => 'USD', 'total' => '20.00'), 'items' => array(array('type' => 'line_item', 'data' => array('quantity' => 1, 'total' => '20.00'), 'meta' => array()))));
        public function __construct(public $id = 42) {}
        public function get_id() { return $this->id; }
        public function get_status() { return $this->status; }
        public function get_type() { return 'shop_order'; }
        public function get_payment_method() { return 'paypal_checkout'; }
        public function get_customer_id() { return $this->customer; }
        public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
        public function has_status($statuses) { return in_array($this->status, (array) $statuses, true); }
        public function get_date_paid() { return $this->paid; }
        public function get_transaction_id() { return $this->transaction; }
        public function get_total() { return 20; }
        public function get_currency() { return 'USD'; }
        public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
        public function save() {
            if (isset($GLOBALS['before_save'])) { ($GLOBALS['before_save'])($this); }
            $GLOBALS['orders'][$this->id] = clone $this;
        }
        public function add_order_note($note) { $GLOBALS['notes'][$this->id][] = $note; }
    }
    function check($condition, $message) { if (!$condition) { throw new \RuntimeException($message); } }
    require __DIR__ . '/../woocommerce-paypal-pro/lib/paypal/class-tthq-paypal-lock.php';
    require __DIR__ . '/../woocommerce-paypal-pro/lib/paypal/class-tthq-paypal-checkout-attempt.php';
    require __DIR__ . '/../woocommerce-paypal-pro/lib/paypal/class-tthq-paypal-button-sub-ajax-handler.php';
    require __DIR__ . '/../woocommerce-paypal-pro/lib/paypal/class-tthq-paypal-webhook-event-handler.php';
    require __DIR__ . '/../woocommerce-paypal-pro/lib/paypal/class-tthq-paypal-utils-ipn-related.php';
    use TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Checkout_Attempt as Attempt;
    use TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Button_Sub_Ajax_Handler as Handler;
    $GLOBALS['wc'] = (object) array('cart' => new class { public function empty_cart() {} }, 'session' => new class {
        public $values = array();
        public function get($key) { return $this->values[$key] ?? 42; }
        public function set($key, $value) { $this->values[$key] = $value; }
    });
    function reset_attempt() {
        $GLOBALS['orders'] = array(); $GLOBALS['notes'] = array(); $GLOBALS['created'] = 0; $GLOBALS['locks'] = array();
        unset($GLOBALS['before_save'], $GLOBALS['during_lookup']);
        $GLOBALS['http_code'] = 0; $GLOBALS['uid'] = 7;
        $GLOBALS['remote'] = (object) array('id' => 'I-TEST', 'plan_id' => 'P-TEST', 'status' => 'APPROVAL_PENDING',
            'subscriber' => (object) array('payer_id' => 'BUYER'),
            'billing_info' => (object) array('cycle_executions' => array((object) array('cycles_completed' => 0))));
        $order = new Retry_Order(); $order->status = 'cancelled'; $order->save();
        return $order;
    }
    $create = static function($source) {
        ++$GLOBALS['created'];
        $new = new Retry_Order(42 + $GLOBALS['created']); $new->customer = $source->customer;
        $new->meta = array('_wcpprog_recovery_source_order_id' => $source->get_id());
        $new->save();
        return $new;
    };
    $old = reset_attempt();
    check(Attempt::get_order('subscription', 'matching-cart') === false, 'Cancelled order cannot resume normally');
    check(Attempt::get_approval_id($old, 'subscription')->code === 'paypal_retry_replace_order', 'Cancelled pending approval requires local replacement');
    $new = Attempt::replace_cancelled_subscription_order($old, 'matching-cart', 'sandbox', $create);
    check($new instanceof Retry_Order && $new->id === 43, 'Create a new local order');
    check(wc_get_order(42)->status === 'cancelled' && $new->status === 'pending', 'Keep original cancelled and replacement pending');
    check($new->meta['_wcppprog_recurring_breakdown'] === $old->meta['_wcppprog_recurring_breakdown'], 'Preserve original recurring snapshot');
    check(Attempt::get_approval_id($new, 'subscription') === 'I-TEST', 'Resume the same remote approval despite payer identity');
    check(Attempt::get_order('subscription', 'matching-cart', true)->id === 43, 'Stale session follows replacement');
    $again = Attempt::replace_cancelled_subscription_order($old, 'matching-cart', 'sandbox', $create);
    check($again->id === 43 && $GLOBALS['created'] === 1, 'Repeated recovery must reuse replacement');
    check(Attempt::get_order('subscription', 'different-cart', true) === false, 'Changed cart must not follow unrelated attempt');
    // Webhooks and callbacks must resolve the replacement, including stale guest sessions.
    $webhook = (new ReflectionClass(\TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Webhook_Event_Handler::class))->newInstanceWithoutConstructor();
    $lookup = new ReflectionMethod($webhook, 'get_subscription_checkout_order');
    check($lookup->invoke($webhook, 'I-TEST')->id === 43, 'Webhook must select replacement rather than earliest cancelled parent');
    $GLOBALS['remote']->status = 'ACTIVE';
    $handler = (new ReflectionClass(Handler::class))->newInstanceWithoutConstructor(); $txn = array();
    check($handler->validate_subscription_checkout_txn_data(array('subscriptionID' => 'I-TEST'), $txn) === true, 'Approval validates against replacement');
    $GLOBALS['uid'] = 0;
    foreach ($GLOBALS['orders'] as $order) { $order->customer = 0; }
    check($handler->validate_subscription_checkout_txn_data(array('subscriptionID' => 'I-TEST'), $txn) === true, 'Guest original session can follow its replacement');
    $GLOBALS['local_subscriptions'] = array(new class { public function get_id() { return 99; } });
    $completed = \TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Utility_IPN_Related::complete_post_subscription_payment_processing(array('subscriptionID' => 'I-TEST', 'orderID' => 'PAYPAL-ORDER'), array(), array());
    check($completed->get_id() === 43 && wc_get_order(43)->get_meta('_wcpprog_subscription_order_id') === 99, 'Approval processing links local subscription to replacement');
    check(!wc_get_order(42)->get_meta('_wcpprog_subscription_order_id') && !wc_get_order(42)->get_meta('_paypal_order_id'), 'Approval processing must leave cancelled parent untouched');
    WC()->session->set('wcpprog_checkout_attempt_subscription', 42);
    check(Attempt::get_order('subscription', 'matching-cart', true)->get_meta('_wcpprog_subscription_order_id') === 99, 'Webhook-first completion remains redirectable');
    // Each completed transfer remains recoverable if the replacement is later cancelled.
    $old = reset_attempt(); $new = Attempt::replace_cancelled_subscription_order($old, 'matching-cart', 'sandbox', $create);
    $new->status = 'failed'; $new->save();
    $third = Attempt::replace_cancelled_subscription_order($new, 'matching-cart', 'sandbox', $create);
    check($third->id === 44 && Attempt::resolve_subscription_order(wc_get_order(42))->id === 44, 'Follow multiple replacements without reopening old orders');
    // Fresh webhook/approval lock excludes recovery and recovery excludes another callback.
    $old = reset_attempt(); $key = 'wcpprog_payment_lock_' . md5('sandboxI-TEST'); $GLOBALS['locks'][$key] = time() . ':webhook';
    check(is_wp_error(Attempt::replace_cancelled_subscription_order($old, 'matching-cart', 'sandbox', $create)) && !$GLOBALS['created'], 'Busy webhook must prevent recovery');
    unset($GLOBALS['locks'][$key]);
    $with_competitor = static function($source) use ($create) {
        $competing = Attempt::with_subscription_lock('I-TEST', 'sandbox', static function() { throw new RuntimeException('Competitor entered'); });
        check(is_wp_error($competing), 'Recovery must exclude approval using the same lock');
        return $create($source);
    };
    check(!is_wp_error(Attempt::replace_cancelled_subscription_order($old, 'matching-cart', 'sandbox', $with_competitor)), 'Recovery succeeds after lock release');
    // A failed publish reuses its fully saved candidate on retry.
    $old = reset_attempt();
    $GLOBALS['before_save'] = static function($order) { if ($order->id === 42 && $order->get_meta('_wcpprog_replacement_order_id')) { throw new RuntimeException('Interrupted publication'); } };
    check(is_wp_error(Attempt::replace_cancelled_subscription_order($old, 'matching-cart', 'sandbox', $create)), 'Publication failure must stop checkout');
    check(!$GLOBALS['locks'] && !wc_get_order(42)->get_meta('_wcpprog_replacement_order_id'), 'Failed publication releases lock and keeps original mapping');
    unset($GLOBALS['before_save']);
    check(Attempt::replace_cancelled_subscription_order($old, 'matching-cart', 'sandbox', $create)->id === 43 && $GLOBALS['created'] === 1, 'Interrupted recovery reuses staged order');
    // Remote approval/payment during creation must prevent publishing the replacement.
    $old = reset_attempt();
    $race = static function($source) use ($create) { $new = $create($source); $GLOBALS['remote']->status = 'ACTIVE'; return $new; };
    check(is_wp_error(Attempt::replace_cancelled_subscription_order($old, 'matching-cart', 'sandbox', $race)), 'Active race must block transfer');
    check(!wc_get_order(42)->get_meta('_wcpprog_replacement_order_id'), 'Do not publish after approval race');
    foreach (array('processing', 'completed', 'refunded', 'trash', 'on-hold') as $status) {
        $old = reset_attempt(); $old->status = $status; $old->save();
        check(is_wp_error(Attempt::replace_cancelled_subscription_order($old, 'matching-cart', 'sandbox', $create)) && !$GLOBALS['created'], 'Do not replace ineligible order: ' . $status);
    }
    foreach (array('paid', 'transaction', '_paypal_transaction_id', '_wcpprog_subscription_order_id') as $marker) {
        $old = reset_attempt();
        if (property_exists($old, $marker)) { $old->$marker = 'recorded'; } else { $old->meta[$marker] = 'recorded'; } $old->save();
        check(is_wp_error(Attempt::replace_cancelled_subscription_order($old, 'matching-cart', 'sandbox', $create)) && !$GLOBALS['created'], 'Recorded payment/link must block recovery: ' . $marker);
    }
    foreach (array('APPROVED', 'ACTIVE', 'SUSPENDED') as $status) {
        $old = reset_attempt(); $GLOBALS['remote']->status = $status;
        check(is_wp_error(Attempt::replace_cancelled_subscription_order($old, 'matching-cart', 'sandbox', $create)) && !$GLOBALS['created'], 'Never transfer an approved remote subscription');
    }
    foreach (array('last_payment', 'cycles') as $activity) {
        $old = reset_attempt();
        if ($activity === 'last_payment') { $GLOBALS['remote']->billing_info->last_payment = (object) array('amount' => 20); }
        else { $GLOBALS['remote']->billing_info->cycle_executions[0]->cycles_completed = 1; }
        check(is_wp_error(Attempt::replace_cancelled_subscription_order($old, 'matching-cart', 'sandbox', $create)) && !$GLOBALS['created'], 'Remote payment history blocks transfer');
    }
    $old = reset_attempt(); $GLOBALS['uid'] = 8;
    check(is_wp_error(Attempt::replace_cancelled_subscription_order($old, 'matching-cart', 'sandbox', $create)) && !$GLOBALS['created'], 'Ownership must be rechecked under lock');
    $old = reset_attempt();
    check(is_wp_error(Attempt::replace_cancelled_subscription_order($old, 'other-cart', 'sandbox', $create)) && !$GLOBALS['created'], 'Fingerprint must be rechecked under lock');
    check(is_wp_error(Attempt::replace_cancelled_subscription_order($old, 'matching-cart', 'sandbox', static function() { return false; })), 'Failed order creation keeps original approval recoverable');
    $GLOBALS['remote']->status = 'ACTIVE';
    $recover = new ReflectionMethod($webhook, 'recover_subscription_order');
    try { $recover->invoke($webhook, 'I-TEST'); throw new RuntimeException('Closed order was accepted by webhook'); }
    catch (RuntimeException $error) { check($error->getMessage() === 'HTTP 503', 'Webhook must not attach an active subscription to cancelled parent'); }
    $old->meta['_wcpprog_replacement_order_id'] = 999; $old->save();
    check(Attempt::get_order('subscription', 'matching-cart', true)->id === 42 && is_wp_error(Attempt::get_approval_id($old, 'subscription')), 'Broken replacement must block instead of creating a remote subscription');
    try { $lookup->invoke($webhook, 'I-TEST'); throw new RuntimeException('Broken mapping was ignored'); }
    catch (RuntimeException $error) { check($error->getMessage() === 'HTTP 503', 'Webhook must retry broken replacement links'); }
    check(!$GLOBALS['locks'], 'No leaked locks');
    $logs = implode("\n", $GLOBALS['retry_logs']);
    check(str_contains($logs, 'replacement_order_ready') && !str_contains($logs, 'BUYER'), 'Useful logs omit payer identity');
    echo "Subscription retry regression checks passed.\n";
}
