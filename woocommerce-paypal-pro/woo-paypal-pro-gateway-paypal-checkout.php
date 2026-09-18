<?php

/**
 * WooCommerce PayPal Checkout Gateway Class
 *
 * Adds PayPal Checkout as a separate payment gateway alongside PayPal Pro.
 */

use TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_JS_Button_Embed;
use TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Utils;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

require WC_PP_PRO_ADDON_PATH . '/traits/trait-wcpprog-ppcp-gateway.php';

class WC_Gateway_PayPal_Checkout extends WC_Payment_Gateway {

    use WC_Gateway_PayPal_Checkout_Trait;

    private $sandbox;
    private $client_id;
    private $client_secret;
    public string $pp_js_sdk_script_handler = 'wcpprog-paypal-checkout-sdk';

    public function __construct() {
        $this->id                 = 'paypal_checkout';
        $this->icon               = apply_filters('woocommerce_paypal_checkout_icon', WC_PP_PRO_ADDON_URL . '/assets/img/pp-ppcp.svg');
        $this->has_fields         = true;
        $this->method_title       = __('PayPal Checkout', 'woocommerce-paypal-pro-payment-gateway');
        $this->method_description = __('Accept payments through secure PayPal Checkout with the latest PayPal payment buttons.', 'woocommerce-paypal-pro-payment-gateway');
        $this->supports           = array('products');

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title          = $this->get_option('title');
        $this->description    = $this->get_option('description');
        $this->enabled        = $this->get_option('enabled');
        $this->sandbox        = $this->get_option('sandbox') === 'yes';
        $this->client_id      = $this->sandbox ? $this->get_option('sandbox_client_id') : $this->get_option('live_client_id');
        $this->client_secret  = $this->sandbox ? $this->get_option('sandbox_client_secret') : $this->get_option('live_client_secret');

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        // Initialize hooks after WordPress is loaded
        add_action('init', array($this, 'init_hooks'), 20);
    }

    public function get_icon() {
        if ( is_admin() ) {
            return $this->icon;
        }

        // return '<img src="'.$this->icon.'" style="height: 24px">';
        return ''; // Don't show icon in front end.
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

        if ( wp_doing_ajax() && is_admin() && current_user_can( 'manage_options' ) ) {
            $this->init_webhooks();
        }
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

        if ( $this->is_subscription_checkout() ) {
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
                'default' => 'no',
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

            'live_webhook_status' => array(
                'title'       => __('Live Webhook Status', 'woocommerce-paypal-pro-payment-gateway'),
                'type'        => 'webhook_status',
                'description' => __('TODO: Need to update.', 'woocommerce-paypal-pro-payment-gateway'),
                'default'     => '',
                'desc_tip'    => true,
                'subtab'    => 'webhooks',
                'custom_attrs' => array(
                    'mode' => 'live',
                ),
            ),
            'test_webhook_status' => array(
                'title'       => __('Test Webhook Status', 'woocommerce-paypal-pro-payment-gateway'),
                'type'        => 'webhook_status',
                'description' => __('TODO: Need to update.', 'woocommerce-paypal-pro-payment-gateway'),
                'default'     => '',
                'desc_tip'    => true,
                'subtab'    => 'webhooks',
                'custom_attrs' => array(
                    'mode' => 'test',
                ),
            ),
            'delete_webhooks' => array(
                'title'       => __('Delete Webhooks', 'woocommerce-paypal-pro-payment-gateway'),
                'type'        => 'delete_webhooks',
                'description' => __('TODO: Need to update.', 'woocommerce-paypal-pro-payment-gateway'),
                'default'     => '',
                'desc_tip'    => true,
                'subtab'    => 'webhooks',
            ),
        );
    }

