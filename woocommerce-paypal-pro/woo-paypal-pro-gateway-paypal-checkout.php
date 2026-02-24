<?php

/**
 * WooCommerce PayPal Checkout Gateway Class
 *
 * Adds PayPal Checkout as a separate payment gateway alongside PayPal Pro.
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WC_Gateway_PayPal_Checkout extends WC_Payment_Gateway {

    private $sandbox;
    private $client_id;
    private $client_secret;

    public function __construct() {
        $this->id                 = 'paypal_checkout';
        $this->icon               = apply_filters('woocommerce_paypal_checkout_icon', 'https://www.paypalobjects.com/webstatic/icon/pp32.png');
        $this->has_fields         = true;
        $this->method_title       = __('PayPal Checkout', 'woocommerce-paypal-pro-payment-gateway');
        $this->method_description = __('Accept payments via PayPal Checkout with smart payment buttons.', 'woocommerce-paypal-pro-payment-gateway');
        $this->supports           = array('products');

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title          = $this->get_option('title');
        $this->description    = $this->get_option('description');
        $this->enabled        = $this->get_option('enabled');
        $this->sandbox        = $this->get_option('sandbox');
        $this->client_id      = $this->sandbox ? $this->get_option('sandbox_client_id') : $this->get_option('live_client_id');
        $this->client_secret  = $this->sandbox ? $this->get_option('sandbox_client_secret') : $this->get_option('live_client_secret');

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        // Initialize hooks after WordPress is loaded
        add_action('init', array($this, 'init_hooks'), 20);
    }

    /**
     * Initialize hooks for button rendering and scripts
     */
    public function init_hooks() {
        // Always add these hooks, but check availability in the methods themselves
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));

        // For cart block, we need to use JavaScript to inject buttons
        add_action('wp_footer', array($this, 'render_paypal_button_on_cart_block'));

        // For cart shortcode, we need to use woocommerce hook to render buttons
        add_action('woocommerce_after_cart_totals', array($this, 'render_paypal_button_on_cart_shortcode'), 15);

        // AJAX actions (always add these for security)
        add_action('wp_ajax_paypal_checkout_create_order', array($this, 'create_paypal_order'));
        add_action('wp_ajax_nopriv_paypal_checkout_create_order', array($this, 'create_paypal_order'));
        add_action('wp_ajax_paypal_checkout_capture_order', array($this, 'capture_paypal_order'));
        add_action('wp_ajax_nopriv_paypal_checkout_capture_order', array($this, 'capture_paypal_order'));
    }

    /**
     * Inject PayPal buttons using JavaScript for block themes
     */
    public function render_paypal_button_on_cart_block() {
        if (! $this->is_available()) {
            echo '<!-- PayPal Checkout: Gateway not available for block theme injection -->';
            return;
        }

        if (! is_cart()) {
            return;
        }

        echo '<!-- PayPal Checkout: Injecting buttons for block theme -->';
        ?>
        <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function() {
                woo_pp_pro_inject_btn_for_cart_block();
            });
        </script>
        <?php
    }

    /**
     * Debug method to check if cart hooks are firing
     */
    public function debug_cart_hook() {
        echo '<!-- PayPal Checkout: Cart hook fired -->';
    }

    /**
     * Debug method to check if checkout hooks are firing
     */
    public function debug_checkout_hook() {
        echo '<!-- PayPal Checkout: Checkout hook fired -->';
    }

    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __('Enable/Disable', 'woocommerce-paypal-pro-payment-gateway'),
                'type'    => 'checkbox',
                'label'   => __('Enable PayPal Checkout', 'woocommerce-paypal-pro-payment-gateway'),
                'default' => 'no',
                'subtab' => 'general',
            ),
            'title' => array(
                'title'       => __('Title', 'woocommerce-paypal-pro-payment-gateway'),
                'type'        => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'woocommerce-paypal-pro-payment-gateway'),
                'default'     => __('PayPal', 'woocommerce-paypal-pro-payment-gateway'),
                'desc_tip'    => true,
                'subtab' => 'general',
            ),
            'description' => array(
                'title'       => __('Description', 'woocommerce-paypal-pro-payment-gateway'),
                'type'        => 'textarea',
                'description' => __('Payment method description that the customer will see on your checkout.', 'woocommerce-paypal-pro-payment-gateway'),
                'default'     => __('Pay with your PayPal account or credit card.', 'woocommerce-paypal-pro-payment-gateway'),
                'desc_tip'    => true,
                'subtab' => 'general',
            ),
            'sandbox' => array(
                'title'   => __('Sandbox', 'woocommerce-paypal-pro-payment-gateway'),
                'type'    => 'checkbox',
                'label'   => __('Enable PayPal sandbox', 'woocommerce-paypal-pro-payment-gateway'),
                'default' => '',
                'description' => __('PayPal sandbox can be used to test payments.', 'woocommerce-paypal-pro-payment-gateway'),
                'desc_tip' => true,
                'subtab' => 'general',
            ),

            'live_account_connection' => array(
                'title'             => __('Live Account Connection Status', 'woocommerce-paypal-pro-payment-gateway'),
                'type'              => 'account_conn_btn',
                'custom_attrs' => array(
                    'onclick' => "location.href='https://woocommerce.com'",
                    'connection_type' => "live",
                    'connected' => array(
                        'msg' => __('Live PayPal account is not connected.', 'woocommerce-paypal-pro-payment-gateway'),
                        'button_text' => __('Disconnect Live Account', 'woocommerce-paypal-pro-payment-gateway'),
                    ),
                    'not_connected' => array(
                        'msg' => __('Live account is connected. If you experience any issues, please disconnect and reconnect.', 'woocommerce-paypal-pro-payment-gateway'),
                        'button_text' => __('Get PayPal Live Credentials', 'woocommerce-paypal-pro-payment-gateway'),
                    ),
                ),
                'description'       => __('Use this button to connect and obtain the live PayPal API credentials automatically to offer the PayPal Commerce Platform checkout option.', 'woocommerce-paypal-pro-payment-gateway'),
                'desc_tip'          => true,
                'subtab'    => 'api_connection',
            ),
            'sandbox_account_connection' => array(
                'title'             => __('Sandbox Account Connection Status', 'woocommerce-paypal-pro-payment-gateway'),
                'type'              => 'account_conn_btn',
                'custom_attrs' => array(
                    'onclick' => "location.href='https://woocommerce.com'",
                    'connection_type' => "sandbox",
                    'connected' => array(
                        'msg' => __('Sandbox PayPal account is not connected.', 'woocommerce-paypal-pro-payment-gateway'),
                        'button_text' => __('Disconnect Sandbox Account', 'woocommerce-paypal-pro-payment-gateway'),
                    ),
                    'not_connected' => array(
                        'msg' => __('Sandbox account is connected. If you experience any issues, please disconnect and reconnect.', 'woocommerce-paypal-pro-payment-gateway'),
                        'button_text' => __('Get PayPal Sandbox Credentials', 'woocommerce-paypal-pro-payment-gateway'),
                    ),
                ),
                'description'       => __('Use this button to connect and obtain the sandbox PayPal API credentials automatically to offer the PayPal Commerce Platform checkout option.', 'woocommerce-paypal-pro-payment-gateway'),
                'desc_tip'          => true,
                'subtab'    => 'api_connection',
            ),
            'delete_access_token_cache' => array(
                'title'             => __('Delete Access Token Cache', 'woocommerce-paypal-pro-payment-gateway'),
                'type'              => 'delete_access_token_cache',
                'custom_attrs' => array(
                ),
                'description'       => __('This will delete the PayPal API access token cache. This is useful if you are having issues with the PayPal API after changing/updating the API credentials.', 'woocommerce-paypal-pro-payment-gateway'),
                'desc_tip'          => true,
                'subtab'    => 'api_connection',
            ),

            'live_client_id' => array(
                'title'       => __('Live Client ID', 'woocommerce-paypal-pro-payment-gateway'),
                'type'        => 'text',
                'description' => __('Get your client ID from PayPal Developer dashboard.', 'woocommerce-paypal-pro-payment-gateway'),
                'default'     => '',
                'desc_tip'    => true,
                'subtab'    => 'api_credentials',
            ),
            'live_client_secret' => array(
                'title'       => __('Live Client Secret', 'woocommerce-paypal-pro-payment-gateway'),
                'type'        => 'password',
                'description' => __('Get your client secret from PayPal Developer dashboard.', 'woocommerce-paypal-pro-payment-gateway'),
                'default'     => '',
                'desc_tip'    => true,
                'subtab'    => 'api_credentials',
            ),
            'sandbox_client_id' => array(
                'title'       => __('Sandbox Client ID', 'woocommerce-paypal-pro-payment-gateway'),
                'type'        => 'text',
                'description' => __('Get your sandbox client ID from PayPal Developer dashboard.', 'woocommerce-paypal-pro-payment-gateway'),
                'default'     => '',
                'desc_tip'    => true,
                'subtab'    => 'api_credentials',
            ),
            'sandbox_client_secret' => array(
                'title'       => __('Sandbox Client Secret', 'woocommerce-paypal-pro-payment-gateway'),
                'type'        => 'password',
                'description' => __('Get your sandbox client secret from PayPal Developer dashboard.', 'woocommerce-paypal-pro-payment-gateway'),
                'default'     => '',
                'desc_tip'    => true,
                'subtab'    => 'api_credentials',
            ),
        );
    }

    public function generate_account_conn_btn_html($key, $data) {
        $field    = $this->plugin_id . $this->id . '_' . $key;

        $defaults = array(
            'class'             => '',
            'css'               => '',
            'custom_attrs' => array(),
            'desc_tip'          => false,
            'description'       => '',
            'title'             => '',
        );

        $data = wp_parse_args($data, $defaults);

        $connection_type = isset($data['custom_attrs']['connection_type']) ? $data['custom_attrs']['connection_type'] : 'sandbox';

        $is_sandbox_enabled = $this->get_option('sandbox') == 'yes' ? true : false;

        $ppcp_onboarding_instance = \TTHQ\WC_PP_PRO\Lib\PayPal\Onboarding\PayPal_PPCP_Onboarding::get_instance();

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field); ?>"><?php echo wp_kses_post($data['title']); ?></label>
                <?php echo $this->get_tooltip_html($data); ?>
            </th>
            <td class="forminp">
                <fieldset>
                    <legend class="screen-reader-text"><span><?php echo wp_kses_post($data['title']); ?></span></legend>
                    <?php 
                    if ($connection_type == 'live') {

                        if (! $is_sandbox_enabled) {
                            // Check if the live account is connected
                            $live_account_connection_status = 'connected';
                            if (empty($this->get_option('live_client_id')) || empty($this->get_option('live_client_secret'))) {
                                //Live API keys are missing. Account is not connected.
                                $live_account_connection_status = 'not-connected';
                            }

                            if ($live_account_connection_status == 'connected') {
                                //Production account connected
                                echo '<div class="wcpprog-paypal-live-account-status"><span class="dashicons dashicons-yes" style="color:green;"></span>&nbsp;';
                                _e("Live account is connected. If you experience any issues, please disconnect and reconnect.", "woocommerce-paypal-pro-payment-gateway");
                                echo '</div>';
                                // Show disconnect option for live account.
                                $ppcp_onboarding_instance->output_production_ac_disconnect_link();
                            } else {
                                //Production account is NOT connected.
                                echo '<div class="wcpprog-paypal-live-account-status"><span class="dashicons dashicons-no" style="color: red;"></span>&nbsp;';
                                _e("Live PayPal account is not connected. Click the button below to authorize the app and acquire API credentials from your PayPal account.", "woocommerce-paypal-pro-payment-gateway");
                                echo '</div>';
                                // Show the onboarding link
                                $ppcp_onboarding_instance->output_production_onboarding_link_code();
                            }
                        } else {
                            echo '<p class="wcpprog_gray_box">';
							_e("For live account onboarding, disable the sandbox mode from general settings.", "woocommerce-paypal-pro-payment-gateway");
							echo '</p>';
                        }
                    } else {
                        if ( $is_sandbox_enabled ) {
                            //Check if the sandbox account is connected
                            $sandbox_account_connection_status = 'connected';
                            if (empty($this->get_option('sandbox_client_id')) || empty($this->get_option('sandbox_client_secret'))) {
                                //Sandbox API keys are missing. Account is not connected.
                                $sandbox_account_connection_status = 'not-connected';
                            }

                            if ($sandbox_account_connection_status == 'connected') {
                                //Test account connected
                                echo '<div class="wcpprog-paypal-sandbox-account-status"><span class="dashicons dashicons-yes" style="color:green;"></span>&nbsp;';
                                _e("Sandbox account is connected. If you experience any issues, please disconnect and reconnect.", "woocommerce-paypal-pro-payment-gateway");
                                echo '</div>';
                                //Show disconnect option for sandbox account.
                                $ppcp_onboarding_instance->output_sandbox_ac_disconnect_link();
                            } else {
                                //Sandbox account is NOT connected.
                                echo '<div class="wcpprog-paypal-sandbox-account-status"><span class="dashicons dashicons-no" style="color: red;"></span>&nbsp;';
                                _e("Sandbox PayPal account is not connected.", "woocommerce-paypal-pro-payment-gateway");
                                echo '</div>';
                                //Show the onboarding link for sandbox account.
                                $ppcp_onboarding_instance->output_sandbox_onboarding_link_code();
                            }
                        } else {
                            echo '<p class="wcpprog_gray_box">';
							_e("For sandbox account onboarding, enable the sandbox mode from general settings.", "woocommerce-paypal-pro-payment-gateway");
							echo '</p>';
                            // echo '<button class="button button-primary" disabled>'.__('Get PayPal Sandbox Credentials', 'woocommerce-paypal-pro-payment-gateway').'</button>';
                        }
                    }
                    ?>
                </fieldset>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    public function generate_delete_access_token_cache_html($key, $data){
        $field    = $this->plugin_id . $this->id . '_' . $key;
        $defaults = array(
            'class'             => '',
            'css'               => '',
            'custom_attrs' => array(),
            'desc_tip'          => false,
            'description'       => '',
            'title'             => '',
        );

        $data = wp_parse_args($data, $defaults);

        $ppcp_onboarding_instance = \TTHQ\WC_PP_PRO\Lib\PayPal\Onboarding\PayPal_PPCP_Onboarding::get_instance();
        
        $output = '';
        ob_start();
        ?>
            <tr valign="top">
                <th scope="row" class="titledesc">
                    <label for="<?php echo esc_attr($field); ?>"><?php echo wp_kses_post($data['title']); ?></label>
                    <?php echo $this->get_tooltip_html($data); ?>
                </th>
                <td class="forminp">
                    <fieldset>
                        <?php $ppcp_onboarding_instance->output_delete_token_cache_button(); ?>
                    </fieldset>
                </td>
            <tr>
        <?php

        $output = ob_get_clean();

        return $output;
    }

    /**
     * Renders settings fields.
     *
     * NOTE: This is an overridden function.
     */
    public function admin_options() {
		$return_path = null;
		wc_back_header( $this->get_method_title(), esc_html__( 'Return to payments', 'woocommerce' ), \Automattic\WooCommerce\Internal\Admin\Settings\Utils::wc_payments_settings_url( $return_path ) );

		echo wp_kses_post( wpautop( $this->get_method_description() ) );

        $current_tab = isset($_GET['subtab']) && !empty($_GET['subtab']) ? sanitize_text_field($_GET['subtab']) : 'general';

        $subtabs = array(
            'general' => __('General', 'woocommerce-paypal-pro-payment-gateway'),
            'api_connection' => __('API Connection', 'woocommerce-paypal-pro-payment-gateway'),
            'api_credentials' => __('API Credentials', 'woocommerce-paypal-pro-payment-gateway'),
        );

        echo '<h3 class="nav-tab-wrapper">';
        foreach ($subtabs as $stab => $title) { 
            $tab_link = 'admin.php?page=wc-settings&tab=checkout&section=paypal_checkout&subtab='.$stab;
            $active_class = $stab == $current_tab ? 'nav-tab-active' : ''; 
            echo '<a class="nav-tab '.esc_attr($active_class).'" href="'.esc_url($tab_link).'">'.esc_html($title).'</a>';
        } 
		echo '</h3>';

        $this->render_subtab_fields($current_tab);
	}

    public function render_subtab_fields($stab = 'general'){
        $fields = array_map( array( $this, 'set_defaults' ), $this->form_fields );

        $subtab_fields = array();
        foreach ($fields as $key => $field) {
            if(isset($field['subtab']) && $field['subtab'] == $stab){
                $subtab_fields[$key] = $field;
            }

            continue;
        }

        echo '<div style="padding: 6px 10px 0px">';
        if (!empty($subtab_fields)) {
            echo '<table class="form-table">' . $this->generate_settings_html( $subtab_fields, false ) . '</table>'; // WPCS: XSS ok.
        } else {
            echo __('No fields found for this subtab', 'woocommerce-paypal-pro-payment-gateway');
        }
        echo '</div>';
    }

    /**
	 * Get a field's posted and validated value.
	 *
     * NOTE: This is an overridden function. The purpose is to prevent update the fields value which are not present in current subtab screen.
     * 
	 * @param string $key Field key.
	 * @param array  $field Field array.
	 * @param array  $post_data Posted data.
	 * @return string
	 */
	public function get_field_value( $key, $field, $post_data = array() ) {
		$type      = $this->get_field_type( $field );
		$field_key = $this->get_field_key( $key );
		$post_data = empty( $post_data ) ? $_POST : $post_data; // WPCS: CSRF ok, input var ok.
		$value     = isset( $post_data[ $field_key ] ) ? $post_data[ $field_key ] : $this->get_option($key);

		if ( isset( $field['sanitize_callback'] ) && is_callable( $field['sanitize_callback'] ) ) {
			return call_user_func( $field['sanitize_callback'], $value );
		}

		// Look for a validate_FIELDID_field method for special handling.
		if ( is_callable( array( $this, 'validate_' . $key . '_field' ) ) ) {
			return $this->{'validate_' . $key . '_field'}( $key, $value );
		}

		// Look for a validate_FIELDTYPE_field method.
		if ( is_callable( array( $this, 'validate_' . $type . '_field' ) ) ) {
			return $this->{'validate_' . $type . '_field'}( $key, $value );
		}

		// Fallback to text.
		return $this->validate_text_field( $key, $value );
	}

    /**
     * Check if this gateway is enabled and available
     */
    public function is_available() {
        if ('yes' === $this->enabled) {
            if (! empty($this->client_id) && ! empty($this->client_secret)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Enqueue payment scripts
     */
    public function payment_scripts() {
        if (! is_cart() && ! is_checkout() && ! isset($_GET['pay_for_order'])) {
            return;
        }

        if ('no' === $this->enabled) {
            return;
        }

        if (empty($this->is_available())) {
            return;
        }

        wp_enqueue_script(
            'paypal-checkout-sdk',
            'https://www.paypal.com/sdk/js?client-id=' . esc_attr($this->client_id) . '&currency=' . get_woocommerce_currency(),
            array('jquery'),
            null,
            true
        );

        wp_enqueue_script(
            'woo-pp-pro-ppcp-related',
            WC_PP_PRO_ADDON_URL . '/assets/js/woo-pp-pro-ppcp-related.js',
            array('jquery', 'paypal-checkout-sdk'),
            null,
            true
        );

        wp_localize_script('paypal-checkout-sdk', 'wc_paypal_checkout_params', array(
            'ajax_url'    => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('wc_paypal_checkout_nonce'),
            'currency'    => get_woocommerce_currency(),
            'total'       => WC()->cart ? WC()->cart->get_total('raw') : 0,
        ));
    }

    public function payment_fields() {
        ?>
        <div id="paypal-checkout-button-container"></div>
        <script>
            jQuery(function($) {
                const btn_container_selector = '#paypal-checkout-button-container';
                woo_pp_pro_render_ppcp_btn(btn_container_selector);
            })
        </script>
        <?php
    }

    /**
     * Render PayPal button on cart page
     */
    public function render_paypal_button_on_cart_shortcode() {
        if (! $this->is_available()) {
            // Debug: Add hidden comment to see if method is being called
            echo '<!-- PayPal Checkout: Gateway not available on cart page -->';
            return;
        }

        if (! is_cart()) {
            return;
        }

        echo '<!-- PayPal Checkout: Rendering button on cart page -->';
        echo '<div class="wc-paypal-checkout-cart-button" style="border: 1px solid #ddd; padding: 15px; margin: 15px 0; border-radius: 5px;">';
        echo '<h3>' . __('Or pay with PayPal', 'woocommerce-paypal-pro-payment-gateway') . '</h3>';
        echo '<div id="paypal-checkout-button-container" style="margin: 20px 0;"></div>';
        echo '</div>';

        echo '<!-- PayPal Checkout: Injecting buttons for block theme -->';
        ?>
        <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function() {
                woo_pp_pro_render_ppcp_btn_with_retry();
            });
        </script>
        <?php
    }

    /**
     * Create PayPal order via AJAX
     */
    public function create_paypal_order() {
        check_ajax_referer('wc_paypal_checkout_nonce', 'nonce');

        // Create WooCommerce order from current cart
        $wc_order = $this->create_wc_order_from_cart();

        if (! $wc_order) {
            wp_send_json_error(array('message' => 'Failed to create order'));
        }

        // Get PayPal access token
        $access_token = $this->get_paypal_access_token();
        if (! $access_token) {
            wp_send_json_error(array('message' => 'Failed to get PayPal access token'));
        }

        // Prepare order data for PayPal
        $order_data = $this->prepare_paypal_order_data($wc_order);

        // Create PayPal order
        $paypal_order_id = $this->create_paypal_order_api($access_token, $order_data);

        if (! $paypal_order_id) {
            wp_send_json_error(array('message' => 'Failed to create PayPal order'));
        }

        // Store PayPal order ID in WC order meta
        $wc_order->update_meta_data('_paypal_order_id', $paypal_order_id);
        $wc_order->save();

        wp_send_json_success(array('order_id' => $paypal_order_id, 'wc_order_id' => $wc_order->get_id()));
    }

    /**
     * Capture PayPal order via AJAX
     */
    public function capture_paypal_order() {
        check_ajax_referer('wc_paypal_checkout_nonce', 'nonce');

        $paypal_order_id = sanitize_text_field($_POST['paypal_order_id']);

        if (empty($paypal_order_id)) {
            wp_send_json_error(array('message' => 'PayPal Order ID is required'));
        }

        // Get PayPal access token
        $access_token = $this->get_paypal_access_token();
        if (! $access_token) {
            wp_send_json_error(array('message' => 'Failed to get PayPal access token'));
        }

        // Capture the PayPal order
        $capture_response = $this->capture_paypal_order_api($access_token, $paypal_order_id);

        if (! $capture_response) {
            wp_send_json_error(array('message' => 'Failed to capture PayPal payment'));
        }

        // Find the WooCommerce order by PayPal order ID
        $wc_order = $this->get_wc_order_by_paypal_id($paypal_order_id);

        if (! $wc_order) {
            wp_send_json_error(array('message' => 'WooCommerce order not found'));
        }

        // Update the WooCommerce order with capture details
        $this->update_wc_order_with_capture_data($wc_order, $capture_response);

        // Mark order as paid and add note
        $wc_order->payment_complete($paypal_order_id);
        $wc_order->add_order_note(sprintf(__('PayPal payment completed. PayPal Order ID: %s', 'woocommerce-paypal-pro-payment-gateway'), $paypal_order_id));

        // Empty cart
        WC()->cart->empty_cart();

        wp_send_json_success(array(
            'redirect' => $wc_order->get_checkout_order_received_url()
        ));
    }

    /**
     * Process the payment (required by WC_Payment_Gateway)
     */
    public function process_payment($order_id) {
        return array(
            'result'   => 'success',
            'redirect' => '',
        );
    }

    /**
     * Create WooCommerce order from current cart
     */
    private function create_wc_order_from_cart() {
        try {
            // Create order from cart
            $checkout = WC()->checkout();

            // Get posted data
            $data = array();
            if (is_user_logged_in()) {
                $user = wp_get_current_user();
                $data['billing_email'] = $user->user_email;
                $data['billing_first_name'] = $user->first_name;
                $data['billing_last_name'] = $user->last_name;
            } else {
                // For guest checkout, we'll get these from PayPal later
                $data['billing_email'] = 'paypal-checkout@example.com';
                $data['billing_first_name'] = 'PayPal';
                $data['billing_last_name'] = 'Customer';
            }

            // Create the order
            $order_id = $checkout->create_order($data);

            if (is_wp_error($order_id)) {
                return false;
            }

            $order = wc_get_order($order_id);

            // Set payment method
            $order->set_payment_method($this);
            $order->set_payment_method_title($this->get_title());

            // Update status to pending
            $order->update_status('pending', __('PayPal Checkout payment pending.', 'woocommerce-paypal-pro-payment-gateway'));

            $order->save();

            return $order;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get PayPal access token
     */
    private function get_paypal_access_token() {
        $base_url = $this->sandbox ? 'https://api.sandbox.paypal.com' : 'https://api.paypal.com';

        $response = wp_remote_post($base_url . '/v1/oauth2/token', array(
            'headers' => array(
                'Accept' => 'application/json',
                'Accept-Language' => 'en_US',
                'Authorization' => 'Basic ' . base64_encode($this->client_id . ':' . $this->client_secret),
            ),
            'body' => 'grant_type=client_credentials',
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return isset($data['access_token']) ? $data['access_token'] : false;
    }

    /**
     * Prepare PayPal order data
     */
    private function prepare_paypal_order_data($wc_order) {
        $currency = get_woocommerce_currency();
        $total = $wc_order->get_total();

        return array(
            'intent' => 'CAPTURE',
            'purchase_units' => array(
                array(
                    'reference_id' => $wc_order->get_id(),
                    'amount' => array(
                        'currency_code' => $currency,
                        'value' => number_format($total, 2, '.', '')
                    ),
                    'description' => sprintf(__('Order %s', 'woocommerce-paypal-pro-payment-gateway'), $wc_order->get_order_number())
                )
            ),
            'application_context' => array(
                'brand_name' => get_bloginfo('name'),
                'user_action' => 'PAY_NOW',
                'return_url' => $wc_order->get_checkout_order_received_url(),
                'cancel_url' => wc_get_cart_url()
            )
        );
    }

    /**
     * Create PayPal order via API
     */
    private function create_paypal_order_api($access_token, $order_data) {
        $base_url = $this->sandbox ? 'https://api.sandbox.paypal.com' : 'https://api.paypal.com';

        $response = wp_remote_post($base_url . '/v2/checkout/orders', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $access_token,
            ),
            'body' => wp_json_encode($order_data),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return isset($data['id']) ? $data['id'] : false;
    }

    /**
     * Capture PayPal order via API
     */
    private function capture_paypal_order_api($access_token, $order_id) {
        $base_url = $this->sandbox ? 'https://api.sandbox.paypal.com' : 'https://api.paypal.com';

        $response = wp_remote_post($base_url . '/v2/checkout/orders/' . $order_id . '/capture', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $access_token,
            ),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return $data;
    }

    /**
     * Get WooCommerce order by PayPal order ID
     */
    private function get_wc_order_by_paypal_id($paypal_order_id) {
        $orders = wc_get_orders(array(
            'meta_key' => '_paypal_order_id',
            'meta_value' => $paypal_order_id,
            'limit' => 1,
        ));

        return ! empty($orders) ? $orders[0] : false;
    }

    /**
     * Update WooCommerce order with capture data
     */
    private function update_wc_order_with_capture_data($wc_order, $capture_response) {
        // Update billing details from PayPal if available
        if (isset($capture_response['payer'])) {
            $payer = $capture_response['payer'];

            if (isset($payer['name'])) {
                $wc_order->set_billing_first_name($payer['name']['given_name'] ?? '');
                $wc_order->set_billing_last_name($payer['name']['surname'] ?? '');
            }

            if (isset($payer['email_address'])) {
                $wc_order->set_billing_email($payer['email_address']);
            }
        }

        // Store PayPal transaction details
        if (isset($capture_response['purchase_units'][0]['payments']['captures'][0])) {
            $capture = $capture_response['purchase_units'][0]['payments']['captures'][0];
            $wc_order->update_meta_data('_paypal_transaction_id', $capture['id']);
            $wc_order->update_meta_data('_paypal_capture_response', $capture_response);
        }

        $wc_order->save();
    }
}

// Add a simple test hook that should always fire to verify the file is loaded
add_action('wp_footer', function () {
    echo '<!-- PayPal Checkout Gateway File Loaded -->';
});

// Add page detection for debugging
add_action('wp_footer', function () {
    if (is_cart()) {
        echo '<!-- This is the CART page -->';
    }
    if (is_checkout()) {
        echo '<!-- This is the CHECKOUT page -->';
    }
}, 999);
