<?php

namespace MobinDev\Novin_Commerce\Digits\Common;

/**
 * Encrypt values that must be recoverable by the site (for example an SMS
 * provider credential).
 *
 * The encryption key is deliberately not derived from a database option. It
 * must be supplied by wp-config.php or the process environment as
 * NOVIN_COMMERCE_ENCRYPTION_KEY. A missing key is a configuration error:
 * this class never silently falls back to plaintext or to a predictable site
 * value.
 *
 * New values use authenticated AES-256-GCM. The legacy CBC format and legacy
 * unmarked plaintext are readable only to support a one-time migration; they
 * are never produced by encrypt(). Callers must save a migrated value before
 * considering the migration complete.
 */
class Secret_Crypt {

	const PREFIX        = 'novin-gcm-v1:';
	const LEGACY_PREFIX = 'novin-aes-v1:';
	const KEY_CONSTANT  = 'NOVIN_COMMERCE_ENCRYPTION_KEY';
	const NONCE_LENGTH  = 12;
	const TAG_LENGTH    = 16;

	/**
	 * Whether authenticated encryption can be performed on this site.
	 *
	 * @return bool
	 */
	public static function has_key() {
		return '' !== self::key_material();
	}

	/**
	 * Encrypt a value with AES-256-GCM.
	 *
	 * @param string $plain Plaintext.
	 * @return string|false Marked ciphertext, or false when encryption is not
	 *                     possible. False is intentional fail-closed behavior.
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}

		$key = self::key();
		if ( false === $key || ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'random_bytes' ) ) {
			return false;
		}

		try {
			$nonce = random_bytes( self::NONCE_LENGTH );
		} catch ( \Throwable $exception ) {
			return false;
		}

		$tag       = '';
		$ciphertext = openssl_encrypt(
			$plain,
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			$nonce,
			$tag,
			'',
			self::TAG_LENGTH
		);

		if ( false === $ciphertext || self::TAG_LENGTH !== strlen( (string) $tag ) ) {
			return false;
		}

		return self::PREFIX . base64_encode( $nonce . $tag . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a stored value. Legacy values are returned in memory so the
	 * administrator can replace them, but callers must not persist them again
	 * without passing through encrypt().
	 *
	 * @param string $stored Stored value.
	 * @return string Plaintext, or an empty string when the value is invalid or
	 *                the current key cannot decrypt it.
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored || ! self::has_key() ) {
			// A deployment without its out-of-database key must not expose
			// legacy plaintext or attempt legacy decryption. Callers can show
			// a generic configuration error and preserve the stored value.
			return '';
		}

		if ( 0 === strpos( $stored, self::PREFIX ) ) {
			return self::decrypt_gcm( substr( $stored, strlen( self::PREFIX ) ) );
		}

		if ( 0 === strpos( $stored, self::LEGACY_PREFIX ) ) {
			return self::decrypt_legacy_cbc( substr( $stored, strlen( self::LEGACY_PREFIX ) ) );
		}

		// Values written by the original plugin were plaintext. Keep them
		// readable only in memory to avoid an unexpected credential outage; the
		// settings layer reports that they need migration and never writes them
		// back unchanged.
		return $stored;
	}

	/**
	 * Whether a value is already in the current authenticated format.
	 *
	 * @param string $stored Stored value.
	 * @return bool
	 */
	public static function is_current_format( $stored ) {
		return 0 === strpos( (string) $stored, self::PREFIX );
	}

	/**
	 * Whether a stored value needs replacement with the current format.
	 *
	 * @param string $stored Stored value.
	 * @return bool
	 */
	public static function needs_migration( $stored ) {
		$stored = (string) $stored;
		return '' !== $stored && ! self::is_current_format( $stored );
	}

	private static function key_material() {
		if ( defined( self::KEY_CONSTANT ) ) {
			$value = constant( self::KEY_CONSTANT );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		if ( function_exists( 'getenv' ) ) {
			$value = getenv( self::KEY_CONSTANT );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		return '';
	}

	/**
	 * @return string|false A binary 32-byte key.
	 */
	private static function key() {
		$material = self::key_material();
		return '' === $material ? false : hash( 'sha256', $material, true );
	}

	private static function decrypt_gcm( $payload ) {
		$key = self::key();
		if ( false === $key || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		$raw = base64_decode( (string) $payload, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= self::NONCE_LENGTH + self::TAG_LENGTH ) {
			return '';
		}

		$nonce      = substr( $raw, 0, self::NONCE_LENGTH );
		$tag        = substr( $raw, self::NONCE_LENGTH, self::TAG_LENGTH );
		$ciphertext = substr( $raw, self::NONCE_LENGTH + self::TAG_LENGTH );
		$plain = openssl_decrypt(
			$ciphertext,
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			$nonce,
			$tag,
			'',
			self::TAG_LENGTH
		);

		return false === $plain ? '' : (string) $plain;
	}

	/**
	 * Read the old AES-CBC format so it can be replaced by AES-GCM. This is
	 * intentionally not used as a fallback for new encryption.
	 *
	 * @param string $payload Base64 payload without the legacy prefix.
	 * @return string
	 */
	private static function decrypt_legacy_cbc( $payload ) {
		if ( ! defined( 'AUTH_KEY' ) || ! defined( 'AUTH_SALT' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		$raw = base64_decode( (string) $payload, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= 16 ) {
			return '';
		}

		$key       = hash( 'sha256', (string) AUTH_KEY . (string) AUTH_SALT, true );
		$iv        = substr( $raw, 0, 16 );
		$ciphertext = substr( $raw, 16 );
		$plain     = openssl_decrypt( $ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		return false === $plain ? '' : (string) $plain;
	}
}
