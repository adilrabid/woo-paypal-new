<?php

namespace TTHQ\WC_PP_PRO\Lib\PayPal;

/**
 * This class handles the ajax request from the PayPal Subscription button events (the create_subscription event is triggered from the Button's JS code). 
 * It creates the required $ipn_data array from the transaction so it can be fed into the existing IPN handler functions easily.
 */
class PayPal_Button_Sub_Ajax_Handler {

	public $ipn_data  = array();

	public $wc_paypal_ppcp;
	private $checkout_customer_data = array();

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

	    if(! check_ajax_referer(PayPal_Utils::auto_prefix('pp_checkout_nonce'), 'nonce', false)){
		    wp_send_json_error(array('message' => 'Failed to create subscription. Nonce verification failed!'));
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


	    $cart = WC()->cart;

	    if ( ! $cart || $cart->is_empty() ) {
		    wp_send_json_error(array('message' => 'Failed to create subscription. Cart is empty!'));
	    }

		$cart_items = $cart->get_cart();

	    /**
	     * @var $sub_product object WC_Product
	     */
		$sub_product = null;
	    foreach ( $cart_items as $item ) {
		    if ( \WCPPROG_Subscription_Related::SUBSCRIPTION_PRODUCT_TYPE === $item['data']->get_type() ) {
		        // PayPal_Utils::log_array($item['data']);
			    $sub_product = $item['data'];
				break;
		    }
	    }

	    if ( ! $sub_product ) {
		    wp_send_json_error( array( 'message' => __( 'No subscription product was found in your cart. Please refresh the page and try again.', 'woocommerce-paypal-pro-payment-gateway' ) ) );
	    }

	    $sub_product_id = $sub_product->get_id();

		$this->read_checkout_customer_data();
		try {
			$subscription_data = $this->get_checkout_subscription_data( $cart, $sub_product );
		} catch ( \InvalidArgumentException $error ) {
			PayPal_Utils::log( 'Subscription checkout validation: ' . $error->getMessage(), false );
			wp_send_json_error( array( 'message' => $error->getMessage() ) );
		} catch ( \Throwable $error ) {
			PayPal_Utils::log( 'Subscription totals calculation failed: ' . $error->getMessage(), false );
			wp_send_json_error( array( 'message' => __( 'Unable to calculate subscription totals. Please refresh checkout and try again.', 'woocommerce-paypal-pro-payment-gateway' ) ) );
		}

		//Note: For PPCP Subscription type buttons, the currency must be the same as the store's currency from settings (PayPal PPCP doesn't allow JS SDK to be one currency and the subscription plan to be in a different currency dynamically).

	    /*
		 * Get the plan ID (or create a new plan if needed) for the product.
	     */
        $plan = PayPal_Utils::create_billing_plan_for_product( $sub_product_id );
        if ( ! $plan['success'] ) {
            wp_send_json_error( array( 'message' => $plan['error_message'] ) );
        }

        $plan_id = isset($plan['plan_id']) ? sanitize_text_field($plan['plan_id']) : '';

	    /*
		 * Create the subscription on PayPal
		 */
		$api_injector = new PayPal_Request_API_Injector();

		// Set the additional args for the API call.
		$additional_args = array();
		$additional_args['return_raw_response'] = true;

		$response = $api_injector->create_paypal_subscription_for_billing_plan( $plan_id, $subscription_data, $additional_args );

		if ( is_wp_error( $response ) ) {
			PayPal_Utils::log( 'PayPal subscription transport error: ' . $response->get_error_message(), false );
			wp_send_json_error( array( 'message' => __( 'Unable to connect to PayPal. Please try again.', 'woocommerce-paypal-pro-payment-gateway' ) ) );
		}

		$status = wp_remote_retrieve_response_code( $response );

		$sub_data = json_decode( wp_remote_retrieve_body( $response ), true );

		// Debug purpose only
		// PayPal_Utils::log( "Received subscription data: " );
		// PayPal_Utils::log_array( $sub_data );

		$paypal_sub_id = isset( $sub_data['id'] ) ? sanitize_text_field($sub_data['id']) : '';
		if ( $status < 200 || $status >= 300 || empty( $paypal_sub_id ) ) {
			// Raw-body mode previously hid HTTP errors and skipped the API logger.
			$details = array();
			foreach ( isset( $sub_data['details'] ) && is_array( $sub_data['details'] ) ? $sub_data['details'] : array() as $detail ) {
				$details[] = array_intersect_key( $detail, array_flip( array( 'field', 'issue', 'description' ) ) );
			}
			$debug_id = wp_remote_retrieve_header( $response, 'paypal-debug-id' );

			PayPal_Utils::log( 'PayPal subscription creation rejected or returned an invalid response.', false );
			PayPal_Utils::log_array( array(
				'http_status' => $status,
				'debug_id' => $debug_id ?: ( $sub_data['debug_id'] ?? '' ),
				'name' => $sub_data['name'] ?? '',
				'message' => $sub_data['message'] ?? 'Response did not contain a subscription ID.',
				'details' => $details,
				'plan_id' => $plan_id,
				'plan_override' => $subscription_data['plan'],
				'shipping_preference' => $subscription_data['application_context']['shipping_preference'] ?? '',
			), false );

			$message = __( 'PayPal could not create the subscription.', 'woocommerce-paypal-pro-payment-gateway' );
			foreach ( $details as $detail ) {
				if ( ! empty( $detail['issue'] ) ) {
					$message .= ' ' . sanitize_text_field( $detail['issue'] );
					if ( ! empty( $detail['field'] ) ) {
						$message .= ' (' . sanitize_text_field( $detail['field'] ) . ')';
					}
				}
			}
			wp_send_json_error( array( 'message' => $message ) );
		}

		//Uncomment the following line to see more details of the subscription data.
		//PayPal_Utils::log_array( $sub_data, true );

		$wc_order = $this->create_wc_order_from_cart();
	    if ( empty($wc_order)) {
		    wp_send_json_error(array('message' => 'Failed to create order'));
	    }

		//Debugging purpose.
		//PayPal_Utils::log_array( $sub_item_data, true );		

	    // Store PayPal order ID in WC order meta
	    $wc_order->update_meta_data('_paypal_subscription_id', $paypal_sub_id);
		$wc_order->update_meta_data( '_wcpprog_paypal_plan_id', $plan_id );
		$wc_order->update_meta_data( '_wcpprog_has_trial', $sub_product->is_trial_enabled() ? 'yes' : 'no' );
		WC()->session->set( 'wcpprog_subscription_approval_order', $wc_order->get_id() );
		$wc_order->update_meta_data( '_wcpprog_initial_payment_pending', (float) $wc_order->get_total() > 0 ? 'yes' : 'no' );

	    $wc_order_attributions = isset($_POST['attributions']) ? map_deep( json_decode(wp_unslash( $_POST['attributions'] ), true), 'sanitize_text_field') : array();
	    if (!empty($wc_order_attributions)) {
			PayPal_Utils::add_wc_order_attribution_fields( $wc_order,  $wc_order_attributions);
		}

	    $wc_order->save();

	    //If everything is processed successfully, send the success response.
		wp_send_json_success( array(
			'subscription_id' => $paypal_sub_id
		) );
    }



