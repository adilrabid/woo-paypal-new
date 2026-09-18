<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once WC_PP_PRO_ADDON_PATH . '/traits/trait-wcpprog-subscription-related.php';

class WCPPROG_Subscription_Related {

    use WCPPROG_Subscription_Related_Trait;

    const SUBSCRIPTION_PRODUCT_TYPE = 'wcpprog_subscription';

    const SUBSCRIPTION_PRODUCT_FIELDS = array(
            'subscription_recurring_price'                 => '',
            'subscription_recurring_sale_price'            => '',
            'subscription_recurring_billing_interval'      => 0,
            'subscription_recurring_billing_interval_type' => '',
            'subscription_reattempt_on_failure'            => 'no',
            'subscription_recurring_billing_count'         => 1,
            'subscription_trial_period'                    => 0,
            'subscription_trial_period_type'               => '',
            'subscription_trial_price'                     => 0,
    );

    public function __construct() {
        require_once WC_PP_PRO_ADDON_PATH . '/subscription/class-wcppprog-sub-order-handler.php';

        add_action( 'init', array( $this, 'init_time_tasks' ) );

        add_filter( 'woocommerce_data_stores', array( $this, 'add_data_stores' ) );

        add_filter( 'woocommerce_product_class', array( $this, 'register_subscription_product_class' ), 99, 4 );

        add_action( 'admin_footer', array( $this, 'custom_product_type_inventory_js' ) );

        add_filter( 'product_type_selector', array( $this, 'add_subscription_product_type' ), 99 );
        add_filter( 'product_type_options', array( $this, 'add_product_type_options' ) );

        add_action( 'woocommerce_product_options_general_product_data', array( $this, 'subscription_settings_fields' ) );
        add_filter( 'woocommerce_product_data_tabs', array( $this, 'product_data_tabs' ) );

        add_action( 'woocommerce_process_product_meta', array( $this, 'save_subscription_product_meta' ), 99, 2 );

        add_action( 'woocommerce_before_calculate_totals', array( $this, 'set_subscription_cart_prices' ) );

        add_action( 'woocommerce_blocks_loaded', array( $this, 'register_subscription_plan_data' ) );
        add_action( 'woocommerce_review_order_after_order_total', array( $this, 'render_checkout_subscription_plan' ) );

        add_action( 'woocommerce_add_to_cart', array( $this, 'enforce_subscription_cart_exclusivity' ), 10, 6 );
        add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_single_subscription_addition' ), 10, 3 );
        add_filter( 'woocommerce_add_to_cart_redirect', array( $this, 'redirect_subscription_to_checkout' ), 10, 2 );
        add_action( 'woocommerce_check_cart_items', array( $this, 'validate_single_subscription_cart' ) );

        add_filter( 'woocommerce_cart_needs_payment', array( $this, 'enable_payment_for_zero_cost_trial_subscription' ), 10, 2 );
        add_filter( 'woocommerce_available_payment_gateways', array( $this, 'restrict_subscription_payment_gateways' ), 100 );

        add_action( 'woocommerce_' . self::SUBSCRIPTION_PRODUCT_TYPE . '_add_to_cart', 'woocommerce_simple_add_to_cart' );

        // Add subscription order link meta box in main order details page.
        add_action( 'add_meta_boxes', array( $this, 'add_subscription_order_link_metabox' ) );
    }

    public function init_time_tasks() {
        require_once WC_PP_PRO_ADDON_PATH . '/subscription/class-wcppprog-sub-prodouct-data-store.php';
        require_once WC_PP_PRO_ADDON_PATH . '/subscription/class-wcppprog-sub-product.php';
    }

    public function add_data_stores( $data_stores ) {
        $data_stores[ 'product-' . self::SUBSCRIPTION_PRODUCT_TYPE ] = 'WCPPROG_Subscription_Product_Data_Store_CPT';

        return $data_stores;
    }

    public function register_subscription_product_class( $classname, $product_type, $post_type, $product_id ) {
        if ( $product_type == self::SUBSCRIPTION_PRODUCT_TYPE ) {
            $classname = WCPPROG_Subscription_Product::class;
        }

        return $classname;
    }

