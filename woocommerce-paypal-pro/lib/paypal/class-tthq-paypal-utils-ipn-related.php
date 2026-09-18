<?php

namespace TTHQ\WC_PP_PRO\Lib\PayPal;

class PayPal_Utility_IPN_Related {

	public static function create_ipn_data_array_from_capture_order_txn_data( $data, $txn_data ) {
		$ipn_data = array();

		//$purchase_units = isset($txn_data['purchase_units']) ? $txn_data['purchase_units'] : array();
		//The $data['order_id'] is the ID for the order created using createOrder API call. The Transaction ID is the ID for the captured payment.
		$txn_id = isset($txn_data['purchase_units'][0]['payments']['captures'][0]['id']) ? $txn_data['purchase_units'][0]['payments']['captures'][0]['id'] : '';
		$ipn_data['txn_id'] = $txn_id;

		//Get the PayPal Order ID and add to the IPN data array.
		if(isset($data['order_id'])){
			$ipn_data['paypal_order_id'] = $data['order_id'];
		} else {
			//We can read the order_id from the txn_data response from PayPal API (if available)
			$ipn_data['paypal_order_id'] = isset($txn_data['id']) ? $txn_data['id'] : '';
		}

		//Get the custom field value from the request
		$custom = isset($data['custom_field']) ? $data['custom_field'] : '';
		$custom = urldecode( $custom );//Decode it just in case it was encoded.
				
		//Add the PayPal API order_id value to the custom field. So it gets saved with custom field data. 
		//This can be used to also save it to the reference DB column field when saving the transaction.		
		$data['custom_field'] = $custom . '&paypal_order_id=' . $ipn_data['paypal_order_id'];

		//Parse the custom field to read the IP address.
		$customvariables = PayPal_Utils::parse_custom_var( $custom );

		//Save cart ID to the IPN data array (useful so we don't have to call get_cart_id function again).
		$ipn_data['cart_id'] = isset($customvariables['wp_cart_id']) ? $customvariables['wp_cart_id'] : '';

		$ipn_data['gateway'] = 'paypal_ppcp';
		$ipn_data['txn_type'] = 'paypal_ppcp_checkout';
		$ipn_data['custom'] = isset($data['custom_field']) ? $data['custom_field'] : '';
		$ipn_data['subscr_id'] = $txn_id;//Same as txn_id for one-time payments.

		$ipn_data['item_number'] = isset($data['button_id']) ? $data['button_id'] : '';
		$ipn_data['item_name'] = isset($data['item_name']) ? $data['item_name'] : '';

		$ipn_data['status'] = isset($txn_data['status']) ? ucfirst( strtolower($txn_data['status']) ) : '';
		$ipn_data['payment_status'] = isset($txn_data['status']) ? ucfirst( strtolower($txn_data['status']) ) : '';

		//Amount
		if ( isset($txn_data['purchase_units'][0]['payments']['captures'][0]['amount']['value']) ){
			//This is for PayPal checkout serverside capture.
			$ipn_data['mc_gross'] = $txn_data['purchase_units'][0]['payments']['captures'][0]['amount']['value'];
		} else {
			$ipn_data['mc_gross'] = 0;
		}

		//Currency
		if ( isset($txn_data['purchase_units'][0]['payments']['captures'][0]['amount']['currency_code']) ){
			//This is for PayPal checkout serverside capture.
			$ipn_data['mc_currency'] = $txn_data['purchase_units'][0]['payments']['captures'][0]['amount']['currency_code'];
		} else {
			$ipn_data['mc_currency'] = 0;
		}

		//Default to 1 for quantity.
		$ipn_data['quantity'] = 1;

		// Customer info.
		$ipn_data['ip_address'] = isset($customvariables['ip']) ? $customvariables['ip'] : '';
		$ipn_data['first_name'] = isset($txn_data['payer']['name']['given_name']) ? $txn_data['payer']['name']['given_name'] : '';
		$ipn_data['last_name'] = isset($txn_data['payer']['name']['surname']) ? $txn_data['payer']['name']['surname'] : '';
		$ipn_data['payer_email'] = isset($txn_data['payer']['email_address']) ? $txn_data['payer']['email_address'] : '';
		$ipn_data['payer_id'] = isset($txn_data['payer']['payer_id']) ? $txn_data['payer']['payer_id'] : '';

		//Address
		$address_street = isset($txn_data['purchase_units'][0]['shipping']['address']['address_line_1']) ? $txn_data['purchase_units'][0]['shipping']['address']['address_line_1'] : '';
		if ( isset ( $txn_data['purchase_units'][0]['shipping']['address']['address_line_2'] )){
			//If address line 2 is present, add it to the address.
			$address_street .= ", " . $txn_data['purchase_units'][0]['shipping']['address']['address_line_2'];
		}		
		$ipn_data['address_street'] = $address_street;
		$ipn_data['address_city'] = isset($txn_data['purchase_units'][0]['shipping']['address']['admin_area_2']) ? $txn_data['purchase_units'][0]['shipping']['address']['admin_area_2'] : '';
		$ipn_data['address_state'] = isset($txn_data['purchase_units'][0]['shipping']['address']['admin_area_1']) ? $txn_data['purchase_units'][0]['shipping']['address']['admin_area_1'] : '';
		$ipn_data['address_zip'] = isset($txn_data['purchase_units'][0]['shipping']['address']['postal_code']) ? $txn_data['purchase_units'][0]['shipping']['address']['postal_code'] : '';
		$country_code = isset($txn_data['purchase_units'][0]['shipping']['address']['country_code']) ? $txn_data['purchase_units'][0]['shipping']['address']['country_code'] : '';
		$ipn_data['address_country'] = PayPal_Utils::get_country_name_by_country_code($country_code);
		
		//Additional variables
		//Phone can be retrieved (if available) from the payer object by making a separate API call to /v2/customer
		$ipn_data['contact_phone'] = isset($txn_data['contact_phone']) ? $txn_data['contact_phone'] : '';

		/**********************************/
		//Ensure the customer's email and name are set. For guest checkout, the email and name may not be set in the standard onApprove data (due to privacy reasons).
		//So we will query the Order details from the PayPal API to get the customer's email and name (if needed).
		/**********************************/
		if( empty($ipn_data['payer_email']) || empty($ipn_data['first_name']) || empty($ipn_data['last_name']) ){
			//Use the order ID to get the customer's email and name from the PayPal API.
			$pp_order_id = isset($data['order_id']) ? $data['order_id'] : '';
			PayPal_Utils::log( 'Customer Email or Name not set in the onApprove data. Going to query the PayPal API for order details. Order ID: ' . $pp_order_id, true );

			//This is for on-site checkout only. So the 'mode' and API creds will be whatever is currently set in the settings.
			$api_injector = new PayPal_Request_API_Injector();
			$order_details = $api_injector->get_paypal_order_details( $pp_order_id );
			if( $order_details !== false ){
				//The order details were retrieved successfully.
				$payer = isset($order_details->payer) ? $order_details->payer : array();
				if(is_object($payer)){
					//Convert the object to an array.
					$customer_data_array = json_decode(json_encode($payer), true);
				}
				//Debugging only.
				PayPal_Utils::log_array( $customer_data_array, true );
				
				if( empty($ipn_data['payer_email']) && isset($customer_data_array['email_address']) ){
					//Set the payer email from the subscriber data.
					$ipn_data['payer_email'] = $customer_data_array['email_address'];
				}
				if( empty($ipn_data['first_name']) && isset($customer_data_array['name']['given_name']) ){
					//Set the payer first name from the subscriber data.
					$ipn_data['first_name'] = $customer_data_array['name']['given_name'];
				}
				if( empty($ipn_data['last_name']) && isset($customer_data_array['name']['surname']) ){
					//Set the payer last name from the subscriber data.
					$ipn_data['last_name'] = $customer_data_array['name']['surname'];
				}
				PayPal_Utils::log( 'Customer Email: ' . $ipn_data['payer_email'] . ', First Name: ' . $ipn_data['first_name'] . ', Last Name: ' . $ipn_data['last_name'], true );

			} else {
				//Error getting order details.
				$validation_error_msg = 'Validation Error! Failed to get transaction/order details from the PayPal API. PayPal Order ID: ' . $pp_order_id;
				PayPal_Utils::log( $validation_error_msg, false );
			}
		}

		//Return the IPN data array.
		return $ipn_data;
	}


