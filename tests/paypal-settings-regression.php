<?php
/** Run: php tests/paypal-settings-regression.php. No WordPress or network required. */
define('ABSPATH', __DIR__);
define('WC_PP_PRO_ADDON_PATH', __DIR__ . '/../woocommerce-paypal-pro');

// Minimal WooCommerce settings boundary, including its non-null checkbox rule.
class WC_Payment_Gateway {
    public $settings = array();
    public function get_field_type($field) { return $field['type']; }
    public function get_field_key($key) { return 'woocommerce_paypal_checkout_' . $key; }
    public function get_option($key) { return $this->settings[$key]; }
    public function validate_checkbox_field($key, $value) { return !is_null($value) ? 'yes' : 'no'; }
    public function validate_text_field($key, $value) { return trim($value); }
}
require WC_PP_PRO_ADDON_PATH . '/woo-paypal-pro-gateway-paypal-checkout.php';

function check($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}

$gateway = (new ReflectionClass('WC_Gateway_PayPal_Checkout'))->newInstanceWithoutConstructor();
$checkbox = array('type' => 'checkbox', 'subtab' => 'general');
$credential = array('type' => 'text', 'subtab' => 'api_credentials');
$_POST = array();

foreach (array('sandbox', 'enabled') as $key) {
    $gateway->settings[$key] = 'yes';
    $_GET = array(); // General is the default tab.
    $gateway->settings[$key] = $gateway->get_field_value($key, $checkbox);
    check($gateway->settings[$key] === 'no', 'Unchecking must save no: ' . $key);

    foreach (array('no', '', 'yes') as $stored) {
        $gateway->settings[$key] = $stored;
        foreach (array('api_credentials', 'api_connection', 'webhooks') as $tab) {
            $_GET['subtab'] = $tab;
            $post = array($gateway->get_field_key('live_client_id') => ' new-client-id ');
            check($gateway->get_field_value($key, $checkbox, $post) === $stored,
                'Other tabs must preserve checkbox state: ' . $key . '/' . $tab);
            check($gateway->get_field_value('live_client_id', $credential, $post) === 'new-client-id',
                'Submitted credentials must still be validated');
        }
    }

    $_GET['subtab'] = 'general';
    check($gateway->get_field_value($key, $checkbox, array($gateway->get_field_key($key) => '1')) === 'yes',
        'Checking must still enable the setting: ' . $key);
}

$gateway->settings['live_client_id'] = 'existing-client-id';
check($gateway->get_field_value('live_client_id', $credential) === 'existing-client-id',
    'Saving General must preserve credentials');
echo "PayPal settings regression checks passed.\n";
