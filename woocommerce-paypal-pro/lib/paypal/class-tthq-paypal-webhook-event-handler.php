<?php

namespace TTHQ\WC_PP_PRO\Lib\PayPal;

/**
 * A Webhook class. Represents a webhook object with parameters in a given mode.
 */
class PayPal_Webhook_Event_Handler {

	public function __construct() {
		//Register to handle the webhook event.
		//Handle it at 'wp_loaded' since custom post types will also be available then
		add_action( 'wp_loaded', array(&$this, 'handle_paypal_webhook' ) );
	}

    public function handle_paypal_webhook(){
        //Handle PayPal Webhook
		if ( ! isset( $_GET['action'] ) || $_GET['action'] !== PayPal_Main::$paypal_webhook_event_query_arg || ! isset( $_GET['mode'] ) ) {
			return;
		}

		$event = file_get_contents( 'php://input' );

		if ( ! $event || substr( $event, 0, 1 ) !== '{' ) {
			PayPal_Utils::log( 'WebHook Error: Empty or non-JSON webhook data!', false );
			wp_die();
		}

		$event = json_decode( $event, true );
		$event_type = isset($event['event_type']) ? sanitize_text_field($event['event_type']) : '';
		$event_summary = isset($event['summary']) ? sanitize_text_field($event['summary']) : '';

		PayPal_Utils::log( 'Webhook event type: ' . $event_type . '. Event summary: ' . $event_summary, true );

		if ($_GET['mode'] == 'production') {
			$mode = 'production';
		} else {
            $mode = 'sandbox';
        }

		//If using simulator, this will need to be commented out.
		//Verify the webhook for the given mode.
		if ( ! self::verify_webhook_event_for_given_mode( $event, $mode ) ) {
			status_header(200);//Send a 200 status code to PayPal to indicate that the webhook event was received successfully.
			wp_die();
		}

		//Handle the events
		//https://developer.paypal.com/api/rest/webhooks/event-names/#link-subscriptions

		// Debug purpose only.
		// PayPal_Utils::log_array('Received event data:');
		// PayPal_Utils::log_array($event);

		//We will handle the following webhook event types.
		//The subscription is added to the payments/transactions menu/database at checkout time (from the front-end). Later these events are used to update the status of the entries.
		switch ( $event_type ) {
			case 'BILLING.SUBSCRIPTION.ACTIVATED':
				// A subscription is activated. This has all the details (including the customer details) in the webhook event.
				$this->handle_subscription_status_update('activated', $event, $mode );
				break;
			case 'BILLING.SUBSCRIPTION.EXPIRED':
				// A subscription expires.
				$this->handle_subscription_status_update('expired', $event, $mode );
				break;
			case 'BILLING.SUBSCRIPTION.CANCELLED':
				// A subscription is cancelled.
				$this->handle_subscription_status_update('cancelled', $event, $mode );
				break;
			case 'BILLING.SUBSCRIPTION.SUSPENDED':
				// A subscription is suspended.
				$this->handle_subscription_status_update('suspended', $event, $mode );
				break;
			case 'PAYMENT.SALE.COMPLETED':
				// A payment is made on a subscription. Update access starts date (if needed).
				$this->handle_subscription_payment_received('sale_completed', $event, $mode );
				break;
			case 'PAYMENT.SALE.REFUNDED':
				// A merchant refunded a sale.
				$this->handle_payment_refunded( 'sale_refunded', $event, $mode );
				break;
			case 'PAYMENT.SALE.REVERSED':
			case 'PAYMENT.CAPTURE.REVERSED':
				$this->handle_payment_refunded( 'reversed', $event, $mode );
				break;
			case 'PAYMENT.CAPTURE.REFUNDED':
				// A merchant refunded a payment capture.
				$this->handle_payment_refunded( 'capture_refunded', $event, $mode );
				break;
			case 'BILLING.SUBSCRIPTION.PAYMENT.FAILED':
				$this->handle_subscription_status_update( 'suspended', $event, $mode );
				break;
			default:
				// Nothing to do for us. Ignore this event.
				PayPal_Utils::log("We currently don't handle this event type: " . $event_type );
				break;
		}


		/**
		 * Trigger an action hook after webhook verification (can be used to do further customization).
		 *
		 * @param array $event The event data received from PayPal API.
		 * @see https://developer.paypal.com/docs/api-basics/notifications/webhooks/notification-messages/
		 * 
		 * * Remember to use plugin shortname as prefix as tag when hooking to this hook.
		 * * Example: 'paypal_subscription_webhook_event' is actually '<prefix>_paypal_subscription_webhook_event'
		 */
		do_action( PayPal_Utils::hook('paypal_subscription_webhook_event'), $event );

		if ( ! headers_sent() ) {
			//Send a 200 status code to PayPal to indicate that the webhook event was received successfully.
			header("HTTP/1.1 200 OK");
		}
		echo '200 OK';//Force header output.
		exit;
    }

