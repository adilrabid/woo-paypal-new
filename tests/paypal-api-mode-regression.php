<?php
/** Run: php tests/paypal-api-mode-regression.php. No network requests. */
namespace TTHQ\WC_PP_PRO\Lib\PayPal {
    // Fake only the HTTP boundary; exercise the actual settings and API wrappers.
    class PayPal_Request_API {
        public static $instance;
        public $mode;
        public $credentials;
        public $requests = array();
        public static function get_instance() { return self::$instance ??= new self(); }
        public function set_mode_and_api_credentials($mode, $live_id, $live_secret, $sandbox_id, $sandbox_secret) {
            $this->mode = $mode;
            $this->credentials = $mode === 'sandbox' ? array($sandbox_id, $sandbox_secret) : array($live_id, $live_secret);
        }
        public function get_api_environment_mode() { return $this->mode; }
        public function get($endpoint, $params) {
            $this->requests[] = array($this->mode, $this->credentials, $endpoint);
            return (object) array('id' => 'I-TEST', 'plan_id' => 'P-TEST', 'status' => 'ACTIVE');
        }
    }
    class PayPal_Utils {
        public static function get_option($key) { return $key; }
        public static function log(...$args) {}
    }
    class PayPal_Checkout_Attempt {
        public static function resolve_subscription_order($order) { return $order; }
    }
    class PayPal_Utility_IPN_Related {
        public static function create_subscription_order(...$args) { return (object) array('created' => true); }
    }
}
namespace {
    define('WC_PP_PRO_ADDON_FILE', __FILE__);
    class WC_PP_PRO_Utility { public static function log() {} public static function log_array() {} }
    function get_option($key, $default = array()) { return array(); }
    function is_wp_error($value) { return false; }
    function wp_json_encode($value) { return json_encode($value); }
    function wp_die($message, ...$args) { throw new RuntimeException($message); }
    function wc_get_orders($args) {
        return array(new class {
            public function get_payment_method() { return 'paypal_checkout'; }
            public function get_meta($key, $single) {
                return array('_wcpprog_paypal_plan_id' => 'P-TEST', '_wcpprog_has_trial' => 'no')[$key] ?? '';
            }
            public function has_status($statuses) { return false; }
            public function get_total() { return 10; }
        });
    }
    function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
    $base = __DIR__ . '/../woocommerce-paypal-pro/lib/paypal/';
    require $base . 'class-tthq-paypal-config.php';
    require $base . 'class-tthq-paypal-request-api-injector.php';
    require $base . 'class-tthq-paypal-webhook.php';
    require $base . 'class-tthq-paypal-webhook-event-handler.php';
    $settings = \TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_PPCP_Config::get_instance();
    foreach (array('live_client_id' => 'live-id', 'live_client_secret' => 'live-secret',
        'sandbox_client_id' => 'sandbox-id', 'sandbox_client_secret' => 'sandbox-secret') as $key => $value) {
        $settings->set_value($key, $value);
    }
    $api = \TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Request_API::get_instance();
    $handler = (new ReflectionClass(\TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Webhook_Event_Handler::class))->newInstanceWithoutConstructor();
    foreach (array('yes' => 'sandbox', 'no' => 'production', '' => 'production') as $setting => $expected) {
        $settings->set_value('sandbox', $setting);
        $injector = new \TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Request_API_Injector();
        $injector->get_paypal_subscription_details('I-TEST');
        check($api->mode === $expected, 'Checkout must use the configured environment: ' . $setting);
        $prefix = $expected === 'sandbox' ? 'sandbox' : 'live';
        check($api->credentials === array($prefix . '-id', $prefix . '-secret'), 'Checkout credentials match environment');
        $webhook = new \TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Webhook();
        check($api->mode === $expected && $webhook->get_id() === 'paypal_webhook_id_' . $expected, 'Webhook wrapper uses the configured environment and ID');
        foreach (array('sandbox', 'production') as $event_mode) {
            foreach (array('get_current_subscription_details', 'recover_subscription_order') as $method) {
                $result = (new ReflectionMethod($handler, $method))->invoke($handler, 'I-TEST', $event_mode);
                check((bool) $result, 'Webhook lookup/recovery succeeds');
                $request = end($api->requests);
                $prefix = $event_mode === 'sandbox' ? 'sandbox' : 'live';
                check($request === array($event_mode, array($prefix . '-id', $prefix . '-secret'), '/v1/billing/subscriptions/I-TEST'),
                    'Webhook must query its event environment even when store settings differ: ' . $method);
            }
        }
    }
    echo "PayPal API environment regression checks passed.\n";
}