    public function enable_payment_for_zero_cost_trial_subscription( $needs_payment, $cart ) {
        foreach ( $cart->get_cart() as $item ) {
            $product = $item['data'];
            if ( self::SUBSCRIPTION_PRODUCT_TYPE === $product->get_type() ) {
                if ( $product->is_trial_enabled() ) {
                    return true; // force payment step even though today's charge is $0
                }
            }
        }

        return $needs_payment;
    }

    public function restrict_subscription_payment_gateways( $gateways ) {
        // Also runs for Store API requests used by Checkout blocks.
        if ( ! WC()->cart ) {
            return $gateways;
        }

        foreach ( WC()->cart->get_cart() as $item ) {
            $product = $item['data'] ?? null;
            if ( $product instanceof WC_Product && self::SUBSCRIPTION_PRODUCT_TYPE === $product->get_type() ) {
                return array_intersect_key( $gateways, array( 'paypal_checkout' => true ) );
            }
        }

        return $gateways;
    }

    public function set_subscription_cart_prices( $cart ) {
        foreach ( $cart->get_cart() as $item ) {
            $product = $item['data'];
            if ( self::SUBSCRIPTION_PRODUCT_TYPE === $product->get_type() && '' !== $product->get_regular_price() ) {
                $product->set_price( $product->get_due_today_amount() );
            }
        }
    }

    public function use_due_today_price_in_cart( $price, $product ) {
        if ( self::SUBSCRIPTION_PRODUCT_TYPE !== $product->get_type() ) {
            return $price;
        }

        // Only override in cart/checkout context — keep normal price on product/shop pages
        if ( is_cart() || is_checkout() || ( defined( 'DOING_AJAX' ) && DOING_AJAX && isset( $_REQUEST['wc-ajax'] ) && in_array( $_REQUEST['wc-ajax'], array(
                                'update_order_review',
                                'get_refreshed_fragments'
                        ), true ) ) ) {
            return $product->get_due_today_amount();
        }

        return $price;
    }

