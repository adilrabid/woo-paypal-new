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
        // The ownership check above is required: the nonce is public, so it must not be the only gate.
        // An order that is already paid, or already has a PayPal capture recorded, is never captured or completed again.
        if ( $order->is_paid() || $order->get_meta( '_paypal_transaction_id', true ) ) {
            wp_send_json_success( array( 'redirect' => $order->get_checkout_order_received_url() ) );
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

		// Capture the order using the PayPal API.
		// https://developer.paypal.com/docs/api/orders/v2/#orders_capture
		// The raw response is needed: the response body alone is a string even when PayPal rejects the capture.
		$api_injector = new PayPal_Request_API_Injector();
		$response = $api_injector->capture_paypal_order( $paypal_order_id, array( 'return_raw_response' => true ) );
		$http_code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$txn_data = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		$debug_id = is_wp_error( $response ) ? '' : sanitize_text_field( (string) wp_remote_retrieve_header( $response, 'paypal-debug-id' ) );

		// A retried capture: PayPal refuses to capture twice, so read the order's actual state instead.
		if ( 422 === $http_code && self::paypal_error_has_issue( $txn_data, 'ORDER_ALREADY_CAPTURED' ) ) {
			$details = $api_injector->get_paypal_order_details( $paypal_order_id );
			$txn_data = $details ? json_decode( wp_json_encode( $details ), true ) : null;
			$http_code = $details ? 200 : 0;
		}

		if ( ! in_array( $http_code, array( 200, 201 ), true ) || ! is_array( $txn_data ) ) {
			PayPal_Utils::log( sprintf(
				'Capture failed for WooCommerce order #%d (PayPal order %s): HTTP %d%s%s. PayPal Debug ID: %s. The order was NOT marked as paid.',
				$order->get_id(), $paypal_order_id, $http_code,
				is_wp_error( $response ) ? ', ' . $response->get_error_message() : '',
				is_array( $txn_data ) && ! empty( $txn_data['name'] ) ? ', ' . sanitize_text_field( $txn_data['name'] ) . ( ! empty( $txn_data['details'][0]['issue'] ) ? ' (' . sanitize_text_field( $txn_data['details'][0]['issue'] ) . ')' : '' ) : '',
				$debug_id ?: 'none'
			), false );
			// Without a definite answer from PayPal (no response or a server error), the customer may have been charged.
			$message = ( 0 === $http_code || $http_code >= 500 )
				? __( 'We could not confirm your PayPal payment. Please check your PayPal account before trying again, or contact the store.', 'woocommerce-paypal-pro-payment-gateway' )
				: __( 'PayPal could not complete this payment, so you have not been charged and the order has not been paid. Please try again or choose another payment method.', 'woocommerce-paypal-pro-payment-gateway' );
			wp_send_json_error( array( 'message' => $message ) );
		}

		// Only a completed capture for this PayPal order and the full order amount pays the order.
		$verification = PayPal_Utility_IPN_Related::verify_capture_for_order( $order, $paypal_order_id, $txn_data );
		$capture = $verification['capture'];
		PayPal_Utils::log( sprintf( 'Capture result for WooCommerce order #%d: %s. Capture ID: %s. %s', $order->get_id(), $verification['result'], $capture['id'] ?? 'none', $verification['reason'] ), 'completed' === $verification['result'] );

		if ( 'failed' === $verification['result'] ) {
			wp_send_json_error( array( 'message' => __( 'We could not confirm this PayPal payment, so the order has not been marked as paid. Please contact the store if you were charged.', 'woocommerce-paypal-pro-payment-gateway' ) ) );
		}

		if ( 'completed' !== $verification['result'] ) {
			// Money may have moved (a pending capture or an unexpected amount): record it and hold the order for review.
			$order->update_meta_data( '_paypal_transaction_id', $capture['id'] );
			$order->set_transaction_id( $capture['id'] );
			/* translators: 1: Why the payment needs review, 2: PayPal capture ID. */
			$order->update_status( 'on-hold', sprintf( __( 'PayPal payment needs review before this order is fulfilled: %1$s PayPal capture ID: %2$s. Check the payment in your PayPal account.', 'woocommerce-paypal-pro-payment-gateway' ), $verification['reason'], $capture['id'] ) );
			WC()->cart->empty_cart();
			wp_send_json_success( array( 'redirect' => $order->get_checkout_order_received_url() ) );
		}

		$data = array(
			'order_id' => $paypal_order_id,
		);
		$ipn_data = PayPal_Utility_IPN_Related::create_ipn_data_array_from_capture_order_txn_data( $data, $txn_data );

		$wc_order = PayPal_Utility_IPN_Related::complete_post_payment_processing( $order, $txn_data, $capture );
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

	/** Whether a PayPal error response lists the given issue code. */
	private static function paypal_error_has_issue( $body, $issue ) {
		$details = is_array( $body ) && isset( $body['details'] ) && is_array( $body['details'] ) ? $body['details'] : array();
		foreach ( $details as $detail ) {
			if ( is_array( $detail ) && $issue === ( $detail['issue'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}
}
