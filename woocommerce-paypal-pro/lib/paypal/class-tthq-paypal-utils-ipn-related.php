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

		//Get the transaction/order details from PayPal API endpoint - /v2/checkout/orders/{$order_id}
		$pp_orderID = isset($data['order_id']) ? $data['order_id'] : '';
		$cart_id = isset($data['cart_id']) ? $data['cart_id'] : '';

		$validation_error_msg = '';

		//This is for on-site checkout only. So the 'mode' and API creds will be whatever is currently set in the settings.
		$api_injector = new PayPal_Request_API_Injector();
		$order_details = $api_injector->get_paypal_order_details( $pp_orderID );
		if( $order_details !== false ){
			//The order details were retrieved successfully.
			if(is_object($order_details)){
				//Convert the object to an array.
				$order_details = json_decode(json_encode($order_details), true);
			}

			// Debug purpose only.
			// PayPal_Utils::log( 'PayPal Order Details: ', true );
			// PayPal_Utils::log_array( $order_details, true );

			// Check that the order's capture status is COMPLETED.
			$status = '';
			// Check if the necessary keys and arrays exist and are not empty
			if (!empty($order_details['purchase_units']) && !empty($order_details['purchase_units'][0]['payments']) && !empty($order_details['purchase_units'][0]['payments']['captures'])) {
				// Access the first item in the 'captures' array
				$capture = $order_details['purchase_units'][0]['payments']['captures'][0];
				$capture_id = isset($capture['id']) ? $capture['id'] : '';
				// Check if 'status' is set for the capture
				if (isset($capture['status'])) {
					// Extract the 'status' value
					$status = $capture['status'];
				}
			}
			if ( strtolower($status) != strtolower('COMPLETED') ) {
				//The order is not completed yet.
				$validation_error_msg = 'Validation Error! The transaction status is not completed yet. Cart ID: ' . $cart_id . ', PayPal Capture ID: ' . $capture_id . ', Capture Status: ' . $status;
				PayPal_Utils::log( $validation_error_msg, false );
				return $validation_error_msg;
			}

			//Check that the amount matches with what we expect.
			$amount = isset($order_details['purchase_units'][0]['amount']['value']) ? $order_details['purchase_units'][0]['amount']['value'] : 0;

			$payment_amount_expected = get_post_meta( $cart_id, 'expected_payment_amount', true );
			if( floatval($amount) < floatval($payment_amount_expected) ){
				//The amount does not match.
				$validation_error_msg = 'Validation Error! The payment amount does not match. Cart ID: ' . $cart_id . ', PayPal Order ID: ' . $pp_orderID . ', Amount Received: ' . $amount . ', Amount Expected: ' . $payment_amount_expected;
				PayPal_Utils::log( $validation_error_msg, false );
				return $validation_error_msg;
			}

			//Check that the currency matches with what we expect.
			$currency = isset($order_details['purchase_units'][0]['amount']['currency_code']) ? $order_details['purchase_units'][0]['amount']['currency_code'] : '';
			$currency_expected = get_post_meta( $cart_id, 'expected_currency', true );
			if( $currency != $currency_expected ){
				//The currency does not match.
				$validation_error_msg = 'Validation Error! The payment currency does not match. Cart ID: ' . $cart_id . ', PayPal Order ID: ' . $pp_orderID . ', Currency Received: ' . $currency . ', Currency Expected: ' . $currency_expected;
				PayPal_Utils::log( $validation_error_msg, false );
				return $validation_error_msg;
			}

		} else {
			//Error getting subscription details.
			$validation_error_msg = 'Validation Error! Failed to get transaction/order details from the PayPal API. PayPal Order ID: ' . $pp_orderID;
			//TODO - Show additional error details if available.
			PayPal_Utils::log( $validation_error_msg, false );
			return $validation_error_msg;
		}

		//All good. The data is valid.
		return true;
	}

	/**
	 * TODO: This is a plugin specific method.
	 */
	public static function complete_post_payment_processing( $data, $txn_data, $ipn_data){
		//Check if this is a duplicate notification.
		if( PayPal_Utility_IPN_Related::is_txn_already_processed($ipn_data)){
			//This transaction notification has already been processed. So we don't need to process it again.
			return true;
		}

		if(!isset($ipn_data['paypal_order_id']) || empty($ipn_data['paypal_order_id'])){
			//If the paypal_order_id is not set, we cannot proceed with the post payment processing.
			PayPal_Utils::log( 'PayPal Order ID is not set in the IPN data. Cannot proceed with post payment processing.', false );
			return false;
		}

		//Get the cart items from the transient.	
		$transient_key = 'estore_ppcp_order_id_' . $ipn_data['paypal_order_id'];
		$retrieved_cart_items = get_transient( $transient_key );

		//Convert the cart items to the format expected by the post payment processing function.
		$ipn_cart_items = PayPal_Utility_IPN_Related::convert_estore_cart_items_to_ipn_cart_items( $retrieved_cart_items, $ipn_data );

		//PayPal_Utils::log_array( $retrieved_cart_items, true );
		PayPal_Utils::log_array( $ipn_cart_items, true );

		eStore_payment_debug( 'PPCP Checkout - calling eStore_do_post_payment_tasks().', true );		
		eStore_do_post_payment_tasks($ipn_data, $ipn_cart_items);

		return true;
	}

	public static function convert_estore_cart_items_to_ipn_cart_items($estore_cart_items, $ipn_data = array()) {
		$cart_items = [];

		$currency = isset($ipn_data['mc_currency']) ? $ipn_data['mc_currency'] : 'USD';
		
		foreach ($estore_cart_items as $item) {
			$cart_items[] = [
				'item_number' => $item['item_number'] ?? '',
				'item_name' => $item['name'] ?? '',
				'quantity' => $item['quantity'] ?? 0,
				'mc_gross' => ($item['price'] ?? 0) * ($item['quantity'] ?? 1),
				'mc_currency' => $currency,
			];
		}
		
		return $cart_items;
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