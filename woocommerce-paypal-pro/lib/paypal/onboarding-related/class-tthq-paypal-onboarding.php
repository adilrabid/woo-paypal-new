<?php

namespace TTHQ\WC_PP_PRO\Lib\PayPal\Onboarding;

use TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Main;
use TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Utils;
use TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_PPCP_Config;

/**
 * A Helper class for PPCP Onboarding.
 */
class PayPal_PPCP_Onboarding {
	protected static $instance;
	/**
	* REPLACE: plugin prefix across different plugins.
	*/
	public static $account_connect_string = 'wcpprog_ppcp_account_connect';

	public function __construct() {
		//NOP
	}

	/**
	 * This needs to be a Singleton class. To make sure that the object and data is consistent throughout the onboarding process.
	 * 
	 * @return PayPal_PPCP_Onboarding
	 */
	public static function get_instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function generate_seller_nonce() {
		// Generate a random string of 40 characters.
		$random_string = substr(str_shuffle(str_repeat('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', 5)), 0, 40);

		// Hash the string using sha256
		$hashed_string = hash('sha256', $random_string);

		// Trim or pad the hashed string to ensure it is between 40 to 64 characters
		$output_string = substr($hashed_string, 0, 64);
		$output_string = str_pad($output_string, 64, '0');

		$seller_nonce = $output_string;
		return $seller_nonce;
	}

	public static function generate_return_url_after_onboarding( $environment_mode = 'production' ){
		$pp_settings_base_path = PayPal_PPCP_Config::get('api_connection_settings_page');
		$base_url = admin_url($pp_settings_base_path);
		$query_args = array();
		/**
		* REPLACE: plugin prefix across different plugins.
		*/
		$query_args['wcpprog_ppcp_after_onboarding'] = '1';
		$query_args['environment_mode'] = $environment_mode;
		$return_url = add_query_arg( $query_args, $base_url );

		//Encode the return URL so when it is used as a query arg, it does not break the URL.
		$return_url_encoded = urlencode($return_url);
		return $return_url_encoded;
	}

	public static function get_signup_link( $environment_mode = 'production' ){

		$seller_nonce = self::generate_seller_nonce();

		$query_args = array();
		$query_args['partnerId'] = PayPal_Utils::get_partner_id_by_environment_mode( $environment_mode );
		$query_args['product'] = 'EXPRESS_CHECKOUT';// 'PPCP' or 'EXPRESS_CHECKOUT';
		$query_args['integrationType'] = 'FO';
		$query_args['features'] = 'PAYMENT,REFUND'; //BILLING_AGREEMENT
		$query_args['partnerClientId'] = PayPal_Utils::get_partner_client_id_by_environment_mode( $environment_mode );
		$query_args['returnToPartnerUrl'] = self::generate_return_url_after_onboarding($environment_mode);
		//$query_args['partnerLogoUrl'] = '';
		$query_args['displayMode'] = 'minibrowser';
		$query_args['sellerNonce'] = $seller_nonce;

		$base_url = PayPal_Utils::get_signup_url_by_environment_mode( $environment_mode );
		$singup_link = add_query_arg( $query_args, $base_url );
		//Example URL = 'https://www.paypal.com/bizsignup/partner/entry?partnerId=USVAEAM3FR5E2&product=PPCP&integrationType=FO&features=PAYMENT,REFUND&partnerClientId=AeO65uHbDsjjFBdx3DO6wffuH2wIHHRDNiF5jmNgXOC8o3rRKkmCJnpmuGzvURwqpyIv-CUYH9cwiuhX&returnToPartnerUrl=&partnerLogoUrl=&displayMode=minibrowser&sellerNonce=a575ab0ee0';
		
		//Save the query args so it can be used for token generation using shared ID after the onboarding is complete.
		PayPal_Utils::update_option('ppcp_connect_query_args_'.$environment_mode, $query_args);

		return $singup_link;
	}

