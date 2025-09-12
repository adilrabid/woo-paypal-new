<?php
/**
 * WooCommerce PayPal Checkout Gateway Class
 *
 * Adds PayPal Checkout as a separate payment gateway alongside PayPal Pro.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class WC_Gateway_PayPal_Checkout extends WC_Payment_Gateway {
    
    public function __construct() {
        $this->id                 = 'paypal_checkout';
        $this->icon               = apply_filters('woocommerce_paypal_checkout_icon', 'https://www.paypalobjects.com/webstatic/icon/pp258.png');
        $this->has_fields         = false;
        $this->method_title       = __( 'PayPal Checkout', 'woocommerce-paypal-pro-payment-gateway' );
        $this->method_description = __( 'Accept payments via PayPal Checkout with smart payment buttons.', 'woocommerce-paypal-pro-payment-gateway' );
        $this->supports           = array( 'products' );

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title          = $this->get_option( 'title' );
        $this->description    = $this->get_option( 'description' );
        $this->enabled        = $this->get_option( 'enabled' );
        $this->sandbox        = $this->get_option( 'sandbox' );
        $this->client_id      = $this->sandbox ? $this->get_option( 'sandbox_client_id' ) : $this->get_option( 'live_client_id' );
        $this->client_secret  = $this->sandbox ? $this->get_option( 'sandbox_client_secret' ) : $this->get_option( 'live_client_secret' );

        // Actions
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        
        // Initialize hooks after WordPress is loaded
        add_action( 'init', array( $this, 'init_hooks' ), 20 );
    }

    /**
     * Initialize hooks for button rendering and scripts
     */
    public function init_hooks() {
        // Always add these hooks, but check availability in the methods themselves
        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
        
        // For block themes, we need to use JavaScript to inject buttons
        add_action( 'wp_footer', array( $this, 'inject_paypal_buttons_for_block_themes' ) );
        
        // Still try traditional hooks as fallback
        add_action( 'woocommerce_proceed_to_checkout', array( $this, 'render_paypal_button_on_cart' ), 20 );
        add_action( 'woocommerce_after_cart_totals', array( $this, 'render_paypal_button_on_cart' ), 15 );
        add_action( 'woocommerce_review_order_before_submit', array( $this, 'render_paypal_button_on_checkout' ), 10 );
        add_action( 'woocommerce_checkout_order_review', array( $this, 'render_paypal_button_on_checkout' ), 25 );
        
        // AJAX actions (always add these for security)
        add_action( 'wp_ajax_paypal_checkout_create_order', array( $this, 'create_paypal_order' ) );
        add_action( 'wp_ajax_nopriv_paypal_checkout_create_order', array( $this, 'create_paypal_order' ) );
        add_action( 'wp_ajax_paypal_checkout_capture_order', array( $this, 'capture_paypal_order' ) );
        add_action( 'wp_ajax_nopriv_paypal_checkout_capture_order', array( $this, 'capture_paypal_order' ) );
    }

    /**
     * Inject PayPal buttons using JavaScript for block themes
     */
    public function inject_paypal_buttons_for_block_themes() {
        if ( ! $this->is_available() ) {
            echo '<!-- PayPal Checkout: Gateway not available for block theme injection -->';
            return;
        }

        if ( ! is_cart() && ! is_checkout() ) {
            return;
        }

        echo '<!-- PayPal Checkout: Injecting buttons for block theme -->';
        ?>
        <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            // Wait a bit for WooCommerce blocks to load
            setTimeout(function() {
                injectPayPalButtons();
            }, 1000);
            
            // Also try again after a longer delay in case blocks load slowly
            setTimeout(function() {
                injectPayPalButtons();
            }, 3000);
        });

        function injectPayPalButtons() {
            // Check if we're on cart page
            if (document.body.classList.contains('woocommerce-cart') || 
                document.querySelector('.wc-block-cart') || 
                document.querySelector('[data-block-name="woocommerce/cart"]')) {
                
                console.log('PayPal: Detected cart page');
                injectCartButton();
            }
            
            // Check if we're on checkout page
            if (document.body.classList.contains('woocommerce-checkout') || 
                document.querySelector('.wc-block-checkout') || 
                document.querySelector('[data-block-name="woocommerce/checkout"]')) {
                
                console.log('PayPal: Detected checkout page');
                injectCheckoutButton();
            }
        }

        function injectCartButton() {
            // Don't inject if already exists
            if (document.querySelector('#paypal-cart-button-container')) {
                return;
            }

            // Try multiple selectors for cart totals area
            var selectors = [
                '.wc-block-cart__totals-wrapper',
                '.cart-collaterals',
                '.cart_totals',
                '.wc-block-cart-totals',
                '.woocommerce-cart-form + .cart-collaterals',
                '.wp-block-woocommerce-cart-totals-block'
            ];

            var targetElement = null;
            for (var i = 0; i < selectors.length; i++) {
                targetElement = document.querySelector(selectors[i]);
                if (targetElement) {
                    console.log('PayPal: Found cart target with selector: ' + selectors[i]);
                    break;
                }
            }

            if (targetElement) {
                var buttonContainer = document.createElement('div');
                buttonContainer.id = 'paypal-cart-button-container';
                buttonContainer.style.cssText = 'border: 1px solid #ddd; padding: 15px; margin: 15px 0; border-radius: 5px; background: #f9f9f9;';
                buttonContainer.innerHTML = '<h3>Or pay with PayPal</h3><div id="paypal-checkout-button-container" style="margin: 20px 0;"></div>';
                
                targetElement.appendChild(buttonContainer);
                renderPayPalButton();
            } else {
                console.log('PayPal: Could not find cart target element');
            }
        }

        function injectCheckoutButton() {
            // Don't inject if already exists
            if (document.querySelector('#paypal-checkout-button-container-checkout')) {
                return;
            }

            // Try multiple selectors for checkout area
            var selectors = [
                '.wc-block-checkout__actions',
                '.woocommerce-checkout-payment',
                '.wc-block-checkout-payment',
                '.checkout-payment',
                'form.checkout',
                '.wc-block-checkout__main'
            ];

            var targetElement = null;
            for (var i = 0; i < selectors.length; i++) {
                targetElement = document.querySelector(selectors[i]);
                if (targetElement) {
                    console.log('PayPal: Found checkout target with selector: ' + selectors[i]);
                    break;
                }
            }

            if (targetElement) {
                var buttonContainer = document.createElement('div');
                buttonContainer.id = 'paypal-checkout-button-container-checkout';
                buttonContainer.style.cssText = 'border: 1px solid #ddd; padding: 15px; margin: 15px 0; border-radius: 5px; background: #f9f9f9;';
                buttonContainer.innerHTML = '<p>Or pay with PayPal:</p><div id="paypal-checkout-button-container" style="margin: 20px 0;"></div>';
                
                targetElement.appendChild(buttonContainer);
                renderPayPalButton();
            } else {
                console.log('PayPal: Could not find checkout target element');
            }
        }

        function renderPayPalButton() {
            if (typeof paypal !== 'undefined' && document.querySelector('#paypal-checkout-button-container')) {
                paypal.Buttons({
                    createOrder: function(data, actions) {
                        return actions.order.create({
                            purchase_units: [{
                                amount: {
                                    value: "<?php echo esc_js( WC()->cart ? WC()->cart->get_total( 'raw' ) : 0 ); ?>"
                                }
                            }]
                        });
                    },
                    onApprove: function(data, actions) {
                        return actions.order.capture().then(function(details) {
                            jQuery.post(wc_paypal_checkout_params.ajax_url, {
                                action: "paypal_checkout_capture_order",
                                order_id: data.orderID,
                                nonce: wc_paypal_checkout_params.nonce
                            }, function(response) {
                                if (response.success) {
                                    window.location.href = response.data.redirect;
                                } else {
                                    alert("Payment failed: " + response.data.message);
                                }
                            });
                        });
                    }
                }).render("#paypal-checkout-button-container");
            } else {
                console.log('PayPal: SDK not loaded or container not found');
            }
        }
        </script>
        <?php
    }

    /**
     * Debug method to check if cart hooks are firing
     */
    public function debug_cart_hook() {
        echo '<!-- PayPal Checkout: Cart hook fired -->';
    }

    /**
     * Debug method to check if checkout hooks are firing
     */
    public function debug_checkout_hook() {
        echo '<!-- PayPal Checkout: Checkout hook fired -->';
    }    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Enable/Disable', 'woocommerce-paypal-pro-payment-gateway' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable PayPal Checkout', 'woocommerce-paypal-pro-payment-gateway' ),
                'default' => 'no'
            ),
            'title' => array(
                'title'       => __( 'Title', 'woocommerce-paypal-pro-payment-gateway' ),
                'type'        => 'text',
                'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-paypal-pro-payment-gateway' ),
                'default'     => __( 'PayPal', 'woocommerce-paypal-pro-payment-gateway' ),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __( 'Description', 'woocommerce-paypal-pro-payment-gateway' ),
                'type'        => 'textarea',
                'description' => __( 'Payment method description that the customer will see on your checkout.', 'woocommerce-paypal-pro-payment-gateway' ),
                'default'     => __( 'Pay with your PayPal account or credit card.', 'woocommerce-paypal-pro-payment-gateway' ),
                'desc_tip'    => true,
            ),
            'sandbox' => array(
                'title'   => __( 'Sandbox', 'woocommerce-paypal-pro-payment-gateway' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable PayPal sandbox', 'woocommerce-paypal-pro-payment-gateway' ),
                'default' => 'yes',
                'description' => __( 'PayPal sandbox can be used to test payments.', 'woocommerce-paypal-pro-payment-gateway' ),
            ),
            'live_client_id' => array(
                'title'       => __( 'Live Client ID', 'woocommerce-paypal-pro-payment-gateway' ),
                'type'        => 'text',
                'description' => __( 'Get your client ID from PayPal Developer dashboard.', 'woocommerce-paypal-pro-payment-gateway' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'live_client_secret' => array(
                'title'       => __( 'Live Client Secret', 'woocommerce-paypal-pro-payment-gateway' ),
                'type'        => 'password',
                'description' => __( 'Get your client secret from PayPal Developer dashboard.', 'woocommerce-paypal-pro-payment-gateway' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'sandbox_client_id' => array(
                'title'       => __( 'Sandbox Client ID', 'woocommerce-paypal-pro-payment-gateway' ),
                'type'        => 'text',
                'description' => __( 'Get your sandbox client ID from PayPal Developer dashboard.', 'woocommerce-paypal-pro-payment-gateway' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'sandbox_client_secret' => array(
                'title'       => __( 'Sandbox Client Secret', 'woocommerce-paypal-pro-payment-gateway' ),
                'type'        => 'password',
                'description' => __( 'Get your sandbox client secret from PayPal Developer dashboard.', 'woocommerce-paypal-pro-payment-gateway' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
        );
    }

    /**
     * Check if this gateway is enabled and available
     */
    public function is_available() {
        if ( 'yes' === $this->enabled ) {
            if ( ! empty( $this->client_id ) && ! empty( $this->client_secret ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Enqueue payment scripts
     */
    public function payment_scripts() {
        if ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) ) {
            return;
        }

        if ( 'no' === $this->enabled ) {
            return;
        }

        if ( empty( $this->client_id ) ) {
            return;
        }

        wp_enqueue_script(
            'paypal-checkout-sdk',
            'https://www.paypal.com/sdk/js?client-id=' . esc_attr( $this->client_id ) . '&currency=' . get_woocommerce_currency(),
            array( 'jquery' ),
            null,
            true
        );

        wp_localize_script( 'paypal-checkout-sdk', 'wc_paypal_checkout_params', array(
            'ajax_url'    => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'wc_paypal_checkout_nonce' ),
            'currency'    => get_woocommerce_currency(),
            'total'       => WC()->cart ? WC()->cart->get_total( 'raw' ) : 0,
        ));
    }

    /**
     * Render PayPal button on cart page
     */
    public function render_paypal_button_on_cart() {
        if ( ! $this->is_available() ) {
            // Debug: Add hidden comment to see if method is being called
            echo '<!-- PayPal Checkout: Gateway not available on cart page -->';
            return;
        }

        echo '<!-- PayPal Checkout: Rendering button on cart page -->';
        echo '<div class="wc-paypal-checkout-cart-button" style="border: 1px solid #ddd; padding: 15px; margin: 15px 0; border-radius: 5px;">';
        echo '<h3>' . __( 'Or pay with PayPal', 'woocommerce-paypal-pro-payment-gateway' ) . '</h3>';
        $this->render_paypal_button_html();
        echo '</div>';
    }

    /**
     * Render PayPal button on checkout page
     */
    public function render_paypal_button_on_checkout() {
        if ( ! $this->is_available() ) {
            // Debug: Add hidden comment to see if method is being called
            echo '<!-- PayPal Checkout: Gateway not available on checkout page -->';
            return;
        }

        echo '<!-- PayPal Checkout: Rendering button on checkout page -->';
        echo '<div class="wc-paypal-checkout-button" style="border: 1px solid #ddd; padding: 15px; margin: 15px 0; border-radius: 5px;">';
        echo '<p>' . __( 'Or pay with PayPal:', 'woocommerce-paypal-pro-payment-gateway' ) . '</p>';
        $this->render_paypal_button_html();
        echo '</div>';
    }

    /**
     * Render PayPal button HTML and JavaScript
     */
    private function render_paypal_button_html() {
        $cart_total = WC()->cart ? WC()->cart->get_total( 'raw' ) : 0;
        
        echo '<div id="paypal-checkout-button-container" style="margin: 20px 0;"></div>';
        echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            if (typeof paypal !== "undefined") {
                paypal.Buttons({
                    createOrder: function(data, actions) {
                        return actions.order.create({
                            purchase_units: [{
                                amount: {
                                    value: "' . esc_js( $cart_total ) . '"
                                }
                            }]
                        });
                    },
                    onApprove: function(data, actions) {
                        return actions.order.capture().then(function(details) {
                            jQuery.post(wc_paypal_checkout_params.ajax_url, {
                                action: "paypal_checkout_capture_order",
                                order_id: data.orderID,
                                nonce: wc_paypal_checkout_params.nonce
                            }, function(response) {
                                if (response.success) {
                                    window.location.href = response.data.redirect;
                                } else {
                                    alert("Payment failed: " + response.data.message);
                                }
                            });
                        });
                    }
                }).render("#paypal-checkout-button-container");
            }
        });
        </script>';
    }

    /**
     * Capture PayPal order via AJAX
     */
    public function capture_paypal_order() {
        check_ajax_referer( 'wc_paypal_checkout_nonce', 'nonce' );

        $order_id = sanitize_text_field( $_POST['order_id'] );
        
        if ( empty( $order_id ) ) {
            wp_send_json_error( array( 'message' => 'Order ID is required' ) );
        }

        // Create WooCommerce order
        $wc_order = wc_create_order();
        
        // Add cart items to order
        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
            $product = $cart_item['data'];
            $wc_order->add_product( $product, $cart_item['quantity'] );
        }

        // Set payment method
        $wc_order->set_payment_method( $this );
        
        // Mark as paid
        $wc_order->payment_complete( $order_id );
        $wc_order->add_order_note( sprintf( __( 'PayPal payment completed. PayPal Order ID: %s', 'woocommerce-paypal-pro-payment-gateway' ), $order_id ) );

        // Empty cart
        WC()->cart->empty_cart();

        wp_send_json_success( array(
            'redirect' => $wc_order->get_checkout_order_received_url()
        ));
    }

    /**
     * Process the payment (required by WC_Payment_Gateway)
     */
    public function process_payment( $order_id ) {
        return array(
            'result'   => 'success',
            'redirect' => '',
        );
    }
}

// Add a simple test hook that should always fire to verify the file is loaded
add_action( 'wp_footer', function() {
    echo '<!-- PayPal Checkout Gateway File Loaded -->';
});

// Add page detection for debugging
add_action( 'wp_footer', function() {
    if ( is_cart() ) {
        echo '<!-- This is the CART page -->';
    }
    if ( is_checkout() ) {
        echo '<!-- This is the CHECKOUT page -->';
    }
}, 999 );