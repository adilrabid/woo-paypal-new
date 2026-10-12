<?php
/** Run: php tests/paypal-subscription-retry-regression.php */
namespace TTHQ\WC_PP_PRO\Lib\PayPal {
	class PayPal_Request_API_Injector {
		public function get_paypal_subscription_details( $id ) { return $GLOBALS['remote']; }
		public function get_paypal_order_details( $id ) { return $GLOBALS['remote']; }
		public function get_last_error_from_api_call() { return array( 'http_code' => $GLOBALS['http_code'] ); }
	}
	class PayPal_Utils {
		public static function log( ...$args ) {}
	}
}
namespace {
	class WP_Error {
		public function __construct( public $code, public $message ) {}
	}
	function __( $message, $domain ) { return $message; }
	function absint( $value ) { return abs( (int) $value ); }
	function get_current_user_id() { return 7; }
	function WC() { return $GLOBALS['wc']; }
	function wc_get_order( $id ) { return $GLOBALS['order']; }
	function wc_get_orders( $args ) { return array( $GLOBALS['order'] ); }
	function sanitize_text_field( $value ) { return $value; }
	function wp_json_encode( $value ) { return json_encode( $value ); }
	class Retry_Order {
		public $status = 'pending';
		public $paid = null;
		public $transaction = '';
		public $meta = array(
			'_wcpprog_checkout_fingerprint' => 'matching-cart',
			'_wcppprog_paypal_subscription_id' => 'I-TEST',
			'_wcpprog_has_trial' => 'no',
		);
		public function get_id() { return 42; }
		public function get_type() { return 'shop_order'; }
		public function get_payment_method() { return 'paypal_checkout'; }
		public function get_customer_id() { return 7; }
		public function get_meta( $key, $single = true ) { return $this->meta[$key] ?? ''; }
		public function has_status( $statuses ) { return in_array( $this->status, (array) $statuses, true ); }
		public function get_date_paid() { return $this->paid; }
		public function get_transaction_id() { return $this->transaction; }
	}
	function check( $condition, $message ) {
		if ( ! $condition ) { throw new \RuntimeException( $message ); }
	}
	require __DIR__ . '/../woocommerce-paypal-pro/lib/paypal/class-tthq-paypal-checkout-attempt.php';
	require __DIR__ . '/../woocommerce-paypal-pro/lib/paypal/class-tthq-paypal-button-sub-ajax-handler.php';
	use TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Checkout_Attempt as Attempt;
	use TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Button_Sub_Ajax_Handler as Handler;
	$GLOBALS['wc'] = (object) array( 'session' => new class {
		public function get( $key ) { return 42; }
	} );
	$GLOBALS['order'] = $order = new Retry_Order();
	$GLOBALS['remote'] = (object) array( 'id' => 'I-TEST', 'status' => 'APPROVAL_PENDING' );
	$GLOBALS['http_code'] = 0;
	check( Attempt::get_order( 'subscription', 'matching-cart' ) === $order, 'Pending order must be reusable.' );
	check( Attempt::get_approval_id( $order, 'subscription' ) === 'I-TEST', 'Pending approval must resume.' );
	check( Attempt::get_order( 'subscription', 'different-cart', true ) === false, 'Recovery must preserve fingerprint checks.' );
	foreach ( array( 'cancelled', 'failed', 'refunded', 'trash', 'processing', 'completed', 'on-hold' ) as $status ) {
		$order->status = $status;
		check( Attempt::get_order( 'subscription', 'matching-cart' ) === false, "$status must not be reused normally." );
		check( Attempt::get_order( 'subscription', 'matching-cart', true ) === $order, "$status must remain available to the duplicate guard." );
		$result = Attempt::get_approval_id( $order, 'subscription' );
		check( $result instanceof WP_Error && 'paypal_retry_order_not_pending' === $result->code, "$status must neither resume nor replace an open remote attempt." );
	}
	$order->status = 'pending';
	foreach ( array( 'paid', 'transaction', '_paypal_transaction_id', '_wcpprog_subscription_order_id' ) as $marker ) {
		if ( property_exists( $order, $marker ) ) { $order->$marker = 'recorded'; }
		else { $order->meta[$marker] = 'recorded'; }
		check( Attempt::get_approval_id( $order, 'subscription' ) instanceof WP_Error, "$marker must prevent approval reuse." );
		if ( property_exists( $order, $marker ) ) { $order->$marker = null; }
		else { unset( $order->meta[$marker] ); }
	}
	// A paid parent without a local subscription link must still block a new subscription.
	$order->status = 'processing';
	$order->paid = 'recorded';
	$GLOBALS['remote']->status = 'ACTIVE';
	$recovered = Attempt::get_order( 'subscription', 'matching-cart', true );
	check( $recovered === $order, 'Webhook-first paid order must be recovered.' );
	$result = Attempt::get_approval_id( $recovered, 'subscription' );
	check( $result instanceof WP_Error && 'paypal_retry_not_pending' === $result->code, 'Active subscription must block replacement before local linking.' );
	$order->meta['_wcpprog_subscription_order_id'] = 99;
	check( Attempt::get_order( 'subscription', 'matching-cart', true )->get_meta( '_wcpprog_subscription_order_id' ) === 99, 'Linked checkout must remain available for redirect.' );
	unset( $order->meta['_wcpprog_subscription_order_id'] );
	$order->paid = null;
	$order->status = 'cancelled';
	foreach ( array( 'CANCELLED', 'EXPIRED' ) as $status ) {
		$GLOBALS['remote']->status = $status;
		check( Attempt::get_approval_id( $order, 'subscription' ) === '', 'Closed remote attempts may be replaced.' );
	}
	$GLOBALS['remote'] = false;
	$GLOBALS['http_code'] = 503;
	check( Attempt::get_approval_id( $order, 'subscription' ) instanceof WP_Error, 'API failure must block replacement.' );
	$GLOBALS['http_code'] = 404;
	check( Attempt::get_approval_id( $order, 'subscription' ) === '', 'Removed remote attempts may be replaced.' );
	// Exercise the actual callback validator with a confirmed remote approval.
	$GLOBALS['remote'] = (object) array( 'id' => 'I-TEST', 'status' => 'ACTIVE' );
	$handler = (new \ReflectionClass( Handler::class ))->newInstanceWithoutConstructor();
	$txn = array();
	foreach ( array( 'cancelled', 'failed', 'refunded', 'trash' ) as $status ) {
		$order->status = $status;
		check( $handler->validate_subscription_checkout_txn_data( array( 'subscriptionID' => 'I-TEST' ), $txn ) !== true, "$status must reject delayed approval callbacks." );
	}
	foreach ( array( 'pending', 'processing', 'completed' ) as $status ) {
		$order->status = $status;
		check( $handler->validate_subscription_checkout_txn_data( array( 'subscriptionID' => 'I-TEST' ), $txn ) === true, "$status must accept confirmed approval, including webhook-first payment." );
	}
	echo "Subscription retry regression checks passed.\n";
}
