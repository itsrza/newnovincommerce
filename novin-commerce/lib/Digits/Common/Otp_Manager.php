<?php

namespace Novinwp\Novin_Commerce\Digits\Common;

use Novinwp\Novin_Commerce\Digits\SmsGateways\Gateway_Registry;

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

	/**
	 * Generate a numeric OTP code of the configured length.
	 */
	private static function generate_code() {
		$length = (int) Digits_Settings::get( 'otp_length', 5 );
		$length = max( 4, min( 8, $length ) );

		$min = (int) str_pad( '1', $length, '0' );
		$max = (int) str_pad( '', $length, '9' );

		return (string) wp_rand( $min, $max );
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

		// Apply a second, independent limit by source IP. A phone-only limit
		// can still be abused by rotating through thousands of numbers.
		if ( self::is_ip_rate_limited() ) {
			return [
				'success' => false,
				'message' => 'تعداد درخواست‌های این اتصال بیش از حد مجاز است. لطفاً کمی بعد دوباره تلاش کنید.',
			];
		}
		self::record_ip_request();

		// Rate-limit: how many codes were requested for this phone number
		// in the last hour, regardless of purpose.
		$resend_window_seconds = 3600;
		$max_resends           = max( 1, (int) Digits_Settings::get( 'otp_max_resends_per_hour', 5 ) );
		$since                 = gmdate( 'Y-m-d H:i:s', time() - $resend_window_seconds );

		$recent_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE phone = %s AND created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$phone,
				$since
			)
		);

		if ( $recent_count >= $max_resends ) {
			return [
				'success' => false,
				'message' => 'تعداد درخواست کد تأیید برای این شماره بیش از حد مجاز است. لطفاً کمی بعد دوباره تلاش کنید.',
			];
		}

		$code       = self::generate_code();
		$ttl        = (int) Digits_Settings::get( 'otp_ttl_seconds', 120 );
		$ttl        = max( 60, $ttl );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $ttl );

		$inserted = $wpdb->insert(
			$table,
			[
				'phone'      => $phone,
				'code'       => $code,
				'purpose'    => $purpose,
				'attempts'   => 0,
				'expires_at' => $expires_at,
				'created_at' => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%s', '%d', '%s', '%s' ]
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
			// A failed gateway call must not leave a usable OTP behind or
			// consume one of the user's hourly resend slots.
			$wpdb->delete( $table, [ 'id' => $otp_id ], [ '%d' ] );
		}

		return $result;
	}

	private static function get_client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	private static function ip_transient_key() {
		$ip = self::get_client_ip();
		return $ip ? 'novin_digits_otp_ip_' . md5( $ip ) : '';
	}

	private static function is_ip_rate_limited() {
		$key = self::ip_transient_key();
		if ( '' === $key ) {
			return false;
		}

		$max = max( 1, (int) Digits_Settings::get( 'otp_max_ip_requests_per_hour', 20 ) );
		$log = get_transient( $key );
		$log = is_array( $log ) ? array_filter( $log, function ( $timestamp ) {
			return (int) $timestamp > time() - HOUR_IN_SECONDS;
		} ) : [];

		return count( $log ) >= $max;
	}

	private static function record_ip_request() {
		$key = self::ip_transient_key();
		if ( '' === $key ) {
			return;
		}

		$log = get_transient( $key );
		$log = is_array( $log ) ? array_filter( $log, function ( $timestamp ) {
			return (int) $timestamp > time() - HOUR_IN_SECONDS;
		} ) : [];
		$log[] = time();
		set_transient( $key, array_values( $log ), HOUR_IN_SECONDS );
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

		$max_attempts = max( 1, (int) Digits_Settings::get( 'otp_max_wrong_attempts', 5 ) );
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

		if ( ! hash_equals( (string) $row->code, $code ) ) {
			$wpdb->update(
				$table,
				[ 'attempts' => (int) $row->attempts + 1 ],
				[ 'id' => (int) $row->id ],
				[ '%d' ],
				[ '%d' ]
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
