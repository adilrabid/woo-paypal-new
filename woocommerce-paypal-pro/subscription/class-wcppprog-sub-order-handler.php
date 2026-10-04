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
		require_once WC_PP_PRO_ADDON_PATH . '/subscription/class-wcppprog-sub-payment-history.php';
		WCPPROG_Subscription_Payment_History::init();

        add_action( 'init', array( $this, 'init_time_tasks' ) );
        add_action( 'wp_loaded', array( $this, 'maybe_refresh_subscription_rewrite_rules' ), 20 );
		add_filter( 'wc_order_statuses', array( $this, 'add_statuses_to_list' ), PHP_INT_MAX );
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
		add_filter( 'woocommerce_order_actions', array( $this, 'subscription_order_actions' ), 20, 2 );
		add_action( 'woocommerce_order_action_wcpprog_send_subscription_information', array( $this, 'send_subscription_information' ) );

        add_filter( 'woocommerce_get_query_vars', array( $this, 'subscriptions_wc_query_vars' ) );
        add_filter( 'woocommerce_endpoint_wcppprog-subscriptions_title', array( $this, 'subscription_endpoint_title' ) );
        add_filter( 'woocommerce_account_menu_items', array($this, 'add_subscriptions_menu_item') );
        add_action( 'woocommerce_account_wcppprog-subscriptions_endpoint', array($this, 'render_subscriptions_endpoint_content') );
        add_action( 'woocommerce_order_details_after_customer_details', array( $this, 'render_customer_order_subscription_link' ) );
	}

    public function init_time_tasks() {
        $this->register_order_type();

        $this->register_statuses();

        // Adds a endpoint publicly accessible for subscription pages for showing subscription orders in front-end customer account dashboard.
        add_rewrite_endpoint( 'wcppprog-subscriptions', EP_ROOT | EP_PAGES );
    }

    /** Refresh cached routes only when our endpoint is missing, including after upgrades. */
    public function maybe_refresh_subscription_rewrite_rules() {
        // Plain permalinks use query arguments and need no endpoint rewrite rules.
        if ( ! get_option( 'permalink_structure' ) ) {
            return;
        }
        foreach ( (array) get_option( 'rewrite_rules', array() ) as $query ) {
            if ( is_string( $query ) && false !== strpos( $query, '&wcppprog-subscriptions=' ) ) {
                return;
            }
        }
        // Run after endpoint registration and refresh the database rules only.
        flush_rewrite_rules( false );
    }

	public function subscription_order_actions( $actions, $order = null ) {
		if ( ! $order instanceof WC_Order || self::ORDER_TYPE !== $order->get_type() ) {
			return $actions;
		}
		return array(
			'wcpprog_send_subscription_information' => __( 'Send subscription information to customer', 'woocommerce-paypal-pro-payment-gateway' ),
		);
	}

	/** Invoked by WooCommerce's nonce-protected order actions form. */
	public function send_subscription_information( $order ) {
		if ( ! $order instanceof WC_Order || self::ORDER_TYPE !== $order->get_type()
			|| ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			return;
		}
		$recipient = $order->get_billing_email();
		if ( ! is_email( $recipient ) ) {
			$this->subscription_email_error( $order, __( 'Subscription information was not sent: the customer billing email is missing or invalid.', 'woocommerce-paypal-pro-payment-gateway' ) );
			return;
		}

		try {
			$mailer = WC()->mailer();
			$message = wc_get_template_html(
				'emails/wcpprog-subscription-information.php',
				array( 'order' => $order ),
				'',
				WC_PP_PRO_ADDON_PATH . '/templates/'
			);
			/* translators: 1: Store name, 2: Subscription order number. */
			$subject = sprintf( __( '[%1$s] Subscription #%2$s information', 'woocommerce-paypal-pro-payment-gateway' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $order->get_order_number() );
			$sent = $mailer->send( $recipient, $subject, $mailer->wrap_message( __( 'Your subscription information', 'woocommerce-paypal-pro-payment-gateway' ), $message ) );
			if ( ! $sent ) {
				$this->subscription_email_error( $order, __( 'Subscription information could not be sent. Please check your email configuration and try again.', 'woocommerce-paypal-pro-payment-gateway' ) );
				return;
			}
			/* translators: %s: Customer email address. */
			$order->add_order_note( sprintf( __( 'Subscription information email sent to %s.', 'woocommerce-paypal-pro-payment-gateway' ), $recipient ), false, true );
		} catch ( Throwable $error ) {
			PayPal_Utils::log( 'Subscription information email failed for order #' . $order->get_id() . ': ' . $error->getMessage(), false );
			$this->subscription_email_error( $order, __( 'Subscription information could not be sent. Please check your email configuration and try again.', 'woocommerce-paypal-pro-payment-gateway' ) );
		}
	}

	private function subscription_email_error( $order, $message ) {
		$order->add_order_note( $message, false, true );
		if ( class_exists( 'WC_Admin_Meta_Boxes' ) ) {
			WC_Admin_Meta_Boxes::add_error( $message );
		}
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

		$paypal_id = $order->get_paypal_subscription_id();
        if ( $paypal_id ) {
            echo '<p class="form-field form-field-wide"><label for="wcppprog-sub-id-input">' . esc_html__( 'Subscription ID:', 'woocommerce-paypal-pro-payment-gateway' ) . '</label>';
            echo $paypal_id ? '<input id="wcppprog-sub-id-input" type="text" readonly value="'.esc_html( $paypal_id ).'" />' : esc_html__( 'N/A', 'woocommerce-paypal-pro-payment-gateway' );
            echo '</p>';
        }

		$next_payment = $order->get_next_payment_date();
		if ( $next_payment && $order->has_status( array( 'wcpprog-active', 'wcpprog-trial' ) ) ) {
			// datetime-local requires an ISO-style value in the store's timezone.
			$next_payment_display = get_date_from_gmt( $next_payment, 'Y-m-d\TH:i' );
            echo '<p class="form-field form-field-wide"><label for="wcppprog-next-payment-input">' . esc_html__( 'Next payment date:', 'woocommerce-paypal-pro-payment-gateway' ) . '</label>';
            echo '<input id="wcppprog-next-payment-input" type="datetime-local" value="' . esc_attr( $next_payment_display ) . '" readonly />';
            echo '</p>';
		}
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
		add_meta_box( 'wcpprog-payment-history', __( 'Received Payments', 'woocommerce-paypal-pro-payment-gateway' ), array( $this, 'render_payment_history_meta_box' ), get_current_screen()->id, 'normal', 'high' );
	}

    public function render_payment_history_meta_box( $object ) {
        $subscription = $object instanceof WC_Order ? $object : wc_get_order( $object->ID );
        self::render_payment_history_table( $subscription );
    }

    /** Shared payment history for the admin metabox and customer email. */
    public static function render_payment_history_table( $subscription, $email = false, $customer_account = false ) {
        if ( ! $subscription || self::ORDER_TYPE !== $subscription->get_type() ) {
            return;
        }
        $payments = WCPPROG_Subscription_Payment_History::get_rows( $subscription );
        if ( ! $payments ) {
            echo '<p>' . esc_html__( 'No received payments have been recorded yet.', 'woocommerce-paypal-pro-payment-gateway' ) . '</p>';
            return;
        }

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

        echo $customer_account ? '<div style="overflow-x:auto"><table class="shop_table shop_table_responsive"><thead><tr>' : ( $email
            ? '<div style="overflow-x:auto"><table cellspacing="0" cellpadding="8" border="1" style="width:100%; border-collapse:collapse;"><thead><tr>'
            : '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr>' );
        foreach ( $cols as $col ) {
            echo '<th scope="col">' . esc_html( $col ) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ( $payments as $payment ) {
            echo '<tr><td>';
            if ( $customer_account ) {
                $linked_order = $payment['edit_url'] ? wc_get_order( $payment['order_id'] ) : false;
                $url = $linked_order && get_current_user_id() > 0 && (int) $linked_order->get_customer_id() === get_current_user_id() ? $linked_order->get_view_order_url() : '';
                echo $url ? '<a href="' . esc_url( $url ) . '">#' . esc_html( $payment['order_number'] ) . '</a>' : '#' . esc_html( $payment['order_number'] );
            } elseif ( $email || ! $payment['edit_url'] ) {
                echo '#' . esc_html( $payment['order_number'] );
            } else {
                echo '<a href="' . esc_url( $payment['edit_url'] ) . '">#' . esc_html( $payment['order_number'] ) . '</a>';
            }
            echo '</td>';
            echo '<td>' . esc_html( $payment['initial'] ? __( 'Initial Payment', 'woocommerce-paypal-pro-payment-gateway' ) : __( 'Recurring Payment', 'woocommerce-paypal-pro-payment-gateway' ) ) . '</td>';
            echo '<td>' . esc_html( WCPPROG_Subscription_Payment_History::format_date( $payment['date'] ) ) . '</td>';
            echo '<td>' . esc_html( wc_get_order_status_name( $payment['status'] ) ) . '</td><td>' . esc_html( $payment['payment_method'] ) . '</td>';
            echo '<td>' . esc_html( $payment['transaction_id'] ?: '—' ) . '</td><td>' . wp_kses_post( wc_price( $payment['amount'], array( 'currency' => $payment['currency'] ) ) ) . ' ' . esc_html( $payment['currency'] ) . '</td><td>';

            if ( $payment['refunds'] ) {
                foreach ( $payment['refunds'] as $refund ) {
                    /* translators: 1: Formatted refund amount, 2: Refund date, 3: Refund ID. */
                    echo '<div>' . wp_kses_post( sprintf( __( 'Amount of %1$s refunded on %2$s. Refund id: %3$s', 'woocommerce-paypal-pro-payment-gateway' ),
                        wc_price( $refund['amount'], array( 'currency' => $payment['currency'] ) ),
                        esc_html( WCPPROG_Subscription_Payment_History::format_date( $refund['date'] ) ),
                        esc_html( $refund['id'] )
                    ) ) . '</div>';
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
		add_meta_box( 'wcpprog-subscription-manage', __( 'Manage subscription', 'woocommerce-paypal-pro-payment-gateway' ), array( $this, 'render_subscription_manage_meta_box' ), get_current_screen()->id, 'side', 'default' );
	}

	public function render_subscription_manage_meta_box( $object ) {
		$order = $object instanceof WC_Order ? $object : wc_get_order( $object->ID );
		if ( ! $this->can_cancel_subscription( $order ) || ! get_current_user_id()
			|| ( ! current_user_can( 'edit_shop_order', $order->get_id() ) && ! $this->customer_owns_subscription( $order ) ) ) {
			return;
		}
		?>
		<p><?php esc_html_e( 'Cancel this subscription in PayPal to stop future payments.', 'woocommerce-paypal-pro-payment-gateway' ); ?></p>
		<button type="button" class="woocommerce-button wp-element-button button" id="wcpprog-cancel-subscription"><?php esc_html_e( 'Cancel subscription', 'woocommerce-paypal-pro-payment-gateway' ); ?></button>
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
					const request = await fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', body });
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
		if ( ! get_current_user_id() ) {
			wp_send_json_error( array( 'message' => __( 'Please log in to manage your subscription.', 'woocommerce-paypal-pro-payment-gateway' ) ), 403 );
		}
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid cancellation request.', 'woocommerce-paypal-pro-payment-gateway' ) ), 405 );
		}
		$posted_id = $_POST['order_id'] ?? '';
		if ( ! is_scalar( $posted_id ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $posted_id )
			|| ! isset( $_POST['nonce'] ) || ! is_string( $_POST['nonce'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid cancellation request.', 'woocommerce-paypal-pro-payment-gateway' ) ), 400 );
		}
		$id = absint( $posted_id );
		check_ajax_referer( 'wcpprog_cancel_subscription_' . $id, 'nonce' );
		$order = wc_get_order( $id );
		if ( ! current_user_can( 'edit_shop_order', $id ) && ! $this->customer_owns_subscription( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'You cannot edit this subscription.', 'woocommerce-paypal-pro-payment-gateway' ) ), 403 );
		}
		if ( ! $this->can_cancel_subscription( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'This subscription cannot be cancelled. Please reload the page.', 'woocommerce-paypal-pro-payment-gateway' ) ) );
		}
		try {
			PayPal_Utils::log( 'Subscription cancellation requested for subscription order #' . $id, true );
			$api = new PayPal_Request_API_Injector();
			$paypal_id = $order->get_meta( '_paypal_subscription_id', true );
			$details = $api->get_paypal_subscription_details( $paypal_id );
			$already_cancelled = $details && isset( $details->status ) && 'CANCELLED' === $details->status;
			if ( ! $already_cancelled && ! $api->cancel_paypal_subscription( $paypal_id ) ) {
				PayPal_Utils::log( 'Subscription cancellation failed for subscription order #' . $id, false );
				PayPal_Utils::log_array( $api->get_last_error_from_api_call(), false );
				wp_send_json_error( array( 'message' => __( 'PayPal could not cancel the subscription. Please try again or contact the store for help.', 'woocommerce-paypal-pro-payment-gateway' ) ) );
			}
			$order->update_meta_data( '_paypal_subscription_status', 'CANCELLED' );
			$order->update_status( 'wcpprog-cancelled', __( 'Subscription cancelled in PayPal.', 'woocommerce-paypal-pro-payment-gateway' ) );
			PayPal_Utils::log( 'Subscription cancellation completed for subscription order #' . $id, true );
			wp_send_json_success();
		} catch ( Throwable $error ) {
			PayPal_Utils::log( 'Subscription cancellation error for subscription order #' . $id . ': ' . $error->getMessage(), false );
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

    /** Subscription lifecycle statuses, independent of the current admin screen. */
    public static function get_subscription_statuses() {
        return array(
            'wc-wcpprog-trial'          => _x( 'Trialing', 'Subscription status', 'woocommerce-paypal-pro-payment-gateway' ),
            'wc-wcpprog-active'         => _x( 'Active', 'Subscription status', 'woocommerce-paypal-pro-payment-gateway' ),
            'wc-wcpprog-on-hold'        => _x( 'On hold', 'Subscription status', 'woocommerce-paypal-pro-payment-gateway' ),
            'wc-wcpprog-pending-cancel' => _x( 'Pending cancellation', 'Subscription status', 'woocommerce-paypal-pro-payment-gateway' ),
            'wc-wcpprog-cancelled'      => _x( 'Cancelled', 'Subscription status', 'woocommerce-paypal-pro-payment-gateway' ),
            'wc-wcpprog-expired'        => _x( 'Expired', 'Subscription status', 'woocommerce-paypal-pro-payment-gateway' ),
        );
    }

    public static function get_subscription_status_name( $status ) {
        $key = 'wc-' . preg_replace( '/^wc-/', '', $status );
        $statuses = self::get_subscription_statuses();
        return $statuses[ $key ] ?? wc_get_order_status_name( $status );
    }

    /** Use only our statuses on our legacy or HPOS subscription list/edit screens. */
    public function add_statuses_to_list( $statuses ) {
        $screen = is_admin() && function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen && in_array( $screen->id, array(
            self::ORDER_TYPE,
            'edit-' . self::ORDER_TYPE,
            'woocommerce_page_wc-orders--' . self::ORDER_TYPE,
        ), true ) ) {
            $statuses = self::get_subscription_statuses();
        }
        return $statuses;
    }

    /** Register with WooCommerce so its account title and endpoint handling apply. */
    public function subscriptions_wc_query_vars( $vars ) {
        $vars['wcppprog-subscriptions'] = 'wcppprog-subscriptions';
        return $vars;
    }

    public function subscription_endpoint_title( $title ) {
        global $wp;
        $value = $wp->query_vars['wcppprog-subscriptions'] ?? '';
        if ( is_string( $value ) && preg_match( '~^view-subscription/([1-9][0-9]*)/?$~', $value, $matches ) ) {
            $order = wc_get_order( absint( $matches[1] ) );
            if ( $this->customer_owns_subscription( $order ) ) {
                /* translators: %s: Subscription order number. */
                return sprintf( __( 'Subscription #%s', 'woocommerce-paypal-pro-payment-gateway' ), $order->get_order_number() );
            }
        }
        return __( 'Subscriptions', 'woocommerce-paypal-pro-payment-gateway' );
    }

    /**
     * Add the menu item, positioned right after "Orders"
     *
     * @return array
     */
    public function add_subscriptions_menu_item( $items ) {
        $new_items = array();
        foreach ( $items as $key => $label ) {
            $new_items[ $key ] = $label;
            if ( 'orders' === $key ) {
                $new_items['wcppprog-subscriptions'] = __( 'Subscriptions', 'woocommerce-paypal-pro-payment-gateway' );
            }
        }

        return $new_items;
    }

    public function render_subscriptions_endpoint_content() {
        global $wp;
        $value = $wp->query_vars['wcppprog-subscriptions'] ?? '';

        if ( ! is_string( $value ) || ( '' !== $value && ! preg_match( '~^(?:view-subscription/[1-9][0-9]*|page/[1-9][0-9]*)/?$~', $value ) ) ) {
            wc_print_notice( __( 'Invalid subscription page.', 'woocommerce-paypal-pro-payment-gateway' ), 'error' );
            return;
        }

        if ( preg_match( '~^view-subscription/([1-9][0-9]*)/?$~', $value, $matches ) ) {
            $this->render_subscription_detail( absint( $matches[1] ) );
            return;
        }

        $page = preg_match( '~^page/([1-9][0-9]*)/?$~', $value, $matches ) ? absint( $matches[1] ) : 1;

        $this->render_subscriptions_list( $page );
    }

    /** Display the current customer's subscriptions, ten per page. */
    public function render_subscriptions_list( $page = 1 ) {
        if ( ! get_current_user_id() ) {
            wc_print_notice( __( 'Please log in to view your subscriptions.', 'woocommerce-paypal-pro-payment-gateway' ), 'error' );
            return;
        }

        $page = max( 1, absint( $page ) );

        $results = wc_get_orders( array(
            'type' => self::ORDER_TYPE,
            'status' => array_keys( array_merge( wc_get_order_statuses(), self::get_subscription_statuses() ) ),
            'customer_id' => get_current_user_id(),
            'limit' => 10,
            'page' => $page,
            'paginate' => true,
            'orderby' => 'date',
            'order' => 'DESC',
        ) );

        wc_get_template( 'myaccount/wcpprog-subscriptions.php', array(
            'subscriptions' => $results->orders,
            'page' => $page,
            'pages' => (int) $results->max_num_pages,
            'handler' => $this,
        ), '', WC_PP_PRO_ADDON_PATH . '/templates/' );
    }

    public function render_customer_order_subscription_link( $order ) {
        if ( ! is_account_page() || ! is_wc_endpoint_url( 'view-order' )
            || ! $order instanceof WC_Order || 'shop_order' !== $order->get_type()
            || ! get_current_user_id() || (int) $order->get_customer_id() !== get_current_user_id() ) {
            return;
        }
        $subscription_id = absint( $order->get_meta( '_wcpprog_subscription_order_id', true ) );
        $subscription = $subscription_id ? wc_get_order( $subscription_id ) : false;
        if ( ! $this->customer_owns_subscription( $subscription ) ) {
            return;
        }
        echo '<section class="woocommerce-order-subscription">';
        echo '<h2>' . esc_html__( 'Subscription', 'woocommerce-paypal-pro-payment-gateway' ) . '</h2>';
        /* translators: %s: Subscription order number. */
        echo '<p><a class="woocommerce-button woocommerce-Button button wp-element-button" href="' . esc_url( $this->get_subscription_view_url( $subscription_id ) ) . '">' . esc_html( sprintf( __( 'View Subscription #%s', 'woocommerce-paypal-pro-payment-gateway' ), $subscription->get_order_number() ) ) . '</a></p>';
        echo '</section>';
    }

    public function get_subscription_view_url( $sub_order_id ) {
        return wc_get_endpoint_url( 'wcppprog-subscriptions', 'view-subscription/' . absint( $sub_order_id ), wc_get_page_permalink( 'myaccount' ) );
    }

    private function customer_owns_subscription( $order ) {
        return get_current_user_id() > 0 && $order instanceof WC_Order
            && self::ORDER_TYPE === $order->get_type()
            && ! $order->has_status( 'trash' )
            && (int) $order->get_customer_id() === get_current_user_id();
    }

    /** Show subscription details, received payments and cancellation for its owner. */
    public function render_subscription_detail( $subscription_id ) {
        $sub_order = wc_get_order( $subscription_id );
        if ( ! $this->customer_owns_subscription( $sub_order ) ) {
            wc_print_notice( __( 'This subscription is unavailable or does not belong to your account.', 'woocommerce-paypal-pro-payment-gateway' ), 'error' );
            return;
        }

        wc_get_template( 'emails/wcpprog-subscription-information.php', array(
                'order' => $sub_order,
                'customer_account' => true
        ), '', WC_PP_PRO_ADDON_PATH . '/templates/' );

        if ( $this->can_cancel_subscription( $sub_order ) ) {
            echo '<h2 class="woocommerce-column__title">' . esc_html__( 'Manage subscription', 'woocommerce-paypal-pro-payment-gateway' ) . '</h2>';
            $this->render_subscription_manage_meta_box( $sub_order );
        }
    }
}

new WCPPROG_Subscription_Order_Handler();