	/**
	 * Handle subscription status update.
	 */
	private function handle_subscription_status_update( $status, $event, $mode ) {
		PayPal_Utils::log( 'Webhook: processing subscription status ' . $status, true );
		$resource = isset( $event['resource'] ) ? $event['resource'] : array();
		$id       = isset( $resource['id'] ) ? $resource['id'] : '';
		$sub_order      = $id ? $this->get_subscription_order_by_paypal_sub_id( $id ) : null;

		if ( ! $sub_order ) {
			PayPal_Utils::log( 'Webhook: status event ignored because subscription was not found.', true );

			return;
		}

		$map = array(
			'activated' => 'wcpprog-active',
			'suspended' => 'wcpprog-on-hold',
			'cancelled' => 'wcpprog-cancelled',
			'expired'   => 'wcpprog-expired'
		);

		if ( isset( $map[ $status ] ) ) {
			// PayPal activation approves the agreement, including its trial period.
			if ( 'activated' === $status && 'yes' === $sub_order->get_meta( '_wcpprog_has_trial', true ) && 'yes' !== $sub_order->get_meta( '_wcpprog_regular_payment_received', true ) ) {
				$map[ $status ] = 'wcpprog-trial';
			}
			$sub_order->set_status( $map[ $status ] );
			$sub_order->update_meta_data( '_paypal_subscription_status', strtoupper( $status ) );
			if ( ! empty( $resource['billing_info']['next_billing_time'] ) ) {
				$sub_order->set_next_payment_date( gmdate( 'Y-m-d H:i:s', strtotime( $resource['billing_info']['next_billing_time'] ) ) );
			}
			/* translators: %s: PayPal subscription status. */
			$sub_order->add_order_note( sprintf( __( 'PayPal subscription %s.', 'woocommerce-paypal-pro-payment-gateway' ), $status ) );
			$sub_order->save();
			PayPal_Utils::log( 'Webhook: subscription order #' . $sub_order->get_id() . ' updated to ' . $map[ $status ], true );
		}
	}

	/**
	 * Handle subscription payment received.
	 */
	private function handle_subscription_payment_received( $payment_status, $event, $mode ) {
		$paypal_sub_id = $event['resource']['billing_agreement_id'] ?? '';
		if ( ! $paypal_sub_id || empty( $event['resource']['id'] ) ) {
			return;
		}
		$lock = 'wcpprog_payment_lock_' . md5( $mode . $paypal_sub_id );
		if ( ! add_option( $lock, time(), '', false ) ) {
			wp_die( 'Subscription payment is being processed. Retry later.', '', array( 'response' => 503 ) );
		}
		$locked = true;
		register_shutdown_function( static function () use ( $lock, &$locked ) {
			if ( $locked ) {
				delete_option( $lock );
			}
		} );
		try {
			$this->process_subscription_sale( $payment_status, $event, $mode );
		} finally {
			delete_option( $lock );
			$locked = false;
		}
	}

