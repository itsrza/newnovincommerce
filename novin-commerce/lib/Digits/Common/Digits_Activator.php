<?php

namespace MobinDev\Novin_Commerce\Digits\Common;

/**
 * Step-based, retry-safe schema owner for the Digits module.
 *
 * Migrations run during activation/admin bootstrap, never from a normal
 * frontend page view. A schema version advances only after the required
 * table/column/index checks pass, so an interrupted upgrade can safely retry.
 */
class Digits_Activator {

	const SCHEMA_VERSION_OPTION = 'novin_commerce_digits_schema_version';
	const CURRENT_SCHEMA        = 4;

	public static function maybe_upgrade() {
		global $wpdb;

		if ( ! $wpdb || ! isset( $wpdb->prefix ) ) {
			return false;
		}

		$version = (int) get_option( self::SCHEMA_VERSION_OPTION, 0 );
		$steps   = [
			1 => [ self::class, 'step_create_tables' ],
			2 => [ self::class, 'step_add_otp_hash' ],
			3 => [ self::class, 'step_add_rate_limits' ],
			4 => [ self::class, 'step_harden_existing_rows' ],
		];

		for ( $next = $version + 1; $next <= self::CURRENT_SCHEMA; $next++ ) {
			if ( ! isset( $steps[ $next ] ) || ! call_user_func( $steps[ $next ] ) || ! self::verify_schema( $next ) ) {
				// Do not claim success. The next admin/activation request retries
				// the same step.
				return false;
			}
			if ( ! update_option( self::SCHEMA_VERSION_OPTION, (string) $next, false ) ) {
				// update_option() returns false when the value is unchanged; the
				// schema is still verified, so only treat a real persistence
				// failure as fatal when a read-back disagrees.
				if ( (string) get_option( self::SCHEMA_VERSION_OPTION, '' ) !== (string) $next ) {
					return false;
				}
			}
		}

		return self::verify_schema( self::CURRENT_SCHEMA );
	}

	private static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . $name;
	}

	private static function charset() {
		global $wpdb;
		if ( method_exists( $wpdb, 'get_charset_collate' ) ) {
			return $wpdb->get_charset_collate();
		}
		return 'DEFAULT CHARACTER SET utf8mb4';
	}

	private static function dbdelta( $sql ) {
		if ( ! function_exists( 'dbDelta' ) ) {
			$path = defined( 'ABSPATH' ) ? ABSPATH . 'wp-admin/includes/upgrade.php' : '';
			if ( $path && file_exists( $path ) ) {
				require_once $path;
			}
		}
		return function_exists( 'dbDelta' ) ? dbDelta( $sql, false ) : false;
	}

	private static function step_create_tables() {
		$otp = self::table( 'novin_commerce_digits_otp' );
		$sms = self::table( 'novin_commerce_digits_sms_log' );
		$rate = self::table( 'novin_commerce_digits_rate_limits' );

		$otp_sql = "CREATE TABLE {$otp} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			phone varchar(32) NOT NULL,
			code varchar(10) NOT NULL DEFAULT '',
			code_hash char(64) NOT NULL DEFAULT '',
			purpose varchar(30) NOT NULL DEFAULT 'login',
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			expires_at datetime NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY phone_purpose (phone,purpose),
			KEY expires_at (expires_at),
			KEY created_at (created_at)
		) " . self::charset() . ';';
		$sms_sql = "CREATE TABLE {$sms} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			gateway varchar(30) NOT NULL,
			phone varchar(32) NOT NULL,
			status varchar(20) NOT NULL,
			message text NULL,
			raw_response text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status_created (status,created_at)
		) " . self::charset() . ';';
		$rate_sql = "CREATE TABLE {$rate} (
			rate_key char(70) NOT NULL,
			window_start datetime NOT NULL,
			request_count bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (rate_key),
			KEY window_start (window_start)
		) " . self::charset() . ';';

		return false !== self::dbdelta( $otp_sql )
			&& false !== self::dbdelta( $sms_sql )
			&& false !== self::dbdelta( $rate_sql );
	}

	private static function step_add_otp_hash() {
		global $wpdb;
		$table = self::table( 'novin_commerce_digits_otp' );
		if ( ! self::table_exists( $table ) ) {
			return false;
		}
		if ( ! self::column_exists( $table, 'code_hash' ) ) {
			$result = $wpdb->query( "ALTER TABLE {$table} ADD COLUMN code_hash char(64) NOT NULL DEFAULT '' AFTER code" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared
			if ( false === $result ) {
				return false;
			}
		}
		return true;
	}

	private static function step_add_rate_limits() {
		return self::step_create_tables();
	}

	private static function step_harden_existing_rows() {
		// Existing plaintext OTP rows are deliberately not guessed or logged.
		// They are invalidated; new requests use code_hash only. This avoids
		// ever copying a one-time secret into a second representation.
		global $wpdb;
		$table = self::table( 'novin_commerce_digits_otp' );
		if ( ! self::table_exists( $table ) || ! self::column_exists( $table, 'code_hash' ) ) {
			return false;
		}
		$result = $wpdb->query( "UPDATE {$table} SET code = '' WHERE code_hash <> '' OR code <> ''" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
		$sms_table  = self::table( 'novin_commerce_digits_sms_log' );
		$log_result = $wpdb->query( "UPDATE {$sms_table} SET raw_response = '', phone = CONCAT('***', RIGHT(phone, 2)) WHERE raw_response <> '' OR phone NOT LIKE '***%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
		return false !== $result && false !== $log_result;
	}

	private static function verify_schema( $version ) {
		$otp = self::table( 'novin_commerce_digits_otp' );
		$sms = self::table( 'novin_commerce_digits_sms_log' );
		if ( ! self::table_exists( $otp ) || ! self::table_exists( $sms ) ) {
			return false;
		}
		if ( $version >= 2 && ! self::column_exists( $otp, 'code_hash' ) ) {
			return false;
		}
		if ( $version >= 3 && ! self::table_exists( self::table( 'novin_commerce_digits_rate_limits' ) ) ) {
			return false;
		}
		return true;
	}

	private static function table_exists( $table ) {
		global $wpdb;
		$like = $wpdb->esc_like( $table );
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
	}

	private static function column_exists( $table, $column ) {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
				$table,
				$column
			)
		);
	}
}