	/**
	 * Override prices for this buyer using WooCommerce's tax/discount calculations.
	 * Amounts include shipping, taxes and fees; do not add PayPal taxes on top.
	 */
	private function get_checkout_subscription_data( $cart, $product ) {
		$cart->calculate_totals();
		$totals = \WCPPROG_Subscription_Related::get_subscription_checkout_totals( $cart, $product );
		$initial_total = $totals['initial'];
		$recurring_total = $totals['recurring'];
		$has_trial = $product->is_trial_enabled();
		$money = static function ( $amount ) {
			return array( 'currency_code' => get_woocommerce_currency(), 'value' => wc_format_decimal( $amount, wc_get_price_decimals() ) );
		};
		$cycles = array();
		if ( $has_trial ) {
			$cycles[] = array( 'sequence' => 1, 'pricing_scheme' => array( 'fixed_price' => $money( $initial_total ) ) );
		}
		$cycles[] = array( 'sequence' => $has_trial ? 2 : 1, 'pricing_scheme' => array( 'fixed_price' => $money( $recurring_total ) ) );
		$data = array( 'plan' => array( 'billing_cycles' => $cycles ), 'quantity' => '1' );
		if ( $cart->needs_shipping() ) {
			$customer = WC()->customer;
			$address = array(
				'address_line_1' => $customer->get_shipping_address_1(),
				'address_line_2' => $customer->get_shipping_address_2(),
				'admin_area_2' => $customer->get_shipping_city(),
				'admin_area_1' => $customer->get_shipping_state(),
				'postal_code' => $customer->get_shipping_postcode(),
				'country_code' => $customer->get_shipping_country(),
			);
			// calculate_shipping() returns selected rates on older WooCommerce too.
			// Do not test shipping_total: a valid selected rate can be free.
			if ( empty( $address['address_line_1'] ) || empty( $address['country_code'] ) ) {
				throw new \InvalidArgumentException( esc_html__( 'Please enter a complete shipping address before subscribing.', 'woocommerce-paypal-pro-payment-gateway' ) );
			}
			$rates = $cart->calculate_shipping();
			$packages = WC()->shipping()->get_packages();
			$chosen = (array) WC()->session->get( 'chosen_shipping_methods', array() );
			if ( ! $rates || ! $packages ) {
				throw new \InvalidArgumentException( esc_html__( 'No shipping options are available for this address. Please verify the address is correct or try a different address.', 'woocommerce-paypal-pro-payment-gateway' ) );
			}
			foreach ( $packages as $key => $package ) {
				if ( empty( $package['rates'] ) ) {
					throw new \InvalidArgumentException( esc_html__( 'No shipping options are available for this address. Please verify the address is correct or try a different address.', 'woocommerce-paypal-pro-payment-gateway' ) );
				}
				if ( empty( $chosen[ $key ] ) || ! isset( $package['rates'][ $chosen[ $key ] ] ) ) {
					throw new \InvalidArgumentException( esc_html__( 'Please select an available shipping method before subscribing.', 'woocommerce-paypal-pro-payment-gateway' ) );
				}
			}
			$full_name = isset( $_POST['shipping_full_name'] ) && is_string( $_POST['shipping_full_name'] )
				? sanitize_text_field( wp_unslash( $_POST['shipping_full_name'] ) ) : '';
			if ( '' === $full_name ) {
				$full_name = trim( $customer->get_shipping_first_name() . ' ' . $customer->get_shipping_last_name() );
			}
			if ( '' === $full_name ) {
				$full_name = trim( $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name() );
			}
			if ( '' === $full_name ) {
				wp_send_json_error( array( 'message' => __( 'Please enter the shipping recipient’s name in checkout before subscribing.', 'woocommerce-paypal-pro-payment-gateway' ) ) );
			}
			$data['subscriber']['shipping_address'] = array(
				'name' => array( 'full_name' => $full_name ),
				'address' => array_filter( $address, 'strlen' ),
			);
			$data['application_context']['shipping_preference'] = 'SET_PROVIDED_ADDRESS';
		} else {
			$data['application_context']['shipping_preference'] = 'NO_SHIPPING';
		}
		PayPal_Utils::log( 'Subscription checkout totals (including tax, shipping, fees and discounts).', true );
		PayPal_Utils::log_array( array( 'initial' => $money( $initial_total ), 'recurring' => $money( $recurring_total ), 'trial' => $has_trial ), true );
		return $data;
	}

