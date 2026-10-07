<?php

namespace MobinDev\Novin_Commerce\Digits\Common;

/**
 * Settings storage for the Digits (mobile signup/login) module.
 *
 * Deliberately uses its own WordPress option (novin_commerce_digits_settings)
 * rather than the core plugin's SettingAPI option, so this module stays a
 * self-contained, removable unit that never touches accounting-sync settings.
 */
class Digits_Settings {

	const OPTION_KEY = 'novin_commerce_digits_settings';

	private static $cache = null;

	private static function all() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION_KEY, [] );
			self::$cache = is_array( $stored ) ? $stored : [];
		}

		return self::$cache;
	}

	public static function get( $key, $default = false ) {
		$all = self::all();

		return $all[ $key ] ?? $default;
	}

	public static function set( $key, $value ) {
		self::$cache         = self::all();
		self::$cache[ $key ] = $value;

		return update_option( self::OPTION_KEY, self::$cache );
	}

	/**
	 * Get the settings sub-array for one SMS gateway, with the stored
	 * (encrypted) password already decrypted for in-memory use.
	 *
	 * @param string $gateway_slug
	 * @return array<string,string>
	 */
	public static function get_gateway_settings( $gateway_slug ) {
		$settings = self::get_gateway_storage_settings( $gateway_slug );

		if ( isset( $settings['password'] ) && '' !== $settings['password'] ) {
			$settings['password'] = Secret_Crypt::decrypt( $settings['password'] );
		}

		return $settings;
	}

	/**
	 * Read the option representation without decrypting it. This is only for
	 * preserving an existing ciphertext when an admin leaves a password field
	 * blank; it must never be returned in a response or rendered in HTML.
	 *
	 * @param string $gateway_slug Gateway identifier.
	 * @return array<string,mixed>
	 */
	public static function get_gateway_storage_settings( $gateway_slug ) {
		$all_gateways = self::get( 'sms_gateways', [] );
		return is_array( $all_gateways ) && isset( $all_gateways[ $gateway_slug ] ) && is_array( $all_gateways[ $gateway_slug ] )
			? $all_gateways[ $gateway_slug ]
			: [];
	}

	/**
	 * Check whether a gateway has a saved password without returning the
	 * password or attempting to expose it to the settings page.
	 *
	 * @param string $gateway_slug
	 * @return bool
	 */
	public static function gateway_has_password( $gateway_slug ) {
		$all_gateways = self::get( 'sms_gateways', [] );
		return is_array( $all_gateways )
			&& isset( $all_gateways[ $gateway_slug ] )
			&& is_array( $all_gateways[ $gateway_slug ] )
			&& isset( $all_gateways[ $gateway_slug ]['password'] )
			&& '' !== trim( (string) $all_gateways[ $gateway_slug ]['password'] );
	}

	/**
	 * Save one SMS gateway's settings sub-array, encrypting the password
	 * field before it is persisted.
	 *
	 * @param string               $gateway_slug
	 * @param array<string,string> $settings Plain-text values as submitted by the admin form.
	 */
	public static function set_gateway_settings( $gateway_slug, array $settings ) {
		if ( isset( $settings['password'] ) && '' !== (string) $settings['password'] ) {
			if ( ! Secret_Crypt::is_current_format( $settings['password'] ) ) {
				$encrypted = Secret_Crypt::encrypt( (string) $settings['password'] );
				if ( false === $encrypted ) {
					// Never downgrade to plaintext when the deployment has not
					// supplied NOVIN_COMMERCE_ENCRYPTION_KEY or OpenSSL failed.
					return false;
				}
				$settings['password'] = $encrypted;
			}
		}

		$all_gateways                  = self::get( 'sms_gateways', [] );
		$all_gateways                  = is_array( $all_gateways ) ? $all_gateways : [];
		$all_gateways[ $gateway_slug ] = $settings;

		return self::set( 'sms_gateways', $all_gateways );
	}
}