    /**
     * Renders settings fields.
     *
     * NOTE: This is an overridden function.
     */
    public function admin_options() {
		$return_path = null;
		wc_back_header( $this->get_method_title(), esc_html__( 'Return to payments', 'woocommerce-paypal-pro-payment-gateway' ), \Automattic\WooCommerce\Internal\Admin\Settings\Utils::wc_payments_settings_url( $return_path ) );

		echo wp_kses_post( wpautop( $this->get_method_description() ) );

        $doc_link = 'https://wp-ecommerce.net/woocommerce-paypal-checkout-paypal-pro';
        $doc_link_html = '<a href="'.esc_url($doc_link).'" target="_blank">'.__( 'PayPal Checkout documentation', 'woocommerce-paypal-pro-payment-gateway').'</a>';
        echo '<p>';
        /* translators: %s: Link to the PayPal Checkout documentation. */
        echo wp_kses_post( sprintf(__( 'Please refer to the %s for setup instructions.', 'woocommerce-paypal-pro-payment-gateway'), $doc_link_html) );
        echo '</p>';

        $current_tab = isset($_GET['subtab']) && !empty($_GET['subtab']) ? sanitize_text_field($_GET['subtab']) : 'general';

        $subtabs = array(
            'general' => __('General', 'woocommerce-paypal-pro-payment-gateway'),
            'api_connection' => __('API Connection', 'woocommerce-paypal-pro-payment-gateway'),
            'api_credentials' => __('API Credentials', 'woocommerce-paypal-pro-payment-gateway'),
            'webhooks' => __('Webhooks', 'woocommerce-paypal-pro-payment-gateway'),
        );

        echo '<h3 class="nav-tab-wrapper">';
        foreach ($subtabs as $stab => $title) { 
            $tab_link = 'admin.php?page=wc-settings&tab=checkout&section=paypal_checkout&subtab='.$stab;
            $active_class = $stab == $current_tab ? 'nav-tab-active' : ''; 
            echo '<a class="nav-tab '.esc_attr($active_class).'" href="'.esc_url($tab_link).'">'.esc_html($title).'</a>';
        } 
		echo '</h3>';

        $this->render_subtab_fields($current_tab);

        wp_enqueue_script(
            'woo-pp-pro-admin-js',
            WC_PP_PRO_ADDON_URL . '/assets/js/woo-pp-pro-admin.js',
            array(),
            WC_PP_PRO_ADDON_VERSION,
            true
        );

        wp_localize_script('woo-pp-pro-admin-js', 'wcpprog_admin_js_vars', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'actions' => array(
                'check_webhook' => 'wcpprog_ppcp_check_webhooks',
                'create_webhook' => 'wcpprog_ppcp_create_webhook',
                'delete_webhook' => 'wcpprog_ppcp_delete_webhooks',
            ),
            'nonces' => array(
                'check_webhook' => wp_create_nonce( 'wcpprog-ppcp-check-webhook' ),
                'create_webhook' => wp_create_nonce( 'wcpprog-ppcp-create-webhook' ),
                'delete_webhook' => wp_create_nonce( 'wcpprog-ppcp-delete-webhook' ),
            ),
            'str' => array(
                'clearing'        => __( 'Clearing...', 'woocommerce-paypal-pro-payment-gateway' ),
                'errorOccured'    => __( 'Error occurred:', 'woocommerce-paypal-pro-payment-gateway' ),
                'creatingWebhook' => __( 'Creating webhook...', 'woocommerce-paypal-pro-payment-gateway' ),
                'deleting'        => __( 'Deleting...', 'woocommerce-paypal-pro-payment-gateway' ),
            ),
            'is_sandbox_enabled' => $this->sandbox,
        ));
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
            echo '<table class="form-table">' . $this->generate_settings_html( $subtab_fields, false ) . '</table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce renders settings fields; custom renderers escape values and include required scripts.
        } else {
            echo esc_html__('No fields found for this subtab', 'woocommerce-paypal-pro-payment-gateway');
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

        $current_subtab = isset($_GET['subtab']) ? $_GET['subtab'] : 'general';

        /**
         * Unchecked checkbox doesn't appear on the post request.
         * Check if the checkbox belongs to current tab but not set (meaning unchecked)
         */
        if ($type == 'checkbox' && !isset( $post_data[$field_key] )) {
            if (isset($this->form_fields[$key]) && $this->form_fields[$key]['subtab'] == $current_subtab ) {
                return '';
            }
        }

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

        $pp_btn_js_embed = PayPal_JS_Button_Embed::get_instance();

        $pp_js_sdk_args = array(
            'is_live_mode' => empty($this->sandbox),
            'live_client_id' => $this->client_id,
            'sandbox_client_id' => $this->client_id,
            'currency' => get_woocommerce_currency(),
        );

        $paypal_button_type = 'buy_now';

        if ($this->is_subscription_checkout()){
            $paypal_button_type = 'subscription';
            $pp_js_sdk_args['intent'] = 'subscription';
            $pp_js_sdk_args['is_subscription'] = 1;
        }

        $pp_btn_js_embed->set_settings_args($pp_js_sdk_args);

        $pp_btn_js_embed->enqueue_papal_sdk_script($this->pp_js_sdk_script_handler);

        wp_enqueue_script(
            'woo-pp-pro-ppcp-related',
            WC_PP_PRO_ADDON_URL . '/assets/js/woo-pp-pro-ppcp-related.js',
            array('jquery', $this->pp_js_sdk_script_handler),
            WC_PP_PRO_ADDON_VERSION,
            true
        );

        wp_localize_script($this->pp_js_sdk_script_handler, 'wc_paypal_checkout_params', array(
            'ajax_url'    => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce(PayPal_Utils::auto_prefix('pp_checkout_nonce')),
            'create_order_ajax_action' => PayPal_Utils::auto_prefix('pp_create_order'),
            'capture_order_ajax_action' => PayPal_Utils::auto_prefix('pp_capture_order'),
            'create_sub_order_ajax_action' => PayPal_Utils::auto_prefix('sub_pp_create_subscription'),
            'onapprove_sub_order_ajax_action' => PayPal_Utils::auto_prefix('sub_onapprove_process_subscription'),
            'webhook_missing_notice' => esc_js($this->webhook_missing_notice()),
            'btn_type'    => esc_js($paypal_button_type),
            'currency'    => get_woocommerce_currency(),
            'total'       => WC()->cart ? WC()->cart->get_total('raw') : 0,
        ));
    }

    public function webhook_missing_notice() {
        if (! $this->is_subscription_checkout()){
            return '';
        }

        $mode = $this->sandbox ? 'sandbox' : 'live';
        $wh_id = PayPal_Utils::get_option( 'paypal_webhook_id_' . $mode );
        if (empty($wh_id)) {
            return esc_html__('Webhooks are not configured!', 'woocommerce-paypal-pro-payment-gateway');
        }

        return '';
    }

    /**
     * Render PayPal button on cart page (for cart shortcode)
     */
    public function payment_fields() {
        ?>
        <div id="paypal-checkout-button-container"></div>
        <script>
            document.addEventListener('wcpprog_paypal_sdk_ready', function () {
                woo_pp_pro_render_ppcp_btn('#paypal-checkout-button-container');
            }, { once: true });
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

        if ( $this->is_subscription_checkout() ) {
            return;
        }

        echo '<!-- PayPal Checkout: Rendering button on cart page -->';
        echo '<div class="wc-paypal-checkout-cart-button" style="border: 1px solid #ddd; padding: 15px; margin: 15px 0; border-radius: 5px;">';
        echo '<h3>' . esc_html__('Or pay with PayPal', 'woocommerce-paypal-pro-payment-gateway') . '</h3>';
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
     * Process the payment (required by WC_Payment_Gateway)
     */
    public function process_payment($order_id) {
        return array(
            'result'   => 'success',
            'redirect' => '',
        );
    }

    public function is_subscription_checkout() {
        return $this->is_cart_all_subscription();
    }

    /**
     * Check whether every item in the cart is the custom subscription product type.
     *
     * @param WC_Cart|null $cart Optional. Defaults to the global cart.
     * @return bool
     */
    public function is_cart_all_subscription( $cart = null ) {
        $cart = !empty($cart) ? $cart : WC()->cart;

        if ( ! $cart || $cart->is_empty() ) {
            return false;
        }

        foreach ( $cart->get_cart() as $item ) {
            if ( WCPPROG_Subscription_Related::SUBSCRIPTION_PRODUCT_TYPE !== $item['data']->get_type() ) {
                return false;
            }
        }

        return true;
    }
}
