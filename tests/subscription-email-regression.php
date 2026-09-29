<?php
/** Run with: php tests/subscription-email-regression.php. No email is delivered. */
namespace TTHQ\WC_PP_PRO\Lib\PayPal {
    class PayPal_Utils { public static function log(...$args) {} }
}
namespace {
    define('ABSPATH', __DIR__);
    define('WC_PP_PRO_ADDON_PATH', dirname(__DIR__) . '/woocommerce-paypal-pro');
    function __($text, ...$args) { return $text; }
    function _n($single, $plural, $count, ...$args) { return $count === 1 ? $single : $plural; }
    function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
    function esc_html_e($text, ...$args) { echo esc_html($text); }
    function esc_html__($text, ...$args) { return esc_html($text); }
    function esc_url($text) { return $text; }
    function wc_get_order($id) { return $GLOBALS['payments'][$id] ?? false; }
    function wc_get_orders($args) { return array_keys($GLOBALS['payments']); }
    function wc_format_datetime($date, $format) { return $date->format($format); }
    function wp_date($format, $timestamp) { return gmdate($format, $timestamp); }
    function wc_price($amount, $args) { return '$' . number_format($amount, 2); }
    function wp_kses_post($text) { return strip_tags($text, '<b><span>'); }
    function absint($value) { return abs((int) $value); }
    function add_action(...$args) {}
    function add_filter(...$args) {}
    function apply_filters($hook, $value, ...$args) { return $value; }
    function current_user_can(...$args) { return $GLOBALS['allowed']; }
    function is_email($text) { return filter_var($text, FILTER_VALIDATE_EMAIL); }
    function WC() { return $GLOBALS['wc']; }
    function wp_specialchars_decode($text, $flags) { return htmlspecialchars_decode($text, $flags); }
    function get_bloginfo($name) { return 'Test Store'; }
    function wc_get_order_status_name($status) { return $status; }
    function wc_date_format() { return 'Y-m-d'; }
    function wc_time_format() { return 'H:i'; }
    function get_date_from_gmt($date, $format) { return '2026-10-27 14:00'; }
    function wc_get_template_html($template, $args, $path, $default) {
        extract($args);
        ob_start();
        include $default . $template;
        return ob_get_clean();
    }
    class WC_Admin_Meta_Boxes {
        public static $errors = array();
        public static function add_error($text) { self::$errors[] = $text; }
    }
    class WC_Order {
        public $type = 'wcpprog_sub_order';
        public $email = 'buyer@example.com';
        public $status = 'wcpprog-active';
        public $notes = array();
        public $meta = array('_paypal_subscription_id' => 'I-123<script>', '_billing_interval' => 2, '_billing_period' => 'month', '_next_payment_date' => '2026-10-27 08:00:00');
        public function get_id() { return 42; }
        public function get_payment_method_title() { return ''; }
        public function get_payment_method() { return ''; }
        public function get_formatted_billing_address() { return 'Buyer Name<br>12 Billing Street'; }
        public function get_formatted_shipping_address() { return 'Recipient Name<br>34 Shipping Street'; }
        public function get_billing_phone() { return '+123456789'; }
        public function get_shipping_phone() { return '+987654321'; }
        public function get_parent_order_id_ref() { return 10; }
        public function get_related_order_ids() { return array(10, 11); }
        public function get_type() { return $this->type; }
        public function get_order_number() { return 'SUB-42'; }
        public function get_billing_email() { return $this->email; }
        public function get_status() { return $this->status; }
        public function has_status($statuses) { return in_array($this->status, (array) $statuses, true); }
        public function get_meta($key, $single) { return $this->meta[$key] ?? ''; }
        public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
        public function save_meta_data() {}
        public function get_meta_data() {
            $data = array();
            foreach ($this->meta as $key => $value) { $data[] = (object) array('key' => $key, 'value' => $value); }
            return $data;
        }
        public function add_order_note($message, ...$args) { $this->notes[] = $message; }
        public function get_items() { return array(new class {
            public function get_name() { return 'Subscription <script>alert(1)</script>'; }
            public function get_quantity() { return 1; }
            public function get_product() { return new WC_Product(); }
        }); }
        public function get_order_item_totals() { return array(array('label' => 'Total:', 'value' => '$25.00')); }
    }
    class WC_Product {
        public static $billing_count = 6;
        public function get_subscription_recurring_billing_count() { return self::$billing_count; }
        public function get_type() { return WCPPROG_Subscription_Related::SUBSCRIPTION_PRODUCT_TYPE; }
        public function get_price_html() { return '$25.00 / month'; }
    }
    class TestPayment extends WC_Order {
        public function __construct(public $id, public $total, public $paid, public $refunds = array()) { $this->type = 'shop_order'; }
        public function get_id() { return $this->id; }
        public function get_order_number() { return 'PAY-' . $this->id; }
        public function get_payment_method_title() { return 'PayPal Checkout'; }
        public function get_total() { return $this->total; }
        public function get_date_paid() { return $this->paid ? new DateTime('2026-09-' . $this->id) : null; }
        public function get_date_created() { return new DateTime('2026-09-01'); }
        public function is_paid() { return $this->paid; }
        public function get_total_refunded() { return $this->refunds ? 5 : 0; }
        public function get_refunds() { return $this->refunds; }
        public function get_transaction_id() { return 'TXN-' . $this->id; }
        public function get_currency() { return 'USD'; }
        public function get_edit_order_url() { return '/wp-admin/order/' . $this->id; }
    }
    $refund = new class {
        public function get_date_created() { return new DateTime('2026-09-12'); }
        public $paypal_id = 'REFUND-1';
        public function get_meta($key, $single) { return $key === '_wcppprog_paypal_refund_id' ? $this->paypal_id : ''; }
        public function get_id() { return 90; }
        public function get_amount() { return 5; }
    };
    $GLOBALS['payments'] = array(10 => new TestPayment(10, 25, true), 11 => new TestPayment(11, 25, true, array($refund)), 12 => new TestPayment(12, 25, false), 13 => new TestPayment(13, 0, true));
    $GLOBALS['allowed'] = true;
    $GLOBALS['wc'] = new class {
        public $mailer;
        public function mailer() { return $this->mailer; }
        public function payment_gateways() { return new class {
            public function payment_gateways() { return array('paypal_checkout' => new class {
                public function get_title() { return 'My Custom Gateway'; }
            }); }
        }; }
    };
    WC()->mailer = new class {
        public $messages = array();
        public $success = true;
        public $fail = false;
        public function wrap_message($heading, $body) { return '<h1>' . $heading . '</h1>' . $body; }
        public function send($recipient, $subject, $message) {
            if ($this->fail) { throw new RuntimeException('Mail transport failed'); }
            $this->messages[] = compact('recipient', 'subject', 'message');
            return $this->success;
        }
    };
    require WC_PP_PRO_ADDON_PATH . '/subscription/class-wcppprog-sub-order-handler.php';
    require WC_PP_PRO_ADDON_PATH . '/subscription/class-wcppprog-sub-related.php';
    function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
    foreach (array(1, 6, 0, '') as $count) {
        WC_Product::$billing_count = $count;
        $plan = WCPPROG_Subscription_Related::get_subscription_plan_data(array('data' => new WC_Product()));
        if ((int) $count > 0) {
            $expected = $count === 1 ? ', stops after 1 recurring payment.' : ', stops after 6 recurring payments.';
            check(str_contains($plan['subscription_plan_html'], $expected), 'Checkout plan includes configured billing count');
        } else {
            check(!str_contains($plan['subscription_plan_html'], 'stops after'), 'Unlimited billing has no finite payment limit');
        }
    }
    WC_Product::$billing_count = 6;
    $handler = new WCPPROG_Subscription_Order_Handler();
    $order = new WC_Order();
    $defaults = array('send_order_details' => 'Invoice', 'regenerate_download_permissions' => 'Downloads');
    check(array_keys($handler->subscription_order_actions($defaults, $order)) === array('wcpprog_send_subscription_information'), 'Replace default subscription actions');
    check($handler->subscription_order_actions($defaults) === $defaults, 'Preserve actions when order is unavailable');
    $order->type = 'shop_order';
    check($handler->subscription_order_actions($defaults, $order) === $defaults, 'Preserve regular order actions');
    $handler->send_subscription_information($order);
    check(!WC()->mailer->messages, 'Regular orders cannot trigger subscription email');
    $order->type = 'wcpprog_sub_order';
    $GLOBALS['allowed'] = false;
    $handler->send_subscription_information($order);
    check(!WC()->mailer->messages, 'Sending requires order editing permission');
    $GLOBALS['allowed'] = true;
    $handler->send_subscription_information($order);
    $message = WC()->mailer->messages[0];
    check($message['recipient'] === 'buyer@example.com', 'Use stored customer billing email');
    foreach (array('SUB-42', 'I-123&lt;script&gt;', 'Every 2 months', '2026-10-27 14:00', 'Payment Gateway', 'PayPal Checkout', 'Subscription ID', 'Subscription plan', '$25.00 / month', ', stops after 6 recurring payments.', 'Excluding applicable tax, shipping, coupon discounts and other fees!', 'Received Payments', 'Initial Payment', 'Recurring Payment', 'TXN-10', 'TXN-11', 'REFUND-1', '$5.00') as $text) {
        check(str_contains($message['message'], $text), 'Include subscription information: ' . $text);
    }
    foreach (array('Initial checkout amounts', 'PayPal subscription ID', '/wp-admin/', 'TXN-12', 'TXN-13') as $text) {
        check(!str_contains($message['message'], $text), 'Exclude obsolete sections, admin links and unpaid/zero orders: ' . $text);
    }
    check(substr_count($message['message'], 'TXN-10') === 1, 'Deduplicate payment links');
    check(strpos($message['message'], 'TXN-11') < strpos($message['message'], 'TXN-10'), 'Show most recent payments first');
    ob_start();
    $handler->render_payment_history_meta_box($order);
    $admin_table = ob_get_clean();
    check(str_contains($admin_table, '/wp-admin/order/10') && str_contains($admin_table, 'REFUND-1'), 'Admin table retains links and refund details');
    check(!str_contains($message['message'], '<script>'), 'Escape customer/product content');
    foreach (array('My Custom Gateway', 'Customer information', 'Email', 'buyer@example.com', 'Billing address', '12 Billing Street', 'Shipping address', '34 Shipping Street', '+123456789', '+987654321') as $text) {
        check(str_contains($message['message'], $text), 'Include configured gateway title and contact details: ' . $text);
    }
    check(str_contains(end($order->notes), 'email sent'), 'Record success');
    $order->status = 'wcpprog-cancelled';
    $handler->send_subscription_information($order);
    check(!str_contains(WC()->mailer->messages[1]['message'], 'Next payment'), 'Do not advertise stale next payment on cancelled subscription');
    $order->email = '';
    $handler->send_subscription_information($order);
    check(count(WC()->mailer->messages) === 2 && count(WC_Admin_Meta_Boxes::$errors) === 1, 'Invalid recipient reports failure without sending');
    $order->email = 'buyer@example.com';
    WC()->mailer->success = false;
    $handler->send_subscription_information($order);
    check(str_contains(end($order->notes), 'could not be sent'), 'Do not report failed send as success');
    WC()->mailer->fail = true;
    $handler->send_subscription_information($order);
    check(count(WC_Admin_Meta_Boxes::$errors) === 3, 'Transport exceptions are reported safely');
    $saved_payments = $GLOBALS['payments'];
    $GLOBALS['payments'] = array();
    ob_start();
    WCPPROG_Subscription_Order_Handler::render_payment_history_table($order, true);
    $archived = ob_get_clean();
    foreach (array('TXN-10', 'TXN-11', 'REFUND-1', 'Initial Payment', 'Recurring Payment') as $text) {
        check(str_contains($archived, $text), 'Keep deleted payment information: ' . $text);
    }
    check(!str_contains($archived, 'TXN-12') && !str_contains($archived, 'TXN-13'), 'Unpaid and zero snapshots stay hidden');
    ob_start();
    $handler->render_payment_history_meta_box($order);
    check(!str_contains(ob_get_clean(), '/wp-admin/'), 'Deleted payment snapshots have no admin links');
    ob_start();
    WCPPROG_Subscription_Order_Handler::render_payment_history_table(new WC_Order(), true);
    check(str_contains(ob_get_clean(), 'No received payments have been recorded yet.'), 'Render empty payment history');