	/**
	 * Validate that the transaction/order exists in PayPal and the price matches the price in the DB.
	 */
	public static function validate_buy_now_checkout_txn_data( $data, $txn_data ) {
		//TODO - We need to update this method to use the correct expected amount and currency from the WP eStore cart.
		//For now, we will return true to avoid breaking the existing functionality.
		return true;
	}

	/**
	 * TODO: This is a plugin specific method.
	 */
	public static function complete_post_payment_processing( $data, $txn_data, $ipn_data){
		$paypal_order_id = isset($data['order_id']) ? sanitize_text_field($data['order_id']) : '';

		// Find the WooCommerce order by PayPal order ID
		$orders = wc_get_orders(array(
            'meta_key' => '_paypal_order_id',
            'meta_value' => $paypal_order_id,
            'limit' => 1,
        ));

        $wc_order = ! empty($orders) ? $orders[0] : false;

        if ( empty($wc_order) ) {
			return new \WP_Error('order_not_found', 'WooCommerce order not found');
        }

        // Update the WooCommerce order with capture details
        if (isset($txn_data['payer'])) {
            $payer = $txn_data['payer'];

            if (isset($payer['name'])) {
                $wc_order->set_billing_first_name($payer['name']['given_name'] ?? '');
                $wc_order->set_billing_last_name($payer['name']['surname'] ?? '');
            }

            if (isset($payer['email_address'])) {
                $wc_order->set_billing_email($payer['email_address']);
            }
        }

        // Store PayPal transaction details
        if (isset($txn_data['purchase_units'][0]['payments']['captures'][0])) {
            $capture = $txn_data['purchase_units'][0]['payments']['captures'][0];
            $wc_order->update_meta_data('_paypal_transaction_id', $capture['id']);
            $wc_order->update_meta_data('_paypal_capture_response', $txn_data);
        }

        $wc_order->save();

		// Mark order as paid and add note
        $wc_order->payment_complete($paypal_order_id);
        /* translators: %s: PayPal order ID. */
        $wc_order->add_order_note(sprintf(__('PayPal payment completed. PayPal Order ID: %s', 'woocommerce-paypal-pro-payment-gateway'), $paypal_order_id));

        // Empty cart
        WC()->cart->empty_cart();

		return $wc_order;
	}

