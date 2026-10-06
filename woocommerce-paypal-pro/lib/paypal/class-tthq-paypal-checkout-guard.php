<?php

namespace TTHQ\WC_PP_PRO\Lib\PayPal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Shared checkout eligibility and address validation. */
class PayPal_Checkout_Guard {
	public static function unsupported_subscription( $order = null ) {
		if ( $order ) {
			if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order, array(
					'parent',
					'renewal',
					'resubscribe',
					'switch'
				) ) ) {
				return true;
			}
			foreach ( $order->get_items() as $item ) {
				$product = $item->get_product();
				if ( $product && class_exists( 'WC_Subscriptions_Product' ) && \WC_Subscriptions_Product::is_subscription( $product ) ) {
					return true;
				}
			}

			return false;
		}
		if ( class_exists( 'WC_Subscriptions_Cart' ) && WC()->cart && \WC_Subscriptions_Cart::cart_contains_subscription() ) {
			return true;
		}
		foreach (
			array(
				'wcs_cart_contains_renewal',
				'wcs_cart_contains_resubscribe',
				'wcs_cart_contains_switches'
			) as $check
		) {
			if ( function_exists( $check ) && WC()->cart && $check() ) {
				return true;
			}
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			$order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );

			return $order ? self::unsupported_subscription( $order ) : false;
		}

		return false;
	}

	public static function read_customer() {
		$posted = isset( $_POST['checkout_customer'] ) && is_string( $_POST['checkout_customer'] ) ? json_decode( wp_unslash( $_POST['checkout_customer'] ), true ) : array();
		$data   = array();
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			foreach (
				array(
					'first_name',
					'last_name',
					'company',
					'address_1',
					'address_2',
					'city',
					'state',
					'postcode',
					'country',
					'email',
					'phone'
				) as $field
			) {
				$key    = $type . '_' . $field;
				$getter = 'get_' . $key;
				if ( ! is_callable( array( WC()->customer, $getter ) ) ) {
					continue;
				}
				$value        = isset( $posted[ $type ] ) && is_array( $posted[ $type ] ) && array_key_exists( $field, $posted[ $type ] ) ? $posted[ $type ][ $field ] : WC()->customer->$getter();
				$data[ $key ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
			}
		}

		return $data;
	}

	public static function validate_addresses( $data, $needs_shipping ) {
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			if ( 'shipping' === $type && ! $needs_shipping ) {
				continue;
			}
			$country = $data[ $type . '_country' ] ?? '';
			$allowed = 'shipping' === $type ? WC()->countries->get_shipping_countries() : WC()->countries->get_allowed_countries();
			if ( ! isset( $allowed[ $country ] ) ) {
				throw new \InvalidArgumentException( __( 'Please select an allowed billing and shipping country.', 'woocommerce-paypal-pro-payment-gateway' ) );
			}
			foreach ( WC()->countries->get_address_fields( $country, $type . '_' ) as $key => $field ) {
				$value = $data[ $key ] ?? '';
				if ( ! empty( $field['required'] ) && '' === trim( $value ) ) {
					/* translators: %s: Checkout field label. */
					throw new \InvalidArgumentException( sprintf( __( '%s is required before paying.', 'woocommerce-paypal-pro-payment-gateway' ), wp_strip_all_tags( $field['label'] ?? $key ) ) );
				}
				if ( '' === $value ) {
					continue;
				}
				$validation = $field['validate'] ?? array();
				if ( in_array( 'postcode', $validation, true ) && ! \WC_Validation::is_postcode( $value, $country )
				     || in_array( 'phone', $validation, true ) && ! \WC_Validation::is_phone( $value )
				     || in_array( 'email', $validation, true ) && ! is_email( $value ) ) {
					throw new \InvalidArgumentException( __( 'Please enter valid checkout contact and address details.', 'woocommerce-paypal-pro-payment-gateway' ) );
				}
			}
			$states = WC()->countries->get_states( $country );
			$state  = $data[ $type . '_state' ] ?? '';
			if ( $state && is_array( $states ) && $states && ! isset( $states[ $state ] ) ) {
				throw new \InvalidArgumentException( __( 'Please select a valid state for your country.', 'woocommerce-paypal-pro-payment-gateway' ) );
			}
		}

	}

	public static function validate_shipping( $cart ) {
		if ( ! $cart->needs_shipping() ) {
			return;
		}
		$rates    = $cart->calculate_shipping();
		$packages = WC()->shipping()->get_packages();
		$chosen   = (array) WC()->session->get( 'chosen_shipping_methods', array() );
		if ( ! $rates || ! $packages ) {
			throw new \InvalidArgumentException( __( 'No shipping options are available for this address.', 'woocommerce-paypal-pro-payment-gateway' ) );
		}
		foreach ( $packages as $key => $package ) {
			if ( empty( $chosen[ $key ] ) || ! isset( $package['rates'][ $chosen[ $key ] ] ) ) {
				throw new \InvalidArgumentException( __( 'Please select an available shipping method for your address.', 'woocommerce-paypal-pro-payment-gateway' ) );
			}
		}
	}
}
