<?php

namespace TTHQ\WC_PP_PRO\Lib\PayPal;

/**
 * This class handles the ajax request from the PayPal Subscription button events (the create_subscription event is triggered from the Button's JS code). 
 * It creates the required $ipn_data array from the transaction so it can be fed into the existing IPN handler functions easily.
 */
class PayPal_Button_Sub_Ajax_Handler {

	public $ipn_data  = array();

	public function __construct() {
		//Handle it at 'wp_loaded' since custom post types will also be available at that point.
		add_action( 'wp_loaded', array(&$this, 'setup_ajax_request_actions' ) );
	}

	/**
	 * Setup the ajax request actions.
	 */
	public function setup_ajax_request_actions() {
		//Handle the create subscription via API ajax request
		add_action( PayPal_Utils::hook('sub_pp_create_subscription', true), array(&$this, 'sub_pp_create_subscription' ) );
		add_action( PayPal_Utils::hook('sub_pp_create_subscription', true, true), array(&$this, 'sub_pp_create_subscription' ) );		

		//Handle the onApprove ajax request for 'Subscription' type buttons
		add_action( PayPal_Utils::hook('sub_onapprove_process_subscription', true), array(&$this, 'sub_onapprove_process_subscription' ) );
		add_action( PayPal_Utils::hook('sub_onapprove_process_subscription', true, true), array(&$this, 'sub_onapprove_process_subscription' ) );		

	}