	/**
	 * TODO: This is a plugin specific method.
	 */
	public static function complete_post_subscription_payment_processing( $data, $txn_data, $ipn_data){
		$paypal_subscription_id = isset($data['subscriptionID']) ? sanitize_text_field($data['subscriptionID']) : '';
		$paypal_order_id = isset($data['orderID']) ? sanitize_text_field($data['orderID']) : '';

		// Find the WooCommerce order by PayPal order ID
		$orders = wc_get_orders(array(
			'meta_key' => '_paypal_subscription_id',
			'type' => 'shop_order',
			'orderby' => 'ID',
			'order' => 'ASC',
			'meta_value' => $paypal_subscription_id,
			'limit' => 1,
		));

		$wc_order = ! empty($orders) ? $orders[0] : false;

		if ( empty($wc_order) ) {
			return new \WP_Error('order_not_found', 'WooCommerce order not found');
		}

		$wc_order->update_meta_data('_paypal_order_id', $paypal_order_id);

		$wc_order->save();

		// Approval is not a payment receipt. The sale webhook supplies the sale ID.
		if ( (float) $wc_order->get_total() <= 0 ) {
			$wc_order->payment_complete();
		}
		/* translators: %s: PayPal subscription ID. */
		$wc_order->add_order_note(sprintf(__('PayPal subscription completed. PayPal Subscription ID: %s', 'woocommerce-paypal-pro-payment-gateway'), $paypal_subscription_id));

		// Empty cart
		WC()->cart->empty_cart();

		self::create_subscription_order($wc_order, $data, $txn_data, $ipn_data);

		return $wc_order;
	}

