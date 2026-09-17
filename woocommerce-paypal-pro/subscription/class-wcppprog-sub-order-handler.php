<?php

use TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Request_API_Injector;
use TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Utils;

if ( ! defined( 'ABSPATH' ) ) {
	// Exit if accessed directly
	exit;
}

class WCPPROG_Subscription_Order_Handler {

	const ORDER_TYPE = 'wcpprog_sub_order';

	public function __construct() {
		add_action( 'init', array( $this, 'register_order_type' ) );

        add_action( 'init', array( $this, 'register_statuses' ) );
		add_filter( 'wc_order_statuses', array( $this, 'add_statuses_to_list' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render_subscription_id_in_order_details' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_cancellation_meta_box' ), 10, 2 );
		add_action( 'add_meta_boxes', array( $this, 'add_payment_history_meta_box' ), 10, 2 );
		add_action( 'add_meta_boxes', array( $this, 'remove_order_attribution_meta_box' ), 100, 2 );
		add_action( 'wp_ajax_wcpprog_cancel_subscription', array( $this, 'cancel_subscription' ) );
		add_filter( 'admin_body_class', array( $this, 'subscription_list_body_class' ) );
		add_filter( 'manage_' . self::ORDER_TYPE . '_posts_columns', array( $this, 'add_subscription_id_column' ) );
		add_action( 'manage_' . self::ORDER_TYPE . '_posts_custom_column', array( $this, 'render_subscription_id_column' ), 10, 2 );
		add_filter( 'manage_woocommerce_page_wc-orders--' . self::ORDER_TYPE . '_columns', array( $this, 'add_subscription_id_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders--' . self::ORDER_TYPE . '_custom_column', array( $this, 'render_subscription_id_column' ), 10, 2 );

	}

	public function add_subscription_id_column( $columns ) {
		unset( $columns['shipping_address'], $columns['wc_actions'], $columns['order_total'] );
		$columns['wcpprog_subscription_id'] = __( 'Subscription ID', 'woocommerce-paypal-pro-payment-gateway' );
		return $columns;
	}

	public function subscription_list_body_class( $classes ) {
		$screen = get_current_screen();
		if ( $screen && 'edit-' . self::ORDER_TYPE === $screen->id ) {
			// WooCommerce scopes its responsive order column styles to this class.
			$classes .= ' post-type-shop_order';
		} elseif ( $screen && 'woocommerce_page_wc-orders--' . self::ORDER_TYPE === $screen->id ) {
			$classes .= ' woocommerce_page_wc-orders';
		}
		return $classes;
	}

	public function render_subscription_id_column( $column, $order ) {
		if ( 'wcpprog_subscription_id' !== $column ) {
			return;
		}
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order || self::ORDER_TYPE !== $order->get_type() ) {
			return;
		}
		$paypal_id = $order->get_meta( '_paypal_subscription_id', true );

		echo $paypal_id ? esc_html( $paypal_id ) : '&mdash;';
	}

