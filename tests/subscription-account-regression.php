<?php
/** Run: php tests/subscription-account-regression.php. No remote calls. */
namespace TTHQ\WC_PP_PRO\Lib\PayPal {
    class PayPal_Utils { public static function log(...$args) {} public static function log_array(...$args) {} }
    class PayPal_Request_API_Injector {
        public static $calls = 0;
        public static $statuses = array('ACTIVE');
        public static $cancel_calls = 0;
        public static $cancel_success = false;
        public function get_paypal_subscription_details($id) { ++self::$calls; $status = count(self::$statuses) > 1 ? array_shift(self::$statuses) : self::$statuses[0]; return $status ? (object) array('status' => $status) : false; }
        public function cancel_paypal_subscription($id) { ++self::$cancel_calls; return self::$cancel_success; }
        public function get_last_error_from_api_call() { return array(); }
    }
}
namespace {
    define('ABSPATH', __DIR__);
    define('WC_PP_PRO_ADDON_PATH', dirname(__DIR__) . '/woocommerce-paypal-pro');
    function add_action(...$args) { $GLOBALS['hooks'][$args[0]] = $args[1]; } function add_filter(...$args) {}
    function __($text, ...$args) { return $text; }
    function _x($text, ...$args) { return $text; }
    function wc_get_order_statuses() { return array('wc-pending' => 'Pending payment', 'wc-completed' => 'Completed'); }
    function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
    function esc_attr($text) { return esc_html($text); }
    function esc_html__($text, ...$args) { return esc_html($text); }
    function esc_html_e($text, ...$args) { echo esc_html($text); }
    function esc_attr_e($text, ...$args) { echo esc_attr($text); }
    function esc_url($text) { return $text; }
    function absint($value) { return abs((int) $value); }
    function get_current_user_id() { return $GLOBALS['uid']; }
    function current_user_can(...$args) { return false; }
    function is_admin() { return $GLOBALS['admin'] ?? false; }
    class WC_AJAX { public static function get_endpoint($action) { return '/?wc-ajax=' . $action; } }
    function is_account_page() { return true; }
    function is_wc_endpoint_url($endpoint) { return $GLOBALS['view_order'] ?? false; }
    function wc_get_order($id) { return $GLOBALS['orders'][$id] ?? false; }
    function wc_get_orders($args) { $GLOBALS['query'] = $args; return $GLOBALS['results']; }
    function wc_print_notice($text, $type) { echo $text; }
    function wc_get_page_permalink($page) { return '/my-account/'; }
    function wc_get_endpoint_url($endpoint, $value, $url) { return $url . $endpoint . '/' . $value; }
    function wc_get_account_endpoint_url($endpoint) { return '/my-account/' . $endpoint . '/'; }
    function wc_get_order_status_name($status) { return $status; }
    function wc_get_template($file, $args, $path, $default) {
        $GLOBALS['template_args'] = $args;
        if (str_starts_with($file, 'myaccount/')) { extract($args); include $default . $file; }
        else { echo 'DETAILS'; }
    }
    function wp_json_encode($value) { return json_encode($value); }
    function wp_create_nonce($action) { return 'nonce'; }
    function admin_url($path) { return '/wp-admin/' . $path; }
    function check_ajax_referer($action, $key, $die = true) { if (!$GLOBALS['nonce_ok'] && $die) { throw new RuntimeException('Invalid nonce'); } return $GLOBALS['nonce_ok']; }
    function wp_send_json_success() { $GLOBALS['success'] = true; throw new RuntimeException('JSON success'); }
    function wp_send_json_error($data, $status = null) { throw new RuntimeException($data['message']); }
    class WC_Order {
        public $meta = array();
        public $next_payment = '2026-11-05';
        public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
        public function set_next_payment_date($date) { $this->next_payment = $date; }
        public function update_status($status, $note) { $this->status = $status; }
        public $owner = 5;
        public $type = 'wcpprog_sub_order';
        public $status = 'wcpprog-active';
        public function get_id() { return 7; }
        public function get_type() { return $this->type; }
        public function get_customer_id() { return $this->owner; }
        public function has_status($status) { return in_array($this->status, (array) $status, true); }
        public function get_status() { return $this->status; }
        public function get_meta($key, $single) { return $key === '_wcppprog_paypal_subscription_id' ? 'I-123<script>' : ''; }
        public function get_paypal_subscription_id() { return 'I-123<script>'; }
        public function get_date_created() { return null; }
        public function get_order_number() { return '7'; }
    }
    require WC_PP_PRO_ADDON_PATH . '/subscription/class-wcppprog-sub-order-handler.php';
    function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
    function output($callback) { ob_start(); $callback(); return ob_get_clean(); }
    $handler = new WCPPROG_Subscription_Order_Handler();
    $GLOBALS['uid'] = 5;
    $GLOBALS['nonce_ok'] = true;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['nonce'] = 'nonce';
    $order = new WC_Order();
    $GLOBALS['orders'] = array(7 => $order);
    $GLOBALS['results'] = (object) array('orders' => array($order), 'max_num_pages' => 3);
    $html = output(fn() => $handler->render_subscriptions_list(2));
    check($GLOBALS['query']['customer_id'] === 5 && $GLOBALS['query']['limit'] === 10 && $GLOBALS['query']['page'] === 2 && $GLOBALS['query']['paginate'], 'Query is paginated and customer-scoped');
    foreach (array('Date', 'I-123&lt;script&gt;', '/wcppprog-subscriptions/page/1', '/wcppprog-subscriptions/page/3', '/wcppprog-subscriptions/view-subscription/7', 'View') as $text) { check(str_contains($html, $text), 'List contains ' . $text); }
    $html = output(fn() => $handler->render_subscription_detail(7));
    check(str_contains($html, 'DETAILS') && str_contains($html, 'Cancel subscription') && $GLOBALS['template_args']['customer_account'], 'Owner gets details and cancellation');
    check(str_contains($html, 'fetch("\/wp-admin\/admin-ajax.php"'), 'Customer uses authenticated AJAX endpoint');
    check(isset($GLOBALS['hooks']['wp_ajax_wcpprog_cancel_subscription']), 'Frontend cancellation handler is registered');
    $GLOBALS['admin'] = true;
    check(str_contains(output(fn() => $handler->render_subscription_manage_meta_box($order)), 'fetch("\/wp-admin\/admin-ajax.php"'), 'Admin keeps admin AJAX endpoint');
    $GLOBALS['admin'] = false;
    $order->status = 'wcpprog-cancelled';
    check(!str_contains(output(fn() => $handler->render_subscription_detail(7)), 'Cancel subscription'), 'Cancelled subscription cannot be cancelled again');
    $order->status = 'wcpprog-active';
    foreach (array(0, 8) as $uid) {
        $GLOBALS['uid'] = $uid;
        check(output(fn() => $handler->render_subscription_manage_meta_box($order)) === '', 'Guest or different customer cannot render cancellation controls');
        check(!str_contains(output(fn() => $handler->render_subscription_detail(7)), 'DETAILS'), 'Guest or different customer cannot read details');
        $_POST['order_id'] = 7;
        try { $handler->cancel_subscription(); } catch (RuntimeException $error) {}
        check(\TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Request_API_Injector::$calls === 0, 'Unauthorized cancellation never calls PayPal');
    }
    $GLOBALS['uid'] = 0;
    unset($GLOBALS['query']);
    output(fn() => $handler->render_subscriptions_list());
    check(!isset($GLOBALS['query']), 'Guest cannot query unowned subscription records');
    $GLOBALS['uid'] = 5;
    $GLOBALS['nonce_ok'] = false;
    try { $handler->cancel_subscription(); } catch (RuntimeException $error) {}
    check(\TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Request_API_Injector::$calls === 0, 'Nonce failure stops cancellation');
    $GLOBALS['nonce_ok'] = true;
    foreach (array(array('7'), '-7', '7invalid', '0') as $invalid_id) {
        $_POST['order_id'] = $invalid_id;
        try { $handler->cancel_subscription(); } catch (RuntimeException $error) {}
        check(\TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Request_API_Injector::$calls === 0, 'Malformed order ID never reaches PayPal');
    }
    $_POST['order_id'] = 7;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    try { $handler->cancel_subscription(); } catch (RuntimeException $error) {}
    check(\TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Request_API_Injector::$calls === 0, 'GET request never reaches PayPal');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    unset($_POST['nonce']);
    try { $handler->cancel_subscription(); } catch (RuntimeException $error) {}
    check(\TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Request_API_Injector::$calls === 0, 'Missing nonce never reaches PayPal');
    $_POST['nonce'] = 'nonce';
    foreach (array('wcpprog-cancelled', 'wcpprog-expired', 'trash') as $status) {
        $order->status = $status;
        try { $handler->cancel_subscription(); } catch (RuntimeException $error) {}
        check(\TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Request_API_Injector::$calls === 0, 'Unavailable subscription never reaches PayPal');
    }
    $order->status = 'wcpprog-active';
    $order->type = 'shop_order';
    try { $handler->cancel_subscription(); } catch (RuntimeException $error) {}
    check(\TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Request_API_Injector::$calls === 0, 'Regular order never reaches PayPal cancellation');
    $order->type = 'wcpprog_sub_order';
    try { $handler->cancel_subscription(); } catch (RuntimeException $error) {}
    check(\TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Request_API_Injector::$calls === 2 && $order->status === 'wcpprog-active', 'Owner can request cancellation; API failure preserves status');
    foreach (array(
        array(array('CANCELLED'), false, 'wcpprog-cancelled', 0, true),
        array(array('EXPIRED'), false, 'wcpprog-expired', 0, true),
        array(array('ACTIVE', 'EXPIRED'), false, 'wcpprog-expired', 1, true),
        array(array('ACTIVE', 'CANCELLED'), false, 'wcpprog-cancelled', 1, true),
        array(array('ACTIVE'), true, 'wcpprog-cancelled', 1, true),
        array(array('SUSPENDED'), false, 'wcpprog-on-hold', 1, false),
        array(array(false), false, 'wcpprog-active', 1, false),
        array(array('UNKNOWN'), false, 'wcpprog-active', 1, false),
    ) as [$statuses, $cancel_success, $expected, $cancel_calls, $success]) {
        \TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Request_API_Injector::$statuses = $statuses;
        \TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Request_API_Injector::$cancel_success = $cancel_success;
        \TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Request_API_Injector::$cancel_calls = 0;
        $order->status = 'wcpprog-active';
        $order->meta = array();
        $order->next_payment = '2026-11-05';
        $GLOBALS['success'] = false;
        try { $handler->cancel_subscription(); } catch (RuntimeException $error) {}
        check($order->status === $expected, 'Synchronize confirmed PayPal status: ' . $expected);
        check(\TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Request_API_Injector::$cancel_calls === $cancel_calls, 'Skip cancellation for terminal subscriptions');
        check($GLOBALS['success'] === $success, 'Only confirmed cancellation/expiry returns success');
        check(($order->next_payment === '') === $success, 'Clear next payment only for terminal subscriptions');
        if ($success) { check($order->meta['_paypal_subscription_status'] === ($expected === 'wcpprog-expired' ? 'EXPIRED' : 'CANCELLED'), 'Save corresponding PayPal status'); }
    }
    $order->type = 'shop_order';
    check(!str_contains(output(fn() => $handler->render_subscription_detail(7)), 'DETAILS'), 'Normal order cannot be viewed as subscription');
    $GLOBALS['wp'] = (object) array('query_vars' => array('wcppprog-subscriptions' => 'view-subscription/7bad'));
    check(str_contains(output(fn() => $handler->render_subscriptions_endpoint_content()), 'Invalid subscription page'), 'Reject malformed detail URL');
    $GLOBALS['results']->orders = array();
    check(str_contains(output(fn() => $handler->render_subscriptions_list()), 'no subscriptions yet'), 'Empty list is explained');
    $GLOBALS['view_order'] = true;
    $order->type = 'wcpprog_sub_order';
    $payment = new class extends WC_Order {
        public $type = 'shop_order';
        public function get_meta($key, $single) { return $key === '_wcpprog_subscription_order_id' ? 7 : ''; }
    };
    $link = output(fn() => $handler->render_customer_order_subscription_link($payment));
    check(str_contains($link, 'View Subscription #7') && str_contains($link, '/wcppprog-subscriptions/view-subscription/7'), 'Linked payment shows customer subscription URL');
    $order->owner = 9;
    check(output(fn() => $handler->render_customer_order_subscription_link($payment)) === '', 'Hide subscriptions owned by another customer');
    $order->owner = 5;
    $payment->owner = 9;
    check(output(fn() => $handler->render_customer_order_subscription_link($payment)) === '', 'Hide link on another customer payment order');
    $payment->owner = 5;
    $GLOBALS['view_order'] = false;
    check(output(fn() => $handler->render_customer_order_subscription_link($payment)) === '', 'Limit link to account order detail endpoint');
    echo "Subscription account regression checks passed.\n";
}