	/**
	 * Handle the create-subscription ajax request for 'Subscription' type buttons.
	 */
    public function sub_pp_create_subscription(){
		//We will create a plan for the button (if needed). Then create a subscription for the user and return the subscription ID.
		//https://developer.paypal.com/docs/api/subscriptions/v1/#plans_create

		//Get the data from the request
		$data = isset( $_POST['data'] ) ? stripslashes_deep( $_POST['data'] ) : array();
		if ( empty( $data ) ) {
			wp_send_json(
				array(
					'success' => false,
					'err_msg'  => __( 'Empty data received.', 'woocommerce-paypal-pro-payment-gateway' ),
				)
			);
		}
		
		if( !is_array( $data ) ){
			//Convert the JSON string to an array (Vanilla JS AJAX data will be in JSON format).
			$data = json_decode( $data, true);		
		}

		//Get the product_id from the request.
		$estore_product_id = isset( $data['estore_product_id'] ) ? intval( $data['estore_product_id'] ) : '';
		$on_page_button_id = isset( $data['on_page_button_id'] ) ? sanitize_text_field( $data['on_page_button_id'] ) : '';
		$unique_key = isset( $data['unique_key'] ) ? sanitize_text_field( $data['unique_key'] ) : '';
		PayPal_Utils::log( 'sub_pp_create_subscription ajax request received. Product ID: '.$estore_product_id.', On Page Button ID: ' . $on_page_button_id . ', Unique Key: ' . $unique_key, true );

		//Variation and Custom amount related data.
		$item_name = isset( $data['item_name'] ) ? sanitize_text_field( $data['item_name'] ) : '';
		$amount_submitted = isset( $data['amount'] ) ? floatval( $data['amount'] ) : 0;
		$custom_price = isset( $data['custom_price'] ) ? floatval( $data['custom_price'] ) : 0;
		PayPal_Utils::log( 'Item Name: ' . $item_name . ', Amount Submitted (includes any variation or custom price): ' . $amount_submitted, true );

		// Check nonce.
		if ( ! check_ajax_referer( $on_page_button_id, '_wpnonce', false ) ) {
			wp_send_json(
				array(
					'success' => false,
					'err_msg'  => __( 'Nonce check failed. The page was most likely cached. Please reload the page and try again.', 'woocommerce-paypal-pro-payment-gateway' ),
				)
			);
			exit;
		}

		//Get the global config instance.
		$wp_eStore_config = \WP_eStore_Config::getInstance();

		//Retrieve the product details from the database.
		$id = $estore_product_id;
		PayPal_Utils::log( 'Retrieving product details from the database for product ID: ' . $id, true );
		$ret_product = eStore_get_product_row_by_id($id);
		if (!$ret_product) {
			$wrong_product_error_msg = eStore_wrong_product_id_error_msg($id);
			PayPal_Utils::log( $wrong_product_error_msg, false );
			wp_send_json(
				array(
					'success' => false,
					'err_msg'  => $wrong_product_error_msg,
				)
			);
			exit;
		}

		if (is_numeric($ret_product->available_copies)) {
			if ($ret_product->available_copies < 1) {// No more copies left
				$out_of_stock_error_msg = sprintf( __( "%s is out of stock.", "woocommerce-paypal-pro-payment-gateway" ), $ret_product->product_name );
				PayPal_Utils::log( $out_of_stock_error_msg, false );
				wp_send_json(
					array(
						'success' => false,
						'err_msg'  => $out_of_stock_error_msg,
					)
				);
				exit;
			}
		}

		//Do a variation price validation (if applicable).
		if (eStore_has_product_variation($estore_product_id) ){
			$var_check_item_ary = array('item_number' => $estore_product_id, 'item_name' => $item_name, 'quantity' => 1, 'mc_gross' => $amount_submitted);
			if( !eStore_is_variation_price_valid( $var_check_item_ary, $ret_product ) ){
				$invalid_variation_price_error_msg = __( 'The selected product variation price is invalid. Please try again.', 'woocommerce-paypal-pro-payment-gateway' );
				PayPal_Utils::log( $invalid_variation_price_error_msg . ' Variation check data: ' . print_r($var_check_item_ary, true), false );
				wp_send_json(
					array(
						'success' => false,
						'err_msg'  => $invalid_variation_price_error_msg,
					)
				);
				exit;
			}
		}

		//Note: For PPCP Subscription type buttons, the currency must be the same as the store's currency from settings (PayPal PPCP doesn't allow JS SDK to be one currency and the subscription plan to be in a different currency dynamically).

		/************************************
		 * Create or Get the PayPal Plan ID *
		 ************************************/
		if (eStore_has_product_custom_price($estore_product_id) && !empty($custom_price) && $custom_price > 0 ){
			//For custom price products (it may include variation also), we will create a new plan on the fly because the price can be different for each user.
			PayPal_Utils::log( 'Creating a new PayPal subscription plan for custom price product. Custom price input field value: ' . $custom_price . ', Amount submitted (includes any custom and variation price): ' . $amount_submitted, true );
			$plan_create_error_msg = '';
			$ret = PayPal_Utils::create_billing_plan_for_variation_product( $estore_product_id, $item_name, $amount_submitted, $custom_price );
			if( $ret['success'] === true ){
				$plan_id = $ret['plan_id'];
				PayPal_Utils::log( 'Created new PayPal subscription plan for custom price product with name: ' . $item_name . ', Plan ID: ' . $plan_id, true );
			} else {
				$plan_create_error_msg = 'Error! Could not create the PayPal subscription plan for the custom price product. Error message: ' . esc_attr( $ret['error_message'] );
			}
		} else if (eStore_has_product_variation($estore_product_id) ){
			// For variation only products, we will create a new plan for each variation on the fly because PayPal billing plans don't support variations. The variation details will be included in the plan name and price.
			PayPal_Utils::log( 'Creating a new PayPal subscription plan for variation product. Amount submitted (includes any variation price): ' . $amount_submitted, true );
			$plan_create_error_msg = '';
			$ret = PayPal_Utils::create_billing_plan_for_variation_product( $estore_product_id, $item_name, $amount_submitted, $custom_price );
			if( $ret['success'] === true ){
				$plan_id = $ret['plan_id'];
				PayPal_Utils::log( 'Created new PayPal subscription plan for variation product with name: ' . $item_name . ', Plan ID: ' . $plan_id, true );
			} else {
				$plan_create_error_msg = 'Error! Could not create the PayPal subscription plan for the variation product. Error message: ' . esc_attr( $ret['error_message'] );
			}
		} else {
			//For normal product (non-variation and non-custom price), we will just get the plan ID from the product meta (or create a new one if not exists). 
			//Get the plan ID (or create a new plan if needed) for the product.
			$plan_id = estore_get_product_meta( $estore_product_id, 'pp_subscription_plan_id', true );
			PayPal_Utils::log('PayPal billing plan ID from product meta: ' . $plan_id, true );
			$plan_create_error_msg = '';
			if( empty( $plan_id )){
				//Need to create a new plan
				$ret = PayPal_Utils::create_billing_plan_for_product( $estore_product_id );
				if( $ret['success'] === true ){
					$plan_id = $ret['plan_id'];
					PayPal_Utils::log( 'Created new PayPal subscription plan for product ID: ' . $estore_product_id . ', Plan ID: ' . $plan_id, true );
				} else {
					$plan_create_error_msg = 'Error! Could not create the PayPal subscription plan for the product. Error message: ' . esc_attr( $ret['error_message'] );
				}
			} else {
				//Found a plan ID. Check if this plan exists in the PayPal account.
				PayPal_Utils::log('Found a plan ID. Check if this billing plan ID (' . $plan_id . ') still exists in PayPal account. If not, create a fresh new plan.', true );
				if( !PayPal_Utils::check_billing_plan_exists( $plan_id ) ){
					//The plan ID does not exist in the PayPal account. Maybe the plan was created earlier in a different mode or using a different paypal account. 
					//We need to create a fresh new plan for this button.
					$ret = PayPal_Utils::create_billing_plan_fresh_new( $estore_product_id );
					if( $ret['success'] === true ){
						$plan_id = $ret['plan_id'];
						PayPal_Utils::log( 'Created new PayPal subscription plan for product ID: ' . $estore_product_id . ', Plan ID: ' . $plan_id, true );
					} else {
						$plan_create_error_msg = 'Error! Could not create the PayPal subscription plan for the product. Error message: ' . esc_attr( $ret['error_message'] );
					}            
				}
			}
		}

		//Check if any error occurred while creating the plan.
		if( !empty( $plan_create_error_msg ) ){
			PayPal_Utils::log( $plan_create_error_msg, false );
			wp_send_json(
				array(
					'success' => false,
					'err_msg'  => $plan_create_error_msg,
				)
			);
			exit;
		}

		/*************************************
		 * Create the subscription on PayPal *
		 ************************************/
		//Going to create the subscription by making the PayPal API call.
		$api_injector = new PayPal_Request_API_Injector();

		//Set the additional args for the API call.
		$additional_args = array();
		$additional_args['return_response_body'] = true;

		$response = $api_injector->create_paypal_subscription_for_billing_plan( $plan_id, $data, $additional_args );

		//We requested the full response body to be returned, so we need to JSON decode it.
		if( $response !== false ){
			//JSON decode the response body to an array.
			$sub_data = json_decode( $response, true );
			$paypal_sub_id = isset( $sub_data['id'] ) ? $sub_data['id'] : '';
		} else {
			//Failed to create the order.
			wp_send_json(
				array(
					'success' => false,
					'err_msg'  => __( 'Failed to create the subscription using PayPal API. Enable the debug logging feature to get more details.', 'woocommerce-paypal-pro-payment-gateway' ),
				)
			);
			exit;
		}

		//Uncomment the following line to see more details of the subscription data.
		//PayPal_Utils::log_array( $sub_data, true );

		PayPal_Utils::log( 'PayPal Subscription ID: ' . $paypal_sub_id, true );

		//Save the item details in the transient for 12 hours (so we can use it later in the success request).
		//(we will use this one in the IPN processing stage).
		$sub_item_data = array(
			'estore_product_id' => $estore_product_id,
			'item_name' => $item_name,
			'amount' => $amount_submitted,
			'custom_price' => $custom_price,
		);
		$transient_key = 'estore_ppcp_subscription_id_' . $paypal_sub_id;
		set_transient( $transient_key, $sub_item_data, 12 * HOUR_IN_SECONDS );
		//Debugging purpose.
		//PayPal_Utils::log_array( $sub_item_data, true );		

		//If everything is processed successfully, send the success response.
		wp_send_json( array( 'success' => true, 'subscription_id' => $paypal_sub_id, 'sub_data' => $sub_data ) );
		exit;
    }


