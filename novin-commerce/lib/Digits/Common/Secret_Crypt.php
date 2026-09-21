<?php

namespace MobinDev\Novin_Commerce\Digits\Common;

/**
 * Small helper for encrypting/decrypting sensitive settings values
 * (currently: SMS gateway passwords) before they are stored in the
 * database, instead of keeping them as plain text inside a WordPress
 * option like every other setting.
 *
 * This intentionally does NOT introduce a new secret to manage: it
 * derives its key from WordPress's own AUTH_KEY/AUTH_SALT constants
 * (already present in every install's wp-config.php), the same trust
 * boundary WordPress itself relies on for cookie/session secrets.
 *
 * This is meant to stop a password from sitting in the database in
 * plain text (e.g. visible in a raw DB dump or backup) — it is not a
 * substitute for restricting DB/admin access.
 */
class Secret_Crypt {

	private static function get_key() {
		$key_material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' );
		if ( '' === $key_material ) {
			// Extremely unlikely on a real WordPress install, but keep a
			// stable per-site fallback so encryption never hard-fails.
			$key_material = get_site_url() . DB_NAME;
		}

		return hash( 'sha256', $key_material, true );
	}

	/**
	 * @param string $plain
	 * @return string Base64-encoded ciphertext, or '' if input was empty.
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			// No OpenSSL available: fall back to storing as-is rather than
			// silently losing the value. This mirrors how the plugin
			// already stores api_pass in SettingAPI without encryption.
			return $plain;
		}

		$iv        = openssl_random_pseudo_bytes( 16 );
		$encrypted = openssl_encrypt( $plain, 'aes-256-cbc', self::get_key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $encrypted ) {
			return $plain;
		}

		return base64_encode( $iv . $encrypted ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * @param string $stored
	 * @return string Decrypted plain text, or '' if input was empty/invalid.
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}

		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return $stored;
		}

		$raw = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= 16 ) {
			// Not a value we encrypted (e.g. legacy plain-text value from
			// before this feature existed) — return as-is so existing
			// settings don't suddenly break.
			return $stored;
		}

		$iv        = substr( $raw, 0, 16 );
		$cipher    = substr( $raw, 16 );
		$decrypted = openssl_decrypt( $cipher, 'aes-256-cbc', self::get_key(), OPENSSL_RAW_DATA, $iv );

		return false === $decrypted ? '' : $decrypted;
	}
}
