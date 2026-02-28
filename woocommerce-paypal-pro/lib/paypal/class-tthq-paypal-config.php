<?php

namespace TTHQ\WC_PP_PRO\Lib\PayPal;

class PayPal_PPCP_Config {

	public static array $config;

	private static $instance;

	public static $plugin_shortname = '';

	public static $log_text_method;
	public static $log_array_method;

	private $ppcp_settings_key = '';

	private $ppcp_settings_values = array();

	private function __construct() {
		// Load plugin specific configs.
		self::$config = self::get_plugin_specific_configs();

		$this->set_plugin_shortname( self::get('plugin_shortname') );
		$this->set_log_text_method( self::get('log_text_method') );
		$this->set_log_array_method( self::get('log_array_method') );
		$this->set_ppcp_settings_key( self::get('ppcp_settings_key') );

		// Load saved settings.
		$this->load_settings_from_db();
	}

	public static function get_plugin_specific_configs(){
		/**
		 * NOTE: Change only the necessary configs.
		 */
		$configs = array(

			'plugin_shortname' => 'wcpprog',
			'plugin_root_file' => WC_PP_PRO_ADDON_FILE,
			
			/**
			 * PayPal PPCP settings group related
			 */
			'ppcp_settings_key' => 'woocommerce_paypal_checkout_settings',
			
			'log_text_method' => 'WC_PP_PRO_Utility::log',
			'log_array_method' => 'WC_PP_PRO_Utility::log_array',
			
			/**
			 * PayPal api connection related
			 */

			'api_connection_settings_page' => 'admin.php?page=wc-settings&tab=checkout&section=paypal_checkout&subtab=api_connection',

			/**
			 * Define all the settings key mappings.
			 */
			'settings_key' => array(
				
				'sandbox_enabled' => 'sandbox',
				
				/**
				 * PayPal api credentials related
				 */
				'sandbox_client_id' => 'sandbox_client_id',
				'sandbox_client_secret' => 'sandbox_client_secret',
				'live_client_id' => 'live_client_id',
				'live_client_secret' => 'live_client_secret',
			
				/**
				 * Merchant related
				 */
				'sandbox_seller_merchant_id' => 'paypal-sandbox-seller-merchant-id',
				'sandbox_seller_paypal_email' => 'paypal-sandbox-seller-paypal-email',
				'live_seller_merchant_id' => 'paypal-live-seller-merchant-id',
				'live_seller_paypal_email' => 'paypal-live-seller-paypal-email',
			),
		);

		return $configs;
	}

	// Private clone method to prevent cloning of the instance
	private function __clone() {
	}

	public function load_settings_from_db() {
		$this->ppcp_settings_values = get_option($this->ppcp_settings_key, array());
	}

	/**
	 * Public method to get the instance of the class
	 *
	 * @return PayPal_PPCP_Config
	 */
	public static function get_instance() {
		if (!isset(self::$instance)) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function set_plugin_shortname($str) {
		self::$plugin_shortname = $str;
	}

	public static function set_log_text_method(callable $method) {
		self::$log_text_method = $method;
	}

	public static function set_log_array_method(callable $method) {
		self::$log_array_method = $method;
	}

	public function set_ppcp_settings_key($ppcp_settings_key) {
		$this->ppcp_settings_key = $ppcp_settings_key;
	}

	public function get_value($key, $default = '') {
		return isset($this->ppcp_settings_values[$key]) ? $this->ppcp_settings_values[$key] : $default;
	}

	public function set_value($key, $value) {
		$this->ppcp_settings_values[$key] = $value;
	}

	public function save() {
		update_option($this->ppcp_settings_key, $this->ppcp_settings_values);
	}

	public static function get($key) {
		if (!isset(self::$config) || empty(self::$config)) {
			self::$config = self::get_plugin_specific_configs();
		}

		if (array_key_exists($key, self::$config)) {
			return self::$config[$key];
		}

		return self::$config;
	}

	public static function key($key, $default = '') {
		$config = self::get('settings_key');

		if (array_key_exists($key, $config)) {
			return $config[$key];
		}

		return $default;
	}

	public function value($key, $default = ''){
		return $this->get_value(self::key($key), $default);
	}
}