    public function render_checkout_subscription_plan() {
        if ( ! WC()->cart ) {
            return;
        }

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $plan = $this->get_subscription_plan_data( $cart_item );
            if ( '' === $plan['subscription_plan_html'] ) {
                continue;
            }

            echo '<tr class="wcpprog-subscription-plan"><td colspan="2" style="padding:16px;">';
            echo '<div style="margin-bottom:8px;">' . esc_html__( 'Subscription plan', 'woocommerce-paypal-pro-payment-gateway' ) . '</div>';
            echo '<div style="font-weight:400; margin-bottom:8px;">' . wp_kses_post( $plan['subscription_plan_html'] ) . '</div>';
            echo '</td></tr>';
        }
    }

    public function register_subscription_plan_data() {
        woocommerce_store_api_register_endpoint_data( array(
            'endpoint' => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER,
            'namespace' => 'wcpprog',
            'data_callback' => array( $this, 'get_subscription_plan_data' ),
            'schema_callback' => function() {
                return array(
                    'subscription_plan_html' => array(
                        'description' => __( 'Subscription billing plan.', 'woocommerce-paypal-pro-payment-gateway' ),
                        'type' => 'string',
                        'readonly' => true,
                    ),
                );
            },
            'schema_type' => ARRAY_A,
        ) );
    }

    /** Calculate billing totals from an already calculated checkout cart. */
    public static function get_subscription_checkout_totals( $cart, $product ) {
        $initial_total = $cart->get_total( 'edit' );
        $recurring_total = $initial_total;

        if ( $product->is_trial_enabled() ) {
            $recurring_cart = clone $cart;
            foreach ( $recurring_cart->cart_contents as &$item ) {
                $item['data'] = clone $item['data'];
                if ( $item['data'] instanceof WCPPROG_Subscription_Product ) {
                    $item['data']->set_subscription_trial_period( 0 );
                    $item['data']->set_price( $item['data']->get_due_today_amount() );
                }
            }
            unset( $item );

            try {
                // Fee extensions may read the global cart instead of their argument.
                WC()->cart = $recurring_cart;
                $recurring_cart->fees_api()->remove_all_fees();
                new WC_Cart_Totals( $recurring_cart );
                $recurring_total = $recurring_cart->get_total( 'edit' );
            } finally {
                WC()->cart = $cart;
                // Restore shipping/session caches for the initial checkout.
                $cart->calculate_totals();
            }
        }

        return array( 'initial' => $initial_total, 'recurring' => $recurring_total );
    }

    public function get_subscription_plan_data( $cart_item ) {
        $product = $cart_item['data'];

        $subscription_plan_html = '';
        if ( $product instanceof WC_Product && self::SUBSCRIPTION_PRODUCT_TYPE === $product->get_type() ) {
            $subscription_plan_html .= $product->get_price_html();
            $subscription_plan_html .= '<div><small>'.esc_html__('Excluding applicable tax, shipping, coupon discounts and other fees!', 'woocommerce-paypal-pro-payment-gateway').'</small></div>';
        }

        return array(
            'subscription_plan_html' => $subscription_plan_html,
        );
    }

    public function add_trial_note_to_cart_price( $price_html, $cart_item, $cart_item_key ) {
        $product = $cart_item['data'];
        if ( self::SUBSCRIPTION_PRODUCT_TYPE !== $product->get_type() ) {
            return $price_html;
        }

        $trial_length = (int) $product->get_meta( '_subscription_trial_period', true );
        if ( $trial_length <= 0 ) {
            return $price_html;
        }

        $recurring_price = $product->is_on_sale() ? $product->get_sale_price() : $product->get_regular_price();
        $interval        = (int) $product->get_meta( '_subscription_recurring_billing_interval', true );
        $period          = $product->get_meta( '_subscription_recurring_billing_interval_type', true );

        $note = sprintf(
                '<br><small class="subscription-trial-note">%s</small>',
                sprintf(
                /* translators: %s: recurring price/period text */
                    esc_html__( 'then %s afterward', 'woocommerce-paypal-pro-payment-gateway' ),
                    wc_price( wc_get_price_to_display( $product, array( 'price' => $recurring_price ) ) ) . ( $interval > 1 ? " every {$interval} {$period}s" : " / {$period}" )
                )
        );

        return $price_html . $note;
    }
    public function custom_product_type_inventory_js() {
        global $post;
        if ( ! $post || 'product' !== get_post_type( $post ) ) {
            return;
        }
        ?>
        <script type="text/javascript">
            jQuery(function ($) {
                $('#inventory_product_data .show_if_simple.show_if_variable').addClass('show_if_<?php echo esc_js( self::SUBSCRIPTION_PRODUCT_TYPE ); ?>');

                $('#product-type').trigger('change');
            });
        </script>
        <?php
    }

    public function add_subscription_product_type( $types ) {
        $types[ self::SUBSCRIPTION_PRODUCT_TYPE ] = __( 'Subscription Product', 'woocommerce-paypal-pro-payment-gateway' );

        return $types;
    }

    public function add_product_type_options( $options ) {
        if ( isset( $options['virtual'] ) ) {
            $options['virtual']['wrapper_class'] .= ' show_if_' . self::SUBSCRIPTION_PRODUCT_TYPE;
        }
        if ( isset( $options['downloadable'] ) ) {
            $options['downloadable']['wrapper_class'] .= ' show_if_' . self::SUBSCRIPTION_PRODUCT_TYPE;
        }

        return $options;
    }

    public function product_data_tabs( $tabs ) {
        if ( isset( $tabs['inventory']['class'] ) ) {
            $tabs['inventory']['class'][] = 'show_if_' . self::SUBSCRIPTION_PRODUCT_TYPE;
        }

        return $tabs;
    }

    public function subscription_settings_fields() {
        echo '<div class="options_group show_if_' . esc_attr( self::SUBSCRIPTION_PRODUCT_TYPE ) . '">';

        woocommerce_wp_text_input( array(
                'id'        => '_subscription_recurring_price',
                'label'     => __( 'Recurring Price', 'woocommerce-paypal-pro-payment-gateway' ) . ' (' . get_woocommerce_currency_symbol() . ')',
                'description' => __( 'The regular price charged each billing period after any trial period ends.', 'woocommerce-paypal-pro-payment-gateway' ),
                'desc_tip'  => false,
                'data_type' => 'price',
        ) );

        woocommerce_wp_text_input( array(
                'id'        => '_subscription_recurring_sale_price',
                'label'     => __( 'Recurring Sale Price', 'woocommerce-paypal-pro-payment-gateway' ) . ' (' . get_woocommerce_currency_symbol() . ')',
                'description' => __( 'Optional discounted price charged for each billing period after the trial period ends. Enter an amount lower than the regular recurring price; otherwise, the discount will not take effect. Leave blank to use the regular price.', 'woocommerce-paypal-pro-payment-gateway' ),
                'desc_tip'  => false,
                'data_type' => 'price',
        ) );

        $this->woocommerce_wp_time_period_input(
                array(
                        'label'       => __( 'Recurring Billing Interval', 'woocommerce-paypal-pro-payment-gateway' ),
                        'description' => __( 'Length of the recurring billing period', 'woocommerce-paypal-pro-payment-gateway' ),
                ),
                array(
                        'id'                => '_subscription_recurring_billing_interval',
                        'custom_attributes' => array(
                                'min' => 1,
                        ),
                ),
                array(
                        'id' => '_subscription_recurring_billing_interval_type',
                )
        );

        woocommerce_wp_text_input( array(
                'id'                => '_subscription_recurring_billing_count',
                'label'             => __( 'Recurring Billing Count', 'woocommerce-paypal-pro-payment-gateway' ),
                'description'       => __( 'After how many cycles should billing stop. Leave this field empty (or enter 0) if you want the payment to continue until the subscription is canceled.', 'woocommerce-paypal-pro-payment-gateway' ),
                'type'              => 'number',
                'custom_attributes' => array(
                        'min'  => 1,
                        'step' => 1,
                ),
        ) );

        woocommerce_wp_checkbox( array(
                'id'          => '_subscription_reattempt_on_failure',
                'label'       => __( 'Reattempt on Failure', 'woocommerce-paypal-pro-payment-gateway' ),
                'description' => __( 'When checked, the payment will be re-attempted two more times if the payment fails. After the third failure, the subscription will be canceled.', 'woocommerce-paypal-pro-payment-gateway' ),
        ) );

        echo '</div>'; // End of option group
        echo '<div class="options_group show_if_' . esc_attr( self::SUBSCRIPTION_PRODUCT_TYPE ) . '">';

        $this->woocommerce_wp_time_period_input(
                array(
                        'label'       => __( 'Trial Period', 'woocommerce-paypal-pro-payment-gateway' ),
                        'description' => __( 'Length of the trial period', 'woocommerce-paypal-pro-payment-gateway' ),
                ),
                array(
                        'id' => '_subscription_trial_period',
                ),
                array(
                        'id' => '_subscription_trial_period_type',
                )
        );

        woocommerce_wp_text_input( array(
                'id'          => '_subscription_trial_price',
                'label'       => __( 'Trial Price', 'woocommerce-paypal-pro-payment-gateway' ) . ' (' . get_woocommerce_currency_symbol() . ')',
                'data_type'   => 'price',
                'description' => __( 'Amount to be charged for the trial period. Leave empty or enter 0 if you want to offer a free trial period', 'woocommerce-paypal-pro-payment-gateway' ),
        ) );

        echo '</div>'; // End of option group
    }

    public function save_subscription_product_meta( $post_id, $post ) {
        $product = wc_get_product( intval( $post_id ) );
        if ( ! $product || $product->get_type() !== self::SUBSCRIPTION_PRODUCT_TYPE ) {
            return;
        }

        foreach ( self::SUBSCRIPTION_PRODUCT_FIELDS as $prop => $default ) {
            $post_key = '_' . $prop;
            $value    = isset( $_POST[ $post_key ] ) ? wc_clean( wp_unslash( $_POST[ $post_key ] ) ) : $default;
            $setter   = "set_$prop";
            $product->$setter( $value );
        }

        // WooCommerce uses these standard fields for purchasability and cart pricing.
        $product->set_regular_price( $product->get_subscription_recurring_price( 'edit' ) );
        $product->set_sale_price( $product->get_subscription_recurring_sale_price( 'edit' ) );
        $product->set_price(
                $product->is_on_sale( 'edit' )
                        ? $product->get_sale_price( 'edit' )
                        : $product->get_regular_price( 'edit' )
        );

        $product->save();
    }

    public function redirect_subscription_to_checkout( $url, $product = null ) {
        return $product instanceof WC_Product && $product->is_type( self::SUBSCRIPTION_PRODUCT_TYPE )
            ? wc_get_checkout_url() : $url;
    }

    public function validate_single_subscription_addition( $passed, $product_id, $quantity ) {
        $product = wc_get_product( $product_id );
        if ( ! $passed || ! $product || $product->get_type() !== self::SUBSCRIPTION_PRODUCT_TYPE ) {
            return $passed;
        }

        if ( WC()->cart ) {
            foreach ( WC()->cart->get_cart() as $item ) {
                if ( (int) $item['product_id'] === (int) $product_id ) {
                    // Silently ignore repeat additions before WooCommerce adds a duplicate notice.
                    if ( ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && ! wc_notice_count( 'error' ) ) {
                        wp_safe_redirect( wc_get_checkout_url() );
                        exit;
                    }
                    return false;
                }
            }
        }

        if ( $quantity > 1 ) {
            wc_add_notice( __( 'You can only purchase one subscription at a time.', 'woocommerce-paypal-pro-payment-gateway' ), 'error' );

            return false;
        }

        return $passed;
    }

    public function validate_single_subscription_cart() {
        if ( ! WC()->cart ) {
            return;
        }

        $subscription_count = 0;
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( $item['data']->get_type() === self::SUBSCRIPTION_PRODUCT_TYPE ) {
                ++ $subscription_count;
                if ( $subscription_count > 1 || (float) $item['quantity'] !== 1.0 ) {
                    wc_add_notice( __( 'You can only check out with one subscription item, with a quantity of one. Please update your cart.', 'woocommerce-paypal-pro-payment-gateway' ), 'error' );

                    return;
                }
            }
        }
    }

    public function enforce_subscription_cart_exclusivity( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
        $added_product = wc_get_product( $product_id );

        if ( ! $added_product ) {
            return;
        }

        $added_is_subscription = self::SUBSCRIPTION_PRODUCT_TYPE === $added_product->get_type();

        foreach ( WC()->cart->get_cart() as $key => $item ) {
            if ( $key === $cart_item_key ) {
                continue; // don't remove the item we just added
            }

            $existing_product         = $item['data'];
            $existing_is_subscription = self::SUBSCRIPTION_PRODUCT_TYPE === $existing_product->get_type();

            // Subscription added → clear everything else (regular products AND other subscriptions)
            // Regular product added → clear only subscriptions
            if ( $added_is_subscription || $existing_is_subscription ) {
                WC()->cart->remove_cart_item( $key );

                if ( ! $added_is_subscription && $existing_is_subscription ) {
                    wc_add_notice(
                            __( 'Your subscription was removed from the cart since it can\'t be purchased together with other products.', 'woocommerce-paypal-pro-payment-gateway' ),
                            'notice'
                    );
                }
            }
        }
    }

    public function add_subscription_order_link_metabox() {
        if (
            class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
            && wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
        ) {
            $screen = wc_get_page_screen_id( 'shop-order' );
        } else {
            $screen = 'shop_order';
        }

        add_meta_box(
                'wcpprog_subscription_order_link',
                __( 'Subscription', 'woocommerce-paypal-pro-payment-gateway' ),
                array( $this, 'render_subscription_order_link_metabox' ),
                $screen,
                'side',
                'high',
        );
    }

    public function render_subscription_order_link_metabox( $post_or_order_object ) {
        $order = ( $post_or_order_object instanceof WP_Post ) ? wc_get_order( $post_or_order_object->ID ) : $post_or_order_object;
        if ( empty( $order ) ) {
            return;
        }

        $output = '';

        $subscription_id = $order->get_meta( '_wcpprog_subscription_order_id' );
        if ( ! empty( $subscription_id ) ) {
            $edit_url = admin_url( 'admin.php?page=wc-orders--' . WCPPROG_Subscription_Order_Handler::ORDER_TYPE . '&id=' . $subscription_id . '&action=edit' );

            $output .= '<a href="' . esc_url( $edit_url ) . '">';
            /* translators: %d: Subscription order ID. */
            $output .= sprintf( esc_html__( 'View Subscription #%d', 'woocommerce-paypal-pro-payment-gateway' ), $subscription_id );
            $output .= '</a>';

        } else {
            $output .= esc_html__( 'No subscription linked.', 'woocommerce-paypal-pro-payment-gateway' );
        }

        echo wp_kses_post( wpautop( $output ) );
    }
}
