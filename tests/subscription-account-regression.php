<?php
/** Run: php tests/subscription-account-regression.php. No remote calls. */
namespace TTHQ\WC_PP_PRO\Lib\PayPal {
    class PayPal_Utils { public static function log(...$args) {} public static function log_array(...$args) {} }
    class PayPal_Request_API_Injector {
        public static $calls = 0;
        public function get_paypal_subscription_details($id) { ++self::$calls; return (object) array('status' => 'ACTIVE'); }
        public function cancel_paypal_subscription($id) { return false; }
        public function get_last_error_from_api_call() { return array(); }
    }
}
namespace {
    define('ABSPATH', __DIR__);
    define('WC_PP_PRO_ADDON_PATH', dirname(__DIR__) . '/woocommerce-paypal-pro');
    function add_action(...$args) {} function add_filter(...$args) {}
    function __($text, ...$args) { return $text; }
    function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
    function esc_attr($text) { return esc_html($text); }
    function esc_html__($text, ...$args) { return esc_html($text); }
    function esc_html_e($text, ...$args) { echo esc_html($text); }
    function esc_attr_e($text, ...$args) { echo esc_attr($text); }
    function esc_url($text) { return $text; }
    function absint($value) { return abs((int) $value); }
    function get_current_user_id() { return $GLOBALS['uid']; }
    function current_user_can(...$args) { return false; }
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
    function check_ajax_referer($action, $key) { if (!$GLOBALS['nonce_ok']) { throw new RuntimeException('Bad nonce'); } }
    function wp_send_json_error($data, $status = null) { throw new RuntimeException($data['message']); }
    class WC_Order {
        public $owner = 5;
        public $type = 'wcpprog_sub_order';
        public $status = 'wcpprog-active';
        public function get_id() { return 7; }
        public function get_type() { return $this->type; }
        public function get_customer_id() { return $this->owner; }
        public function has_status($status) { return in_array($this->status, (array) $status, true); }
        public function get_status() { return $this->status; }
        public function get_meta($key, $single) { return $key === '_paypal_subscription_id' ? 'I-123<script>' : ''; }
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
    $order = new WC_Order();
    $GLOBALS['orders'] = array(7 => $order);
    $GLOBALS['results'] = (object) array('orders' => array($order), 'max_num_pages' => 3);
    $html = output(fn() => $handler->render_subscriptions_list(2));
    check($GLOBALS['query']['customer_id'] === 5 && $GLOBALS['query']['limit'] === 10 && $GLOBALS['query']['page'] === 2 && $GLOBALS['query']['paginate'], 'Query is paginated and customer-scoped');
    foreach (array('Date', 'I-123&lt;script&gt;', 'page/1', 'page/3', 'view-subscription/7', 'View') as $text) { check(str_contains($html, $text), 'List contains ' . $text); }
    $html = output(fn() => $handler->render_subscription_detail(7));
    check(str_contains($html, 'DETAILS') && str_contains($html, 'Cancel subscription') && $GLOBALS['template_args']['customer_account'], 'Owner gets details and cancellation');
    check(str_contains($html, 'fetch("\/wp-admin\/admin-ajax.php"'), 'Frontend uses explicit AJAX URL');
    $order->status = 'wcpprog-cancelled';
    check(!str_contains(output(fn() => $handler->render_subscription_detail(7)), 'Cancel subscription'), 'Cancelled subscription cannot be cancelled again');
    $order->status = 'wcpprog-active';
    foreach (array(0, 8) as $uid) {
        $GLOBALS['uid'] = $uid;
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
    try { $handler->cancel_subscription(); } catch (RuntimeException $error) {}
    check(\TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Request_API_Injector::$calls === 1 && $order->status === 'wcpprog-active', 'Owner can request cancellation; API failure preserves status');
    $order->type = 'shop_order';
    check(!str_contains(output(fn() => $handler->render_subscription_detail(7)), 'DETAILS'), 'Normal order cannot be viewed as subscription');
    $GLOBALS['wp'] = (object) array('query_vars' => array('subscriptions' => 'view-subscription/7bad'));
    check(str_contains(output(fn() => $handler->render_subscriptions_endpoint_content()), 'Invalid subscription page'), 'Reject malformed detail URL');
    $GLOBALS['results']->orders = array();
    check(str_contains(output(fn() => $handler->render_subscriptions_list()), 'no subscriptions yet'), 'Empty list is explained');
    echo "Subscription account regression checks passed.\n";
}
