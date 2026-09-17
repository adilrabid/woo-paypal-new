<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_JS_Button_Embed;
use TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Utils;

class WC_PP_PRO_Gateway_Blocks_Support_PPCP extends AbstractPaymentMethodType {

	private $gateway;

	protected $name = 'paypal_checkout'; // payment gateway id

	public function initialize() {
		// get gateway class
		$gateways      = WC()->payment_gateways->payment_gateways();
		$this->gateway = $gateways[$this->name];

		// get payment gateway settings
		$this->settings = get_option("woocommerce_{$this->name}_settings", array());
	}

	public function is_active() {
		return !empty($this->settings['enabled']) && 'yes' === $this->settings['enabled'];
	}

	public function get_payment_method_script_handles() {
		$is_subscription = $this->gateway && $this->gateway->is_subscription_checkout();
		$is_sandbox      = 'yes' === $this->get_setting( 'sandbox' );
		$paypal_sdk      = PayPal_JS_Button_Embed::get_instance();

		$paypal_sdk->set_settings_args( array(
			'is_live_mode'    => ! $is_sandbox,
			'live_client_id'  => $this->get_setting( 'live_client_id' ),
			'sandbox_client_id' => $this->get_setting( 'sandbox_client_id' ),
			'currency'        => get_woocommerce_currency(),
			'intent'          => $is_subscription ? 'subscription' : 'capture',
			'is_subscription' => $is_subscription,
		) );

		// Keep the SDK helper as the single place that constructs PayPal's URL.
		// The block bundle still declares this handle as a dependency below.
		$paypal_sdk->register_papal_sdk_script( $this->gateway->pp_js_sdk_script_handler );

		$asset_path   = WC_PP_PRO_ADDON_PATH . '/block-integration/paypal-ppcp/index.asset.php';
		$version      = null;
		$dependencies = array();
		if (file_exists($asset_path)) {
			$asset        = require $asset_path;
			$version      = isset($asset['version']) ? $asset['version'] : $version;
			$dependencies = isset($asset['dependencies']) ? $asset['dependencies'] : $dependencies;
		}
		$dependencies[] = $this->gateway->pp_js_sdk_script_handler;

		wp_register_script(
			'wcpprog-block-support-script-paypal-ppcp',
			plugins_url('', WC_PP_PRO_ADDON_FILE) . '/block-integration/paypal-ppcp/index.js',
			$dependencies,
			$version,
			true
		);

		// Return the script handler(s), so woocommerce can handle enqueueing of them.
		return array('wcpprog-block-support-script-paypal-ppcp');
	}

	public function get_payment_method_data() {
		return array(
			'title'                         => $this->get_setting('title'),
			'description'                   => $this->get_setting('description'),
			'securitycodehint'              => $this->get_setting('securitycodehint') == 'yes',
			'icon'                          => apply_filters('woocommerce_paypal_checkout_icon', WC_PP_PRO_ADDON_URL . '/assets/img/pp-ppcp.svg'),
			'ppcpIcons'                     => $this->get_ppcp_icons(),
			'supports'                      => array('products', 'pay_button'),
			'checkoutType'                  => $this->gateway && $this->gateway->is_subscription_checkout() ? 'subscription' : 'capture',
			'ajax'                          => array(
				'url'                        => admin_url( 'admin-ajax.php' ),
				'nonce'                      => wp_create_nonce( PayPal_Utils::auto_prefix( 'pp_checkout_nonce' ) ),
				'createOrderAction'          => PayPal_Utils::auto_prefix( 'pp_create_order' ),
				'captureOrderAction'         => PayPal_Utils::auto_prefix( 'pp_capture_order' ),
				'createSubscriptionAction'   => PayPal_Utils::auto_prefix( 'sub_pp_create_subscription' ),
				'approveSubscriptionAction'  => PayPal_Utils::auto_prefix( 'sub_onapprove_process_subscription' ),
			),
		);
	}

	public function get_ppcp_icons() {
		return array(
			// array(
			// 	"id" => "wcpprog-wc-payment-method-ppcp",
			// 	"alt" => "PayPal PPCP Icon",
			// 	"src" => WC_PP_PRO_ADDON_URL . '/assets/img/pp-ppcp.svg',
			// ),
		);
	}
}