	private function process_subscription_sale( $payment_status, $event, $mode ) {
		$r              = isset( $event['resource'] ) ? $event['resource'] : array();
		$paypal_sub_id      = isset( $r['billing_agreement_id'] ) ? $r['billing_agreement_id'] : '';
		$transaction_id = isset( $r['id'] ) ? $r['id'] : '';
		$sub_order            = $paypal_sub_id ? $this->get_subscription_order_by_paypal_sub_id( $paypal_sub_id ) : null;

		PayPal_Utils::log( 'Webhook: processing recurring payment ' . $transaction_id . ' for PayPal subscription ' . $paypal_sub_id, true );

		if ( ! $sub_order ) {
			PayPal_Utils::log( 'Webhook: checkout subscription not ready; requesting delivery retry for ' . $paypal_sub_id, true );
			wp_die( 'Subscription checkout is not ready. Retry later.', '', array( 'response' => 503 ) );
		}
		if ( ! $transaction_id ) {
			PayPal_Utils::log( 'Webhook: recurring payment ignored because required data is missing.', true );

			return;
		}

		$existing = wc_get_orders( array(
			'type' => 'shop_order',
			'meta_key'   => '_paypal_transaction_id',
			'meta_value' => $transaction_id,
			'limit'      => 1
		) );

		$parent = wc_get_order( $sub_order->get_parent_order_id_ref() );
		if ( empty( $existing ) && $parent && 'yes' === $parent->get_meta( '_wcpprog_initial_payment_pending', true ) ) {
			$amount = $r['amount']['total'] ?? ( $r['amount']['value'] ?? '' );
			$currency = $r['amount']['currency'] ?? ( $r['amount']['currency_code'] ?? '' );
			if ( '' === $amount || wc_format_decimal( $amount, wc_get_price_decimals() ) !== wc_format_decimal( $parent->get_total(), wc_get_price_decimals() ) || $currency !== $parent->get_currency() ) {
				PayPal_Utils::log( 'Webhook: initial payment amount/currency does not match checkout order #' . $parent->get_id(), false );
				wp_die( 'Initial payment does not match checkout.', '', array( 'response' => 503 ) );
			}
			$parent->update_meta_data( '_paypal_transaction_id', $transaction_id );
			$parent->update_meta_data( '_wcpprog_initial_payment_pending', 'no' );
			$parent->set_transaction_id( $transaction_id );
			$parent->save();
			$parent->payment_complete( $transaction_id );
			/* translators: %s: PayPal transaction ID. */
			$parent->add_order_note( sprintf( __( 'PayPal initial subscription payment received. Transaction ID: %s', 'woocommerce-paypal-pro-payment-gateway' ), $transaction_id ) );
			$existing = array( $parent );
			PayPal_Utils::log( 'Webhook: initial payment linked to checkout order #' . $parent->get_id(), true );
		}
		if ( empty( $existing ) ) {
			PayPal_Utils::log( 'Webhook: creating renewal order for subscription order #' . $sub_order->get_id(), true );
			$order = wc_create_order( array( 'customer_id' => $sub_order->get_customer_id() ) );
			$order->set_payment_method( 'paypal_checkout' );
			$order->set_payment_method_title( __( 'PayPal Checkout', 'woocommerce-paypal-pro-payment-gateway' ) );
			$order->set_billing_address( $sub_order->get_address( 'billing' ) );
			$order->set_shipping_address( $sub_order->get_address( 'shipping' ) );
			foreach ( $sub_order->get_items() as $item ) {
				$order->add_item( PayPal_Utility_IPN_Related::copy_subscription_line_item( $item ) );
			}
			$order->calculate_totals( false );
			$amount = isset( $r['amount']['total'] ) ? $r['amount']['total'] : ( isset( $r['amount']['value'] ) ? $r['amount']['value'] : '' );
			if ( '' !== $amount ) {
				$order->set_total( (float) $amount );
			}
			$order->update_meta_data( '_paypal_subscription_id', $paypal_sub_id );
			$order->update_meta_data( '_paypal_transaction_id', $transaction_id );
			$order->update_meta_data( '_wcpprog_subscription_order_id', $sub_order->get_id() );
			$order->save();
			$order->payment_complete( $transaction_id );
			/* translators: %s: PayPal transaction ID. */
			$order->add_order_note( sprintf( __( 'PayPal subscription renewal payment completed. Transaction ID: %s', 'woocommerce-paypal-pro-payment-gateway' ), $transaction_id ) );
			$sub_order->add_related_order_id( $order->get_id() );
			PayPal_Utils::log( 'Webhook: renewal order #' . $order->get_id() . ' created for transaction ' . $transaction_id, true );
		} else {
			PayPal_Utils::log( 'Webhook: transaction ' . $transaction_id . ' already belongs to order #' . $existing[0]->get_id(), true );
			return;
		}
		// The initial charge (including a paid trial) is linked to the parent
		// above. A new renewal order marks the start of regular billing.
		$sub_order->update_meta_data( '_wcpprog_regular_payment_received', 'yes' );
		$sub_order->set_status( 'wcpprog-active' );
		PayPal_Utils::log( 'Webhook: regular payment received; subscription order #' . $sub_order->get_id() . ' is active.', true );
		$sub_order->update_meta_data( '_paypal_last_transaction_id', $transaction_id );
		$sub_order->save();
	}