	/**
	 * This creates a subscription order under thw woocommerce subscription menu.
	 *
	 * NOTE: This is a plugin specific method.
	 *
	 * @param $order
	 *
	 * @return void
	 */
	public static function copy_subscription_line_item( $item ) {
		// Persisted clones retain their ID and would move the original item.
		$copy = new \WC_Order_Item_Product();
		$data = $item->get_data();
		unset( $data['id'], $data['order_id'], $data['meta_data'] );
		$copy->set_props( $data );
		foreach ( $item->get_meta_data() as $meta ) {
			if ( in_array( $meta->key, array( '_reduced_stock', '_restock_refunded_items' ), true ) ) {
				continue;
			}
			$copy->add_meta_data( $meta->key, $meta->value );
		}
		return $copy;
	}

	public static function create_subscription_order( $order, $data, $txn_data, $ipn_data ) {
		$order_id = $order->get_id();
		if ( $order->get_meta( '_wcpprog_subscription_order_id', true ) ) {
			return;
		}

		try {
			foreach ( $order->get_items() as $item ) {
				$product = $item->get_product();

				if ( ! $product || \WCPPROG_Subscription_Related::SUBSCRIPTION_PRODUCT_TYPE !== $product->get_type() ) {
					continue;
				}

				$subscription_order = new \WCPPROG_WC_Subscription_Order();

				$subscription_order->set_customer_id( $order->get_customer_id() );
				$subscription_order->set_billing_address( $order->get_address( 'billing' ) );
				$subscription_order->set_shipping_address( $order->get_address( 'shipping' ) );

				// The PayPal subscription has been approved before this order is created.
				$has_trial = 'yes' === $order->get_meta( '_wcpprog_has_trial', true );
				$subscription_order->update_meta_data( '_wcpprog_has_trial', $has_trial ? 'yes' : 'no' );
				$subscription_order->set_status( $has_trial ? 'wcpprog-trial' : 'wcpprog-active' );

				// Copy the line item onto the subscription for reference
				$subscription_order->add_item( self::copy_subscription_line_item( $item ) );

				$interval = (int) $product->get_meta( '_subscription_recurring_billing_interval', true );
				$period   = $product->get_meta( '_subscription_recurring_billing_interval_type', true );
				$sub_plan_id = isset($txn_data['plan_id']) ? sanitize_text_field($txn_data['plan_id']) : '';

				$subscription_order->update_meta_data( '_billing_interval', $interval );
				$subscription_order->update_meta_data( '_billing_period', $period );
				$subscription_order->update_meta_data( '_parent_order_id', $order_id );
				$subscription_order->update_meta_data( '_related_order_ids', array( $order_id ) );
				$subscription_order->update_meta_data( '_paypal_subscription_id', $order->get_meta( '_paypal_subscription_id', true ) );
				$subscription_order->update_meta_data( '_paypal_subscription_plan_id', $sub_plan_id );

				if ( $has_trial ) {
					$trial_length = (int) $product->get_meta( '_subscription_trial_period', true );
					$trial_period = $product->get_meta( '_subscription_trial_period_type', true );
					$next_payment = strtotime( "+{$trial_length} {$trial_period}" );
				} else {
					$next_payment = strtotime( "+{$interval} {$period}" );
				}

				$subscription_order->set_next_payment_date( gmdate( 'Y-m-d H:i:s', $next_payment ) );
				$subscription_order->calculate_totals( false );
				$subscription_order->save();

				$subscription_order_id = $subscription_order->get_id();

				PayPal_Utils::log('Subscription order created. Subscription order ID: ' . $subscription_order_id );

				// Link back from the parent order too
				$order->update_meta_data( '_wcpprog_subscription_order_id', $subscription_order_id );
				$order->save();
			}
		} catch (\Exception $e) {
			PayPal_Utils::log( $e->getMessage(), false );
		}
	}