    // Saves update a linked subscription even when nobody views the history.
    $GLOBALS['payments'] = $saved_payments;
    $GLOBALS['payments'][42] = $order;
    $saved_payments[10]->meta['_wcpprog_subscription_order_id'] = 42;
    $saved_payments[10]->total = 30;
    WCPPROG_Subscription_Payment_History::order_saved($saved_payments[10]);
    check($order->meta['_wcppprog_payment_snapshot_10']['amount'] === 30, 'Refresh amount on order save');
    $refund->paypal_id = '';
    $saved_payments[10]->refunds = array($refund);
    WCPPROG_Subscription_Payment_History::refund_saved(new class {
        public function get_parent_id() { return 10; }
    });
    check(count($order->meta['_wcppprog_payment_snapshot_10']['refunds']) === 1, 'Refresh refund details on refund save');
    check($order->meta['_wcppprog_payment_snapshot_10']['refunds'][0]['id'] === '#90', 'Refund without PayPal metadata uses local ID without an order-only method');
    ob_start();
    $handler->render_payment_history_meta_box($order);
    check(str_contains(ob_get_clean(), '#90'), 'Render refunds before the webhook attaches PayPal metadata');
    $refund->paypal_id = 'REFUND-1';
    WCPPROG_Subscription_Payment_History::refresh_order(10);
    check($order->meta['_wcppprog_payment_snapshot_10']['refunds'][0]['id'] === 'REFUND-1', 'Persist PayPal refund ID once available');
    $saved_payments[10]->refunds = array();
    $GLOBALS['payments'][90] = new class {
        public function get_type() { return 'shop_order_refund'; }
        public function get_parent_id() { return 10; }
    };
    WCPPROG_Subscription_Payment_History::before_delete(90);
    unset($GLOBALS['payments'][90]);
    WCPPROG_Subscription_Payment_History::after_delete(90);
    check(!$order->meta['_wcppprog_payment_snapshot_10']['refunds'], 'Deleting an individual refund refreshes the snapshot');
    $saved_payments[10]->refunds = array($refund);
    WCPPROG_Subscription_Payment_History::before_delete(10);
    $saved_payments[10]->refunds = array();
    WCPPROG_Subscription_Payment_History::refresh_order(10);
    check(count($order->meta['_wcppprog_payment_snapshot_10']['refunds']) === 1, 'Parent deletion preserves refunds while child records are removed');
    unset($GLOBALS['payments'][10]);
    $rows = WCPPROG_Subscription_Payment_History::get_rows($order);
    $initial = array_values(array_filter($rows, static fn($row) => $row['order_id'] === 10))[0];
    check($initial['amount'] === 30 && !$initial['edit_url'], 'Deleted initial payment uses its latest saved amount');
    echo "Subscription email regression checks passed.\n";
}