	/**
	 * Handle subscription payment refund.
	 */
	private function handle_payment_refunded( $payment_status, $event, $mode ) {
		$r = isset( $event['resource'] ) ? $event['resource'] : array();

		$paypal_refund_id = isset( $r['id'] ) ? sanitize_text_field($r['id']) : '';

		$parent_id = isset( $r['sale_id'] ) ? sanitize_text_field($r['sale_id']) : '';

		if (empty($parent_id)) {
			foreach ( isset( $r['links'] ) ? $r['links'] : array() as $link ) {
				if ( ! $parent_id && ! empty( $link['href'] ) && in_array( isset( $link['rel'] ) ? $link['rel'] : '', array(
						'up',
						'capture'
					), true ) ) {
					$parent_id = basename( wp_parse_url( $link['href'], PHP_URL_PATH ) );
				}
			}
		}

		$orders = $parent_id ? wc_get_orders( array(
			'meta_key'   => '_paypal_transaction_id',
			'meta_value' => $parent_id,
			'limit'      => 1
		) ) : array();

		if ( ! $paypal_refund_id || empty( $orders ) || wc_get_orders( array(
				'meta_key'   => '_wcppprog_paypal_refund_id',
				'meta_value' => $paypal_refund_id,
				'limit'      => 1
			) ) ) {
			PayPal_Utils::log( 'Webhook: refund ignored because the original order was not found or refund was already recorded.', true );

			return;
		}

		$amount = isset( $r['amount']['total'] ) ? $r['amount']['total'] : ( isset( $r['amount']['value'] ) ? $r['amount']['value'] : 0 );

		if ( isset( $r['note_to_payer'] ) ) {
			$reason = sanitize_text_field( $r['note_to_payer'] );
		} else if ( isset( $r['description'] ) ) {
			$reason = sanitize_text_field( $r['description'] );
		} else {
			$reason = __( 'N/A.', 'woocommerce-paypal-pro-payment-gateway' );
		}

		$refund = wc_create_refund( array(
			'order_id'       => $orders[0]->get_id(),
			'amount'         => (float) $amount,
			'reason'         => $reason,
			'refund_payment' => false,
			'restock_items'  => false
		) );

		if ( ! is_wp_error( $refund ) ) {
			$refund->update_meta_data( '_wcppprog_paypal_refund_id', $paypal_refund_id );
			$refund->save();
			PayPal_Utils::log( 'Webhook: refund #' . $refund->get_id() . ' recorded against order #' . $orders[0]->get_id(), true );
		} else {
			PayPal_Utils::log( 'Webhook: WooCommerce refund creation failed: ' . $refund->get_error_message(), false );
		}
	}

	private function get_subscription_order_by_paypal_sub_id( $paypal_sub_id ) {
		PayPal_Utils::log( 'Webhook: locating subscription order for paypal subscription ID ' . $paypal_sub_id, true );

		$orders = wc_get_orders( array(
			'type'       => \WCPPROG_Subscription_Order_Handler::ORDER_TYPE,
			'meta_key'   => '_paypal_subscription_id',
			'meta_value' => $paypal_sub_id,
			'limit'      => 1
		) );

		if ( ! empty( $orders ) ) {
			PayPal_Utils::log( 'Webhook: found subscription order #' . $orders[0]->get_id(), true );

			return $orders[0];
		}

		PayPal_Utils::log( 'Webhook: no subscription order found for PayPal ID ' . $paypal_sub_id, false );

		return null;
	}

