<?php

if ( class_exists( 'WCPPROG_Subscription_Product' ) ) {
	return;
}

// Also Make sure WC_Product_Simple is loaded first
if ( ! class_exists( 'WC_Product' ) ) {
	return;
}

// Also Make sure WC_Product_Simple is loaded first
if ( ! class_exists( 'WC_Product_Simple' ) ) {
	return;
}

class WCPPROG_Subscription_Product extends WC_Product {

	protected $extra_data = WCPPROG_Subscription_Related::SUBSCRIPTION_PRODUCT_FIELDS;

	public function is_sold_individually() {
		return true;
	}

	/**
	 * Get internal type.
	 *
	 * @return string
	 */
	public function get_type() {
		return WCPPROG_Subscription_Related::SUBSCRIPTION_PRODUCT_TYPE;
	}


	// one get_/set_ pair per key — get_prop()/set_prop() from WC_Data
	public function get_subscription_recurring_price( $context = 'view' ) {
		return $this->get_prop( 'subscription_recurring_price', $context );
	}

	public function set_subscription_recurring_price( $value ) {
		$this->set_prop( 'subscription_recurring_price', wc_format_decimal( $value ) );
	}

	public function get_subscription_recurring_sale_price( $context = 'view' ) {
		return $this->get_prop( 'subscription_recurring_sale_price', $context );
	}

	public function set_subscription_recurring_sale_price( $value ) {
		$this->set_prop( 'subscription_recurring_sale_price', wc_format_decimal( $value ) );
	}

	public function get_subscription_recurring_billing_interval( $context = 'view' ) {
		return (int) $this->get_prop( 'subscription_recurring_billing_interval', $context );
	}

	public function set_subscription_recurring_billing_interval( $value ) {
		$this->set_prop( 'subscription_recurring_billing_interval', intval( wc_clean( $value ) ) );
	}

	public function get_subscription_recurring_billing_interval_type( $context = 'view' ) {
		return $this->get_prop( 'subscription_recurring_billing_interval_type', $context );
	}

	public function set_subscription_recurring_billing_interval_type( $value ) {
		$this->set_prop( 'subscription_recurring_billing_interval_type', wc_clean( $value ) );
	}

	public function get_subscription_reattempt_on_failure( $context = 'view' ) {
		return $this->get_prop( 'subscription_reattempt_on_failure', $context );
	}

	public function set_subscription_reattempt_on_failure( $value ) {
		$this->set_prop( 'subscription_reattempt_on_failure', wc_clean( $value ) );
	}

	public function get_subscription_recurring_billing_count( $context = 'view' ) {
		return $this->get_prop( 'subscription_recurring_billing_count', $context );
	}

	public function set_subscription_recurring_billing_count( $value ) {
		$this->set_prop( 'subscription_recurring_billing_count', intval( wc_clean( $value ) ) );
	}

	public function get_subscription_trial_period( $context = 'view' ) {
		return $this->get_prop( 'subscription_trial_period', $context );
	}

	public function set_subscription_trial_period( $value ) {
		$this->set_prop( 'subscription_trial_period', intval( wc_clean( $value ) ) );
	}

	public function get_subscription_trial_period_type( $context = 'view' ) {
		return $this->get_prop( 'subscription_trial_period_type', $context );
	}

	public function set_subscription_trial_period_type( $value ) {
		$this->set_prop( 'subscription_trial_period_type', wc_clean( $value ) );
	}

	public function get_subscription_trial_price( $context = 'view' ) {
		return $this->get_prop( 'subscription_trial_price', $context );
	}

	public function set_subscription_trial_price( $value ) {
		$this->set_prop( 'subscription_trial_price', wc_format_decimal( $value ) );
	}

	/**
	 * The amount actually due today — what cart/checkout math and PayPal charge should use.
	 */
	public function get_due_today_amount() {
		if ( $this->is_trial_enabled() ) {
			return (float) $this->get_subscription_trial_price();
		}

		// No trial — due today is the normal recurring price (respecting sale price).
		return $this->is_on_sale() ? (float) $this->get_sale_price() : (float) $this->get_regular_price();
	}


	public function get_price_html( $deprecated = '' ) {
		if ( '' === $this->get_price() ) {
			$price = apply_filters( 'woocommerce_empty_price_html', '', $this );
		} elseif ( $this->is_on_sale() ) {
			$price = wc_format_sale_price(
				wc_get_price_to_display( $this, array( 'price' => $this->get_regular_price() ) ),
				wc_get_price_to_display( $this, array( 'price' => $this->get_sale_price() ) ),
			);
			$price .= $this->get_price_suffix();
		} else {
			$price = wc_price( wc_get_price_to_display( $this, array( 'price' => $this->get_regular_price() ) ) ) . $this->get_price_suffix();
		}

		if ( '' !== $this->get_price() ) {
			$price = $this->get_trial_prefix() . $price . $this->get_recurring_suffix();
		}

		return apply_filters( 'woocommerce_get_price_html', $price, $this );
	}

