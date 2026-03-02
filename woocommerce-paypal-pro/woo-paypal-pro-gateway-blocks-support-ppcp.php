<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

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
		$asset_path   = WC_PP_PRO_ADDON_PATH . '/block-integration/paypal-ppcp/index.asset.php';
		$version      = null;
		$dependencies = array();
		if (file_exists($asset_path)) {
			$asset        = require $asset_path;
			$version      = isset($asset['version']) ? $asset['version'] : $version;
			$dependencies = isset($asset['dependencies']) ? $asset['dependencies'] : $dependencies;
		}

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
		);
	}

	public function get_ppcp_icons() {
		return array(
			array(
				"id" => "wcpprog-wc-payment-method-ppcp",
				"alt" => "PayPal PPCP Icon",
				"src" => WC_PP_PRO_ADDON_URL . '/assets/img/pp-ppcp.svg',
			),
		);
	}
}