	public function register_order_type() {
		require_once WC_PP_PRO_ADDON_PATH . '/subscription/class-wcppprog-sub-order.php';

		if ( function_exists( 'wc_register_order_type' ) ) {
			wc_register_order_type(
				self::ORDER_TYPE,
				array(
					'labels'                           => array(
						'name'               => __( 'Subscriptions', 'woocommerce-paypal-pro-payment-gateway' ),
						'singular_name'      => __( 'Subscription', 'woocommerce-paypal-pro-payment-gateway' ),
						'add_new'            => __( 'Add Subscription', 'woocommerce-paypal-pro-payment-gateway' ),
						'add_new_item'       => __( 'Add New Subscription', 'woocommerce-paypal-pro-payment-gateway' ),
						'edit'               => __( 'Edit', 'woocommerce-paypal-pro-payment-gateway' ),
						'edit_item'          => __( 'Edit Subscription', 'woocommerce-paypal-pro-payment-gateway' ),
						'new_item'           => __( 'New Subscription', 'woocommerce-paypal-pro-payment-gateway' ),
						'view'               => __( 'View Subscription', 'woocommerce-paypal-pro-payment-gateway' ),
						'view_item'          => __( 'View Subscription', 'woocommerce-paypal-pro-payment-gateway' ),
						'search_items'       => __( 'Search Subscriptions', 'woocommerce-paypal-pro-payment-gateway' ),
						// 'not_found'          => WCS_Admin_Empty_List_Content_Manager::get_content(),
						'not_found_in_trash' => __( 'No Subscriptions found in trash', 'woocommerce-paypal-pro-payment-gateway' ),
						'parent'             => __( 'Parent Subscriptions', 'woocommerce-paypal-pro-payment-gateway' ),
						'menu_name'          => __( 'Subscriptions', 'woocommerce-paypal-pro-payment-gateway' ),
					),
					'description'                      => __( 'This is where subscriptions are stored.', 'woocommerce-paypal-pro-payment-gateway' ),
					'public'                           => false,
					'show_ui'                          => true,
					'capability_type'                  => 'shop_order',
					'map_meta_cap'                     => true,
					'publicly_queryable'               => false,
					'exclude_from_search'              => true,
					'show_in_menu'                     => current_user_can( 'manage_woocommerce' ) ? 'woocommerce' : true,
					'hierarchical'                     => false,
					'show_in_nav_menus'                => false,
					'rewrite'                          => false,
					'query_var'                        => false,
					'supports'                         => array( 'title', 'comments', 'custom-fields' ),
					'has_archive'                      => false,

					// wc_register_order_type() params
					'exclude_from_orders_screen'       => true,
					'add_order_meta_boxes'             => true,
					'exclude_from_order_count'         => true,
					'exclude_from_order_views'         => true,
					'exclude_from_order_webhooks'      => true,
					'exclude_from_order_reports'       => true,
					'exclude_from_order_sales_reports' => true,
					'class_name'                       => WCPPROG_WC_Subscription_Order::class,
				)
			);
		}
	}

	public function render_subscription_id_in_order_details( $order ) {
		if ( ! $order instanceof WC_Order || self::ORDER_TYPE !== $order->get_type() ) {
			return;
		}

		$paypal_id = $order->get_meta( '_paypal_subscription_id', true );
		echo '<p class="form-field form-field-wide"><label for="wcppprog-sub-id-input">' . esc_html__( 'Subscription ID:', 'woocommerce-paypal-pro-payment-gateway' ) . '</label>';
		echo $paypal_id ? '<input id="wcppprog-sub-id-input" type="text" readonly value="'.esc_html( $paypal_id ).'" />' : esc_html__( 'N/A', 'woocommerce-paypal-pro-payment-gateway' );
		echo '</p>';
	}

	private function can_cancel_subscription( $order ) {
		return $order instanceof WC_Order && self::ORDER_TYPE === $order->get_type()
			&& ! $order->has_status( array( 'wcpprog-cancelled', 'cancelled', 'wcpprog-expired' ) )
			&& ! in_array( strtoupper( $order->get_meta( '_paypal_subscription_status', true ) ), array( 'CANCELLED', 'EXPIRED' ), true )
			&& $order->get_meta( '_paypal_subscription_id', true );
	}

	public function remove_order_attribution_meta_box( $screen_id, $object ) {
		$order = $object instanceof WC_Order ? $object : ( $object instanceof WP_Post ? wc_get_order( $object->ID ) : false );
		if ( ! $order || self::ORDER_TYPE !== $order->get_type() ) {
			return;
		}

		$screen = get_current_screen();
		if ( $screen ) {
			remove_meta_box( 'woocommerce-order-source-data', $screen->id, 'side' );
		}
	}

	public function add_payment_history_meta_box( $screen_id, $object ) {
		$order = $object instanceof WC_Order ? $object : ( $object instanceof WP_Post ? wc_get_order( $object->ID ) : false );
		if ( ! $order || self::ORDER_TYPE !== $order->get_type() ) {
			return;
		}
		add_meta_box( 'wcpprog-payment-history', __( 'Received Payments', 'woocommerce-paypal-pro-payment-gateway' ), array( $this, 'render_payment_history_meta_box' ), get_current_screen()->id, 'normal', 'default' );
	}

