<?php

namespace TTHQ\WC_PP_PRO\Lib\PayPal;

/**
 * This clcass handles the ajax requests from the PayPal button's createOrder, captureOrder functions.
 * On successful onApprove event, it creates the required $ipn_data array from the transaction so it can be fed into the existing IPN handler functions easily.
 */
class PayPal_Button_Ajax_Handler {

	public $wc_paypal_ppcp;
	private $checkout_customer_data = array();

	public function __construct() {
		//Handle it at 'wp_loaded' hook since custom post types will also be available at that point.
		add_action( 'wp_loaded', array(&$this, 'setup_ajax_request_actions' ) );
	}

	/**
	 * Setup the ajax request actions.
	 */
	public function setup_ajax_request_actions() {
		/*----- Cart Checkout Related -----*/
		//Handle the create-order ajax request for 'Add to Cart' type buttons.
		add_action( PayPal_Utils::hook('pp_create_order', true), array($this, 'pp_create_order' ) );
		add_action( PayPal_Utils::hook('pp_create_order', true, true), array($this, 'pp_create_order' ) );
		
		//Handle the capture-order ajax request for 'Add to Cart' type buttons.
		add_action( PayPal_Utils::hook('pp_capture_order', true), array($this, 'pp_capture_order' ) );
		add_action( PayPal_Utils::hook('pp_capture_order', true, true), array($this, 'pp_capture_order' ) );
	}

	/**
	 * Handle the pp_create_order ajax request for standard cart checkout.
	 */
	 public function pp_create_order(){
		if(! check_ajax_referer(PayPal_Utils::auto_prefix('pp_checkout_nonce'), 'nonce', false)){
			wp_send_json_error(array('message' => 'Failed to create order. Nonce verification failed!'));
		}

		$gateways = WC()->payment_gateways()->payment_gateways();

		$wc_paypal_ppcp = null;
		if ( isset( $gateways['paypal_checkout'] ) ) {
			$wc_paypal_ppcp = $gateways['paypal_checkout'];
		}

		if (empty($wc_paypal_ppcp)) {
			wp_send_json_error(array('message' => 'Failed to create order. Payment Gateway not found.'));
		}

		$this->wc_paypal_ppcp = $wc_paypal_ppcp;
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			wp_send_json_error( array( 'message' => __( 'Your cart is empty. Please refresh checkout.', 'woocommerce-paypal-pro-payment-gateway' ) ) );
		}
		if ( ! $wc_paypal_ppcp->is_available() || PayPal_Checkout_Guard::unsupported_subscription() || $wc_paypal_ppcp->is_subscription_checkout() ) {
			wp_send_json_error( array( 'message' => __( 'This cart cannot use a one-time PayPal payment.', 'woocommerce-paypal-pro-payment-gateway' ) ) );
		}
		try {
			$this->checkout_customer_data = PayPal_Checkout_Guard::read_customer();
			PayPal_Checkout_Guard::validate_addresses( $this->checkout_customer_data, WC()->cart->needs_shipping() );
			foreach ( $this->checkout_customer_data as $key => $value ) {
				$setter = 'set_' . $key;
				WC()->customer->$setter( $value );
			}
			WC()->cart->calculate_totals();
			PayPal_Checkout_Guard::validate_shipping( WC()->cart );
		} catch ( \InvalidArgumentException $error ) {
			wp_send_json_error( array( 'message' => $error->getMessage() ) );
		}
		$fingerprint = PayPal_Checkout_Attempt::fingerprint( $wc_paypal_ppcp );
		$previous_order = PayPal_Checkout_Attempt::get_order( 'payment', $fingerprint );
		if ( $previous_order && PayPal_Checkout_Guard::unsupported_subscription( $previous_order ) ) {
			wp_send_json_error( array( 'message' => __( 'This order cannot use a one-time PayPal payment.', 'woocommerce-paypal-pro-payment-gateway' ) ), 403 );
		}
		if ( $previous_order ) {
			$approval_id = PayPal_Checkout_Attempt::get_approval_id( $previous_order, 'payment' );
			if ( is_wp_error( $approval_id ) ) {
				wp_send_json_error( array( 'message' => $approval_id->get_error_message() ) );
			}
			if ( $approval_id ) {
				wp_send_json_success( array( 'order_id' => $approval_id, 'wc_order_id' => $previous_order->get_id() ) );
			}
		}

        // Create WooCommerce order from current cart
        $wc_order = $this->create_wc_order_from_cart( $fingerprint );

