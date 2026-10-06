<?php
/** Run: php tests/subscription-status-regression.php. No WordPress database required. */
namespace TTHQ\WC_PP_PRO\Lib\PayPal {
    class PayPal_Utils { public static function log(...$args) {} }
}
namespace {
    define('ABSPATH', __DIR__);
    define('WC_PP_PRO_ADDON_PATH', dirname(__DIR__) . '/woocommerce-paypal-pro');
    function add_action(...$args) {}
    function add_filter(...$args) {}
    function _x($text, ...$args) { return $text; }
    function is_admin() { return $GLOBALS['admin']; }
    function get_current_screen() { return $GLOBALS['screen']; }
    function get_current_user_id() { return 5; }
    function absint($value) { return abs((int) $value); }
    function wc_get_order_statuses() {
        return $GLOBALS['handler']->add_statuses_to_list(array('wc-pending' => 'Pending payment', 'wc-completed' => 'Completed', 'wc-active' => 'Active', 'wc-pending-cancel' => 'Pending cancellation'));
    }
    function wc_get_order_status_name($status) { return wc_get_order_statuses()['wc-' . $status] ?? $status; }
    function wc_get_orders($args) {
        $GLOBALS['query'] = $args;
        $found = in_array('wc-wcpprog-active', $args['status'] ?? array_keys(wc_get_order_statuses()), true);
        $orders = $found ? array(new WCPPROG_WC_Subscription_Order()) : array();
        return !empty($args['paginate']) ? (object) array('orders' => $orders, 'max_num_pages' => 1) : $orders;
    }
    function wc_get_template($file, $args, ...$rest) { $GLOBALS['template_args'] = $args; }
    class WC_Order {
        protected function get_valid_statuses() { return array_keys(wc_get_order_statuses()); }
        public function get_id() { return 7; }
    }
    require WC_PP_PRO_ADDON_PATH . '/subscription/class-wcppprog-sub-order-handler.php';
    require WC_PP_PRO_ADDON_PATH . '/subscription/class-wcppprog-sub-order.php';
    require WC_PP_PRO_ADDON_PATH . '/lib/paypal/class-tthq-paypal-webhook-event-handler.php';
    function check($condition, $message) { if (!$condition) { throw new \RuntimeException($message); } }
    $handler = (new \ReflectionClass('WCPPROG_Subscription_Order_Handler'))->newInstanceWithoutConstructor();
    $GLOBALS['handler'] = $handler;
    $GLOBALS['admin'] = true;
    $own = array_keys($handler::get_subscription_statuses());
    foreach (array('shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders', 'shop_subscription', 'edit-shop_subscription', 'woocommerce_page_wc-orders--shop_subscription', 'dashboard', null) as $id) {
        $GLOBALS['screen'] = $id ? (object) array('id' => $id) : null;
        check(!array_intersect($own, array_keys(wc_get_order_statuses())), 'No subscription choices on ' . ($id ?? 'no screen'));
    }
    foreach (array('wcpprog_sub_order', 'edit-wcpprog_sub_order', 'woocommerce_page_wc-orders--wcpprog_sub_order') as $id) {
        $GLOBALS['screen'] = (object) array('id' => $id);
        check($own === array_keys(wc_get_order_statuses()), 'Only our subscription choices on ' . $id);
    }
    // No screen is available during frontend, webhook, cron or most AJAX requests.
    $GLOBALS['screen'] = null;
    $GLOBALS['admin'] = false;
    $valid = new \ReflectionMethod('WCPPROG_WC_Subscription_Order', 'get_valid_statuses');
    $valid->setAccessible(true);
    check($own === $valid->invoke(new WCPPROG_WC_Subscription_Order()), 'Only our statuses are valid even without an admin screen');
    $normal_valid = new \ReflectionMethod('WC_Order', 'get_valid_statuses');
    $normal_valid->setAccessible(true);
    check(!array_intersect($own, $normal_valid->invoke(new WC_Order())), 'Normal orders do not accept subscription statuses outside our screens');
    check($handler::get_subscription_status_name('wcpprog-active') === 'Active', 'Frontend label is readable');
    check($handler::get_subscription_status_name('wc-wcpprog-trial') === 'Trialing', 'Prefixed label is readable');
    check($handler::get_subscription_status_name('completed') === 'Completed', 'Core labels still work');
    $handler->render_subscriptions_list();
    check(count($GLOBALS['template_args']['subscriptions']) === 1, 'Frontend query finds subscriptions without the global status list');
    check(!in_array('trash', $GLOBALS['query']['status'], true), 'Frontend query excludes trash');
    $webhook = (new \ReflectionClass('TTHQ\\WC_PP_PRO\\Lib\\PayPal\\PayPal_Webhook_Event_Handler'))->newInstanceWithoutConstructor();
    $lookup = new \ReflectionMethod($webhook, 'get_subscription_order_by_paypal_sub_id');
    $lookup->setAccessible(true);
    check($lookup->invoke($webhook, 'I-123') instanceof WCPPROG_WC_Subscription_Order, 'Webhook lookup finds subscription without an admin screen');
    echo "Subscription status regression checks passed.\n";
}
