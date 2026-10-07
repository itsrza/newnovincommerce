<?php

namespace Novinwp\Novin_Commerce\Digits\SmsGateways;

/**
 * NPSMS (npsms.com) gateway.
 *
 * Sends SMS via a simple GET request to:
 *   https://npsms.com/sendSmsViaURL.aspx?domainName=...&userName=...&password=...
 *       &reciverNumber=...&senderNumber=...&smsText=...
 *
 * All parameter values (domain name, username, password, sender number,
 * and the message text) are user-provided or dynamic, so every one of
 * them is passed through rawurlencode() before being placed in the
 * query string. This keeps Persian text in the message, and any special
 * characters in the password, from corrupting the request.
 */
class Npsms_Gateway implements Sms_Gateway_Interface {

	const ENDPOINT = 'https://npsms.com/sendSmsViaURL.aspx';

	public function get_slug() {
		return 'npsms';
	}

	public function get_label() {
		return 'NPSMS (npsms.com)';
	}

	public function get_settings_fields() {
		return [
			[
				'key'         => 'domain_name',
				'label'       => 'نام دامنه (Domain Name)',
				'type'        => 'text',
				'default'     => '',
				'description' => 'مقدار اختصاصی حساب کاربری شما در NPSMS (پارامتر domainName).',
			],
			[
				'key'         => 'username',
				'label'       => 'نام کاربری (Username)',
				'type'        => 'text',
				'default'     => '',
				'description' => 'نام کاربری پنل NPSMS شما.',
			],
			[
				'key'         => 'password',
				'label'       => 'رمز عبور (Password)',
				'type'        => 'password',
				'default'     => '',
				'description' => 'رمز عبور پنل NPSMS شما.',
			],
			[
				'key'         => 'sender_number',
				'label'       => 'شماره فرستنده (Sender Number)',
				'type'        => 'text',
				'default'     => '30006403868611',
				'description' => 'شماره خط ارسال‌کننده پیامک. مقدار پیش‌فرض قابل ویرایش است.',
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function send( $to, $message, array $settings ) {
		$domain_name   = isset( $settings['domain_name'] ) ? (string) $settings['domain_name'] : '';
		$username      = isset( $settings['username'] ) ? (string) $settings['username'] : '';
		$password      = isset( $settings['password'] ) ? (string) $settings['password'] : '';
		$sender_number = isset( $settings['sender_number'] ) && '' !== $settings['sender_number']
			? (string) $settings['sender_number']
			: '30006403868611';

		if ( '' === $domain_name || '' === $username || '' === $password ) {
			return [
				'success' => false,
				'message' => 'تنظیمات درگاه NPSMS ناقص است (نام دامنه، نام کاربری یا رمز عبور خالی است).',
				'raw'     => '',
			];
		}

		$query = [
			'domainName'    => $domain_name,
			'userName'      => $username,
			'password'      => $password,
			'reciverNumber' => $to,
			'senderNumber'  => $sender_number,
			'smsText'       => $message,
		];

		// Build the query string manually with rawurlencode() on every
		// value (including the password) rather than relying solely on
		// add_query_arg()/http_build_query() defaults, so behavior stays
		// explicit and predictable regardless of WordPress/PHP versions.
		$pairs = [];
		foreach ( $query as $key => $value ) {
			$pairs[] = $key . '=' . rawurlencode( (string) $value );
		}
		$url = self::ENDPOINT . '?' . implode( '&', $pairs );

		$response = wp_remote_get(
			$url,
			[
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'message' => 'خطا در برقراری ارتباط با NPSMS: ' . $response->get_error_message(),
				'raw'     => $response->get_error_message(),
			];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		// A 200 response alone is not proof that NPSMS accepted the SMS: an
		// ASP.NET error/login form can also be returned with status 200. Accept
		// numeric result codes, or an HTML response only when it contains an
		// explicit success marker and no failure marker.
		$trimmed_body = trim( (string) $body );
		$decoded_body = html_entity_decode( $trimmed_body, ENT_QUOTES, 'UTF-8' );
		$is_numeric_success = '' !== $trimmed_body
			&& is_numeric( $trimmed_body )
			&& (float) $trimmed_body >= 0;
		$is_legacy_form = false !== stripos( $decoded_body, 'sendSmsViaURL.aspx' )
			&& ( false !== stripos( $decoded_body, 'reciverNumber' ) || false !== stripos( $decoded_body, '<form' ) );
		$has_success_marker = (bool) preg_match( '/(?:\bsuccess(?:ful)?\b|\bsent\b|\baccepted\b|\bmessage\s*id\b|\bsms\s*id\b|ارسال\s*(?:شد|موفق)|موفق)/iu', $decoded_body );
		$has_failure_marker = (bool) preg_match( '/(?:\berror\b|\bfailed\b|\bfailure\b|\binvalid\b|\bincorrect\b|نامعتبر|خطا|ناموفق|اشتباه)/iu', $decoded_body );
		$is_legacy_form_success = $is_legacy_form && $has_success_marker && ! $has_failure_marker;
		$is_success = 200 === (int) $code && ( $is_numeric_success || $is_legacy_form_success );
		$safe_body = $this->redact_sensitive_response( $trimmed_body );

		return [
			'success' => $is_success,
			'message' => $is_success
				? ( $is_legacy_form_success && ! $is_numeric_success
					? 'پیامک با موفقیت ارسال شد (پاسخ سازگار NPSMS دریافت شد).'
					: 'پیامک با موفقیت ارسال شد (پاسخ سرور: ' . $safe_body . ').' )
				: 'ارسال پیامک ناموفق بود. کد HTTP: ' . (int) $code . '.',
			'raw'     => $safe_body,
		];
	}

	/**
	 * NPSMS echoes the request query in its HTML response. Never persist or
	 * display the gateway password from that response in the SMS log.
	 */
	private function redact_sensitive_response( $body ) {
		$body = (string) $body;
		$redacted = preg_replace(
			'/(password|passwd)(?:=|%3D)[^&\\s"<>]+/i',
			'$1=[redacted]',
			$body
		);
		$redacted = null === $redacted ? $body : $redacted;

		// Some NPSMS responses echo the query as HTML input elements rather
		// than as a URL. Redact both common attribute orderings as well.
		$redacted = preg_replace(
			"~(name\\s*=\\s*[\"'](?:password|passwd)[\"'][^>]*value\\s*=\\s*[\"'])[^\"']*([\"'])~i",
			'$1[redacted]$2',
			$redacted
		);
		$redacted = preg_replace(
			"~(value\\s*=\\s*[\"'])[^\"']*([\"'][^>]*name\\s*=\\s*[\"'](?:password|passwd)[\"'])~i",
			'$1[redacted]$2',
			$redacted
		);

		return null === $redacted ? $body : $redacted;
	}
}