        if (! $wc_order) {
            wp_send_json_error(array('message' => 'Failed to create order'));
        }

		/* translators: %s: WooCommerce order number. */
		$description = sprintf(__('Order %s', 'woocommerce-paypal-pro-payment-gateway'), $wc_order->get_order_number());

		// Create the order using the PayPal API.
		// https://developer.paypal.com/docs/api/orders/v2/#orders_create
		$data = array(
			'description' => $description,
			'grand_total' => $wc_order->get_total(),
			// 'sub_total' => $formatted_sub_total,
			// 'postage_cost' => $formatted_postage_cost,
			// 'tax' => $formatted_tax_amount,
			'currency' => get_woocommerce_currency(),
			// 'shipping_preference' => $shipping_preference,
			'application_context' => array(
                'brand_name' => get_bloginfo('name'),
                'user_action' => 'PAY_NOW',
                'return_url' => $wc_order->get_checkout_order_received_url(),
                'cancel_url' => wc_get_cart_url()
            )
		);

        $data['application_context']['shipping_preference'] = WC()->cart->needs_shipping() ? 'SET_PROVIDED_ADDRESS' : 'NO_SHIPPING';
        if ( WC()->cart->needs_shipping() ) {
            $data['shipping'] = array(
                'name' => array( 'full_name' => trim( $wc_order->get_shipping_first_name() . ' ' . $wc_order->get_shipping_last_name() ) ),
                'address' => array(
                    'address_line_1' => $wc_order->get_shipping_address_1(),
                    'address_line_2' => $wc_order->get_shipping_address_2(),
                    'admin_area_2' => $wc_order->get_shipping_city(),
                    'admin_area_1' => $wc_order->get_shipping_state(),
                    'postal_code' => $wc_order->get_shipping_postcode(),
                    'country_code' => $wc_order->get_shipping_country(),
                ),
            );
        }

		//Set the additional args for the API call.
		$additional_args = array();
		$additional_args['return_response_body'] = true;

		//Create the order using the PayPal API.
		$api_injector = new PayPal_Request_API_Injector();
        $response = $api_injector->create_paypal_order_by_url_and_args($data, $additional_args);

		PayPal_Utils::log_array(json_decode($response));

		//We requested the response body to be returned, so we need to JSON decode it.
		if( $response !== false ){
			$order_data = json_decode( $response, true );
			$paypal_order_id = isset( $order_data['id'] ) ? $order_data['id'] : '';
		} else {
			//Failed to create the order.
            wp_send_json_error(array('message' => 'Failed to create PayPal order'));
		}
		if ( empty( $paypal_order_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Failed to create PayPal order. Please try again.', 'woocommerce-paypal-pro-payment-gateway' ) ) );
		}

        // Store PayPal order ID in WC order meta
        $wc_order->update_meta_data('_paypal_order_id', $paypal_order_id);

		 $wc_order_attributions = isset($_POST['attributions']) ? map_deep( json_decode(wp_unslash( $_POST['attributions'] ), true), 'sanitize_text_field') : array();
		 if (!empty($wc_order_attributions)) {
			 PayPal_Utils::add_wc_order_attribution_fields( $wc_order,  $wc_order_attributions);
		 }

        $wc_order->save();

        wp_send_json_success(array('order_id' => $paypal_order_id, 'wc_order_id' => $wc_order->get_id()));
    }

	/**
     * Create WooCommerce order from current cart
     */
    private function create_wc_order_from_cart( $fingerprint ) {
        try {
            $data = $this->checkout_customer_data;
            $data['ship_to_different_address'] = 1;

            // Create the order
            $order_id = PayPal_Checkout_Attempt::create_order( $data, 'payment', $fingerprint );

            if (is_wp_error($order_id)) {
                return false;
            }

            $order = wc_get_order($order_id);

            // Set payment method
            $order->set_payment_method($this->wc_paypal_ppcp);
            $order->set_payment_method_title($this->wc_paypal_ppcp->get_title());

            // Update status to pending
            $order->update_status('pending', __('PayPal Checkout payment pending.', 'woocommerce-paypal-pro-payment-gateway'));

            $order->save();
			PayPal_Checkout_Attempt::remember( $order, 'payment', $fingerprint );

            return $order;
        } catch (\Exception $e) {
            return false;
        }
    }

