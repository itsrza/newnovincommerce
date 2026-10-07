<?php

namespace MobinDev\Novin_Commerce\Digits\Frontend;

use MobinDev\Novin_Commerce\Digits\Common\Digits_Settings;
use MobinDev\Novin_Commerce\Plugin;

/**
 * WooCommerce-facing behavior for the Digits module: autofilling the
 * checkout phone field with the user's registered mobile number, and
 * formatting the phone number shown on invoices.
 *
 * This class only ever touches the WooCommerce checkout/order-display
 * layer — it never calls into the accounting-sync code, keeping the two
 * systems fully decoupled.
 */
class Woocommerce_Integration {

	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function hooks() {
		$this->plugin->get_loader()->add_filter( 'woocommerce_checkout_get_value', $this, 'autofill_checkout_phone', 10, 2 );
		// WooCommerce CRUD getters are used by order screens, emails and most
		// invoice/PDF integrations. Format the display value without changing
		// the normalized phone stored on the order.
		$this->plugin->get_loader()->add_filter( 'woocommerce_order_get_billing_phone', $this, 'format_order_phone', 10, 2 );
		$this->plugin->get_loader()->add_filter( 'woocommerce_order_get_shipping_phone', $this, 'format_order_phone', 10, 2 );
	}

	/**
	 * @param mixed  $value Current value WooCommerce would use for this field.
	 * @param string $input Field key, e.g. 'billing_phone'.
	 * @return mixed
	 */
	public function autofill_checkout_phone( $value, $input ) {
		if ( 'billing_phone' !== $input ) {
			return $value;
		}
		if ( ! empty( $value ) ) {
			// Don't override a value the customer already entered/saved.
			return $value;
		}
		if ( 'on' !== Digits_Settings::get( 'wc_autofill_checkout_phone', 'on' ) ) {
			return $value;
		}
		if ( ! is_user_logged_in() ) {
			return $value;
		}

		$user_id  = get_current_user_id();
		$phone_no = get_user_meta( $user_id, 'digits_phone_no', true );

		return $phone_no ? $phone_no : $value;
	}

	/**
	 * Format an order phone for invoice/email/admin display while preserving
	 * the normalized value stored in billing_phone/shipping_phone.
	 *
	 * @param mixed       $value Phone value.
	 * @param object|null $order Order object (accepted for the WooCommerce hook signature).
	 * @return mixed
	 */
	public function format_order_phone( $value, $order = null ) {
		$value = (string) $value;
		if ( '' === trim( $value ) ) {
			return $value;
		}

		$format = sanitize_key( Digits_Settings::get( 'wc_invoice_phone_format', 'local' ) );
		if ( ! in_array( $format, [ 'local', 'international', 'international_no_plus' ], true ) ) {
			$format = 'local';
		}

		$digits = preg_replace( '/[^0-9]/', '', $value );
		if ( '' === $digits ) {
			return $value;
		}

		$country_code = preg_replace( '/[^0-9]/', '', (string) Digits_Settings::get( 'default_country_code', '98' ) );
		$country_code = $country_code ? $country_code : '98';
		if ( 0 === strpos( $digits, '00' ) ) {
			$digits = substr( $digits, 2 );
		}
		if ( 0 === strpos( $digits, '0' ) && strlen( $digits ) > 1 ) {
			$digits = $country_code . substr( $digits, 1 );
		} elseif ( 0 !== strpos( $digits, $country_code ) && strlen( $digits ) >= 8 ) {
			$digits = $country_code . $digits;
		}

		if ( 'local' === $format && 0 === strpos( $digits, $country_code ) ) {
			return '0' . substr( $digits, strlen( $country_code ) );
		}
		if ( 'international' === $format ) {
			return '+' . $digits;
		}

		return $digits;
	}
}
