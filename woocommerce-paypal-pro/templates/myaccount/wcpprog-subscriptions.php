<?php
/**
 * Front-end customer subscriptions list template.
 *
 * @package woocommerce-paypal-pro-payment-gateway
 *
 * @var WC_Order[] $subscriptions
 * @var WCPPROG_Subscription_Order_Handler $handler
 * @var int $page
 * @var int $pages
 */

defined( 'ABSPATH' ) || exit;

$list_url = wc_get_account_endpoint_url( 'wcppprog-subscriptions' );
$wp_button_class = function_exists( 'wc_wp_theme_get_element_class_name' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '';
?>
<?php if ( ! $subscriptions ) : ?>
    <p class="woocommerce-info"><?php echo esc_html( 1 === $page ? __( 'You have no subscriptions yet.', 'woocommerce-paypal-pro-payment-gateway' ) : __( 'No subscriptions were found on this page.', 'woocommerce-paypal-pro-payment-gateway' ) ); ?></p>
    <?php if ( $page > 1 ) : ?><p><a class="button" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Back to subscriptions', 'woocommerce-paypal-pro-payment-gateway' ); ?></a></p><?php endif; ?>
<?php else : ?>
    <?php $columns = array(
        'order' => __( 'Order', 'woocommerce-paypal-pro-payment-gateway' ),
        'date' => __( 'Date', 'woocommerce-paypal-pro-payment-gateway' ),
        'status' => __( 'Status', 'woocommerce-paypal-pro-payment-gateway' ),
        'subscription' => __( 'Subscription ID', 'woocommerce-paypal-pro-payment-gateway' ),
        'actions' => __( 'Actions', 'woocommerce-paypal-pro-payment-gateway' ),
    ); ?>
    <table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table">
        <thead><tr><?php foreach ( $columns as $label ) : ?><th scope="col"><?php echo esc_html( $label ); ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ( $subscriptions as $subscription ) : ?>
            <?php $url = $handler->get_subscription_view_url( $subscription->get_id() ); $date = $subscription->get_date_created(); ?>
            <tr class="woocommerce-orders-table__row">
                <th class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-number" data-title="<?php echo esc_attr( $columns['order'] ); ?>">
                    <a href="<?php echo esc_url( $url ); ?>">#<?php echo esc_html( $subscription->get_order_number() ); ?></a>
                </th>
                <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-date" data-title="<?php echo esc_attr( $columns['date'] ); ?>">
                    <?php if ( $date ) : ?><time datetime="<?php echo esc_attr( $date->date( 'c' ) ); ?>"><?php echo esc_html( wc_format_datetime( $date ) ); ?></time><?php else : ?>—<?php endif; ?>
                </td>
                <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-status" data-title="<?php echo esc_attr( $columns['status'] ); ?>">
                    <?php echo esc_html( WCPPROG_Subscription_Order_Handler::get_subscription_status_name( $subscription->get_status() ) ); ?>
                </td>
                <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-subscription-id" data-title="<?php echo esc_attr( $columns['subscription'] ); ?>">
                    <?php echo esc_html( $subscription->get_paypal_subscription_id() ?: '—' ); ?>
                </td>
                <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-actions" data-title="<?php echo esc_attr( $columns['actions'] ); ?>">
                    <a class="woocommerce-button wp-element-button button view" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'View', 'woocommerce-paypal-pro-payment-gateway' ); ?></a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ( $pages > 1 ) : ?>
        <div role="navigation" class="woocommerce-pagination woocommerce-pagination--without-numbers woocommerce-Pagination" aria-label="<?php esc_attr_e( 'Subscription pages', 'woocommerce-paypal-pro-payment-gateway' ); ?>">
            <?php if ( $page > 1 ) : ?><a class="woocommerce-button woocommerce-button--previous woocommerce-Button woocommerce-Button--previous button<?php echo esc_attr( $wp_button_class ); ?>" href="<?php echo esc_url( wc_get_endpoint_url( 'wcppprog-subscriptions', 'page/' . ( $page - 1 ), wc_get_page_permalink( 'myaccount' ) ) ); ?>"><?php esc_html_e( 'Previous', 'woocommerce-paypal-pro-payment-gateway' ); ?></a><?php endif; ?>
            <?php if ( $page < $pages ) : ?><a class="woocommerce-button woocommerce-button--next woocommerce-Button woocommerce-Button--next button<?php echo esc_attr( $wp_button_class ); ?>" href="<?php echo esc_url( wc_get_endpoint_url( 'wcppprog-subscriptions', 'page/' . ( $page + 1 ), wc_get_page_permalink( 'myaccount' ) ) ); ?>"><?php esc_html_e( 'Next', 'woocommerce-paypal-pro-payment-gateway' ); ?></a><?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
