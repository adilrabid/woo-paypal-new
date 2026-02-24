<?php

namespace TTHQ\WC_PP_PRO\Lib\PayPal;

/**
 * This clcass handles the ajax requests from the PayPal button's createOrder, captureOrder functions.
 * On successful onApprove event, it creates the required $ipn_data array from the transaction so it can be fed into the existing IPN handler functions easily.
 */
class PayPal_Button_Ajax_Handler {

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
		add_action( PayPal_Utils::hook('pp_create_order', true), array(&$this, 'pp_create_order' ) );
		add_action( PayPal_Utils::hook('pp_create_order', true, true), array(&$this, 'pp_create_order' ) );
		
		//Handle the capture-order ajax request for 'Add to Cart' type buttons.
		add_action( PayPal_Utils::hook('pp_capture_order', true), array(&$this, 'pp_capture_order' ) );
		add_action( PayPal_Utils::hook('pp_capture_order', true, true), array(&$this, 'pp_capture_order' ) );	

		/*----- Buy Now Button Related -----*/
		//Handle the create-order ajax request for 'Buy Now' type buttons.
		add_action( PayPal_Utils::hook('buy_now_pp_create_order', true), array(&$this, 'buy_now_pp_create_order' ) );
		add_action( PayPal_Utils::hook('buy_now_pp_create_order', true, true), array(&$this, 'buy_now_pp_create_order' ) );		

