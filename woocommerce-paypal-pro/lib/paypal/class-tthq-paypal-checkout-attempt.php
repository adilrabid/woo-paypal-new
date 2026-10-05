<?php

namespace TTHQ\WC_PP_PRO\Lib\PayPal;

/** Keep an interrupted PayPal approval attached to its original checkout. */
class PayPal_Checkout_Attempt {
	public static function fingerprint( $gateway, $details = array() ) {
		return hash( 'sha256', wp_json_encode( array(
			WC()->cart->get_cart_hash(),
			get_woocommerce_currency(),
			WC()->customer->get_billing(),
			WC()->customer->get_shipping(),
			WC()->session->get( 'chosen_shipping_methods', array() ),
			$gateway->get_option( 'sandbox' ),
			$gateway->get_option( 'live_client_id' ),
			$gateway->get_option( 'sandbox_client_id' ),
			$details,
		) ) );
	}

	public static function get_order( $type, $fingerprint ) {
		$id = absint( WC()->session->get( 'wcpprog_checkout_attempt_' . $type ) );
		$order = $id ? wc_get_order( $id ) : false;
		if ( ! $order || 'shop_order' !== $order->get_type()
			|| 'paypal_checkout' !== $order->get_payment_method()
			|| (int) $order->get_customer_id() !== get_current_user_id()
			|| ! $order->has_status( 'pending' ) || $order->get_date_paid() || $order->get_transaction_id()
			|| $order->get_meta( '_paypal_transaction_id', true )
			|| $order->get_meta( '_wcpprog_subscription_order_id', true )
			|| $fingerprint !== $order->get_meta( '_wcpprog_checkout_fingerprint', true ) ) {
			return false;
		}
		return $order;
	}

	/** Return the existing approval ID only after checking its server-side status. */
	public static function get_approval_id( $order, $type ) {
		$id = $order->get_meta( 'subscription' === $type ? '_wcppprog_paypal_subscription_id' : '_paypal_order_id', true );
		if ( ! $id ) {
			return '';
		}
		$api = new PayPal_Request_API_Injector();
		$details = 'subscription' === $type ? $api->get_paypal_subscription_details( $id ) : $api->get_paypal_order_details( $id );
		if ( ! $details || ( $details->id ?? '' ) !== $id ) {
			$error = $api->get_last_error_from_api_call();
			if ( ! $details && 404 === (int) ( $error['http_code'] ?? 0 ) ) {
				// An expired/removed remote resource cannot be reopened. Keep its order for history.
				return '';
			}
			return new \WP_Error( 'paypal_retry_unverified', __( 'Unable to check your previous PayPal attempt. Please try again shortly.', 'woocommerce-paypal-pro-payment-gateway' ) );
		}
		$allowed = 'subscription' === $type ? array( 'APPROVAL_PENDING' ) : array( 'CREATED', 'PAYER_ACTION_REQUIRED', 'APPROVED' );
		if ( in_array( $details->status ?? '', $allowed, true ) ) {
			return $id;
		}
		$closed = 'subscription' === $type ? array( 'CANCELLED', 'EXPIRED' ) : array( 'VOIDED' );
		if ( in_array( $details->status ?? '', $closed, true ) ) {
			return '';
		}
		// Never replace an identifier that a delayed approval or payment may still use.
		return new \WP_Error( 'paypal_retry_not_pending', __( 'Your previous PayPal attempt is no longer awaiting approval. Please refresh checkout and check your orders before trying another payment.', 'woocommerce-paypal-pro-payment-gateway' ) );
	}

	public static function remember( $order, $type, $fingerprint ) {
		$order->update_meta_data( '_wcpprog_checkout_fingerprint', $fingerprint );
		$order->save();
		WC()->session->set( 'wcpprog_checkout_attempt_' . $type, $order->get_id() );
	}

	/** Let WooCommerce rebuild only our own matching, as-yet-unlinked order. */
	public static function create_order( $data, $type, $fingerprint ) {
		$order = self::get_order( $type, $fingerprint );
		$unlinked = $order && ! $order->get_meta( '_paypal_order_id', true ) && ! $order->get_meta( '_wcppprog_paypal_subscription_id', true );
		$previous = WC()->session->get( 'order_awaiting_payment' );
		WC()->session->set( 'order_awaiting_payment', $unlinked ? $order->get_id() : 0 );
		try {
			$data['payment_method'] = 'paypal_checkout';
			return WC()->checkout()->create_order( $data );
		} finally {
			WC()->session->set( 'order_awaiting_payment', $previous );
		}
	}
}
