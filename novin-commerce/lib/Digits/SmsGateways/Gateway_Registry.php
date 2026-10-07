<?php

namespace Novinwp\Novin_Commerce\Digits\SmsGateways;

/**
 * Central place that lists every available SMS gateway. Adding a new
 * provider later only requires: (1) a new class implementing
 * Sms_Gateway_Interface, and (2) one line added to all().
 */
class Gateway_Registry {

	/**
	 * @return array<string, Sms_Gateway_Interface> Keyed by gateway slug.
	 */
	public static function all() {
		$gateways = [
			new Npsms_Gateway(),
		];

		/**
		 * Allow other code (or future add-ons) to register additional
		 * SMS gateways without touching this file.
		 *
		 * @param Sms_Gateway_Interface[] $gateways
		 */
		$gateways = apply_filters( 'novin_commerce_digits_sms_gateways', $gateways );

		$indexed = [];
		foreach ( $gateways as $gateway ) {
			if ( $gateway instanceof Sms_Gateway_Interface ) {
				$indexed[ $gateway->get_slug() ] = $gateway;
			}
		}

		return $indexed;
	}

	/**
	 * @param string $slug
	 * @return Sms_Gateway_Interface|null
	 */
	public static function get( $slug ) {
		$all = self::all();

		return $all[ $slug ] ?? null;
	}
}
