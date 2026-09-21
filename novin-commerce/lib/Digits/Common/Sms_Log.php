<?php

namespace MobinDev\Novin_Commerce\Digits\Common;

/**
 * Records every SMS send attempt (OTP or otherwise) so the admin settings
 * page can show a success/failure report, independent of the core
 * accounting-sync log (SyncLog).
 */
class Sms_Log {

	private static function table() {
		global $wpdb;

		return $wpdb->prefix . 'novin_commerce_digits_sms_log';
	}

	public static function add( $gateway, $phone, $status, $message = '', $raw_response = '' ) {
		global $wpdb;

		$status = in_array( $status, [ 'success', 'error' ], true ) ? $status : 'error';

		return $wpdb->insert(
			self::table(),
			[
				'gateway'      => sanitize_key( $gateway ),
				'phone'        => sanitize_text_field( $phone ),
				'status'       => $status,
				'message'      => sanitize_textarea_field( $message ),
				'raw_response' => sanitize_textarea_field( $raw_response ),
				'created_at'   => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	public static function recent( $limit = 20 ) {
		global $wpdb;

		$limit = min( 100, max( 1, absint( $limit ) ) );
		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is not user input; $limit is cast to int above.
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) );
	}

	public static function counts_since( $seconds = 86400 ) {
		global $wpdb;

		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - absint( $seconds ) );

		$success = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND status = 'success'", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$error   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND status = 'error'", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return [
			'success' => $success,
			'error'   => $error,
		];
	}
}