	/**
	 * Handle the onApprove ajax request for 'Subscription' type buttons
	 */
    public function sub_onapprove_process_subscription(){

		//Get the data from the request
		$data = isset( $_POST['data'] ) ? json_decode( stripslashes_deep( $_POST['data'] ), true ) : array();
		if ( empty( $data ) ) {
			wp_send_json(
				array(
					'success' => false,
					'err_msg'  => __( 'Empty data received.', 'woocommerce-paypal-pro-payment-gateway' ),
				)
			);
		}
		PayPal_Utils::log_array( $data, true );//Debugging only

		//Get the product_id from the request.
		$estore_product_id = isset( $data['estore_product_id'] ) ? intval( $data['estore_product_id'] ) : '';
		$on_page_button_id = isset( $data['on_page_button_id'] ) ? sanitize_text_field( $data['on_page_button_id'] ) : '';
		$unique_key = isset( $data['unique_key'] ) ? sanitize_text_field( $data['unique_key'] ) : '';
		PayPal_Utils::log( 'sub_onapprove_process_subscription ajax request received. Product ID: '.$estore_product_id.', On Page Button ID: ' . $on_page_button_id . ', Unique Key: ' . $unique_key, true );

		// Check nonce.
		if ( ! check_ajax_referer( $on_page_button_id, '_wpnonce', false ) ) {
			wp_send_json(
				array(
					'success' => false,
					'err_msg'  => __( 'Nonce check failed. The page was most likely cached. Please reload the page and try again.', 'woocommerce-paypal-pro-payment-gateway' ),
				)
			);
			exit;
		}

		//Get the transaction data from the request.
		$txn_data = isset( $_POST['txn_data'] ) ? json_decode( stripslashes_deep( $_POST['txn_data'] ), true ) : array();
		//PayPal_Utils::log( 'Transaction data received from the onApprove ajax request.', true );
		//PayPal_Utils::log_array( $txn_data, true );//Debugging only.

		if ( empty( $txn_data ) ) {
			wp_send_json(
				array(
					'success' => false,
					'err_msg'  => __( 'Empty transaction data received.', 'woocommerce-paypal-pro-payment-gateway' ),
				)
			);
		}

		//Create the IPN data array from the transaction data.
		//Need to include the following values in the $data array.
		$data['custom_field'] = get_transient( $unique_key );//We saved the custom field data in the transient using the unique key.
		$this->create_ipn_data_array_from_create_subscription_txn_data( $data, $txn_data );
		//PayPal_Utils::log_array( $this->ipn_data, true );//Debugging only.
		
		//Validate the subscription txn data before using it.
		$validation_response = $this->validate_subscription_checkout_txn_data( $data, $txn_data );
		if( $validation_response !== true ){
			wp_send_json(
				array(
					'success' => false,
					'err_msg'  => $validation_response,
				)
			);
			exit;
		}

		//Process the IPN data array
		PayPal_Utils::log( 'Validation passed. Going to save the subscription transaction data.', true );

		$cart_items = array();
		//We will create a single cart item from the IPN data for saving the transaction
		$current_cart_item = array();
		$current_cart_item['item_number'] = $estore_product_id;
		$current_cart_item['item_name'] = $this->ipn_data['item_name'];
		$current_cart_item['quantity'] = 1;
		$current_cart_item['mc_gross'] = $this->ipn_data['mc_gross'];
		$cart_items[] = $current_cart_item;

		$this->save_subscription_txn_data( $this->ipn_data, $cart_items, $txn_data );

		// Trigger the IPN processed action hook (so other plugins can can listen for this event).
		do_action( 'paypal_ppcp_subscription_checkout_ipn_processed', $this->ipn_data );
		do_action( 'paypal_payment_ipn_processed', $this->ipn_data );

		//Get the thank you page URL
		$estore_product_db_row = eStore_get_product_row_by_id( $estore_product_id );
		$thank_you_page_url = isset( $estore_product_db_row->return_url ) ? esc_url( $estore_product_db_row->return_url ) : '';
		if( empty( $thank_you_page_url ) ){
			$thank_you_page_url = get_option('cart_return_from_paypal_url');
		}
		$redirect_url = $thank_you_page_url;

		//If everything is processed successfully, send the success response.
		wp_send_json( array(
			'success' => true,
			'subscription_id' => isset( $this->ipn_data['subscr_id'] ) ? $this->ipn_data['subscr_id'] : '',			
			'redirect_url' => $redirect_url,
		) );
		exit;
    }