	/**
	 * Handles the order capture for standard cart checkout.
	 */
	public function pp_capture_order(){
		if(! check_ajax_referer(PayPal_Utils::auto_prefix('pp_checkout_nonce'), 'nonce', false)){
			wp_send_json_error(array('message' => 'Failed to create order. Nonce verification failed!'));
		}

        $paypal_order_id = isset($_POST['paypal_order_id']) ? sanitize_text_field($_POST['paypal_order_id']) : '';

        if (empty($paypal_order_id)) {
			PayPal_Utils::log( 'pp_capture_order - empty order ID received.', false );
            wp_send_json_error(array('message' => 'PayPal Order ID is required'));
        }

        $orders = wc_get_orders( array( 'type' => 'shop_order', 'meta_key' => '_paypal_order_id', 'meta_value' => $paypal_order_id, 'limit' => 1 ) );
        $order = $orders ? $orders[0] : false;
        if ( ! $order || 'paypal_checkout' !== $order->get_payment_method()
            || (int) $order->get_customer_id() !== get_current_user_id()
            || ( ! get_current_user_id() && (int) WC()->session->get( 'wcpprog_checkout_attempt_payment' ) !== $order->get_id() )
            || PayPal_Checkout_Guard::unsupported_subscription( $order )
            || $order->get_meta( '_wcppprog_paypal_subscription_id', true ) ) {
            wp_send_json_error( array( 'message' => __( 'This order cannot use a one-time PayPal payment.', 'woocommerce-paypal-pro-payment-gateway' ) ), 403 );
        }
        $needs_shipping = false;
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $needs_shipping = $needs_shipping || ( $product && $product->needs_shipping() );
            if ( $product && 'wcpprog_subscription' === $product->get_type() ) {
                wp_send_json_error( array( 'message' => __( 'Subscriptions cannot use a one-time PayPal payment.', 'woocommerce-paypal-pro-payment-gateway' ) ), 403 );
            }
        }

        $address_data = array();
        foreach ( array( 'billing', 'shipping' ) as $type ) {
            foreach ( $order->get_address( $type ) as $field => $value ) {
                $address_data[ $type . '_' . $field ] = $value;
            }
        }
        try {
            PayPal_Checkout_Guard::validate_addresses( $address_data, $needs_shipping );
        } catch ( \InvalidArgumentException $error ) {
            wp_send_json_error( array( 'message' => $error->getMessage() ) );
        }

		//Set the additional args for the API call.
		$additional_args = array();
		$additional_args['return_response_body'] = true;

		// Capture the order using the PayPal API.
		// https://developer.paypal.com/docs/api/orders/v2/#orders_capture
		$api_injector = new PayPal_Request_API_Injector();
		$response = $api_injector->capture_paypal_order( $paypal_order_id, $additional_args );

		//We requested the response body to be returned, so we need to JSON decode it.
		if($response !== false){
			$txn_data = json_decode( $response, true );//JSON decode the response body that we received.
		} else {
			//Failed to capture the order.
			wp_send_json_error(array('message' => 'Failed to capture PayPal payment'));
		}

		$data = array(
			'order_id' => $paypal_order_id,
		);

		$ipn_data = PayPal_Utility_IPN_Related::create_ipn_data_array_from_capture_order_txn_data( $data, $txn_data );
		$paypal_capture_id = isset( $ipn_data['txn_id'] ) ? $ipn_data['txn_id'] : '';
		PayPal_Utils::log( 'PayPal Capture ID (Transaction ID): ' . $paypal_capture_id, true );
		PayPal_Utils::log_array( $ipn_data, true );//Debugging purpose.

		/* Since this capture is done from server side, the validation is not required but we are doing it anyway. */
		//Validate the buy now txn data before using it.
		$validation_response = PayPal_Utility_IPN_Related::validate_buy_now_checkout_txn_data( $data, $txn_data );
		if( empty($validation_response) ){
			wp_send_json_error(array('message' => $validation_response));
		}

		/**
		 * TODO: This is a plugin specific method.
		 */
		$wc_order = PayPal_Utility_IPN_Related::complete_post_payment_processing( $data, $txn_data, $ipn_data );
		if (is_wp_error($wc_order)) {
			 wp_send_json_error(array('message' => $wc_order->get_error_message()));
		}

		/**
		 * Trigger the IPN processed action hook (so other plugins can can listen for this event).
		 * Remember to use plugin shortname as prefix when searching for this hook.
		 */ 
		do_action( PayPal_Utils::hook('paypal_checkout_ipn_processed'), $ipn_data );
		do_action( PayPal_Utils::hook('payment_ipn_processed'), $ipn_data );

        wp_send_json_success(array(
            'redirect' => $wc_order->get_checkout_order_received_url()
        ));
	}
}