	public static function complete_buy_now_post_payment_processing( $data, $txn_data, $ipn_data){
		//Check if this is a duplicate notification.
		if( PayPal_Utility_IPN_Related::is_txn_already_processed($ipn_data)){
			//This transaction notification has already been processed. So we don't need to process it again.
			return true;
		}

		//Convert the purchase unit items to the format expected by the post payment processing function.
		$ipn_cart_items = PayPal_Utility_IPN_Related::create_ipn_cart_items_for_estore_buy_now( $data, $txn_data, $ipn_data );

		//PayPal_Utils::log_array( $retrieved_cart_items, true );
		PayPal_Utils::log_array( $ipn_cart_items, true );

		eStore_payment_debug( 'PPCP Buy Now - calling eStore_do_post_payment_tasks().', true );		
		eStore_do_post_payment_tasks($ipn_data, $ipn_cart_items);

		return true;
	}

	public static function create_ipn_cart_items_for_estore_buy_now($data, $txn_data, $ipn_data = array()) {
		if(!isset($ipn_data['paypal_order_id']) || empty($ipn_data['paypal_order_id'])){
			//If the paypal_order_id is not set, we cannot proceed with the post payment processing.
			PayPal_Utils::log( 'PayPal Order ID is not set in the IPN data. Cannot proceed with post payment processing.', false );
			return false;
		}

		//Get the purchase unit items from the transient.	
		$transient_key = 'estore_ppcp_order_id_' . $ipn_data['paypal_order_id'];
		$retrieved_pu_items = get_transient( $transient_key );

		$estore_product_id = isset( $data['estore_product_id'] ) ? intval( $data['estore_product_id'] ) : '';

		$cart_items = [];

		$currency = isset($ipn_data['mc_currency']) ? $ipn_data['mc_currency'] : 'USD';

		foreach ($retrieved_pu_items as $index => $item) {
			$cart_items[] = [
				'item_number' => $estore_product_id,
				'item_name' => $item['name'] ?? '',
				'quantity' => $item['quantity'] ?? 1,
				'mc_gross' => ($item['unit_amount']['value'] ?? 0) * ($item['quantity'] ?? 1),
				'mc_currency' => $item['unit_amount']['currency_code'] ?? $currency,
			];
		}

		return $cart_items;
	}

	public static function is_txn_already_processed( $ipn_data ){
		// Query the DB to check if we have already processed this transaction or not.
		global $wpdb;
		$txn_id = isset($ipn_data['txn_id']) ? $ipn_data['txn_id'] : '';
		$payer_email = isset($ipn_data['payer_email']) ? $ipn_data['payer_email'] : '';
		$order_id = isset($ipn_data['order_id']) ? $ipn_data['order_id'] : '';
		
		$processed = eStore_is_txn_already_processed($ipn_data);
		if ($processed) {
			// And if we have already processed it, do nothing and return true
			PayPal_Utils::log( "This transaction has already been processed (Txn ID: ".$txn_id.", Payer Email: ".$payer_email."). This looks to be a duplicate notification. Nothing to do here.", true );
			return true;
		}
		return false;
	}
        
}