	/**
	 * Builds "Free for 14 days, then " / "$4.99 for 14 days, then " prefix, if a trial is configured.
	 */
	protected function get_trial_prefix() {
		$trial_length = (int) $this->get_subscription_trial_period();

		if ( $trial_length <= 0 ) {
			return '';
		}

		$trial_period_type = $this->get_subscription_trial_period_type();
		$trial_price       = $this->get_subscription_trial_price();
		$trial_price       = ( '' === $trial_price ) ? 0 : (float) $trial_price;

		$period_label = $this->get_period_label( $trial_period_type, $trial_length );

		if ( $trial_price <= 0 ) {
			// Free trial
			$prefix = sprintf(
			/* translators: %1$d: trial length, %2$s: period label (e.g. "days") */
				esc_html__( 'Free for %1$d %2$s, then ', 'woocommerce-paypal-pro-payment-gateway' ),
				$trial_length,
				$period_label
			);
		} else {
			// Paid/discounted trial
			$prefix = sprintf(
			/* translators: %1$s: trial price, %2$d: trial length, %3$s: period label */
				esc_html__( '%1$s for %2$d %3$s, then ', 'woocommerce-paypal-pro-payment-gateway' ),
				wc_price( wc_get_price_to_display( $this, array( 'price' => $trial_price ) ) ),
				$trial_length,
				$period_label
			);
		}

		return '<span class="subscription-trial-details">' . $prefix . '</span>';
	}

	/**
	 * Builds " / month" / " every 3 months" recurring suffix.
	 */
	protected function get_recurring_suffix() {
		$interval = $this->get_subscription_recurring_billing_interval();
		$period   = $this->get_subscription_recurring_billing_interval_type();

		if ( ! $period ) {
			return '';
		}

		$period_label = $this->get_period_label( $period, $interval );

		if ( $interval > 1 ) {
			$suffix = sprintf(
			/* translators: %1$d: interval, %2$s: period label */
				esc_html__( ' every %1$d %2$s', 'woocommerce-paypal-pro-payment-gateway' ),
				$interval,
				$period_label
			);
		} else {
			$suffix = sprintf(
			/* translators: %s: period label */
				esc_html__( ' / %s', 'woocommerce-paypal-pro-payment-gateway' ),
				$period_label
			);
		}

		return '<span class="subscription-details">' . $suffix . '</span>';
	}

	/**
	 * Get the add to url used mainly in loops.
	 *
	 * @return string
	 */
	public function add_to_cart_url() {
		$url = $this->is_purchasable() && $this->is_in_stock() ? remove_query_arg(
			'added-to-cart',
			add_query_arg(
				array(
					'add-to-cart' => $this->get_id(),
				),
				( function_exists( 'is_feed' ) && is_feed() ) || ( function_exists( 'is_404' ) && is_404() ) ? $this->get_permalink() : wc_get_checkout_url()
			)
		) : $this->get_permalink();

		return apply_filters( 'woocommerce_product_add_to_cart_url', $url, $this );
	}

	public function add_to_cart_text() {
		$text = $this->is_purchasable() && $this->is_in_stock() ? $this->get_subscription_add_to_cart_text() : __( 'Read more', 'woocommerce-paypal-pro-payment-gateway' );

		return apply_filters( 'woocommerce_product_add_to_cart_text', $text, $this );
	}

	public function single_add_to_cart_text() {
		$text = $this->get_subscription_add_to_cart_text();

		return apply_filters( 'woocommerce_product_single_add_to_cart_text', $text, $this );
	}

	public function get_subscription_add_to_cart_text() {
		return __( 'Subscribe', 'woocommerce-paypal-pro-payment-gateway' );
	}

	public function is_trial_enabled() {
		$trial_period = (int) $this->get_subscription_trial_period();
		if ( $trial_period > 0 ) {
			return true;
		}

		return false;
	}

	/**
	 * Shared singular/plural period label helper.
	 */
	protected function get_period_label( $period_type, $count ) {
		$labels = array(
			'day'   => _n( 'day', 'days', $count, 'woocommerce-paypal-pro-payment-gateway' ),
			'week'  => _n( 'week', 'weeks', $count, 'woocommerce-paypal-pro-payment-gateway' ),
			'month' => _n( 'month', 'months', $count, 'woocommerce-paypal-pro-payment-gateway' ),
			'year'  => _n( 'year', 'years', $count, 'woocommerce-paypal-pro-payment-gateway' ),
		);

		return $labels[ $period_type ] ?? $period_type;
	}
}