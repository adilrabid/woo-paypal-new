<?php
/**
 * The subscription details template, for both front-end subscription details view and subscription info email body.
 *
 * @package woocommerce-paypal-pro-payment-gateway
 *
 * @var WCPPROG_WC_Subscription_Order $order
 */

defined( 'ABSPATH' ) || exit;

//$details    = array(
//    __( 'Subscription', 'woocommerce-paypal-pro-payment-gateway' ) => '#' . $order->get_order_number(),
//    __( 'Status', 'woocommerce-paypal-pro-payment-gateway' )       => wc_get_order_status_name( $order->get_status() ),
//);

$paypal_id  = $order->get_meta( '_paypal_subscription_id', true );
$parent     = wc_get_order( $order->get_parent_order_id_ref() );
$gateway_id = $order->get_payment_method() ?: ( $parent ? $parent->get_payment_method() : '' );
$gateway_id = $gateway_id ?: ( $paypal_id ? 'paypal_checkout' : '' );
$gateways   = WC()->payment_gateways()->payment_gateways();
$gateway    = isset( $gateways[ $gateway_id ] ) ? $gateways[ $gateway_id ]->get_title() : '';
$gateway    = $gateway ?: ( $order->get_payment_method_title() ?: ( $parent ? $parent->get_payment_method_title() : '' ) );

$order_items = $order->get_items();
$subscription_item = isset($order_items[0]) ? $order_items[0] : array();
foreach ( $order->get_items() as $item ){
    $details[ __( 'Product', 'woocommerce-paypal-pro-payment-gateway' ) ] = $item->get_name();
}

$plans = array();
foreach ( $order->get_items() as $item ) {
    $plan = WCPPROG_Subscription_Related::get_subscription_plan_data( array( 'data' => $item->get_product() ) );
    if ( ! empty( $plan['subscription_plan_html'] ) ) {
        $plans[] = $plan['subscription_plan_html'];
    }
}
$details[ __( 'Subscription plan', 'woocommerce-paypal-pro-payment-gateway' ) ] = $plans ? implode( '<br>', $plans ) : esc_html__( 'Unavailable', 'woocommerce-paypal-pro-payment-gateway' );;

$details[ __( 'Payment Method', 'woocommerce-paypal-pro-payment-gateway' ) ] = $gateway ?: '—';
$details[ __( 'Subscription ID', 'woocommerce-paypal-pro-payment-gateway' ) ] = $paypal_id ?: '—';

$interval = absint( $order->get_meta( '_billing_interval', true ) );
$period   = $order->get_meta( '_billing_period', true );

$periods  = array(
    /* translators: %d: Billing interval in days. */
    'day'   => _n( 'Every %d day', 'Every %d days', $interval, 'woocommerce-paypal-pro-payment-gateway' ),
    /* translators: %d: Billing interval in weeks. */
    'week'  => _n( 'Every %d week', 'Every %d weeks', $interval, 'woocommerce-paypal-pro-payment-gateway' ),
    /* translators: %d: Billing interval in months. */
    'month' => _n( 'Every %d month', 'Every %d months', $interval, 'woocommerce-paypal-pro-payment-gateway' ),
    /* translators: %d: Billing interval in years. */
    'year'  => _n( 'Every %d year', 'Every %d years', $interval, 'woocommerce-paypal-pro-payment-gateway' ),
);

if ( $interval && isset( $periods[ $period ] ) ) {
    $details[ __( 'Billing interval', 'woocommerce-paypal-pro-payment-gateway' ) ] = sprintf( $periods[ $period ], $interval );
}

$next_payment = $order->get_next_payment_date();
if ( $next_payment && $order->has_status( array( 'wcpprog-active', 'wcpprog-trial' ) ) ) {
    // Stored next-payment dates are UTC; show the date in the store's timezone.
    $details[ __( 'Next payment', 'woocommerce-paypal-pro-payment-gateway' ) ] = get_date_from_gmt( $next_payment, wc_date_format() . ' ' . wc_time_format() );
}
?>

<p>
    <?php echo sprintf(
    "Subscription #%s was created on %s and is currently %s.",
    '<mark class="order-number">'.esc_html($order->get_order_number()).'</mark>',
    '<mark class="order-date"><time datetime="'.esc_attr( $order->get_date_created()->date( 'c' )).'">'. esc_html(wc_format_datetime( $order->get_date_created() )) .'</time></mark>',
    '<mark class="order-status">'.esc_html(wc_get_order_status_name($order->get_status())).'</mark>'
    ); ?>
</p>

<h2 class="woocommerce-order-details__title"><?php esc_html_e('Subscription Details', 'woocommerce-paypal-pro-payment-gateway'); ?></h2>

<table cellspacing="0" cellpadding="8" border="1" style="width:100%; border-collapse:collapse;" class="woocommerce-table woocommerce-table--order-details shop_table order_details">
    <?php foreach ( $details as $label => $value ) { ?>
        <tr>
            <th scope="row"><?php echo esc_html( $label ); ?></th>
            <td><?php echo wp_kses_post( $value ); ?></td>
        </tr>
    <?php } ?>
</table>

<section class="woocommerce-customer-details">
    <section class="woocommerce-columns woocommerce-columns--2 woocommerce-columns--addresses col2-set addresses">
        <div class="woocommerce-column woocommerce-column--1 woocommerce-column--billing-address col-1">
            <h2 class="woocommerce-column__title"><?php esc_html_e( 'Billing address', 'woocommerce-paypal-pro-payment-gateway' ); ?></h2>
            <address>
                <?php echo wp_kses_post( $order->get_formatted_billing_address() ?: esc_html__( 'N/A', 'woocommerce-paypal-pro-payment-gateway' ) ); ?>
                <?php if ( $order->get_billing_phone() ) { ?>
                <p class="woocommerce-customer-details--phone">
                    <?php echo esc_html( $order->get_billing_phone() ); ?>
                </p>
                <?php } ?>
                <p class="woocommerce-customer-details--email">
                    <?php echo esc_html( $order->get_billing_email() ); ?>
                </p>
            </address>
        </div>

        <?php if ($order->get_formatted_shipping_address()) { ?>
        <div class="woocommerce-column woocommerce-column--2 woocommerce-column--shipping-address col-2">
            <h2 class="woocommerce-column__title"><?php esc_html_e( 'Shipping address', 'woocommerce-paypal-pro-payment-gateway' ); ?></h2>
            <address>
                <?php echo wp_kses_post( $order->get_formatted_shipping_address() ?: esc_html__( 'N/A', 'woocommerce-paypal-pro-payment-gateway' ) ); ?>
                <?php if ( $order->get_shipping_phone() ) { ?>
                <p class="woocommerce-customer-details--phone">
                    <?php echo esc_html( $order->get_shipping_phone() ); ?>
                </p>
                <?php } ?>
            </address>
        </div>
        <?php } ?>
    </section>
</section>

<h2 class="woocommerce-column__title"><?php esc_html_e( 'Received Payments', 'woocommerce-paypal-pro-payment-gateway' ); ?></h2>
<?php WCPPROG_Subscription_Order_Handler::render_payment_history_table( $order, true, ! empty( $customer_account ) ); ?>
