<?php

trait WCPPROG_Subscription_Related_Trait {

	public function woocommerce_wp_time_period_input(array $field, array $interval_input, array $interval_type_input){
		$field = wp_parse_args(
			$field,
			array(
				'id'             => '',
				'label'             => '',
				'description'             => '',
				'class'             => 'short',
				'style'             => 'display: inline-flex; width: 100%',
				'wrapper_class'     => '',
				'desc_tip'          => false,
				'custom_attributes' => array(),
			)
		);

		echo '<p class="form-field ' . esc_attr( $field['id'] ) . '_field ' . esc_attr( $field['wrapper_class'] ) . '">
		<label for="' . esc_attr( $field['id'] ) . '">' . wp_kses_post( $field['label'] ) . '</label>';

		$help_tip    = null;
		$description = null;
		if ( ! empty( $field['description'] ) ) {
			if ( is_array( $field['description'] ) ) {
				$help_tip    = reset( $field['description'] );
				$description = end( $field['description'] );
			} elseif ( false !== $field['desc_tip'] ) {
				$help_tip = $field['description'];
			} else {
				$description = $field['description'];
			}
		}

		if ( ! is_null( $help_tip ) ) {
			echo wp_kses_post( wc_help_tip( $help_tip ) );
		}

		echo '<span class="' . esc_attr( $field['class'] ) . '" style="' . esc_attr( $field['style'] ) . '">';
		$this->woocommerce_wp_text_input($interval_input);
		$this->woocommerce_wp_select($interval_type_input);
		echo '</span>';

		if ( ! is_null( $description ) ) {
			$hidden_class = true === ( $field['description_hidden'] ?? false ) ? ' hidden' : '';
			//phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<span class="description' . $hidden_class . '">' . wp_kses_post( $description ) . '</span>';
		}

		echo '</p>';
	}

	/**
	 * Output a text input box.
	 *
	 * @param array        $field Field data.
	 * @param WC_Data|null $data  WC_Data object, will be preferred over post object when passed.
	 */
	public function woocommerce_wp_text_input($field, ?WC_Data $data = null) {
		global $post;

		$field['placeholder']   = isset( $field['placeholder'] ) ? $field['placeholder'] : '';
		$field['class']         = isset( $field['class'] ) ? $field['class'] : 'short';
		$field['style']         = isset( $field['style'] ) ? $field['style'] : 'width: 26%; margin-right: 3%';
		$field['wrapper_class'] = isset( $field['wrapper_class'] ) ? $field['wrapper_class'] : '';
		$field['value']         = $field['value'] ?? \Automattic\WooCommerce\Utilities\OrderUtil::get_post_or_object_meta( $post, $data, $field['id'], true );
		$field['name']          = isset( $field['name'] ) ? $field['name'] : $field['id'];
		$field['type']          = isset( $field['type'] ) ? $field['type'] : 'number';
		$field['desc_tip']      = isset( $field['desc_tip'] ) ? $field['desc_tip'] : false;

        $default_custom_attributes = array( 'min'  => 0, 'step' => 1 );

		$field['custom_attributes'] = isset( $field['custom_attributes'] ) ? wp_parse_args($field['custom_attributes'], $default_custom_attributes) : $default_custom_attributes;

		$data_type              = empty( $field['data_type'] ) ? '' : $field['data_type'];

		switch ( $data_type ) {
			case 'price':
				$field['class'] .= ' wc_input_price';
				$field['value']  = wc_format_localized_price( $field['value'] );
				break;
			case 'decimal':
				$field['class'] .= ' wc_input_decimal';
				$field['value']  = wc_format_localized_decimal( $field['value'] );
				break;
			case 'stock':
				$field['class'] .= ' wc_input_stock';
				$field['value']  = wc_stock_amount( $field['value'] );
				break;
			case 'url':
				$field['class'] .= ' wc_input_url';
				$field['value']  = esc_url( $field['value'] );
				break;

			default:
				break;
		}

		echo '<input type="' . esc_attr( $field['type'] ) . '" class="' . esc_attr( $field['class'] ) . '" style="' . esc_attr( $field['style'] ) . '" name="' . esc_attr( $field['name'] ) . '" id="' . esc_attr( $field['id'] ) . '" value="' . esc_attr( $field['value'] ) . '" placeholder="' . esc_attr( $field['placeholder'] ) . '"';
		foreach ( (array) $field['custom_attributes'] as $attribute => $value ) {
			echo ' ' . esc_attr( $attribute ) . '="' . esc_attr( $value ) . '"';
		}
		echo ' /> ';

	}

	/**
	 * Output a select input box.
	 *
	 * @param array        $field Field data.
	 * @param WC_Data|null $data  WC_Data object, will be preferred over post object when passed.
	 */
	public function woocommerce_wp_select( $field, ?WC_Data $data = null ) {
		global $post;

		$field = wp_parse_args(
			$field,
			array(
				'class'             => 'select short',
				'style'             => 'width: 26%',
				'wrapper_class'     => '',
				'value'             => \Automattic\WooCommerce\Utilities\OrderUtil::get_post_or_object_meta( $post, $data, $field['id'], true ),
				'name'              => $field['id'],
				'desc_tip'          => false,
				'options'          => array(
					'day'   => __( 'Day', 'woocommerce-paypal-pro-payment-gateway' ),
					'week'  => __( 'Week', 'woocommerce-paypal-pro-payment-gateway' ),
					'month' => __( 'Month', 'woocommerce-paypal-pro-payment-gateway' ),
					'year'  => __( 'Year', 'woocommerce-paypal-pro-payment-gateway' ),
				),
				'custom_attributes' => array(),
			)
		);

		$wrapper_attributes = array(
			'class' => $field['wrapper_class'] . " form-field {$field['id']}_field",
		);

		$label_attributes = array(
			'for' => $field['id'],
		);

		$field_attributes          = (array) $field['custom_attributes'];
		$field_attributes['style'] = $field['style'];
		$field_attributes['id']    = $field['id'];
		$field_attributes['name']  = $field['name'];
		$field_attributes['class'] = $field['class'];

		$tooltip     = ! empty( $field['description'] ) && false !== $field['desc_tip'] ? $field['description'] : '';
		$description = ! empty( $field['description'] ) && false === $field['desc_tip'] ? $field['description'] : '';
		?>

		<select <?php echo wc_implode_html_attributes( $field_attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_implode_html_attributes escapes attribute names and values. ?>>
			<?php
			foreach ( $field['options'] as $key => $value ) {
				echo '<option value="' . esc_attr( $key ) . '"' . selected( is_array( $field['value'] ) ? in_array( (string) $key, array_map( 'strval', $field['value'] ), true ) : (string) $key === (string) $field['value'], true, false ) . '>' . esc_html( $value ) . '</option>';
			}
			?>
		</select>

		<?php
	}

}
