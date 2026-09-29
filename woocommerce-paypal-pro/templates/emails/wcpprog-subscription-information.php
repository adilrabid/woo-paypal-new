<?php
/** Subscription information, sent manually from the subscription order actions. */
defined( 'ABSPATH' ) || exit;

$details = array(
	__( 'Subscription', 'woocommerce-paypal-pro-payment-gateway' ) => '#' . $order->get_order_number(),
	__( 'Status', 'woocommerce-paypal-pro-payment-gateway' ) => wc_get_order_status_name( $order->get_status() ),
);
$paypal_id = $order->get_meta( '_paypal_subscription_id', true );
$parent = wc_get_order( $order->get_parent_order_id_ref() );
$gateway_id = $order->get_payment_method() ?: ( $parent ? $parent->get_payment_method() : '' );
$gateway_id = $gateway_id ?: ( $paypal_id ? 'paypal_checkout' : '' );
$gateways = WC()->payment_gateways()->payment_gateways();
$gateway = isset( $gateways[ $gateway_id ] ) ? $gateways[ $gateway_id ]->get_title() : '';
$gateway = $gateway ?: ( $order->get_payment_method_title() ?: ( $parent ? $parent->get_payment_method_title() : '' ) );
$details[ __( 'Payment Gateway', 'woocommerce-paypal-pro-payment-gateway' ) ] = $gateway ?: '—';
$details[ __( 'Subscription ID', 'woocommerce-paypal-pro-payment-gateway' ) ] = $paypal_id ?: '—';
$plans = array();
foreach ( $order->get_items() as $item ) {
	$plan = WCPPROG_Subscription_Related::get_subscription_plan_data( array( 'data' => $item->get_product() ) );
	if ( ! empty( $plan['subscription_plan_html'] ) ) {
		$plans[] = $plan['subscription_plan_html'];
	}
}
$interval = absint( $order->get_meta( '_billing_interval', true ) );
$period = $order->get_meta( '_billing_period', true );
$periods = array(
	/* translators: %d: Billing interval in days. */
	'day' => _n( 'Every %d day', 'Every %d days', $interval, 'woocommerce-paypal-pro-payment-gateway' ),
	/* translators: %d: Billing interval in weeks. */
	'week' => _n( 'Every %d week', 'Every %d weeks', $interval, 'woocommerce-paypal-pro-payment-gateway' ),
	/* translators: %d: Billing interval in months. */
	'month' => _n( 'Every %d month', 'Every %d months', $interval, 'woocommerce-paypal-pro-payment-gateway' ),
	/* translators: %d: Billing interval in years. */
	'year' => _n( 'Every %d year', 'Every %d years', $interval, 'woocommerce-paypal-pro-payment-gateway' ),
);
if ( $interval && isset( $periods[ $period ] ) ) {
	$details[ __( 'Billing interval', 'woocommerce-paypal-pro-payment-gateway' ) ] = sprintf( $periods[ $period ], $interval );
}
$next_payment = $order->get_meta( '_next_payment_date', true );
if ( $next_payment && $order->has_status( array( 'wcpprog-active', 'wcpprog-trial' ) ) ) {
	// Stored next-payment dates are UTC; show the date in the store's timezone.
	$details[ __( 'Next payment', 'woocommerce-paypal-pro-payment-gateway' ) ] = get_date_from_gmt( $next_payment, wc_date_format() . ' ' . wc_time_format() );
}
?>
<p><?php esc_html_e( 'Here are the details of your subscription.', 'woocommerce-paypal-pro-payment-gateway' ); ?></p>
<table cellspacing="0" cellpadding="8" border="1" style="width:100%; border-collapse:collapse;">
	<?php foreach ( $details as $label => $value ) : ?>
		<tr><th scope="row" style="text-align:left;"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( $value ); ?></td></tr>
	<?php endforeach; ?>
	<tr><th scope="row" style="text-align:left;"><?php esc_html_e( 'Subscription plan', 'woocommerce-paypal-pro-payment-gateway' ); ?></th><td><?php echo $plans ? wp_kses_post( implode( '<br>', $plans ) ) : esc_html__( 'Unavailable', 'woocommerce-paypal-pro-payment-gateway' ); ?></td></tr>
</table>
<h2><?php esc_html_e( 'Subscription items', 'woocommerce-paypal-pro-payment-gateway' ); ?></h2>
<ul>
	<?php foreach ( $order->get_items() as $item ) : ?>
		<li><?php echo esc_html( $item->get_name() . ' × ' . $item->get_quantity() ); ?></li>
	<?php endforeach; ?>
</ul>
<h2><?php esc_html_e( 'Customer information', 'woocommerce-paypal-pro-payment-gateway' ); ?></h2>
<table cellspacing="0" cellpadding="8" border="1" style="width:100%; border-collapse:collapse;">
	<tr><th scope="row" style="text-align:left;"><?php esc_html_e( 'Email', 'woocommerce-paypal-pro-payment-gateway' ); ?></th><td><?php echo esc_html( $order->get_billing_email() ); ?></td></tr>
	<tr><th scope="row" style="text-align:left;"><?php esc_html_e( 'Billing address', 'woocommerce-paypal-pro-payment-gateway' ); ?></th><td>
		<?php echo wp_kses_post( $order->get_formatted_billing_address() ?: esc_html__( 'Not provided', 'woocommerce-paypal-pro-payment-gateway' ) ); ?>
		<?php if ( $order->get_billing_phone() ) : ?><br><?php echo esc_html( $order->get_billing_phone() ); ?><?php endif; ?>
	</td></tr>
	<tr><th scope="row" style="text-align:left;"><?php esc_html_e( 'Shipping address', 'woocommerce-paypal-pro-payment-gateway' ); ?></th><td>
		<?php echo wp_kses_post( $order->get_formatted_shipping_address() ?: esc_html__( 'Not provided', 'woocommerce-paypal-pro-payment-gateway' ) ); ?>
		<?php if ( $order->get_shipping_phone() ) : ?><br><?php echo esc_html( $order->get_shipping_phone() ); ?><?php endif; ?>
	</td></tr>
</table>
<h2><?php esc_html_e( 'Received Payments', 'woocommerce-paypal-pro-payment-gateway' ); ?></h2>
<?php WCPPROG_Subscription_Order_Handler::render_payment_history_table( $order, true ); ?>