	public static function create_ipn_data_from_paypal_api_subscription_details_data( $sub_details, $event ){
		//Creates the $ipn_data array using the data from the PayPal API endpoint - v1/billing/subscriptions/{$subscription_id}
		$ipn_data = array();
		if(!is_object($sub_details)){
			PayPal_Utils::log( 'Error! Invalid subscription details data. Cannot create ipn data.', false );
			return false;
		}

		//Get the subscriber info array
		$subscriber_info = $sub_details->subscriber;
		if(is_object($subscriber_info)){
			//Convert the object to an array.
			$subscriber_info = json_decode(json_encode($subscriber_info), true);
		}

		//Get the billing info array
		$billing_info = $sub_details->billing_info;
		if(is_object($billing_info)){
			//Convert the object to an array.
			$billing_info = json_decode(json_encode($billing_info), true);
		}

		//Get the Subscription ID and Txn ID from the event data.
		$subscription_id = isset( $event['resource']['billing_agreement_id'] ) ? $event['resource']['billing_agreement_id'] : '';
		$txn_id = isset( $event['resource']['id'] ) ? $event['resource']['id'] : '';

		//Get the custom field data of the original subscription checkout from the user profile (if available).
		/**
		 * TODO: This is a plugin specific method,
		 * 
		 * FIXME: This need to rework or remove if needed.
		 */
		// $custom = \Transactions::get_original_custom_value_from_transactions_cpt( $subscription_id );

		//Set the data to the $ipn_data array.
		$ipn_data['custom'] = isset($custom) ? $custom : '';
		$ipn_data['payer_email'] = $subscriber_info['email_address'];
		$ipn_data['first_name'] = $subscriber_info['name']['given_name'];
		$ipn_data['last_name'] = $subscriber_info['name']['surname'];

		$ipn_data['txn_id'] = $txn_id;
		$ipn_data['subscr_id'] = $subscription_id;
		$ipn_data['mc_gross'] = isset($billing_info['last_payment']['amount']['value']) ? $billing_info['last_payment']['amount']['value'] : '';
		$ipn_data['gateway'] = 'paypal_subscription_checkout';
		$ipn_data['txn_type'] = 'pp_subscription_sale_completed_webhook';
		$ipn_data['status'] = 'Completed';

		return $ipn_data;
	}

	public static function is_sale_completed_webhook_already_processed( $event ){
		// Query the DB to check if we have already processed this transaction or not.
		global $wpdb;
		$txn_id = isset( $event['resource']['id'] ) ? $event['resource']['id'] : '';
		$subscription_id = isset( $event['resource']['billing_agreement_id'] ) ? $event['resource']['billing_agreement_id'] : '';
		
		/**
		 * TODO: This query is plugin specific,
		 * 
		 * FIXME: This need to be changed/modified.
		 * 
		 */
		$processed = false; //is_webhook_processed( $txn_id, $subscription_id );
		if ($processed) {
			// And if we have already processed it, do nothing and return true
			PayPal_Utils::log( "This webhook event has already been processed (Txn ID: ".$txn_id.", Subscr ID: ".$subscription_id."). This looks to be a duplicate webhook notification. Nothing to do.", true );
			return true;
		}
		return false;
	}

	/**
	 * Gets the HTTP headers that you received from PayPal webhook.
	 */
	public static function get_paypal_webhook_headers() {
		//This method of getting the headers is more robust than using getallheaders() as this method will work on nginx servers as well.
		$headers = array();
		foreach ( $_SERVER as $key => $value ) {
			if ( substr( $key, 0, 5 ) !== 'HTTP_' ) {
				continue;
			}
			$header = str_replace( ' ', '-', str_replace( '_', ' ', strtoupper( substr( $key, 5 ) ) ) );

			$headers[ $header ] = $value;
		}
		return $headers;
	}

	public static function verify_webhook_event_for_given_mode( $event, $mode ){
		//Get HTTP headers received from the PayPal webhook.
		$headers = self::get_paypal_webhook_headers();

		//Verify the webhook event signaure.
		$pp_webhook = new PayPal_Webhook();
		//Set the mode based on the received webhook (so we are processing according to that instead of the current mode setting in the plugin settings).
		$pp_webhook->set_mode_and_api_creds_based_on_mode( $mode );

		$response = $pp_webhook->verify_webhook_signature( $event, $headers );

		if( $response !== false && $response->verification_status === 'SUCCESS' ){
			//Webhook verification success!
			return true;
		}

		if ( isset( $response->verification_status ) && $response->verification_status !== 'SUCCESS' ) {
			PayPal_Utils::log( 'Error! Webhook verification failed! Environment mode: '. $mode . ', Verification status: ' . $response->verification_status, false );
			return false;
		}

		//If we are here then something went wrong. Log the error and return false.
		//We can check the PayPal_Request_API->last_error to find additional details if needed.
		PayPal_Utils::log( 'Error! Webhook verification failed! Environment mode: '. $mode, false );
		return false;
	}
}