    public function render_payment_history_meta_box( $object ) {
        $subscription = $object instanceof WC_Order ? $object : wc_get_order( $object->ID );
        if ( ! $subscription || self::ORDER_TYPE !== $subscription->get_type() ) {
            return;
        }
        $parent_id = $subscription->get_parent_order_id_ref();
        // Include both stored links and orders linked back to this subscription.
        $ids = array_merge( array( $parent_id ), $subscription->get_related_order_ids(), wc_get_orders( array(
                'type' => 'shop_order',
                'limit' => -1,
                'return' => 'ids',
                'meta_key' => '_wcpprog_subscription_order_id',
                'meta_value' => $subscription->get_id(),
        ) ) );
        $payments = array();
        foreach ( array_unique( array_filter( array_map( 'absint', $ids ) ) ) as $id ) {
            $order = wc_get_order( $id );
            if ( ! $order || 'shop_order' !== $order->get_type() || (float) $order->get_total() <= 0 ) {
                continue;
            }
            if ( ! $order->get_date_paid() && ! $order->is_paid() && ! $order->get_total_refunded() ) {
                continue;
            }
            $payments[] = $order;
        }
        if ( ! $payments ) {
            echo '<p>' . esc_html__( 'No received payments have been recorded yet.', 'woocommerce-paypal-pro-payment-gateway' ) . '</p>';
            return;
        }
        usort( $payments, static function ( $a, $b ) {
            $a_date = $a->get_date_paid() ?: $a->get_date_created();
            $b_date = $b->get_date_paid() ?: $b->get_date_created();
            return ( $b_date ? $b_date->getTimestamp() : 0 ) <=> ( $a_date ? $a_date->getTimestamp() : 0 );
        } );

        $cols = array(
                __( 'Order', 'woocommerce-paypal-pro-payment-gateway' ),
                __( 'Payment Type', 'woocommerce-paypal-pro-payment-gateway' ),
                __( 'Date', 'woocommerce-paypal-pro-payment-gateway' ),
                __( 'Status', 'woocommerce-paypal-pro-payment-gateway' ),
                __( 'Payment Method', 'woocommerce-paypal-pro-payment-gateway' ),
                __( 'Transaction ID', 'woocommerce-paypal-pro-payment-gateway' ),
                __( 'Amount', 'woocommerce-paypal-pro-payment-gateway' ),
                __( 'Refunds', 'woocommerce-paypal-pro-payment-gateway' )
        );

        echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr>';
        foreach ( $cols as $col ) {
            echo '<th scope="col">' . esc_html( $col ) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ( $payments as $payment ) {
            $date        = $payment->get_date_paid();
            $transaction = $payment->get_transaction_id() ?: $payment->get_meta( '_paypal_transaction_id', true );
            echo '<tr><td><a href="' . esc_url( $payment->get_edit_order_url() ) . '">#' . esc_html( $payment->get_order_number() ) . '</a></td>';
            echo '<td>' . esc_html( $payment->get_id() === $parent_id ? __( 'Initial Payment', 'woocommerce-paypal-pro-payment-gateway' ) : __( 'Recurring Payment', 'woocommerce-paypal-pro-payment-gateway' ) ) . '</td>';
            echo '<td>' . esc_html( $date ? wc_format_datetime( $date, wc_date_format() . ' ' . wc_time_format() ) : '—' ) . '</td>';
            echo '<td>' . esc_html( wc_get_order_status_name( $payment->get_status() ) ) . '</td><td>' . esc_html( $payment->get_payment_method_title() ) . '</td>';
            echo '<td>' . esc_html( $transaction ?: '—' ) . '</td><td>' . wp_kses_post( wc_price( $payment->get_total(), array( 'currency' => $payment->get_currency() ) ) ) . ' ' . esc_html( $payment->get_currency() ) . '</td><td>';

            $refunds = $payment->get_refunds();
            if ( ! empty($refunds) ) {
                foreach ( $refunds as $refund ) {
                    $refund_date = $refund->get_date_created();
                    $refund_date = $refund_date ? wc_format_datetime( $refund_date, wc_date_format() . ' ' . wc_time_format() ) : '—';

                    $refund_id   = $refund->get_meta( '_wcppprog_paypal_refund_id', true ) ?: $refund->get_transaction_id();
                    $refund_id   = $refund_id ?: '#' . $refund->get_id();

                    $refund_price_amount = wc_price( $refund->get_amount(), array( 'currency' => $payment->get_currency() ) );
                    /* translators: 1: Formatted refund amount, 2: Refund date, 3: Refund ID. */
                    echo wp_kses_post( sprintf(__('Amount of %1$s refunded on %2$s. Refund id: %3$s', 'woocommerce-paypal-pro-payment-gateway'), $refund_price_amount, $refund_date, $refund_id ) );
                }
            } else {
                echo '-';
            }

            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

	public function add_cancellation_meta_box( $screen_id, $object ) {
		$order = $object instanceof WC_Order ? $object : ( $object instanceof WP_Post ? wc_get_order( $object->ID ) : false );
		if ( ! $this->can_cancel_subscription( $order ) || ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			return;
		}
		add_meta_box( 'wcpprog-subscription-cancel', __( 'Cancel subscription', 'woocommerce-paypal-pro-payment-gateway' ), array( $this, 'render_cancellation_meta_box' ), get_current_screen()->id, 'side', 'default' );
	}

	public function render_cancellation_meta_box( $object ) {
		$order = $object instanceof WC_Order ? $object : wc_get_order( $object->ID );
		if ( ! $this->can_cancel_subscription( $order ) ) {
			return;
		}
		?>
		<p><?php esc_html_e( 'Cancel this subscription in PayPal to stop future payments.', 'woocommerce-paypal-pro-payment-gateway' ); ?></p>
		<button type="button" class="button" id="wcpprog-cancel-subscription"><?php esc_html_e( 'Cancel subscription', 'woocommerce-paypal-pro-payment-gateway' ); ?></button>
		<p id="wcpprog-cancel-result" role="status"></p>
		<script>
		(function () {
			const button = document.getElementById('wcpprog-cancel-subscription');
			const result = document.getElementById('wcpprog-cancel-result');
			button.addEventListener('click', async function () {
				if (!window.confirm(<?php echo wp_json_encode( __( 'Cancel this subscription in PayPal? Future payments will stop.', 'woocommerce-paypal-pro-payment-gateway' ) ); ?>)) return;
				button.disabled = true;
				result.textContent = <?php echo wp_json_encode( __( 'Cancelling subscription…', 'woocommerce-paypal-pro-payment-gateway' ) ); ?>;
				const body = new URLSearchParams({
					action: 'wcpprog_cancel_subscription',
					order_id: <?php echo absint( $order->get_id() ); ?>,
					nonce: <?php echo wp_json_encode( wp_create_nonce( 'wcpprog_cancel_subscription_' . $order->get_id() ) ); ?>
				});
				try {
					const request = await fetch(ajaxurl, { method: 'POST', body });
					if (!request.ok) throw new Error('Cancellation request failed');
					const response = await request.json();
					if (response.success) {
						window.location.reload();
					} else {
						result.textContent = response.data.message;
						button.disabled = false;
					}
				} catch (error) {
					result.textContent = <?php echo wp_json_encode( __( 'Cancellation could not be confirmed. Please reload and try again.', 'woocommerce-paypal-pro-payment-gateway' ) ); ?>;
					button.disabled = false;
				}
			});
		})();
		</script>
		<?php
	}

	public function cancel_subscription() {
		$id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		check_ajax_referer( 'wcpprog_cancel_subscription_' . $id, 'nonce' );
		if ( ! current_user_can( 'edit_shop_order', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'You cannot edit this subscription.', 'woocommerce-paypal-pro-payment-gateway' ) ), 403 );
		}
		$order = wc_get_order( $id );
		if ( ! $this->can_cancel_subscription( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'This subscription cannot be cancelled. Please reload the page.', 'woocommerce-paypal-pro-payment-gateway' ) ) );
		}
		try {
			PayPal_Utils::log( 'Admin cancellation requested for subscription order #' . $id, true );
			$api = new PayPal_Request_API_Injector();
			$paypal_id = $order->get_meta( '_paypal_subscription_id', true );
			$details = $api->get_paypal_subscription_details( $paypal_id );
			$already_cancelled = $details && isset( $details->status ) && 'CANCELLED' === $details->status;
			if ( ! $already_cancelled && ! $api->cancel_paypal_subscription( $paypal_id ) ) {
				PayPal_Utils::log( 'Admin cancellation failed for subscription order #' . $id, false );
				PayPal_Utils::log_array( $api->get_last_error_from_api_call(), false );
				wp_send_json_error( array( 'message' => __( 'PayPal could not cancel the subscription. Please check the debug log and try again.', 'woocommerce-paypal-pro-payment-gateway' ) ) );
			}
			$order->update_meta_data( '_paypal_subscription_status', 'CANCELLED' );
			$order->update_status( 'wcpprog-cancelled', __( 'Subscription cancelled in PayPal by an administrator.', 'woocommerce-paypal-pro-payment-gateway' ) );
			PayPal_Utils::log( 'Admin cancellation completed for subscription order #' . $id, true );
			wp_send_json_success();
		} catch ( Throwable $error ) {
			PayPal_Utils::log( 'Admin cancellation error for subscription order #' . $id . ': ' . $error->getMessage(), false );
			wp_send_json_error( array( 'message' => __( 'Cancellation could not be confirmed. Please reload and try again.', 'woocommerce-paypal-pro-payment-gateway' ) ) );
		}
	}

	public function register_statuses() {
		register_post_status( 'wc-wcpprog-trial', array(
			'label' => _x( 'Trialing', 'Subscription status', 'woocommerce-paypal-pro-payment-gateway' ),
			'public' => false,
			'exclude_from_search' => false,
			'show_in_admin_all_list' => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: Number of subscriptions with this status. */
			'label_count' => _n_noop( 'Trial <span class="count">(%s)</span>', 'Trial <span class="count">(%s)</span>', 'woocommerce-paypal-pro-payment-gateway' ),
		) );
		register_post_status( 'wc-wcpprog-active', array(
			'label'                     => _x( 'Active', 'Subscription status', 'woocommerce-paypal-pro-payment-gateway' ),
			'public'                    => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: Number of subscriptions with this status. */
			'label_count'               => _n_noop( 'Active <span class="count">(%s)</span>', 'Active <span class="count">(%s)</span>', 'woocommerce-paypal-pro-payment-gateway' ),
		) );

		register_post_status( 'wc-wcpprog-on-hold', array(
			'label'                     => _x( 'On hold', 'Subscription status', 'woocommerce-paypal-pro-payment-gateway' ),
			'public'                    => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: Number of subscriptions with this status. */
			'label_count'               => _n_noop( 'On hold <span class="count">(%s)</span>', 'On hold <span class="count">(%s)</span>', 'woocommerce-paypal-pro-payment-gateway' ),
		) );

		register_post_status( 'wc-wcpprog-pending-cancel', array(
			'label'                     => _x( 'Pending cancellation', 'Subscription status', 'woocommerce-paypal-pro-payment-gateway' ),
			'public'                    => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: Number of subscriptions with this status. */
			'label_count'               => _n_noop( 'Pending cancellation <span class="count">(%s)</span>', 'Pending cancellation <span class="count">(%s)</span>', 'woocommerce-paypal-pro-payment-gateway' ),
		) );

		register_post_status( 'wc-wcpprog-cancelled', array(
			'label'                     => _x( 'Cancelled', 'Subscription status', 'woocommerce-paypal-pro-payment-gateway' ),
			'public'                    => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: Number of subscriptions with this status. */
			'label_count'               => _n_noop( 'Cancelled <span class="count">(%s)</span>', 'Cancelled <span class="count">(%s)</span>', 'woocommerce-paypal-pro-payment-gateway' ),
		) );

		register_post_status( 'wc-wcpprog-expired', array(
			'label'                     => _x( 'Expired', 'Subscription status', 'woocommerce-paypal-pro-payment-gateway' ),
			'public'                    => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: Number of subscriptions with this status. */
			'label_count'               => _n_noop( 'Expired <span class="count">(%s)</span>', 'Expired <span class="count">(%s)</span>', 'woocommerce-paypal-pro-payment-gateway' ),
		) );
	}

    public function add_statuses_to_list( $statuses ) {
        $statuses['wc-wcpprog-trial']          = _x( 'Trialing', 'Subscription status', 'woocommerce-paypal-pro-payment-gateway' );
        $statuses['wc-wcpprog-active']         = _x( 'Active', 'Subscription status', 'woocommerce-paypal-pro-payment-gateway' );
        $statuses['wc-wcpprog-on-hold']        = _x( 'On hold', 'Subscription status', 'woocommerce-paypal-pro-payment-gateway' );
        $statuses['wc-wcpprog-pending-cancel'] = _x( 'Pending cancellation', 'Subscription status', 'woocommerce-paypal-pro-payment-gateway' );
        $statuses['wc-wcpprog-cancelled']      = _x( 'Cancelled', 'Subscription status', 'woocommerce-paypal-pro-payment-gateway' );
        $statuses['wc-wcpprog-expired']        = _x( 'Expired', 'Subscription status', 'woocommerce-paypal-pro-payment-gateway' );

        return $statuses;
    }
}

new WCPPROG_Subscription_Order_Handler();
