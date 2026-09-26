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
}
