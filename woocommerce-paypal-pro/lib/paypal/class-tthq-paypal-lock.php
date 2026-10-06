<?php

namespace TTHQ\WC_PP_PRO\Lib\PayPal;

/** Database-backed leases, including recovery of legacy timestamp-only locks. */
class PayPal_Lock {
	const TTL = 300;

	public static function acquire( $key ) {
		global $wpdb;
		$token = time() . ':' . wp_generate_uuid4();
		// Read/write the database directly: an object cache cannot arbitrate leases.
		$inserted = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, $token
		) );
		if ( 1 !== $inserted ) {
			$previous = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
			if ( null === $previous || (int) $previous > time() - self::TTL ) {
				return false;
			}
			// Only one contender can replace the exact expired owner we observed.
			if ( 1 !== $wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s", $token, $key, $previous
			) ) ) {
				return false;
			}
		}
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return $token;
	}

	public static function release( $key, $token ) {
		global $wpdb;
		// A delayed finally/shutdown from an old owner must not delete a new lease.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = %s", $key, $token
		) );
		wp_cache_delete( $key, 'options' );
	}
}
