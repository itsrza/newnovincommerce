<?php

namespace MobinDev\Novin_Commerce\Digits\Common;

use MobinDev\Novin_Commerce\Digits\SmsGateways\Gateway_Registry;

/**
 * Handles OTP lifecycle: generate + send, verify, and the rate-limiting
 * rules configured on the Digits settings page (max resend count, max
 * wrong attempts, code lifetime).
 */
class Otp_Manager {

	private static function table() {
		global $wpdb;

		return $wpdb->prefix . 'novin_commerce_digits_otp';
	}

	private static function rate_table() {
		global $wpdb;

		return $wpdb->prefix . 'novin_commerce_digits_rate_limits';
	}

	/**
	 * Generate a numeric OTP code of the configured length. wp_rand() is
	 * backed by the WordPress cryptographic random source; leading zeroes are
	 * retained so every code has the configured length.
	 */
	private static function generate_code() {
		$length = (int) Digits_Settings::get( 'otp_length', 5 );
		$length = max( 4, min( 8, $length ) );
		$max    = ( 10 ** $length ) - 1;

		return str_pad( (string) wp_rand( 0, $max ), $length, '0', STR_PAD_LEFT );
	}

	/**
	 * Hash an OTP for storage. The phone and purpose are included as a domain
	 * separator so a database copy cannot be used to compare a code across
	 * contexts. The hash is intentionally one-way; the code is never stored.
	 */
	private static function hash_code( $phone, $purpose, $code ) {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : '';
		if ( '' === $salt && defined( 'AUTH_KEY' ) ) {
			$salt = (string) AUTH_KEY . ( defined( 'AUTH_SALT' ) ? (string) AUTH_SALT : '' );
		}
		if ( '' === $salt ) {
			return '';
		}
		return hash_hmac( 'sha256', (string) $phone . "\0" . (string) $purpose . "\0" . (string) $code, (string) $salt );
	}

