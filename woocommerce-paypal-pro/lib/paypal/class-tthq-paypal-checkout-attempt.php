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

	public static function get_order( $type, $fingerprint, $recover_subscription = false ) {
		$id = absint( WC()->session->get( 'wcpprog_checkout_attempt_' . $type ) );
		$order = $id ? wc_get_order( $id ) : false;
		if ( $order && 'subscription' === $type ) {
			$order = self::resolve_subscription_order( $order ) ?: $order;
		}
		if ( ! $order || 'shop_order' !== $order->get_type()
			|| 'paypal_checkout' !== $order->get_payment_method()
			|| (int) $order->get_customer_id() !== get_current_user_id()
			|| $fingerprint !== $order->get_meta( '_wcpprog_checkout_fingerprint', true ) ) {
			return false;
		}
		// A webhook may have completed this checkout before the browser received
		// approval. Keep its remote ID available to the retry guard, even if paid.
		// Recovery is not permission to reopen approval: get_approval_id checks that.
		if ( $recover_subscription && 'subscription' === $type && $order->get_meta( '_wcppprog_paypal_subscription_id', true ) ) {
			return $order;
		}
		if ( ! self::can_resume_approval( $order ) ) {
			return false;
		}
		return $order;
	}

	private static function can_resume_approval( $order ) {
		return $order->has_status( 'pending' ) && self::is_unpaid_unlinked( $order );
	}

	private static function is_unpaid_unlinked( $order ) {
		return ! $order->get_date_paid() && ! $order->get_transaction_id()
			&& ! $order->get_meta( '_paypal_transaction_id', true )
			&& ! $order->get_meta( '_wcpprog_subscription_order_id', true );
	}

	/** All approval and webhook lookups follow the same durable replacement chain. */
	public static function resolve_subscription_order( $order ) {
		$seen = array();
		while ( $order && $order->get_meta( '_wcpprog_replacement_order_id', true ) ) {
			if ( isset( $seen[ $order->get_id() ] ) ) { return false; }
			$seen[ $order->get_id() ] = true;
			$next = wc_get_order( absint( $order->get_meta( '_wcpprog_replacement_order_id', true ) ) );
			if ( ! $next || 'shop_order' !== $next->get_type() || 'paypal_checkout' !== $next->get_payment_method()
				|| (int) $next->get_customer_id() !== (int) $order->get_customer_id()
				|| $next->get_meta( '_wcppprog_paypal_subscription_id', true ) !== $order->get_meta( '_wcppprog_paypal_subscription_id', true )
				|| $next->get_meta( '_wcpprog_checkout_fingerprint', true ) !== $order->get_meta( '_wcpprog_checkout_fingerprint', true ) ) {
				return false;
			}
			$order = $next;
		}
		return $order;
	}

	/** Serialize browser approval/recovery with payment and status webhooks. */
	public static function with_subscription_lock( $id, $mode, $callback ) {
		$key = 'wcpprog_payment_lock_' . md5( $mode . $id );
		$token = $id ? PayPal_Lock::acquire( $key ) : false;
		if ( ! $token ) {
			return new \WP_Error( 'paypal_subscription_busy', __( 'Your subscription is being processed. Please try again shortly.', 'woocommerce-paypal-pro-payment-gateway' ) );
		}
		$locked = true;
		register_shutdown_function( static function () use ( $key, $token, &$locked ) {
			if ( $locked ) { PayPal_Lock::release( $key, $token ); }
		} );
		try { return $callback(); }
		finally { $locked = false; PayPal_Lock::release( $key, $token ); }
	}

	/** Keep the pending PayPal approval, but give it a valid local checkout. */
	public static function replace_cancelled_subscription_order( $old, $fingerprint, $mode, $create_order ) {
		$id = $old->get_meta( '_wcppprog_paypal_subscription_id', true );
		return self::with_subscription_lock( $id, $mode, static function () use ( $old, $id, $fingerprint, $create_order ) {
			try {
				$current = self::resolve_subscription_order( wc_get_order( $old->get_id() ) );
				if ( ! $current || (int) $current->get_customer_id() !== get_current_user_id()
					|| 'paypal_checkout' !== $current->get_payment_method()
					|| $fingerprint !== $current->get_meta( '_wcpprog_checkout_fingerprint', true )
					|| $id !== $current->get_meta( '_wcppprog_paypal_subscription_id', true ) ) {
					throw new \RuntimeException( 'Recovery order no longer matches checkout.' );
				}
				if ( $current->get_id() !== $old->get_id() ) { return $current; }
				$approval = self::get_approval_id( $current, 'subscription' );
				if ( ! is_wp_error( $approval ) || 'paypal_retry_replace_order' !== $approval->get_error_code() ) {
					return is_wp_error( $approval ) ? $approval : new \WP_Error( 'paypal_retry_changed', __( 'Your previous PayPal attempt has changed. Please refresh checkout and try again.', 'woocommerce-paypal-pro-payment-gateway' ) );
				}
				// A previous request may have saved a candidate before publishing
				// the replacement link. Reuse it instead of creating another order.
				$candidates = wc_get_orders( array( 'type' => 'shop_order', 'meta_key' => '_wcpprog_recovery_source_order_id',
					'meta_value' => $current->get_id(), 'orderby' => 'ID', 'order' => 'ASC', 'limit' => 1 ) );
				$new = $candidates ? $candidates[0] : $create_order( $current );
				if ( ! $new || is_wp_error( $new ) || $new->get_id() === $current->get_id()
					|| 'shop_order' !== $new->get_type() || 'paypal_checkout' !== $new->get_payment_method()
					|| (int) $new->get_customer_id() !== (int) $current->get_customer_id()
					|| ! self::can_resume_approval( $new ) ) {
					throw new \RuntimeException( 'Replacement order is not eligible for approval.' );
				}
				$new->update_meta_data( '_wcpprog_recovery_source_order_id', $current->get_id() );
				foreach ( array( '_wcppprog_paypal_subscription_id', '_wcpprog_paypal_plan_id', '_wcppprog_recurring_breakdown',
					'_wcpprog_has_trial', '_wcpprog_initial_payment_pending', '_wcpprog_checkout_fingerprint' ) as $key ) {
					$new->update_meta_data( $key, $current->get_meta( $key, true ) );
				}
				$new->save();
				// Recheck PayPal after order creation, before publishing the link.
				$approval = self::get_approval_id( $current, 'subscription' );
				if ( ! is_wp_error( $approval ) || 'paypal_retry_replace_order' !== $approval->get_error_code() ) {
					throw new \RuntimeException( 'PayPal approval changed while preparing replacement.' );
				}
				$current = wc_get_order( $current->get_id() );
				$new = wc_get_order( $new->get_id() );
				if ( ! $current || ! $current->has_status( array( 'cancelled', 'failed' ) ) || ! self::is_unpaid_unlinked( $current )
					|| ! $new || ! self::can_resume_approval( $new ) ) {
					throw new \RuntimeException( 'Original order changed during recovery.' );
				}
				$current->update_meta_data( '_wcpprog_replacement_order_id', $new->get_id() );
				$current->save();
				$current->add_order_note( sprintf( __( 'Pending PayPal approval moved to replacement order #%s. This order remains closed.', 'woocommerce-paypal-pro-payment-gateway' ), $new->get_id() ) );
				$new->add_order_note( sprintf( __( 'Resuming the existing PayPal approval from order #%s. No new PayPal subscription was created.', 'woocommerce-paypal-pro-payment-gateway' ), $current->get_id() ) );
				self::log_subscription_retry( 'replacement_order_ready', $new, false, array( 'original_order_id' => $current->get_id() ) );
				return $new;
			} catch ( \Throwable $error ) {
				PayPal_Utils::log( 'Subscription recovery: ' . $error->getMessage(), false );
				return new \WP_Error( 'paypal_recovery_failed', __( 'Unable to prepare a new order for your existing PayPal approval. Please try again shortly or contact the store.', 'woocommerce-paypal-pro-payment-gateway' ) );
			}
		} );
	}

	private static function has_subscription_activity( $details ) {
		// PayPal can identify the payer while approval is still pending.
		// Identity alone does not establish approval or payment activity.
		if ( ! empty( $details->billing_info->last_payment ) ) {
			return true;
		}
		foreach ( $details->billing_info->cycle_executions ?? array() as $cycle ) {
			if ( (int) ( $cycle->cycles_completed ?? 0 ) > 0 ) {
				return true;
			}
		}
		return false;
	}

	/** Log only state and presence flags, never customer data or API payloads. */
	private static function log_subscription_retry( $stage, $order, $details = false, $extra = array() ) {
		$completed_cycles = 0;
		foreach ( $details->billing_info->cycle_executions ?? array() as $cycle ) {
			$completed_cycles += max( 0, (int) ( $cycle->cycles_completed ?? 0 ) );
		}
		PayPal_Utils::log( 'Subscription retry: ' . $stage . ' ' . wp_json_encode( array_merge( array(
			'order_id' => $order ? $order->get_id() : 0,
			'order_status' => $order ? $order->get_status() : 'missing',
			'has_paypal_subscription_id' => $order && (bool) $order->get_meta( '_wcppprog_paypal_subscription_id', true ),
			'has_paid_date' => $order && (bool) $order->get_date_paid(),
			'has_transaction_id' => $order && (bool) $order->get_transaction_id(),
			'has_paypal_transaction_id' => $order && (bool) $order->get_meta( '_paypal_transaction_id', true ),
			'has_local_subscription_link' => $order && (bool) $order->get_meta( '_wcpprog_subscription_order_id', true ),
			'paypal_status' => $details->status ?? 'unavailable',
			'has_payer_id' => ! empty( $details->subscriber->payer_id ),
			'has_last_payment' => ! empty( $details->billing_info->last_payment ),
			'completed_cycles' => $completed_cycles,
		), $extra ) ) );
	}

	/** Return the existing approval ID only after checking its server-side status. */
	public static function get_approval_id( $order, $type ) {
		if ( 'subscription' === $type && $order->get_meta( '_wcpprog_replacement_order_id', true ) ) {
			return new \WP_Error( 'paypal_retry_replacement_unavailable', __( 'Your replacement order could not be verified. Please contact the store before trying another payment.', 'woocommerce-paypal-pro-payment-gateway' ) );
		}
		$id = $order->get_meta( 'subscription' === $type ? '_wcppprog_paypal_subscription_id' : '_paypal_order_id', true );
		if ( 'subscription' === $type ) {
			self::log_subscription_retry( 'checking_previous_attempt', $order );
		}
		if ( ! $id ) {
			return '';
		}
		$api = new PayPal_Request_API_Injector();
		$details = 'subscription' === $type ? $api->get_paypal_subscription_details( $id ) : $api->get_paypal_order_details( $id );
		if ( 'subscription' === $type ) {
			$error = $details ? array() : $api->get_last_error_from_api_call();
			self::log_subscription_retry( 'paypal_lookup_result', $order, $details, array(
				'remote_id_matches' => $details && ( $details->id ?? '' ) === $id,
				'lookup_error_http_code' => (int) ( $error['http_code'] ?? 0 ),
			) );
		}
		if ( ! $details || ( $details->id ?? '' ) !== $id ) {
			$error = $api->get_last_error_from_api_call();
			if ( ! $details && 404 === (int) ( $error['http_code'] ?? 0 )
				&& ( 'subscription' !== $type || self::is_unpaid_unlinked( $order ) ) ) {
				// An expired/removed remote resource cannot be reopened. Keep its order for history.
				return '';
			}
			return new \WP_Error( 'paypal_retry_unverified', __( 'Unable to check your previous PayPal attempt. Please try again shortly.', 'woocommerce-paypal-pro-payment-gateway' ) );
		}
		$allowed = 'subscription' === $type ? array( 'APPROVAL_PENDING' ) : array( 'CREATED', 'PAYER_ACTION_REQUIRED', 'APPROVED' );
		if ( in_array( $details->status ?? '', $allowed, true ) ) {
			if ( ! self::can_resume_approval( $order ) ) {
				if ( 'subscription' === $type && $order->has_status( array( 'cancelled', 'failed' ) )
					&& self::is_unpaid_unlinked( $order ) && ! self::has_subscription_activity( $details ) ) {
					return new \WP_Error( 'paypal_retry_replace_order', __( 'Preparing a new order for your existing PayPal approval.', 'woocommerce-paypal-pro-payment-gateway' ) );
				}
				// Do not replace this still-open remote attempt either: it could
				// still be approved in an older browser tab.
				if ( 'subscription' === $type ) {
					self::log_subscription_retry( 'blocked_before_replacement', $order, $details );
				}
				return new \WP_Error( 'paypal_retry_order_not_pending', __( 'Your previous order can no longer resume PayPal approval. Please check your orders or contact the store before trying another payment.', 'woocommerce-paypal-pro-payment-gateway' ) );
			}
			return $id;
		}
		$closed = 'subscription' === $type ? array( 'CANCELLED', 'EXPIRED' ) : array( 'VOIDED' );
		if ( in_array( $details->status ?? '', $closed, true ) ) {
			if ( 'subscription' === $type && ( ! self::is_unpaid_unlinked( $order ) || self::has_subscription_activity( $details ) ) ) {
				return new \WP_Error( 'paypal_retry_subscription_activity', __( 'Your previous PayPal subscription has approval or payment activity. Please check your orders or contact the store before trying another payment.', 'woocommerce-paypal-pro-payment-gateway' ) );
			}
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