		//Handle the capture-order ajax request for 'Buy Now' type buttons.
		add_action( PayPal_Utils::hook('buy_now_pp_capture_order', true), array(&$this, 'buy_now_pp_capture_order' ) );
		add_action( PayPal_Utils::hook('buy_now_pp_capture_order', true, true), array(&$this, 'buy_now_pp_capture_order' ) );

	}

	/**
	 * Handle the pp_create_order ajax request for standard cart checkout.
	 */
	 public function pp_create_order(){
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

		$cart_id = isset( $data['cart_id'] ) ? sanitize_text_field( $data['cart_id'] ) : '';
		$on_page_button_id = isset( $data['on_page_button_id'] ) ? sanitize_text_field( $data['on_page_button_id'] ) : '';
		PayPal_Utils::log( 'pp_create_order ajax request received for createOrder. Cart ID: '.$cart_id.', On Page Button ID: ' . $on_page_button_id, true );

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
		
		//Ensure the PHP session is there so we can get details from the cart.
		//NOTE: We can also, save the cart items to a transient with a unique key (e.g. cart_id) when the button is rendered then retrieve it here.
		if (session_status() === PHP_SESSION_NONE) {
			session_start();
		}

		//Get the cart and item details.
		$description = 'WP eStore Cart Order';//Default description.
		$description = htmlspecialchars($description);
		$description = substr($description, 0, 127);//Limit the item name to 127 characters (PayPal limit)

		//Get all the payment totals/amount
		$cart_sub_total = eStore_get_cart_total();
		$formatted_sub_total = number_format($cart_sub_total,2,'.','');
		
		//Shipping amount
		//Note: use the following function to avoid recalculating shipping if it's already zero due to free shipping discount or store pickup option.
		$cart_postage_cost = eStore_calculate_cart_shipping_if_not_zero();
		$formatted_postage_cost = number_format($cart_postage_cost, 2, '.', '');
		//PayPal_Utils::log( 'Cart shipping cost: ' . $cart_postage_cost, true );

		//Tax amount
		$cart_total_tax = eStore_calculate_total_cart_tax();
		$formatted_cart_total_tax = number_format($cart_total_tax, 2, '.', '');

		//Grand total
		$cart_grand_total = $cart_sub_total + $cart_postage_cost + $cart_total_tax;
		$formatted_grand_total = number_format($cart_grand_total, 2, '.', '');

		//Get the currency
		$currency = !empty(get_option( 'cart_payment_currency' )) ? get_option( 'cart_payment_currency' ) : 'USD';

		//Get the cart items from the session.
		// Note: We can also use a transient to store the cart items. Logic in the comment below.
		// - when PPCP button is rendered, we save cart items to transient with a unique key (e.g. cart_id).
		// - pass that key to the AJAX request.
		// - retrieve the cart items from transient using that key.
		$cart_items = isset($_SESSION['eStore_cart']) ? $_SESSION['eStore_cart'] : array();
		//Debugging purpose.
		//PayPal_Utils::log_array( $cart_items, true );
		

		//Ensure the cart items are not empty.
		if( empty($cart_items) ){
			//If the cart is empty, then we cannot create an order.
			wp_send_json(
				array(
					'success' => false,
					'err_msg'  => __( 'The cart is empty. Please add items to the cart before proceeding.', 'woocommerce-paypal-pro-payment-gateway' ),
				)
			);
			exit;
		}

		//Create the purchase units items array from the cart items.
		$pu_items = PayPal_Utils::create_purchase_units_items_list( $cart_items );

		//Get the shipping preference.
		$all_items_digital = is_all_eStore_cart_items_digital();
		if( $all_items_digital ){
			//This will only happen if the shortcode attribute 'digital' is set to '1' for all the items in the cart. 
			//So we don't need to check postage cost.
			$shipping_preference = 'NO_SHIPPING';
		} else {
			//At least one item is not digital. Get the customer-provided shipping address on the PayPal site.
			$shipping_preference = 'GET_FROM_FILE';//This is also the default value for the shipping preference.
		}
		PayPal_Utils::log("Shipping preference based on the 'all items digital' flag: " . $shipping_preference, true);

		// Create the order using the PayPal API.
		// https://developer.paypal.com/docs/api/orders/v2/#orders_create
		$data = array(
			'description' => $description,
			'grand_total' => $formatted_grand_total,
			'sub_total' => $formatted_sub_total,
			'postage_cost' => $formatted_postage_cost,
			'tax_total' => $formatted_cart_total_tax,
			'currency' => $currency,
			'shipping_preference' => $shipping_preference,
		);
		//Debugging purposes.		
		PayPal_Utils::log_array( $data, true );

		//Set the additional args for the API call.
		$additional_args = array();
		$additional_args['return_response_body'] = true;

		//Create the order using the PayPal API.
		$api_injector = new PayPal_Request_API_Injector();
		$response = $api_injector->create_paypal_order_by_url_and_args( $data, $additional_args, $pu_items );
            
		//We requested the response body to be returned, so we need to JSON decode it.
		if( $response !== false ){
			$order_data = json_decode( $response, true );
			$paypal_order_id = isset( $order_data['id'] ) ? $order_data['id'] : '';
		} else {
			//Failed to create the order.
			wp_send_json(
				array(
					'success' => false,
					'err_msg'  => __( 'Failed to create the order using PayPal API. Enable the debug logging feature to get more details.', 'woocommerce-paypal-pro-payment-gateway' ),
				)
			);
			exit;
		}

        PayPal_Utils::log( 'PayPal Order ID: ' . $paypal_order_id, true );

		//Save the order ID in the transient for 12 hours (so we can use it later in the capture order request).
		//(we will use this one to process in the IPN processing stage).
		$transient_key = 'estore_ppcp_order_id_' . $paypal_order_id;
		set_transient( $transient_key, $cart_items, 12 * HOUR_IN_SECONDS );

		//TODO 
		//Save the grand total and currency in the order CPT (we will match it with the PayPal response later in verification stage).
		//update_post_meta( $cart_id, 'expected_payment_amount', $formatted_grand_total );
		//update_post_meta( $cart_id, 'expected_currency', $currency );

		//Save the current cart items with the PayPal order ID in the order CPT (we will use this one to process in the IPN processing stage).
		//$cart_items = get_post_meta($cart_id, 'wp_estore_cart_items', true);
		//$wp_estore_cart_items_pp_order_id_key = 'wp_estore_cart_items_' . $paypal_order_id;
		//update_post_meta( $cart_id, $wp_estore_cart_items_pp_order_id_key, $cart_items );

		//If everything is processed successfully, send the success response.
		wp_send_json( array( 'success' => true, 'order_id' => $paypal_order_id, 'order_data' => $order_data ) );
		exit;
    }


	/**
	 * Handles the order capture for standard cart checkout.
	 */
	public function pp_capture_order(){

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

		//Get the order_id from data
		$order_id = isset( $data['order_id'] ) ? sanitize_text_field($data['order_id']) : '';
		if ( empty( $order_id ) ) {
			PayPal_Utils::log( 'pp_capture_order - empty order ID received.', false );
			wp_send_json(
				array(
					'success' => false,
					'err_msg'  => __( 'Empty order ID received.', 'woocommerce-paypal-pro-payment-gateway' ),
				)
			);
		}

		$cart_id = isset( $data['cart_id'] ) ? sanitize_text_field( $data['cart_id'] ) : '';
		$on_page_button_id = isset( $data['on_page_button_id'] ) ? sanitize_text_field( $data['on_page_button_id'] ) : '';
		PayPal_Utils::log( 'Received request - pp_capture_order. PayPal Order ID: ' . $order_id . ', Cart ID: '.$cart_id.', On Page Button ID: ' . $on_page_button_id, true );

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

		//Set the additional args for the API call.
		$additional_args = array();
		$additional_args['return_response_body'] = true;

		// Capture the order using the PayPal API.
		// https://developer.paypal.com/docs/api/orders/v2/#orders_capture
		$api_injector = new PayPal_Request_API_Injector();
		$response = $api_injector->capture_paypal_order( $order_id, $additional_args );

		//We requested the response body to be returned, so we need to JSON decode it.
		if($response !== false){
			$txn_data = json_decode( $response, true );//JSON decode the response body that we received.
		} else {
			//Failed to capture the order.
			wp_send_json(
				array(
					'success' => false,
					'err_msg'  => __( 'Failed to capture the order. Enable the debug logging feature to get more details.', 'woocommerce-paypal-pro-payment-gateway' ),
				)
			);
			exit;
		}

		//--
		// PayPal_Utils::log_array($data, true);//Debugging purpose.
		// PayPal_Utils::log_array($txn_data, true);//Debugging purpose.
		//--

		//Create the IPN data array from the transaction data.
		//Need to include the following values in the $data array.
		$data['custom_field'] = get_transient( $cart_id );//We saved the custom field in the transient using cart ID.
		
		$ipn_data = PayPal_Utility_IPN_Related::create_ipn_data_array_from_capture_order_txn_data( $data, $txn_data );
		$paypal_capture_id = isset( $ipn_data['txn_id'] ) ? $ipn_data['txn_id'] : '';
		PayPal_Utils::log( 'PayPal Capture ID (Transaction ID): ' . $paypal_capture_id, true );
		PayPal_Utils::log_array( $ipn_data, true );//Debugging purpose.
		
		/* Since this capture is done from server side, the validation is not required but we are doing it anyway. */
		//Validate the buy now txn data before using it.
		$validation_response = PayPal_Utility_IPN_Related::validate_buy_now_checkout_txn_data( $data, $txn_data );
		if( $validation_response !== true ){
			//Debug logging will reveal more details.
			wp_send_json(
				array(
					'success' => false,
					'error_detail'  => $validation_response,/* it contains the error message */
				)
			);
			exit;
		}
		
		//Process the IPN data array
		PayPal_Utils::log( 'Validation passed. Going to create/update record and save transaction data.', true );
		
		/**
		 * TODO: This is a plugin specific method.
		 */
		PayPal_Utility_IPN_Related::complete_post_payment_processing( $data, $txn_data, $ipn_data );

		/**
		 * Trigger the IPN processed action hook (so other plugins can can listen for this event).
		 * Remember to use plugin shortname as prefix when searching for this hook.
		 */ 
		do_action( PayPal_Utils::hook('paypal_checkout_ipn_processed'), $ipn_data );
		do_action( PayPal_Utils::hook('payment_ipn_processed'), $ipn_data );

		//Everything is processed successfully, send the success response.
		wp_send_json( array( 'success' => true, 'order_id' => $order_id, 'capture_id' => $paypal_capture_id, 'txn_data' => $txn_data ) );
		exit;
	}

	/**
	 * Handles the create order for 'Buy Now' type buttons.
	 */
	public function buy_now_pp_create_order(){
		//Get the data from the request for Buy Now button.
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
		PayPal_Utils::log( 'buy_now_pp_create_order ajax request received for createOrder. Product ID: '.$estore_product_id.', On Page Button ID: ' . $on_page_button_id . ', Unique Key: ' . $unique_key, true );

		//Variation and Custom amount related data.
		$item_name = isset( $data['item_name'] ) ? sanitize_text_field( $data['item_name'] ) : '';
		$amount_submitted = isset( $data['amount'] ) ? floatval( $data['amount'] ) : 0;
		$custom_price = isset( $data['custom_price'] ) ? floatval( $data['custom_price'] ) : 0;

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
		$ret_product = eStore_get_product_row_by_id($id);
		if (!$ret_product) {
			$wrong_product_error_msg = eStore_wrong_product_id_error_msg($id);
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

		//Get the currency
		if (!empty($ret_product->currency_code)){
			//Use the product currency if it's set.
			$currency = $ret_product->currency_code;
		} else if (!empty(get_option( 'cart_payment_currency' ))) {
			//Use the default settings currency.
			$currency = get_option('cart_payment_currency');
		} else {
			//Fallback to USD.
			$currency = 'USD';
		}

		//Get the cart and item details.
		$description = 'WP eStore Buy Now Product ID: ' . $estore_product_id;//Default description.
		$description = htmlspecialchars($description);
		$description = substr($description, 0, 127);//Limit the item name to 127 characters (PayPal limit)

		//Get all the payment amount/totals.
    	if ($ret_product->custom_price_option == '1' && $custom_price > 0) {
			//#1) First, check if custom price option is set.
			$amount_sub_total = $custom_price;
		} else if (eStore_has_product_variation($estore_product_id) ){
			//#2) Then check if product has variations and use the submitted amount.
			//We already did variation price validation above in the process.
			$amount_sub_total = $amount_submitted;
		} else {
			//#3) Fallback to the product price specified in the database.
			$amount_sub_total = $ret_product->price;
		}
		//Shipping
		$postage_cost = 0;
        if (!empty($ret_product->shipping_cost)) {
            $base_shipping = get_option('eStore_base_shipping');
            $postage_cost = round($ret_product->shipping_cost + $base_shipping, 2);
		}
		//Tax
		$tax = 0;
		if (get_option('eStore_enable_tax')) {
			if (is_numeric($ret_product->tax)) {
				$tax = round(($amount_sub_total * $ret_product->tax) / 100, 2);
			} else {
				$tax_rate = get_option('eStore_global_tax_rate');
				$tax = round(($amount_sub_total * $tax_rate) / 100, 2);
			}
		}

		$sub_total = $amount_sub_total;
		$formatted_sub_total = number_format($sub_total,2,'.','');
		$formatted_postage_cost = number_format($postage_cost, 2, '.', '');
		$formatted_total_tax = number_format($tax, 2, '.', '');
		$grand_total = $sub_total + $postage_cost + $tax;
		$formatted_grand_total = number_format($grand_total, 2, '.', '');		

		//Create the purchase units items array from the product data.
		//For Buy Now button, we should only have one item in the cart.
		//Create the $cart_item array with the submitted product data (that includes variation and custom amount info) and then we will use it to create the purchase units items list.
		$item_name = !empty($item_name) ? $item_name : $ret_product->name;
		$digital_flag = wp_eStore_is_digital_product( $ret_product );
		$cart_item = array(
			'name' => $item_name,
			'quantity' => 1,
			'price' => $sub_total,
			'digital_flag' => $digital_flag,
		);		
		$pu_items = PayPal_Utils::create_purchase_unit_items_list_using_bn_data( $cart_item );

		//Get the shipping preference.
		$is_digital_item = wp_eStore_is_digital_product( $ret_product );
		if( $is_digital_item ){
			//This will only happen if the shortcode attribute 'digital' is set to '1' for all the items in the cart. 
			//So we don't need to check postage cost.
			$shipping_preference = 'NO_SHIPPING';
		} else {
			//At least one item is not digital. Get the customer-provided shipping address on the PayPal site.
			$shipping_preference = 'GET_FROM_FILE';//This is also the default value for the shipping preference.
		}
		PayPal_Utils::log("Shipping preference based on the 'digital' flag: " . $shipping_preference, true);

		// Create the order using the PayPal API.
		// https://developer.paypal.com/docs/api/orders/v2/#orders_create
		$data = array(
			'description' => $description,
			'grand_total' => $formatted_grand_total,
			'sub_total' => $formatted_sub_total,
			'postage_cost' => $formatted_postage_cost,
			'tax_total' => $formatted_total_tax,
			'currency' => $currency,
			'shipping_preference' => $shipping_preference,
		);
		//Debugging purposes.		
		PayPal_Utils::log_array( $data, true );

		//Set the additional args for the API call.
		$additional_args = array();
		$additional_args['return_response_body'] = true;

		//Create the order using the PayPal API.
		$api_injector = new PayPal_Request_API_Injector();
		$response = $api_injector->create_paypal_order_by_url_and_args( $data, $additional_args, $pu_items );
            
		//We requested the response body to be returned, so we need to JSON decode it.
		if( $response !== false ){
			$order_data = json_decode( $response, true );
			$paypal_order_id = isset( $order_data['id'] ) ? $order_data['id'] : '';
		} else {
			//Failed to create the order.
			wp_send_json(
				array(
					'success' => false,
					'err_msg'  => __( 'Failed to create the order using PayPal API. Enable the debug logging feature to get more details.', 'woocommerce-paypal-pro-payment-gateway' ),
				)
			);
			exit;
		}

        PayPal_Utils::log( 'PayPal Order ID: ' . $paypal_order_id, true );

		//Save the order ID in the transient for 12 hours (so we can use it later in the capture order request).
		//(we will use this one in the IPN processing stage).
		$transient_key = 'estore_ppcp_order_id_' . $paypal_order_id;
		set_transient( $transient_key, $pu_items, 12 * HOUR_IN_SECONDS );
		//Debugging purpose.
		//PayPal_Utils::log_array( $pu_items, true );

		//If everything is processed successfully, send the success response.
		wp_send_json( array( 'success' => true, 'order_id' => $paypal_order_id, 'order_data' => $order_data ) );
		exit;		
		
	}


	/**
	 * Handles the order capture for 'Buy Now' type buttons.
	 */
	public function buy_now_pp_capture_order(){

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

		//Get the order_id from data
		$order_id = isset( $data['order_id'] ) ? sanitize_text_field($data['order_id']) : '';
		if ( empty( $order_id ) ) {
			PayPal_Utils::log( 'buy_now_pp_capture_order - empty order ID received.', false );
			wp_send_json(
				array(
					'success' => false,
					'err_msg'  => __( 'Empty order ID received.', 'woocommerce-paypal-pro-payment-gateway' ),
				)
			);
		}

		$estore_product_id = isset( $data['estore_product_id'] ) ? intval( $data['estore_product_id'] ) : '';
		$on_page_button_id = isset( $data['on_page_button_id'] ) ? sanitize_text_field( $data['on_page_button_id'] ) : '';
		$unique_key = isset( $data['unique_key'] ) ? sanitize_text_field( $data['unique_key'] ) : '';
		PayPal_Utils::log( 'Received request - buy_now_pp_capture_order. PayPal Order ID: ' . $order_id . ', Product ID: '.$estore_product_id.', On Page Button ID: ' . $on_page_button_id . ', Unique Key: ' . $unique_key, true );

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

		//Set the additional args for the API call.
		$additional_args = array();
		$additional_args['return_response_body'] = true;

		// Capture the order using the PayPal API.
		// https://developer.paypal.com/docs/api/orders/v2/#orders_capture
		$api_injector = new PayPal_Request_API_Injector();
		$response = $api_injector->capture_paypal_order( $order_id, $additional_args );

		//We requested the response body to be returned, so we need to JSON decode it.
		if($response !== false){
			$txn_data = json_decode( $response, true );//JSON decode the response body that we received.
		} else {
			//Failed to capture the order.
			wp_send_json(
				array(
					'success' => false,
					'err_msg'  => __( 'Failed to capture the order. Enable the debug logging feature to get more details.', 'woocommerce-paypal-pro-payment-gateway' ),
				)
			);
			exit;
		}

		//--
		// PayPal_Utils::log_array($data, true);//Debugging purpose.
		// PayPal_Utils::log_array($txn_data, true);//Debugging purpose.
		//--

		//Create the IPN data array from the transaction data.
		//Need to include the following values in the $data array.
		$data['custom_field'] = get_transient( $unique_key );//We saved the custom field in the transient using unique key.

		$ipn_data = PayPal_Utility_IPN_Related::create_ipn_data_array_from_capture_order_txn_data( $data, $txn_data );
		$paypal_capture_id = isset( $ipn_data['txn_id'] ) ? $ipn_data['txn_id'] : '';
		PayPal_Utils::log( 'PayPal Capture ID (Transaction ID): ' . $paypal_capture_id, true );
		PayPal_Utils::log_array( $ipn_data, true );//Debugging purpose.
		
		/* Since this capture is done from server side, the validation is not required but we are doing it anyway. */
		//Validate the buy now txn data before using it.
		//TODO : Verify if we need to validate here for Buy Now button.
		// $validation_response = PayPal_Utility_IPN_Related::validate_buy_now_checkout_txn_data( $data, $txn_data );
		// if( $validation_response !== true ){
		// 	//Debug logging will reveal more details.
		// 	wp_send_json(
		// 		array(
		// 			'success' => false,
		// 			'error_detail'  => $validation_response,/* it contains the error message */
		// 		)
		// 	);
		// 	exit;
		// }
		
		//Process the IPN data array
		PayPal_Utils::log( 'Validation passed. Going to create/update record and save transaction data.', true );
		
		/**
		 * TODO: This is a plugin specific method.
		 */
		PayPal_Utility_IPN_Related::complete_buy_now_post_payment_processing( $data, $txn_data, $ipn_data );

		/**
		 * Trigger the IPN processed action hook (so other plugins can can listen for this event).
		 * Remember to use plugin shortname as prefix when searching for this hook.
		 */ 
		do_action( PayPal_Utils::hook('paypal_ppcp_buy_now_ipn_processed'), $ipn_data );
		do_action( PayPal_Utils::hook('payment_ipn_processed'), $ipn_data );

		//Everything is processed successfully, send the success response.
		wp_send_json( array( 'success' => true, 'order_id' => $order_id, 'capture_id' => $paypal_capture_id, 'txn_data' => $txn_data ) );
		exit;
	}

}