	/**
	 * Read only supported address fields, never customer IDs or posted totals.
	 */
	private function read_checkout_customer_data() {
		$posted = isset( $_POST['checkout_customer'] ) && is_string( $_POST['checkout_customer'] )
			? json_decode( wp_unslash( $_POST['checkout_customer'] ), true ) : array();
		$customer = WC()->customer;
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' ) as $field ) {
				$key = $type . '_' . $field;
				$getter = 'get_' . $key;
				if ( ! is_callable( array( $customer, $getter ) ) ) {
					continue;
				}
				$value = isset( $posted[ $type ] ) && is_array( $posted[ $type ] ) && array_key_exists( $field, $posted[ $type ] )
					? $posted[ $type ][ $field ] : $customer->$getter();
				$this->checkout_customer_data[ $key ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
			}
		}
		$data = $this->checkout_customer_data;
		if ( empty( $data['billing_first_name'] ) || empty( $data['billing_last_name'] ) || ! is_email( $data['billing_email'] ?? '' ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter your billing name and a valid email address before subscribing.', 'woocommerce-paypal-pro-payment-gateway' ) ) );
		}
		foreach ( $data as $key => $value ) {
			$setter = 'set_' . $key;
			$customer->$setter( $value );
		}
	}

	/**
	 * Create WooCommerce order from current cart
	 */
	private function create_wc_order_from_cart() {
		try {
			// Create order from cart
			$checkout = WC()->checkout();

			// Get posted data
			$data = $this->checkout_customer_data;
			$data['ship_to_different_address'] = 1;

			// Create the order
			$order_id = $checkout->create_order($data);

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

			return $order;
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * Handle the onApprove ajax request for 'Subscription' type buttons
	 */
    public function sub_onapprove_process_subscription(){
	    if(! check_ajax_referer(PayPal_Utils::auto_prefix('pp_checkout_nonce'), 'nonce', false)){
		    wp_send_json_error(array('message' => 'Failed to approve subscription. Nonce verification failed!'));
	    }

		//Get the data from the request
		$data = isset( $_POST['data'] ) ? json_decode( wp_unslash( $_POST['data'] ), true ) : array();
		if ( empty( $data ) ) {
			wp_send_json_error( array(
				'message'  => __( 'Empty data received.', 'woocommerce-paypal-pro-payment-gateway' ),
			));
		}
		PayPal_Utils::log_array( $data, true );//Debugging only

		//Get the transaction data from the request.
		$txn_data = isset( $_POST['txn_data'] ) ? json_decode( wp_unslash( $_POST['txn_data'] ), true ) : array();
		if ( empty( $txn_data ) ) {
			wp_send_json_error(
				array(
					'message'  => __( 'Empty transaction data received.', 'woocommerce-paypal-pro-payment-gateway' ),
				)
			);
		}

		//Create the IPN data array from the transaction data.
		//Need to include the following values in the $data array.
//		$data['custom_field'] = get_transient( $unique_key );//We saved the custom field data in the transient using the unique key. // TODO: Remvoe this

		//Validate the subscription txn data before using it.
		$validation_response = $this->validate_subscription_checkout_txn_data( $data, $txn_data ); // TODO: need to uncomment
		if( $validation_response !== true ){
			wp_send_json_error(
				array(
					'message'  => $validation_response,
				)
			);
		}

		//Process the IPN data array
		$this->create_ipn_data_array_from_create_subscription_txn_data( $data, $txn_data );
		PayPal_Utils::log( 'Validation passed! Going to save the subscription transaction data.', true );

	    $wc_order = PayPal_Utility_IPN_Related::complete_post_subscription_payment_processing( $data, $txn_data, $this->ipn_data );
	    if (is_wp_error($wc_order)) {
		    wp_send_json_error(array('message' => $wc_order->get_error_message()));
	    }

		// Trigger the IPN processed action hook (so other plugins can can listen for this event).
		do_action( PayPal_Utils::auto_prefix('paypal_ppcp_subscription_checkout_ipn_processed'), $this->ipn_data );
		do_action( PayPal_Utils::auto_prefix('paypal_payment_ipn_processed'), $this->ipn_data );

		//Get the thank-you page URL
		$redirect_url = $wc_order->get_checkout_order_received_url();;

		//If everything is processed successfully, send the success response.
		wp_send_json_success( array(
			'message' => 'Subscription approved successfully.',
			// 'subscription_id' => isset( $this->ipn_data['subscr_id'] ) ? $this->ipn_data['subscr_id'] : '',
			'redirect_to' => $redirect_url,
		) );
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
	public function validate_subscription_checkout_txn_data( $data, &$txn_data ) {
		$subscription_id = isset( $data['subscriptionID'] ) ? sanitize_text_field( $data['subscriptionID'] ) : '';
		$orders = $subscription_id ? wc_get_orders( array( 'type' => 'shop_order', 'meta_key' => '_paypal_subscription_id', 'meta_value' => $subscription_id, 'orderby' => 'ID', 'order' => 'ASC', 'limit' => 1 ) ) : array();
		$order = $orders ? $orders[0] : false;
		$session_orders = array_map( 'absint', array( WC()->session->get( 'wcpprog_subscription_approval_order' ), WC()->session->get( 'wcpprog_subscription_checkout_order' ), WC()->session->get( 'order_awaiting_payment' ) ) );
		if ( ! $order || (int) $order->get_customer_id() !== get_current_user_id()
			|| ( ! get_current_user_id() && ! in_array( $order->get_id(), $session_orders, true ) ) ) {
			return __( 'The subscription does not belong to this checkout session.', 'woocommerce-paypal-pro-payment-gateway' );
		}
		$api = new PayPal_Request_API_Injector();
		$details = $api->get_paypal_subscription_details( $subscription_id );
		if ( ! $details || ( $details->id ?? '' ) !== $subscription_id || 'ACTIVE' !== ( $details->status ?? '' ) ) {
			PayPal_Utils::log( 'Subscription approval not confirmed by PayPal for order #' . $order->get_id(), false );
			return __( 'PayPal has not confirmed an active subscription. Please try again.', 'woocommerce-paypal-pro-payment-gateway' );
		}
		$plan_id = $order->get_meta( '_wcpprog_paypal_plan_id', true );
		if ( $plan_id && $plan_id !== ( $details->plan_id ?? '' ) ) {
			return __( 'The PayPal subscription plan does not match this order.', 'woocommerce-paypal-pro-payment-gateway' );
		}
		$trial_setting = $order->get_meta( '_wcpprog_has_trial', true );
		$has_trial = 'yes' === $trial_setting;
		// Orders created before the snapshot was introduced still have product items.
		if ( '' === $trial_setting ) {
			foreach ( $order->get_items() as $item ) {
				$product = $item->get_product();
				if ( $product instanceof \WCPPROG_Subscription_Product && $product->is_trial_enabled() ) {
					$has_trial = true;
				}
			}
		}
		foreach ( $details->billing_info->cycle_executions ?? array() as $cycle ) {
			if ( 'TRIAL' === ( $cycle->tenure_type ?? '' ) && ! $has_trial ) {
				return __( 'PayPal reports a trial that is not configured for this order.', 'woocommerce-paypal-pro-payment-gateway' );
			}
		}
		// Approval does not prove payment. The verified sale webhook validates
		// the actual amount/currency and records the transaction on the order.
		$txn_data = json_decode( wp_json_encode( $details ), true );
		PayPal_Utils::log( 'Subscription approval validated against WooCommerce order #' . $order->get_id() . '; trial: ' . ( $has_trial ? 'yes' : 'no' ), true );
		return true;
	}

}
