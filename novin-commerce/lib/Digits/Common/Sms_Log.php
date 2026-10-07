<?php

namespace MobinDev\Novin_Commerce\Digits\Common;

use MobinDev\Novin_Commerce\Common\Text_Encoding;

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
				// Keep only a masked destination in the operational log. The
				// canonical phone and OTP remain outside this log table.
				'phone'        => self::mask_phone( sanitize_text_field( $phone ) ),
				'status'       => $status,
				'message'      => sanitize_textarea_field( Text_Encoding::normalize( $message ) ),
				// Provider responses are untrusted and can echo credentials or
				// request data. The argument is retained for API compatibility,
				// but is deliberately never persisted.
				'raw_response' => '',
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
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) );
		foreach ( (array) $rows as $row ) {
			if ( isset( $row->phone ) ) {
				$row->phone = self::mask_phone( $row->phone );
			}
			if ( isset( $row->message ) ) {
				$row->message = Text_Encoding::normalize( $row->message );
			}
			if ( isset( $row->raw_response ) ) {
				// Legacy rows may contain a provider payload. Never expose it
				// through the admin/API reader, even before migration cleanup.
				$row->raw_response = '';
			}
		}
		return $rows;
	}

	private static function mask_phone( $phone ) {
		$phone = (string) $phone;
		if ( 0 === strpos( $phone, '***' ) ) {
			return substr( $phone, 0, 5 );
		}
		$length = strlen( $phone );
		if ( $length <= 4 ) {
			return str_repeat( '*', $length );
		}

		return substr( $phone, 0, 3 ) . str_repeat( '*', max( 1, $length - 5 ) ) . substr( $phone, -2 );
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

	/**
	 * Remove old SMS log rows while retaining a useful operational history.
	 *
	 * @param int $days Number of days to keep.
	 * @return int|false
	 */
	public static function prune( $days = 30 ) {
		global $wpdb;

		$days   = max( 1, absint( $days ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$table  = self::table();

		return $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