	/**
	 * Atomically increment a rate bucket in the plugin-owned table.
	 *
	 * @param string $key Hashed identity, never a raw phone/IP.
	 * @param int    $max Maximum requests in the window.
	 * @param int    $window Window length in seconds.
	 * @return bool Whether this request is within the limit.
	 */
	private static function allow_rate_request( $key, $max, $window = HOUR_IN_SECONDS ) {
		global $wpdb;

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::rate_table() ) ) );
		if ( '' === $key || self::rate_table() !== $table_exists ) {
			// Fail closed when the migration has not installed the atomic rate
			// table; do not silently fall back to a racy transient.
			return false;
		}

		$now          = time();
		$window_start = gmdate( 'Y-m-d H:i:s', $now - ( $now % max( 1, (int) $window ) ) );
		$table        = self::rate_table();
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (rate_key, window_start, request_count) VALUES (%s, %s, 1)
				ON DUPLICATE KEY UPDATE request_count = IF(window_start < %s, 1, LEAST(request_count + 1, 4294967295)), window_start = IF(window_start < %s, %s, window_start)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is plugin-owned.
				$key,
				$window_start,
				$window_start,
				$window_start,
				$window_start
			)
		);
		if ( false === $result ) {
			return false;
		}

		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT request_count FROM {$table} WHERE rate_key = %s LIMIT 1", $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $count <= max( 1, (int) $max );
	}

	private static function rate_key( $scope, $value ) {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : ( defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : 'novin' );
		return sanitize_key( $scope ) . ':' . hash_hmac( 'sha256', (string) $value, (string) $salt );
	}

	/**
	 * @return array{success:bool,message:string} success:false means the
	 *         OTP was NOT sent (rate-limited or gateway misconfigured/failed);
	 *         the message is meant to be shown to the end user (in Persian).
	 */
	public static function request_otp( $phone, $purpose = 'login' ) {
		global $wpdb;

		$phone   = self::normalize_phone( $phone );
		$purpose = sanitize_key( $purpose );
		$table   = self::table();

		// Both limits use a database atomic increment, not a read-then-write
		// transient. This remains race-safe when several requests arrive at
		// the same time or when an external object cache is not available.
		$ip = self::get_client_ip();
		if ( $ip && ! self::allow_rate_request( self::rate_key( 'ip', $ip ), (int) Digits_Settings::get( 'otp_max_ip_requests_per_hour', 20 ) ) ) {
			return [
				'success' => false,
				'message' => 'تعداد درخواست‌های این اتصال بیش از حد مجاز است. لطفاً کمی بعد دوباره تلاش کنید.',
			];
		}

		$max_resends = min( 255, max( 1, (int) Digits_Settings::get( 'otp_max_resends_per_hour', 5 ) ) );
		if ( ! self::allow_rate_request( self::rate_key( 'phone', $phone ), $max_resends ) ) {
			return [
				'success' => false,
				'message' => 'تعداد درخواست کد تأیید برای این شماره بیش از حد مجاز است. لطفاً کمی بعد دوباره تلاش کنید.',
			];
		}

		$code       = self::generate_code();
		$code_hash  = self::hash_code( $phone, $purpose, $code );
		if ( '' === $code_hash ) {
			return [
				'success' => false,
				'message' => 'تنظیمات امنیتی سایت برای صدور کد تأیید کامل نیست.',
			];
		}
		$ttl        = (int) Digits_Settings::get( 'otp_ttl_seconds', 120 );
		$ttl        = max( 60, $ttl );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $ttl );

		$inserted = $wpdb->insert(
			$table,
			[
				'phone'      => $phone,
				'code_hash'  => $code_hash,
				// Keep the legacy column empty for old installations. It is
				// retained only so the step migration can safely convert old
				// rows without a destructive schema change.
				'code'       => '',
				'purpose'    => $purpose,
				'attempts'   => 0,
				'expires_at' => $expires_at,
				'created_at' => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);

		if ( ! $inserted ) {
			return [
				'success' => false,
				'message' => 'خطای داخلی در ثبت کد تأیید. لطفاً دوباره تلاش کنید.',
			];
		}

		$otp_id = (int) $wpdb->insert_id;
		$result = self::send_code_sms( $phone, $code );
		if ( empty( $result['success'] ) && $otp_id > 0 ) {
			// A failed gateway call must not leave a usable OTP behind. The
			// atomic rate bucket intentionally still counts the attempt.
			$wpdb->delete( $table, [ 'id' => $otp_id ], [ '%d' ] );
		}

		return $result;
	}

	private static function get_client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	private static function send_code_sms( $phone, $code ) {
		$gateway_slug = Digits_Settings::get( 'active_sms_gateway', '' );
		$gateway      = $gateway_slug ? Gateway_Registry::get( $gateway_slug ) : null;

		if ( ! $gateway ) {
			return [
				'success' => false,
				'message' => 'درگاه پیامکی برای ارسال کد تأیید تنظیم نشده است. لطفاً با مدیر سایت تماس بگیرید.',
			];
		}

		$template = Digits_Settings::get( 'otp_message_template', 'کد تأیید شما: {code}' );
		$message  = str_replace( '{code}', $code, $template );

		$settings = Digits_Settings::get_gateway_settings( $gateway_slug );
		$result   = $gateway->send( $phone, $message, $settings );

		Sms_Log::add(
			$gateway_slug,
			$phone,
			$result['success'] ? 'success' : 'error',
			$result['message'],
			$result['raw'] ?? ''
		);

		return [
			'success' => (bool) $result['success'],
			'message' => $result['success']
				? 'کد تأیید ارسال شد.'
				: 'ارسال پیامک ناموفق بود. لطفاً دوباره تلاش کنید یا با پشتیبانی سایت تماس بگیرید.',
		];
	}

	/**
	 * @return array{success:bool,message:string}
	 */
	public static function verify_otp( $phone, $code, $purpose = 'login', $consume = true ) {
		global $wpdb;

		$phone   = self::normalize_phone( $phone );
		$code    = sanitize_text_field( $code );
		$purpose = sanitize_key( $purpose );
		$table   = self::table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE phone = %s AND purpose = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$phone,
				$purpose
			)
		);

		if ( ! $row ) {
			return [
				'success' => false,
				'message' => 'کدی برای این شماره یافت نشد. لطفاً دوباره درخواست کد کنید.',
			];
		}

		$max_attempts = min( 255, max( 1, (int) Digits_Settings::get( 'otp_max_wrong_attempts', 5 ) ) );
		if ( (int) $row->attempts >= $max_attempts ) {
			return [
				'success' => false,
				'message' => 'تعداد تلاش‌های مجاز برای این کد به پایان رسیده است. لطفاً دوباره درخواست کد کنید.',
			];
		}

		if ( strtotime( $row->expires_at . ' UTC' ) < time() ) {
			return [
				'success' => false,
				'message' => 'کد تأیید منقضی شده است. لطفاً دوباره درخواست کد کنید.',
			];
		}

		$expected_hash = self::hash_code( $phone, $purpose, $code );
		$stored_hash   = isset( $row->code_hash ) ? (string) $row->code_hash : '';
		// Rows without a hash are invalid, including legacy plaintext rows. A
		// migration must invalidate them rather than reintroducing plaintext
		// verification into the runtime path.
		$valid = '' !== $stored_hash && '' !== $expected_hash && hash_equals( $stored_hash, $expected_hash );

		if ( ! $valid ) {
			// Increment in SQL so two wrong attempts cannot overwrite one
			// another and accidentally bypass the configured limit.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET attempts = LEAST(attempts + 1, 255) WHERE id = %d AND attempts < %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					(int) $row->id,
					$max_attempts
				)
			);

			return [
				'success' => false,
				'message' => 'کد وارد شده صحیح نیست.',
			];
		}

		// Password-protected login modes validate the password after the OTP.
		// Let the caller defer deletion until both credentials are correct.
		if ( $consume && 1 !== self::consume_otp( (int) $row->id ) ) {
			// Two requests can validate the same row before either reaches the
			// DELETE. Only the request that wins the delete may authenticate.
			return [
				'success' => false,
				'message' => 'کد تأیید قبلاً مصرف شده است. لطفاً کد جدیدی درخواست کنید.',
			];
		}

		return [
			'success' => true,
			'message' => 'کد تأیید صحیح است.',
			'otp_id'  => (int) $row->id,
		];
	}

	/**
	 * Consume a previously validated OTP row.
	 *
	 * @param int $otp_id OTP row ID.
	 * @return int|false
	 */
	public static function consume_otp( $otp_id ) {
		global $wpdb;

		$otp_id = absint( $otp_id );
		if ( ! $otp_id ) {
			return false;
		}

		return $wpdb->delete( self::table(), [ 'id' => $otp_id ], [ '%d' ] );
	}

	/**
	 * Delete expired OTP rows. The extra age condition also cleans up rows
	 * left behind by failed gateway calls or interrupted requests.
	 *
	 * @param int $days Number of days to retain at most.
	 * @return int|false
	 */
	public static function prune( $days = 2 ) {
		global $wpdb;

		$days   = max( 1, absint( $days ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$now    = gmdate( 'Y-m-d H:i:s' );
		$table  = self::table();

		return $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires_at < %s OR created_at < %s", $now, $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Normalize a phone number to digits-only with country code, e.g.
	 * "09123456789" -> "989123456789". This is intentionally simple and
	 * Iran-focused (the plugin's primary market); numbers already
	 * starting with a country code are passed through mostly unchanged.
	 */
	public static function normalize_phone( $phone ) {
		// Convert Persian/Arabic-Indic digits to Latin digits first.
		$persian_digits = [ '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ];
		$arabic_digits  = [ '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' ];
		$latin_digits   = [ '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ];
		$phone          = str_replace( $persian_digits, $latin_digits, (string) $phone );
		$phone          = str_replace( $arabic_digits, $latin_digits, $phone );

		$phone = preg_replace( '/[^0-9]/', '', $phone );
		$phone = (string) $phone;

		$default_country_code = preg_replace( '/[^0-9]/', '', (string) Digits_Settings::get( 'default_country_code', '98' ) );
		$default_country_code = $default_country_code ? $default_country_code : '98';

		if ( 0 === strpos( $phone, '00' ) ) {
			$phone = substr( $phone, 2 );
		}

		if ( 0 === strpos( $phone, '0' ) && strlen( $phone ) === 11 ) {
			// Local format, e.g. 09123456789 -> 989123456789
			$phone = $default_country_code . substr( $phone, 1 );
		} elseif ( 0 !== strpos( $phone, $default_country_code ) && strlen( $phone ) === 10 ) {
			// Bare subscriber number without leading zero or country code.
			$phone = $default_country_code . $phone;
		}

		return $phone;
	}
}
