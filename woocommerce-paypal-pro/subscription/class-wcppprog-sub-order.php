<?php

// Ensure the WooCommerce order class is loaded first.
if ( ! class_exists( 'WC_Order' ) ) {
	return;
}

class WCPPROG_WC_Subscription_Order extends WC_Order {

	public $order_type = WCPPROG_Subscription_Order_Handler::ORDER_TYPE;

	public function get_type() {
		return WCPPROG_Subscription_Order_Handler::ORDER_TYPE;
	}

	public function set_next_payment_date( $date ) {
		$this->update_meta_data( '_next_payment_date', $date );
	}

	public function get_parent_order_id_ref() {
		return (int) $this->get_meta( '_parent_order_id', true );
	}

	public function get_related_order_ids() {
		$ids = $this->get_meta( '_related_order_ids', true );

		return is_array( $ids ) ? $ids : array();
	}

	public function add_related_order_id( $order_id ) {
		$ids   = $this->get_related_order_ids();
		$ids[] = (int) $order_id;
		$this->update_meta_data( '_related_order_ids', array_unique( $ids ) );
	}

}