	public function create_ipn_data_array_from_create_subscription_txn_data( $data, $txn_data ) {
		$ipn = array();

		//Get the custom field value from the request
		$custom = isset($data['custom_field']) ? $data['custom_field'] : '';
		$custom = urldecode( $custom );//Decode it just in case it was encoded.

		//We can add any PayPal API id reference value to the custom field. So it gets saved with custom field data.
		//This can be used to also save it to the reference DB column field when saving the transaction.
		//$data['custom_field'] = $custom . '&paypal_order_id=' . $ipn['paypal_order_id'];

		$customvariables = PayPal_Utils::parse_custom_var( $custom );

		$billing_info = isset($txn_data['billing_info']) ? $txn_data['billing_info'] : array();

		$address_street = isset($txn_data['subscriber']['shipping_address']['address']['address_line_1']) ? $txn_data['subscriber']['shipping_address']['address']['address_line_1'] : '';
		if ( isset ( $txn_data['subscriber']['shipping_address']['address']['address_line_2'] )){
			//If address line 2 is present, add it to the address.
			$address_street .= ", " . $txn_data['subscriber']['shipping_address']['address']['address_line_2'];
		}

		//Set the gateway and txn_type values.
		$ipn['gateway'] = 'paypal_subscription_checkout';
		$ipn['txn_type'] = 'pp_subscription_new';//Can be used to find sub-created type transactions.

		//The custom field value.
		$ipn['custom'] = isset($data['custom_field']) ? $data['custom_field'] : '';

		//This will save the button ID.
		$ipn['payment_button_id'] = isset($data['button_id']) ? $data['button_id'] : '';
		
		//If the subscription is for live mode or sandbox mode. We will use this to set the 'is_live' flag in the transaction record.
		$ppcp_configs = PayPal_PPCP_Config::get_instance();
		$sandbox_enabled = $ppcp_configs->get_value('enable-sandbox-testing');
		$ipn['is_live'] = $sandbox_enabled ? 0 : 1; //We need to save the environment (live or sandbox) of the subscription.

		//Subscription specific data.
		$ipn['plan_id'] = isset($txn_data['plan_id']) ? $txn_data['plan_id'] : '';//The plan ID of the subscription
		$ipn['subscr_id'] = isset($txn_data['id']) ? $txn_data['id'] : '';//The subscription ID
		$ipn['create_time'] = isset($txn_data['create_time']) ? $txn_data['create_time'] : '';

		//Get the sub item data from the transient. We saved the item details in the transient in the create subscription ajax handler. 
		//We will use that data to populate the item_number and item_name fields in the IPN data array (since those fields are required when saving the transaction and they are not available in the subscription create response).
		$transient_key = 'estore_ppcp_subscription_id_' . $ipn['subscr_id'];
		$retrieved_sub_item_data = get_transient( $transient_key );

		//The item number and name will be taken from cart_items array while saving the transaction.
		$ipn['item_number'] = isset($retrieved_sub_item_data['estore_product_id']) ? $retrieved_sub_item_data['estore_product_id'] : '';
		$ipn['item_name'] = isset($retrieved_sub_item_data['item_name']) ? $retrieved_sub_item_data['item_name'] : '';			

		//The transaction ID is not available in the create/activate subscription response. So we will just use the order ID here.
		//The subscription capture happens in the background. So if we want to use the get transactions list API to get the transaction ID of the first transaction, we will need to do that later using cronjob maybe.
		$ipn['txn_id'] = isset($data['order_id']) ? $data['order_id'] : '';

		$ipn['status'] = __('subscription created', 'woocommerce-paypal-pro-payment-gateway');
		$ipn['payment_status'] = __('subscription created', 'woocommerce-paypal-pro-payment-gateway');
		$ipn['subscription_status'] = isset($txn_data['status']) ? $txn_data['status'] : '';//Can be used to check if the subscription is active or not (in the webhook handler)

		//Amount and currency.
		$ipn['mc_gross'] = isset($txn_data['billing_info']['last_payment']['amount']['value']) ? $txn_data['billing_info']['last_payment']['amount']['value'] : 0;
		$ipn['mc_currency'] = isset($txn_data['billing_info']['last_payment']['amount']['currency_code']) ? $txn_data['billing_info']['last_payment']['amount']['currency_code'] : '';
		if( $this->is_trial_payment( $billing_info )){
			//TODO: May need to get the trial amount from the 'cycle_executions' array
			$ipn['is_trial_txn'] = 'yes';
		}
		$ipn['quantity'] = 1;

		//Customer info.
		$ipn['ip'] = isset($customvariables['user_ip']) ? $customvariables['user_ip'] : '';
		$ipn['first_name'] = isset($txn_data['subscriber']['name']['given_name']) ? $txn_data['subscriber']['name']['given_name'] : '';
		$ipn['last_name'] = isset($txn_data['subscriber']['name']['surname']) ? $txn_data['subscriber']['name']['surname'] : '';
		$ipn['payer_email'] = isset($txn_data['subscriber']['email_address']) ? $txn_data['subscriber']['email_address'] : '';
		$ipn['payer_id'] = isset($txn_data['subscriber']['payer_id']) ? $txn_data['subscriber']['payer_id'] : '';
		$ipn['address_street'] = $address_street;
		$ipn['address_city']    = isset($txn_data['subscriber']['shipping_address']['address']['admin_area_2']) ? $txn_data['subscriber']['shipping_address']['address']['admin_area_2'] : '';
		$ipn['address_state']   = isset($txn_data['subscriber']['shipping_address']['address']['admin_area_1']) ? $txn_data['subscriber']['shipping_address']['address']['admin_area_1'] : '';
		$ipn['address_zip']     = isset($txn_data['subscriber']['shipping_address']['address']['postal_code']) ? $txn_data['subscriber']['shipping_address']['address']['postal_code'] : '';
		$country_code = isset($txn_data['subscriber']['shipping_address']['address']['country_code']) ? $txn_data['subscriber']['shipping_address']['address']['country_code'] : '';
		$ipn['address_country'] = PayPal_Utils::get_country_name_by_country_code($country_code);

		//Create the full address string from the address components (sometimes we use this full address string).
        $full_address_string = (isset($ipn['address_street']) ? $ipn['address_street'] : '');
        $full_address_string .= "\n" . (isset($ipn['address_city']) ? $ipn['address_city'] : '');
        $full_address_string .= "\n" . (isset($ipn['address_state']) ? $ipn['address_state'] : '') . " " . (isset($ipn['address_zip']) ? $ipn['address_zip'] : '');
        $full_address_string .= "\n" . (isset($ipn['address_country']) ? $ipn['address_country'] : '');
		$ipn['address'] = trim( $full_address_string );

		/**********************************/
		//Ensure the customer's email and name are set. For guest checkout, the email and name may not be set in the standard onApprove data.
		//So we will query the subscrition details from the PayPal API to get the subscriber's email and name (if needed).
		/**********************************/
		if( empty($ipn['payer_email']) || empty($ipn['first_name']) || empty($ipn['last_name']) ){
			//Use the subscription ID to get the subscriber's email and name from the PayPal API.
			$subscription_id = isset($ipn['subscr_id']) ? $ipn['subscr_id'] : '';
			PayPal_Utils::log( 'Subscriber Email or Name not set in the onApprove data. Going to query the PayPal API for subscription details. Subscription ID: ' . $subscription_id, true );

			//This is for on-site checkout only. So the 'mode' and API creds will be whatever is currently set in the settings.
			$api_injector = new PayPal_Request_API_Injector();
			$sub_details = $api_injector->get_paypal_subscription_details( $subscription_id );
			if( $sub_details !== false ){
				$subscriber = isset($sub_details->subscriber) ? $sub_details->subscriber : array();
				if(is_object($subscriber)){
					//Convert the object to an array.
					$subscriber_data_array = json_decode(json_encode($subscriber), true);
				}
				//Debugging only.
				PayPal_Utils::log_array( $subscriber_data_array, true );
				
				if( empty($ipn['payer_email']) && isset($subscriber_data_array['email_address']) ){
					//Set the payer email from the subscriber data.
					$ipn['payer_email'] = $subscriber_data_array['email_address'];
				}
				if( empty($ipn['first_name']) && isset($subscriber_data_array['name']['given_name']) ){
					//Set the payer first name from the subscriber data.
					$ipn['first_name'] = $subscriber_data_array['name']['given_name'];
				}
				if( empty($ipn['last_name']) && isset($subscriber_data_array['name']['surname']) ){
					//Set the payer last name from the subscriber data.
					$ipn['last_name'] = $subscriber_data_array['name']['surname'];
				}
				PayPal_Utils::log( 'Subscriber Email: ' . $ipn['payer_email'] . ', First Name: ' . $ipn['first_name'] . ', Last Name: ' . $ipn['last_name'], true );
			} else {
				//Error getting subscription details.
				$validation_error_msg = 'Validation Error! Failed to get subscription details from the PayPal API. Subscription ID: ' . $subscription_id;
				PayPal_Utils::log( $validation_error_msg, false );
			}
		}		

		//Return the IPN data array. This will be used to create/update the member account and save the transaction data.
		$this->ipn_data = $ipn;
	}

