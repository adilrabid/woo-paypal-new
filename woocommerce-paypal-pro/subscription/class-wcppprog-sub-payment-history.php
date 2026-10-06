<?php

defined( 'ABSPATH' ) || exit;

/** Persist only the fields displayed in a subscription's received payments table. */
class WCPPROG_Subscription_Payment_History {
    const META_PREFIX = '_wcppprog_payment_snapshot_';
    private static $deleting = array();
    private static $refund_parents = array();

    public static function init() {
        add_action( 'woocommerce_after_order_object_save', array( __CLASS__, 'order_saved' ) );
        add_action( 'woocommerce_after_order_refund_object_save', array( __CLASS__, 'refund_saved' ) );
        add_action( 'woocommerce_order_refunded', array( __CLASS__, 'refresh_order' ) );
        add_action( 'woocommerce_refund_deleted', array( __CLASS__, 'refund_deleted' ), 20, 2 );
        // WooCommerce CRUD hooks cover HPOS; post hooks cover legacy admin deletion.
        foreach ( array( 'woocommerce_before_delete_order', 'before_delete_post' ) as $hook ) {
            add_action( $hook, array( __CLASS__, 'before_delete' ), 5 );
        }
        foreach ( array( 'woocommerce_before_trash_order', 'wp_trash_post' ) as $hook ) {
            add_action( $hook, array( __CLASS__, 'refresh_order' ), 5 );
        }
        foreach ( array( 'woocommerce_delete_order', 'woocommerce_delete_order_refund', 'deleted_post' ) as $hook ) {
            add_action( $hook, array( __CLASS__, 'after_delete' ), 20 );
        }
    }

    public static function snapshot( $payment, $subscription ) {
        $date = $payment->get_date_paid();
        $created = $payment->get_date_created();
        $refunds = array();
        // Subscription admin screens filter the default order statuses. Refunds
        // always use completed; query explicitly instead of using that filter/cache.
        foreach ( wc_get_orders( array(
            'type' => 'shop_order_refund',
            'parent' => $payment->get_id(),
            'status' => 'wc-completed',
            'limit' => -1,
        ) ) as $refund ) {
            $refund_date = $refund->get_date_created();
            $refunds[] = array(
                'id' => $refund->get_meta( '_wcppprog_paypal_refund_id', true ) ?: ( '#' . $refund->get_id() ),
                'date' => $refund_date ? $refund_date->getTimestamp() : 0,
                'amount' => $refund->get_amount(),
            );
        }
        return array(
            'order_id' => $payment->get_id(),
            'order_number' => $payment->get_order_number(),
            'initial' => $payment->get_id() === $subscription->get_parent_order_id_ref(),
            'date' => $date ? $date->getTimestamp() : 0,
            'sort_date' => $date ? $date->getTimestamp() : ( $created ? $created->getTimestamp() : 0 ),
            'status' => $payment->get_status(),
            'payment_method' => $payment->get_payment_method_title(),
            'transaction_id' => $payment->get_transaction_id() ?: $payment->get_meta( '_paypal_transaction_id', true ),
            'amount' => $payment->get_total(),
            'currency' => $payment->get_currency(),
            'refunds' => $refunds,
            'received' => (float) $payment->get_total() > 0 && ( $date || $payment->is_paid() || $refunds ),
        );
    }

    public static function save_snapshot( $payment, $subscription ) {
        $key = self::META_PREFIX . $payment->get_id();
        $snapshot = self::snapshot( $payment, $subscription );
        if ( $subscription->get_meta( $key, true ) !== $snapshot ) {
            // Separate metadata per payment avoids overwriting another renewal's history.
            $subscription->update_meta_data( $key, $snapshot );
            $subscription->save_meta_data();
        }
        return $snapshot;
    }

    public static function order_saved( $order ) {
        if ( 'shop_order' !== $order->get_type() || $order->has_status( 'trash' ) || isset( self::$deleting[ $order->get_id() ] ) ) {
            return;
        }
        $subscription_id = absint( $order->get_meta( '_wcpprog_subscription_order_id', true ) );
        $subscription = $subscription_id ? wc_get_order( $subscription_id ) : false;
        if ( $subscription && WCPPROG_Subscription_Order_Handler::ORDER_TYPE === $subscription->get_type() ) {
            self::save_snapshot( $order, $subscription );
        }
    }

    public static function refresh_order( $id ) {
        if ( isset( self::$deleting[ $id ] ) ) {
            return;
        }
        $order = wc_get_order( $id );
        if ( $order ) {
            self::order_saved( $order );
        }
    }

    public static function refund_saved( $refund ) {
        self::refresh_order( $refund->get_parent_id() );
    }

    public static function refund_deleted( $refund_id, $order_id ) {
        self::refresh_order( $order_id );
    }

    public static function before_delete( $id ) {
        $order = wc_get_order( $id );
        if ( ! $order ) {
            return;
        }
        if ( 'shop_order_refund' === $order->get_type() ) {
            self::$refund_parents[ $id ] = $order->get_parent_id();
        } elseif ( 'shop_order' === $order->get_type() ) {
            self::refresh_order( $id );
            // Child refund deletion must not erase the final snapshot of this payment.
            self::$deleting[ $id ] = true;
        }
    }

    public static function after_delete( $id ) {
        if ( isset( self::$refund_parents[ $id ] ) ) {
            $parent_id = self::$refund_parents[ $id ];
            unset( self::$refund_parents[ $id ] );
            self::refresh_order( $parent_id );
        }
    }

    public static function get_rows( $subscription ) {
        $snapshots = array();
        foreach ( $subscription->get_meta_data() as $meta ) {
            if ( 0 === strpos( $meta->key, self::META_PREFIX ) && is_array( $meta->value ) && ! empty( $meta->value['order_id'] ) ) {
                $snapshots[ (int) $meta->value['order_id'] ] = $meta->value;
            }
        }
        $ids = array_merge( array( $subscription->get_parent_order_id_ref() ), $subscription->get_related_order_ids(), array_keys( $snapshots ), wc_get_orders( array(
            'type' => 'shop_order', 'limit' => -1, 'return' => 'ids',
            'meta_key' => '_wcpprog_subscription_order_id', 'meta_value' => $subscription->get_id(),
        ) ) );
        $rows = array();
        foreach ( array_unique( array_filter( array_map( 'absint', $ids ) ) ) as $id ) {
            $payment = wc_get_order( $id );
            $url = '';
            if ( $payment && 'shop_order' === $payment->get_type() && ! $payment->has_status( 'trash' ) ) {
                // Also backfill existing subscriptions while their linked orders still exist.
                $snapshot = self::save_snapshot( $payment, $subscription );
                $url = $payment->get_edit_order_url();
            } else {
                $snapshot = $snapshots[ $id ] ?? array();
            }
            if ( ! empty( $snapshot['received'] ) ) {
                $snapshot['edit_url'] = $url;
                $rows[] = $snapshot;
            }
        }
        usort( $rows, static function ( $a, $b ) { return $b['sort_date'] <=> $a['sort_date']; } );
        return $rows;
    }

    public static function format_date( $timestamp ) {
        return $timestamp ? wp_date( wc_date_format() . ' ' . wc_time_format(), $timestamp ) : '—';
    }
}
