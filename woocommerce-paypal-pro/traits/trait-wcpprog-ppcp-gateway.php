<?php

use TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Utils;
use TTHQ\WC_PP_PRO\Lib\PayPal\PayPal_Webhook;

trait WC_Gateway_PayPal_Checkout_Trait {
    protected function init_webhooks() {
        add_action( 'wp_ajax_wcpprog_ppcp_check_webhooks', array( $this, 'ajax_check_webhooks' ) );
        add_action( 'wp_ajax_wcpprog_ppcp_create_webhook', array( $this, 'ajax_create_webhook' ) );
        add_action( 'wp_ajax_wcpprog_ppcp_delete_webhooks', array( $this, 'ajax_delete_webhooks' ) );
    }

    /**
     * Process AJAX request for check webhooks.
     */
    public function ajax_check_webhooks() {
        if (! check_ajax_referer('wcpprog-ppcp-check-webhook', false, false)){
            wp_send_json_error(
                array(
                        'message' => __('Nonce verification failed!', 'woocommerce-paypal-pro-payment-gateway'),
                )
            );
        }

        try {
            $webhook = new PayPal_Webhook();
            $result = $webhook->check_webhook();

            wp_send_json_success( $result );

        } catch ( \Exception $e ) {
            wp_send_json_error(array(
                    'message' => $e->getMessage(),
            ));
        }
    }

    /**
     * Process AJAX request for create webhooks.
     */
    public function ajax_create_webhook() {
        if (! check_ajax_referer('wcpprog-ppcp-create-webhook', false, false)){
            wp_send_json_error(
                array(
                        'message' => __('Nonce verification failed!', 'woocommerce-paypal-pro-payment-gateway'),
                )
            );
        }

        $mode = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : 'live';

        try {
            $webhook = new PayPal_Webhook();

            if ($mode == 'live') {
                $result = $webhook->check_and_create_webhook_for_live_mode();
            } else {
                $result = $webhook->check_and_create_webhook_for_sandbox_mode();
            }

            wp_send_json_success( $result );

        } catch ( \Exception $e ) {
            wp_send_json_error(array(
                    'message' => $e->getMessage(),
            ));
        }
    }

    /**
     * Process AJAX request for delete webhooks.
     */
    public function ajax_delete_webhooks() {
        if (! check_ajax_referer('wcpprog-ppcp-delete-webhook', false, false)){
            wp_send_json_error(
                    array(
                            'message' => __('Nonce verification failed!', 'woocommerce-paypal-pro-payment-gateway'),
                    )
            );
        }

        try {
            $webhook = new PayPal_Webhook();

            $result = $webhook->check_and_delete_webhooks_for_both_modes();

            wp_send_json_success( $result );

        } catch ( \Exception $e ) {
            wp_send_json_error(array(
                    'message' => $e->getMessage(),
            ));
        }
    }