	public function is_trial_payment( $billing_info ) {
		if( isset( $billing_info['cycle_executions'][0]['tenure_type'] ) && ($billing_info['cycle_executions'][0]['tenure_type'] === 'TRIAL')){
			return true;
		}
		return false;
	}

	/**
	 * Validate that the subscription exists in PayPal and the price matches the price in the DB.
	 */
	public function validate_subscription_checkout_txn_data( $data, $txn_data ) {
		//Get the subscription details from PayPal API endpoint - v1/billing/subscriptions/{$subscription_id}
		$subscription_id = $data['subscriptionID'];
		$button_id = $data['button_id'];

		$validation_error_msg = '';

		//This is for on-site checkout only. So the 'mode' and API creds will be whatever is currently set in the settings.
		$api_injector = new PayPal_Request_API_Injector();
		$sub_details = $api_injector->get_paypal_subscription_details( $subscription_id );
		if( $sub_details !== false ){
			$billing_info = $sub_details->billing_info;
			if(is_object($billing_info)){
				//Convert the object to an array.
				$billing_info = json_decode(json_encode($billing_info), true);
			}
			//PayPal_Utils::log_array( $billing_info, true );//Debugging only.
			
			$tenure_type = isset($billing_info['cycle_executions'][0]['tenure_type']) ? $billing_info['cycle_executions'][0]['tenure_type'] : ''; //'REGULAR' or 'TRIAL'
			$sequence = isset($billing_info['cycle_executions'][0]['sequence']) ? $billing_info['cycle_executions'][0]['sequence'] : '';//1, 2, 3, etc.
			$cycles_completed = isset($billing_info['cycle_executions'][0]['cycles_completed']) ? $billing_info['cycle_executions'][0]['cycles_completed'] : '';//1, 2, 3, etc.
			PayPal_Utils::log( 'Subscription tenure type: ' . $tenure_type . ', Sequence: ' . $sequence . ', Cycles Completed: '. $cycles_completed, true );			

			//Tenure type - 'REGULAR' or 'TRIAL'
			$tenure_type = isset($billing_info['cycle_executions'][0]['tenure_type']) ? $billing_info['cycle_executions'][0]['tenure_type'] : 'REGULAR';
			//If tenure type is 'TRIAL', check that this button has a trial period.
			if( $tenure_type === 'TRIAL' ){
				PayPal_Utils::log('Trial payment detected.', true);//TODO - remove later.

				//Check that the button has a trial period.
				$trial_billing_cycle = get_post_meta( $button_id, 'trial_billing_cycle', true );
				if( empty($trial_billing_cycle) ){
					//This button does not have a trial period. So this is not a valid trial payment.
					$validation_error_msg = 'Validation Error! This is a trial payment but the button does not have a trial period configured. Button ID: ' . $button_id . ', Subscription ID: ' . $subscription_id;
					PayPal_Utils::log( $validation_error_msg, false );
					return $validation_error_msg;
				}
			} else {
				//This is a regular subscription checkout (without trial). Check that the price matches.
				$amount = isset($billing_info['last_payment']['amount']['value']) ? $billing_info['last_payment']['amount']['value'] : 0;
				$recurring_billing_amount = get_post_meta( $button_id, 'recurring_billing_amount', true );
				if( $amount < $recurring_billing_amount ){
					//The amount does not match.
					$validation_error_msg = 'Validation Error! The subscription amount does not match. Button ID: ' . $button_id . ', Subscription ID: ' . $subscription_id . ', Amount Received: ' . $amount . ', Amount Expected: ' . $recurring_billing_amount;
					PayPal_Utils::log( $validation_error_msg, false );
					return $validation_error_msg;
				}
				//Check that the Currency code matches
				// $currency = isset($billing_info['last_payment']['amount']['currency_code']) ? $billing_info['last_payment']['amount']['currency_code'] : '';
				// $currency_expected = get_post_meta( $button_id, 'payment_currency', true );
				// if( $currency !== $currency_expected ){
				// 	//The currency does not match.
				// 	$validation_error_msg = 'Validation Error! The subscription currency does not match. Button ID: ' . $button_id . ', Subscription ID: ' . $subscription_id . ', Currency Received: ' . $currency . ', Currency Expected: ' . $currency_expected;
				// 	PayPal_Utils::log( $validation_error_msg, false );
				// 	return $validation_error_msg;
				// }
			}

		} else {
			//Error getting subscription details.
			$validation_error_msg = 'Validation Error! Failed to get subscription details from the PayPal API. Subscription ID: ' . $subscription_id;
			//TODO - Show additional error details if available.
			PayPal_Utils::log( $validation_error_msg, false );
			return $validation_error_msg;
		}

		//All good. The data is valid.
		return true;
	}

