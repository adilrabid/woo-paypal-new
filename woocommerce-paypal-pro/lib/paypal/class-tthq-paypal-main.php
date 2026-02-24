<?php

namespace TTHQ\WC_PP_PRO\Lib\PayPal;

use TTHQ\WC_PP_PRO\Lib\PayPal\Onboarding\PayPal_PPCP_Onboarding_Serverside;

//Includes
include_once( 'class-tthq-paypal-config.php' );
include_once( 'class-tthq-paypal-request-api.php' );
include_once( 'class-tthq-paypal-request-api-injector.php' );
include_once( 'class-tthq-paypal-js-button-embed.php' );
include_once( 'class-tthq-paypal-subsc-billing-plan.php' );
include_once( 'class-tthq-paypal-webhook.php' );
include_once( 'class-tthq-paypal-webhook-event-handler.php' );
include_once( 'class-tthq-paypal-onapprove-ipn-handler.php' );
include_once( 'class-tthq-paypal-utils.php' );//Misc project specific utility functions.
include_once( 'class-tthq-paypal-utils-ipn-related.php' );//Misc IPN related utility functions.
include_once( 'class-tthq-paypal-cache.php' );
include_once( 'class-tthq-paypal-bearer.php' );
include_once( 'class-tthq-paypal-button-ajax-handler.php' );
include_once( 'class-tthq-paypal-button-sub-ajax-handler.php' );
include_once( 'class-tthq-paypal-acdc-related.php' );

//Onboarding related includes
include_once( 'onboarding-related/class-tthq-paypal-onboarding.php' );//PPCP Onboarding related functions.
include_once( 'onboarding-related/class-tthq-paypal-onboarding-serverside.php' );//PPCP Onboarding serverside helper.

/**
 * The Main class to handle the new PayPal library related tasks. 
 * It initializes when this file is inlcuded.
 */
class PayPal_Main {

	public static $api_base_url_production = 'https://api-m.paypal.com';	
	public static $api_base_url_sandbox = 'https://api-m.sandbox.paypal.com';
	public static $signup_url_production = 'https://www.paypal.com/bizsignup/partner/entry';	
	public static $signup_url_sandbox = 'https://www.sandbox.paypal.com/bizsignup/partner/entry';	
	public static $partner_id_production = '3FWGC6LFTMTUG';//Same as the partner's merchant id of the live account.
	public static $partner_id_sandbox = '47CBLN36AR4Q4';// Same as the merchant id of the platform app sandbox account.
	public static $partner_client_id_production = 'AWo6ovbrHzKZ3hHFJ7APISP4MDTjes-rJPrIgyFyKmbH-i8iaWQpmmaV5hyR21m-I6f_APG6n2rkZbmR'; //Platform app's client id.
	public static $partner_client_id_sandbox = 'AeO65uHbDsjjFBdx3DO6wffuH2wIHHRDNiF5jmNgXOC8o3rRKkmCJnpmuGzvURwqpyIv-CUYH9cwiuhX';

	public static $paypal_webhook_event_query_arg = '';
	
    public function __construct() {

		PayPal_PPCP_Config::get_instance();

		//Set the menu page for the API connection settings.
		self::$paypal_webhook_event_query_arg = PayPal_Utils::auto_prefix('paypal_webhook_event', '_');
		
		if ( isset( $_GET['action'] ) && $_GET['action'] == self::$paypal_webhook_event_query_arg && isset( $_GET['mode'] )) {
			//Register action (to handle webhook) only on our webhook notification URL.
			new PayPal_Webhook_Event_Handler();
		}

		//Initialize the PayPal Ajax Create and Capture Order Class so it can handle the ajax request(s) for one-time payments.
		// new PayPal_Button_Ajax_Handler();

		//Initialize the PayPal Ajax Create and Process subscription events so it can handle the ajax request(s) for subscriptions.
		// new PayPal_Button_Sub_Ajax_Handler();

		//Initialize the PayPal OnApprove IPN Handler so it can handle the 'onApprove' ajax request(s).
		// new PayPal_OnApprove_IPN_Handler();

		//Initialize the PayPal ACDC related class so it can handle the ajax request(s).
		// new PayPal_ACDC_Related();

		//Initialize the PayPal onboarding serverside class so it can handle the 'onboardedCallback' ajax request.
		new PayPal_PPCP_Onboarding_Serverside();	
		
    }

}

new PayPal_Main();