    public function generate_account_conn_btn_html($key, $data) {
        $field    = $this->plugin_id . $this->id . '_' . $key;

        $defaults = array(
                'class'             => '',
                'css'               => '',
                'custom_attrs' => array(),
                'desc_tip'          => false,
                'description'       => '',
                'title'             => '',
        );

        $data = wp_parse_args($data, $defaults);

        $connection_type = isset($data['custom_attrs']['connection_type']) ? $data['custom_attrs']['connection_type'] : 'sandbox';

        $is_sandbox_enabled = $this->get_option('sandbox') == 'yes' ? true : false;

        $ppcp_onboarding_instance = \TTHQ\WC_PP_PRO\Lib\PayPal\Onboarding\PayPal_PPCP_Onboarding::get_instance();

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field); ?>"><?php echo wp_kses_post($data['title']); ?></label>
                <?php echo wp_kses_post( $this->get_tooltip_html($data) ); ?>
            </th>
            <td class="forminp">
                <fieldset>
                    <legend class="screen-reader-text"><span><?php echo wp_kses_post($data['title']); ?></span></legend>
                    <?php
                    if ($connection_type == 'live') {

                        if (! $is_sandbox_enabled) {
                            // Check if the live account is connected
                            $live_account_connection_status = 'connected';
                            if (empty($this->get_option('live_client_id')) || empty($this->get_option('live_client_secret'))) {
                                //Live API keys are missing. Account is not connected.
                                $live_account_connection_status = 'not-connected';
                            }

                            if ($live_account_connection_status == 'connected') {
                                //Production account connected
                                echo '<div class="wcpprog-paypal-live-account-status"><span class="dashicons dashicons-yes" style="color:green;"></span>&nbsp;';
                                esc_html_e("Live account is connected. If you experience any issues, please disconnect and reconnect.", "woocommerce-paypal-pro-payment-gateway");
                                echo '</div>';
                                // Show disconnect option for live account.
                                $ppcp_onboarding_instance->output_production_ac_disconnect_link();
                            } else {
                                //Production account is NOT connected.
                                echo '<div class="wcpprog-paypal-live-account-status"><span class="dashicons dashicons-no" style="color: red;"></span>&nbsp;';
                                esc_html_e("Live PayPal account is not connected. Click the button below to authorize the app and acquire API credentials from your PayPal account.", "woocommerce-paypal-pro-payment-gateway");
                                echo '</div>';
                                // Show the onboarding link
                                $ppcp_onboarding_instance->output_production_onboarding_link_code();
                            }
                        } else {
                            echo '<p class="wcpprog_gray_box">';
                            esc_html_e("For live account onboarding, disable the sandbox mode from general settings.", "woocommerce-paypal-pro-payment-gateway");
                            echo '</p>';
                        }
                    } else {
                        if ( $is_sandbox_enabled ) {
                            //Check if the sandbox account is connected
                            $sandbox_account_connection_status = 'connected';
                            if (empty($this->get_option('sandbox_client_id')) || empty($this->get_option('sandbox_client_secret'))) {
                                //Sandbox API keys are missing. Account is not connected.
                                $sandbox_account_connection_status = 'not-connected';
                            }

                            if ($sandbox_account_connection_status == 'connected') {
                                //Test account connected
                                echo '<div class="wcpprog-paypal-sandbox-account-status"><span class="dashicons dashicons-yes" style="color:green;"></span>&nbsp;';
                                esc_html_e("Sandbox account is connected. If you experience any issues, please disconnect and reconnect.", "woocommerce-paypal-pro-payment-gateway");
                                echo '</div>';
                                //Show disconnect option for sandbox account.
                                $ppcp_onboarding_instance->output_sandbox_ac_disconnect_link();
                            } else {
                                //Sandbox account is NOT connected.
                                echo '<div class="wcpprog-paypal-sandbox-account-status"><span class="dashicons dashicons-no" style="color: red;"></span>&nbsp;';
                                esc_html_e("Sandbox PayPal account is not connected.", "woocommerce-paypal-pro-payment-gateway");
                                echo '</div>';
                                //Show the onboarding link for sandbox account.
                                $ppcp_onboarding_instance->output_sandbox_onboarding_link_code();
                            }
                        } else {
                            echo '<p class="wcpprog_gray_box">';
                            esc_html_e("For sandbox account onboarding, enable the sandbox mode from general settings.", "woocommerce-paypal-pro-payment-gateway");
                            echo '</p>';
                            // echo '<button class="button button-primary" disabled>'.__('Get PayPal Sandbox Credentials', 'woocommerce-paypal-pro-payment-gateway').'</button>';
                        }
                    }
                    ?>
                </fieldset>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    public function generate_delete_access_token_cache_html($key, $data){
        $field    = $this->plugin_id . $this->id . '_' . $key;
        $defaults = array(
                'class'             => '',
                'css'               => '',
                'custom_attrs' => array(),
                'desc_tip'          => false,
                'description'       => '',
                'title'             => '',
        );

        $data = wp_parse_args($data, $defaults);

        $ppcp_onboarding_instance = \TTHQ\WC_PP_PRO\Lib\PayPal\Onboarding\PayPal_PPCP_Onboarding::get_instance();

        $output = '';
        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field); ?>"><?php echo wp_kses_post($data['title']); ?></label>
                <?php echo wp_kses_post( $this->get_tooltip_html($data) ); ?>
            </th>
            <td class="forminp">
                <fieldset>
                    <?php $ppcp_onboarding_instance->output_delete_token_cache_button(); ?>
                </fieldset>
            </td>
        <tr>
        <?php

        $output = ob_get_clean();

        return $output;
    }

    public function generate_webhook_status_html($key, $data){
        $field    = $this->plugin_id . $this->id . '_' . $key;

        $mode = isset($data['custom_attrs']['mode'] ) ? sanitize_key($data['custom_attrs']['mode'])  : '';
        $is_live = $mode == 'live' ? true : false;
        $is_live_mode_enabled = $this->get_option('sandbox') != 'yes' ? true : false;

        $to_disable = $is_live ? 'sandbox' : 'live';

        ob_start();
        ?>

        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field); ?>"><?php echo wp_kses_post($data['title']); ?></label>
                <?php echo wp_kses_post( $this->get_tooltip_html($data) ); ?>
            </th>
            <td class="forminp">
                <fieldset>
                    <legend class="screen-reader-text"><span><?php echo wp_kses_post($data['title']); ?></span></legend>
                    <?php
                    if ($is_live_mode_enabled != $is_live) {
                        echo '<p class="wcpprog_gray_box">';
                        /* translators: 1: PayPal environment to connect, 2: Environment to disable in settings. */
                        printf(esc_html__('For %1$s account onboarding, disable the %2$s mode from general settings.', "woocommerce-paypal-pro-payment-gateway"), esc_html( $mode ), esc_html( $to_disable ));
                        echo '</p>';
                    } else {
                        ?>
                        <span class="wcpprog-paypal-ppcp-<?php echo esc_attr( $mode ); ?>-webhook-status">
                            <span class="dashicons dashicons-update"></span>
                            <span class="wcpprog-paypal-ppcp-webhook-statuc-msg"><?php esc_html_e( 'Checking...', 'woocommerce-paypal-pro-payment-gateway' ); ?></span>
                        </span>
                        <p><button style="display: none" class="button wcpprog-paypal-ppcp-create-webhook-btn" data-hook-mode="<?php echo esc_attr( $mode ); ?>"><?php esc_html_e( 'Create Webhook', 'woocommerce-paypal-pro-payment-gateway' ); ?></button></p>
                        <?php
                    }
                    ?>
                </fieldset>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    public function generate_delete_webhooks_html($key, $data){
        ob_start();
        ?>

        <tr>
            <th scope="row"><?php esc_html_e('Delete Webhooks', 'woocommerce-paypal-pro-payment-gateway'); ?></th>
            <td>
                <button type="button" class="button" id="wcpprog-paypal-ppcp-delete-webhook-btn"><?php esc_html_e('Delete Webhooks', 'woocommerce-paypal-pro-payment-gateway'); ?></button>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

}
