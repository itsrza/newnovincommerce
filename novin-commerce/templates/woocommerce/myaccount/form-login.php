<?php
/**
 * NovinCommerce's targeted My Account login template.
 *
 * This replaces only WooCommerce's classic form-login.php. It intentionally
 * does not buffer or rewrite the surrounding page. Block/custom-builder
 * pages should place [novin_digits_form] in their account content.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

do_action( 'woocommerce_before_customer_login_form' );
do_action( 'novin_commerce_digits_account_form' );
do_action( 'woocommerce_after_customer_login_form' );