	/**
	* REPLACE: plugin prefix across different plugins.
	*/
	public function output_sandbox_onboarding_link_code() {
		$sandbox_singup_link = self::get_signup_link('sandbox');
		$wp_nonce = wp_create_nonce( self::$account_connect_string );
		$ajax_post_url = admin_url('admin-ajax.php');
		?>
		<script>
			function wcpprog_ppcp_onboarded_callback_sandbox(authCode, sharedId) {
				console.log('WC PP PRO PayPal Sandbox Onboarded-Callback');
				//Send the authCode and sharedId to your server and do the next steps.
				//You can use the sellerNonce to identify the user.

				let data = JSON.stringify({
					authCode: authCode,
					sharedId: sharedId,
					environment: 'sandbox',
				});

				const formData = new FormData();
				formData.append('action', '<?php echo esc_js(PayPal_Utils::auto_prefix('handle_onboarded_callback_data')) ?>');
				formData.append('data', data);
				formData.append('_wpnonce', '<?php echo esc_js($wp_nonce) ?>');

				//Post the AJAX request to the server.
				fetch('<?php echo $ajax_post_url; ?>', {
					method: 'POST',
					body: formData,
				}).then(response => response.json())
				.then(result => {
					//The AJAX post request was successful. Need to check if the processing was successful.
					//The response.json() method is used to parse the response as JSON. Then, the result object contains the parsed JSON response.
					if(result.success){
						//All good.
						console.log('Successfully processed the handle_onboarded_callback_data.');
					} else {
						alert("Error: " + result.msg);
					}
				}).catch(function(err) {
					console.error(err);
					alert("Something went wrong with the AJAX request on this server! See the console log for more details.");
				})

				return false;
			}
		</script>
		<a class="button button-primary direct" target="_blank"
			data-paypal-onboard-complete="wcpprog_ppcp_onboarded_callback_sandbox"
			href="<?php echo ($sandbox_singup_link); ?>"
			data-paypal-button="true"><?php esc_html_e('Get PayPal Sandbox Credentials', 'woocommerce-paypal-pro-payment-gateway')?>
		</a>
		<script id="paypal-js-sandbox" src="https://www.sandbox.paypal.com/webapps/merchantboarding/js/lib/lightbox/partner.js"></script>
		<?php
	}

	public function output_sandbox_ac_disconnect_link(){		
		$ajax_post_url = admin_url('admin-ajax.php');
		?>
		<a
			id="ppcp_ac_disconnect_sandbox"
			class="button"
			href="<?php echo esc_url(admin_url(PayPal_PPCP_Config::get('api_connection_settings_page'))) ?>"
			data-ac-disconnect-action="<?php echo esc_attr(PayPal_Utils::auto_prefix('ppcp_disconnect_sandbox')) ?>"
			data-ac-disconnect-nonce="<?php echo esc_attr(wp_create_nonce(PayPal_Utils::auto_prefix('ac_disconnect_nonce_sandbox'))) ?>"
		>
			<?php _e('Disconnect Sandbox Account', 'woocommerce-paypal-pro-payment-gateway')?>
		</a>

		<script>
			document.addEventListener('DOMContentLoaded', function(){
				const acDisconnectLink = document.getElementById('ppcp_ac_disconnect_sandbox');
				acDisconnectLink.addEventListener('click', function(e){
					e.preventDefault();

					if (! confirm('Are you sure you want to disconnect the PayPal sandbox account?')){
						return;
					}

					const action = this.getAttribute('data-ac-disconnect-action');
					const nonce = this.getAttribute('data-ac-disconnect-nonce');
					
					const formData = new FormData();
					formData.append('action', action);
					formData.append('_wpnonce', nonce);

					fetch('<?php echo $ajax_post_url; ?>', {
						method: 'POST',
						body: formData,
					}).then(response => response.json())
					.then(result => {
						if(result.success){
							alert('Account disconnected successfully.');

							window.location.reload();
						} else {
							alert("Error: " + result.msg);
						}
					}).catch((err) => {
						console.error(err);
						alert("Something went wrong with the AJAX request on this server! See the console log for more details.");
					})

				})
			})
		</script>

		<?php
	}

	/**
	* REPLACE: plugin prefix across different plugins.
	*/
	public function output_production_onboarding_link_code() {
		//We need to separate JavaScript functions to handle the after onbarding callback. So we are using different function names.
		$singup_link = self::get_signup_link('production');
		$wp_nonce = wp_create_nonce( self::$account_connect_string );
		$ajax_post_url = admin_url('admin-ajax.php');
		?>
		<script>
			function wcpprog_ppcp_onboarded_callback_production(authCode, sharedId) {
				console.log('WC PP PRO PayPal Production Onboarded-Callback');
				//Send the authCode and sharedId to your server and do the next steps.
				//You can use the sellerNonce to identify the user.				

				let data = JSON.stringify({
						authCode: authCode,
						sharedId: sharedId,
						environment: 'production',
				});

				const formData = new FormData();
				formData.append('action', 'wcpprog_handle_onboarded_callback_data');
				formData.append('data', data);
				formData.append('_wpnonce', '<?php echo $wp_nonce; ?>');

				//Post the AJAX request to the server.
				fetch('<?php echo $ajax_post_url; ?>', {
					method: 'POST',
					body: formData,
				}).then(response => response.json())
				.then(result => {
					//The AJAX post request was successful. Need to check if the processing was successful.
					//The response.json() method is used to parse the response as JSON. Then, the result object contains the parsed JSON response.
					if(result.success){
						//All good.
						console.log('Successfully processed the handle_onboarded_callback_data.');
					} else {
						alert("Error: " + result.msg);
					}
				}).catch(function(err) {
					console.error(err);
					alert("Something went wrong with the AJAX request on this server! See the console log for more details.");
				})

				return false;
			}
		</script>
		<a class="button button-primary direct" target="_blank"
			data-paypal-onboard-complete="wcpprog_ppcp_onboarded_callback_production"
			href="<?php echo ($singup_link); ?>"
			data-paypal-button="true"><?php esc_html_e('Get PayPal Live Credentials', 'woocommerce-paypal-pro-payment-gateway') ?>
		</a>
		<script id="paypal-js" src="https://www.paypal.com/webapps/merchantboarding/js/lib/lightbox/partner.js"></script>
		<?php
	}