	public function save_subscription_txn_data( $ipn_data, $cart_items, $txn_data ) {
		global $wpdb;
		$customvariables = eStore_get_payment_custom_var($ipn_data['custom']);

		$firstname = $ipn_data['first_name'];
		$lastname = $ipn_data['last_name'];
		$emailaddress = $ipn_data['payer_email'];
		$clientdate = (date("Y-m-d"));
		$clienttime = (date("H:i:s"));

		$eMember_id = $customvariables['eMember_id'];
		if(empty($eMember_id)){$eMember_id = $customvariables['eMember_userid'];}

		$customer_ip = $customvariables['ip'];
		if (empty($customer_ip)) {$customer_ip = "No information";}

		$status = "Paid Recurring Payment";
		$coupon_used = isset($ipn_data['coupon_used'])? $ipn_data['coupon_used'] : '';

		foreach ($cart_items as $current_cart_item) {

			$current_product_id = $current_cart_item['item_number'];
			$cart_item_data_name = $current_cart_item['item_name'];
			$cart_item_qty = $current_cart_item['quantity'];
			$sale_price = $current_cart_item['mc_gross'];

			//Update the Customer table
			$fields = array();
			$fields['first_name'] = $firstname;
			$fields['last_name'] = $lastname;
			$fields['email_address'] = $emailaddress;
			$fields['purchased_product_id'] = $current_product_id;
			$fields['txn_id'] = $ipn_data['txn_id'];
			$fields['date'] = $clientdate;
			$fields['sale_amount'] = $sale_price;
			$fields['coupon_code_used'] = $coupon_used;
			$fields['member_username'] = $eMember_id;
			$fields['product_name'] = stripslashes($cart_item_data_name);
			$fields['address'] = stripslashes($ipn_data['address']);
			$fields['phone'] = isset($ipn_data['phone']) ? $ipn_data['phone'] : '';
			$fields['subscr_id'] = $ipn_data['subscr_id'];
			$fields['purchase_qty'] = $cart_item_qty;
			$fields['ipaddress'] = $customer_ip;
			$fields['status'] = $status;
			$fields['serial_number'] = isset($ipn_data['product_key_data']) ? stripslashes($ipn_data['product_key_data']) : '';
			$fields['notes'] = '';
			$fields['address_street'] = isset($ipn_data['address_street'])? stripslashes($ipn_data['address_street']) : '';
			$fields['address_city'] = isset($ipn_data['address_city'])? stripslashes($ipn_data['address_city']) : '';
			$fields['address_state'] = isset($ipn_data['address_state'])? stripslashes($ipn_data['address_state']) : '';
			$fields['address_zip'] = isset($ipn_data['address_zip'])? stripslashes($ipn_data['address_zip']) : '';
			$fields['address_country'] = isset($ipn_data['address_country'])? stripslashes($ipn_data['address_country']) : '';

			//Debugging only
			PayPal_Utils::log_array($fields, true);

			$fields = array_filter($fields);//Remove any null values.
			$result = $wpdb->insert(WP_ESTORE_CUSTOMER_TABLE_NAME, $fields);
			if(!$result){
				PayPal_Utils::log('Notice! initial database table insert failed. Trying again by converting charset.', true);
				//Convert the charset to UTF-8 format
				$cart_item_data_name = mb_convert_encoding($cart_item_data_name, "UTF-8", "windows-1252");
				$fields['product_name'] = stripslashes($cart_item_data_name);
				$fields['first_name'] = mb_convert_encoding($firstname, "UTF-8", "windows-1252");
				$fields['last_name'] = mb_convert_encoding($lastname, "UTF-8", "windows-1252");
				$buyer_shipping_info = mb_convert_encoding($ipn_data['address'], "UTF-8", "windows-1252");
				$fields['address'] = stripslashes($buyer_shipping_info);
				$result = $wpdb->insert(WP_ESTORE_CUSTOMER_TABLE_NAME, $fields);
				if(!$result){
					PayPal_Utils::log('Error! Failed to update customer data into the database table. DB insert query failed.', false);
				}
			}

			//Update the sales/stats table
			$sales_data = array();
			$sales_data['cust_email'] = $emailaddress;
			$sales_data['date'] = $clientdate;
			$sales_data['time'] = $clienttime;
			$sales_data['item_id'] = $current_product_id;
			$sales_data['sale_price'] = $sale_price;
			$result = $wpdb->insert(WP_ESTORE_DB_SALES_TABLE_NAME, $sales_data);

			PayPal_Utils::log('Transaction data captured for PayPal subscription payment.', true);

			//eStore's action after recurring payment product database update
			do_action('eStore_product_database_updated_after_recurring_payment', $ipn_data, $cart_items);
		}
	}

}