	public function output_production_ac_disconnect_link(){
		$ajax_post_url = admin_url('admin-ajax.php');
		?>
		<a
			id="ppcp_ac_disconnect_production"
			class="button"
			href="<?php echo esc_url(admin_url(PayPal_PPCP_Config::get('api_connection_settings_page'))) ?>"
			data-ac-disconnect-action="<?php echo esc_attr(PayPal_Utils::auto_prefix('ppcp_disconnect_production')) ?>"
			data-ac-disconnect-nonce="<?php echo esc_attr(wp_create_nonce(PayPal_Utils::auto_prefix('ac_disconnect_nonce_production'))) ?>"
		>
			<?php _e('Disconnect Sandbox Account', 'woocommerce-paypal-pro-payment-gateway')?>
		</a>

		<script>
			document.addEventListener('DOMContentLoaded', function(){
				const acDisconnectLink = document.getElementById('ppcp_ac_disconnect_production');
				acDisconnectLink.addEventListener('click', function(e){
					e.preventDefault();

					if (! confirm('Are you sure you want to disconnect the PayPal account?')){
						return;
					}

					const action = this.getAttribute('data-ac-disconnect-action');
					const nonce = this.getAttribute('data-ac-disconnect-nonce');
					
					const formData = new FormData();
					formData.append('action', action);
					formData.append('_wpnonce', nonce);

					fetch('<?php echo $ajax_post_url; ?>', {
						method: 'POST',
						body: formData,
					}).then(response => response.json())
					.then(result => {
						if(result.success){
							alert('Account disconnected successfully.');

							window.location.reload();
						} else {
							alert("Error: " + result.msg);
						}
					}).catch((err) => {
						console.error(err);
						alert("Something went wrong with the AJAX request on this server! See the console log for more details.");
					})

				})
			})
		</script>

		<?php
	}

	public function output_delete_token_cache_button(){		
		$ajax_post_url = admin_url('admin-ajax.php');
		?>
			<a 
				id="ppcp_delete_cache_btn"
				class="button-secondary" 
				data-delete-cache-action="<?php echo esc_attr(PayPal_Utils::auto_prefix('ppcp_delete_cache', '_')) ?>"
				data-delete-cache-nonce="<?php echo esc_attr(wp_create_nonce(PayPal_Utils::auto_prefix('ppcp_delete_cache_nonce'))) ?>"
			>
				<?php esc_html_e('Delete Token Cache', 'woocommerce-paypal-pro-payment-gateway') ?>
			</a>
			<script>

				document.addEventListener('DOMContentLoaded', function(){
					const deleteCacheBtn = document.getElementById('ppcp_delete_cache_btn');

					deleteCacheBtn.addEventListener('click', function(e){
						e.preventDefault();

						if (!confirm('Are you sure you want delete token cache?')){
							return;
						};

						const action = this.getAttribute('data-delete-cache-action');
						const nonce = this.getAttribute('data-delete-cache-nonce');
						
						const formData = new FormData();
						formData.append('action', action);
						formData.append('_wpnonce', nonce);

						fetch('<?php echo $ajax_post_url; ?>', {
							method: 'POST',
							body: formData,
						}).then(response => response.json())
						.then(result => {
							if(result.success){
								alert('Successfully deleted token cache.');
							} else {
								alert("Error: " + result.msg);
							}
						}).catch((err) => {
							console.error(err);
							alert("Something went wrong with the AJAX request on this server! See the console log for more details.");
						})
					})
				})
			</script>
		<?php
	}
}