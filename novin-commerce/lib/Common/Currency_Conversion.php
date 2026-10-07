<?php

namespace MobinDev\Novin_Commerce\Common;

/**
 * Keeps accounting prices in their stored Rial value while exposing an
 * optional Toman view to WooCommerce and the WordPress admin.
 *
 * The accounting connector continues to write the original value to product
 * meta. When the setting is enabled, WooCommerce's product price getters
 * return one tenth of that value, which means product pages, catalogues,
 * cart totals and checkout all use the same unit. Values entered in the
 * standard WooCommerce product editor and in the role-price fields are
 * multiplied back before they are persisted.
 */
final class Currency_Conversion {

	const OPTION_KEY       = 'novin_toman_conversion_enabled';
	const DIVISOR          = 10;
	const ORDER_UNIT_META  = '_novin_commerce_price_unit';
	const ORDER_UNIT_TOMAN = 'toman';

	private static $booted = false;

	/**
	 * Register all WooCommerce hooks once the WooCommerce plugin is available.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( self::$booted || ! class_exists( '\WooCommerce' ) ) {
			return;
		}

		self::$booted = true;
		$priority     = PHP_INT_MAX;

		// Product getters are used by product pages, loops, widgets, cart
		// calculations, checkout and most admin product price displays.
		foreach ( array(
			'woocommerce_product_get_price',
			'woocommerce_product_get_regular_price',
			'woocommerce_product_get_sale_price',
			'woocommerce_product_variation_get_price',
			'woocommerce_product_variation_get_regular_price',
			'woocommerce_product_variation_get_sale_price',
		) as $filter ) {
			add_filter( $filter, array( __CLASS__, 'filter_product_price' ), $priority, 2 );
		}

		// Variable-product price caches have their own filters. Without these
		// hooks a variable product could show Rial values while its variation
		// page showed Toman values.
		foreach ( array(
			'woocommerce_variation_prices_price',
			'woocommerce_variation_prices_regular_price',
			'woocommerce_variation_prices_sale_price',
		) as $filter ) {
			add_filter( $filter, array( __CLASS__, 'filter_product_price' ), $priority, 2 );
		}
		add_filter( 'woocommerce_get_variation_prices_hash', array( __CLASS__, 'add_unit_to_price_hash' ), $priority, 3 );

		// WooCommerce passes the submitted admin values to these actions before
		// saving the product object. Only posted fields are changed, so a bulk
		// or programmatic save with no price field cannot be multiplied twice.
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'prepare_product_for_storage' ), 99, 1 );
		add_action( 'woocommerce_admin_process_variation_object', array( __CLASS__, 'prepare_variation_for_storage' ), 99, 2 );

		// Orders created through checkout contain the already-converted cart
		// amounts. Mark them so historical Rial orders can be converted for
		// display without converting a new order a second time.
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'mark_checkout_order_unit' ), 10, 2 );
		add_action( 'woocommerce_new_order', array( __CLASS__, 'mark_new_order_unit' ), 10, 2 );
		foreach ( array(
			'woocommerce_order_get_total',
			'woocommerce_order_get_subtotal',
			'woocommerce_order_get_total_tax',
			'woocommerce_order_get_cart_tax',
			'woocommerce_order_get_shipping_total',
			'woocommerce_order_get_shipping_tax',
			'woocommerce_order_get_discount_total',
			'woocommerce_order_get_discount_tax',
			'woocommerce_order_get_fee_total',
		) as $filter ) {
			add_filter( $filter, array( __CLASS__, 'filter_order_amount' ), $priority, 2 );
		}
		foreach ( array(
			'woocommerce_order_item_get_total',
			'woocommerce_order_item_get_subtotal',
			'woocommerce_order_item_get_total_tax',
			'woocommerce_order_item_get_subtotal_tax',
		) as $filter ) {
			add_filter( $filter, array( __CLASS__, 'filter_order_item_amount' ), $priority, 2 );
		}
	}

	/**
	 * Whether the Rial-to-Toman display conversion is enabled.
	 *
	 * A missing option deliberately means enabled so existing installations
	 * receive the requested default without an activation-time migration.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$value = SettingAPI::get( self::OPTION_KEY, null );
		if ( null === $value ) {
			return true;
		}

		return ! in_array( strtolower( trim( (string) $value ) ), array( 'off', '0', 'false', 'no' ), true );
	}

	/**
	 * Convert a stored Rial price to the value shown/used by WooCommerce.
	 *
	 * @param mixed $price Price value.
	 * @return mixed
	 */
	public static function to_display( $price ) {
		if ( ! self::is_enabled() || '' === $price || null === $price || ! is_numeric( $price ) ) {
			return $price;
		}

		return self::format( (float) $price / self::DIVISOR );
	}

	/**
	 * Convert an admin-entered Toman price back to the stored Rial value.
	 *
	 * @param mixed $price Price value.
	 * @return mixed
	 */
	public static function to_storage( $price ) {
		if ( ! self::is_enabled() || '' === $price || null === $price || ! is_numeric( $price ) ) {
			return $price;
		}

		return self::format( (float) $price * self::DIVISOR );
	}

	/**
	 * Keep WooCommerce's variable-price cache separate for each display unit.
	 *
	 * @param array       $hash        Existing hash parts.
	 * @param object      $product     Variable product.
	 * @param bool        $for_display Whether the cache is for display.
	 * @return array
	 */
	public static function add_unit_to_price_hash( $hash, $product = null, $for_display = false ) {
		if ( ! is_array( $hash ) ) {
			$hash = array();
		}
		$hash[] = self::is_enabled() ? 'toman' : 'rial';
		return $hash;
	}

	/**
	 * WooCommerce product/variation price filter.
	 *
	 * @param mixed       $price    Price value.
	 * @param object|null $product  Product or variation, when provided.
	 * @return mixed
	 */
	public static function filter_product_price( $price, $product = null ) {
		return self::to_display( $price );
	}

	/**
	 * Multiply only values actually submitted by the simple product editor.
	 *
	 * @param object $product WooCommerce product.
	 * @return void
	 */
	public static function prepare_product_for_storage( $product ) {
		if ( ! self::is_enabled() || ! is_object( $product ) || ! isset( $_POST ) ) {
			return;
		}

		foreach ( array( 'regular_price', 'sale_price' ) as $property ) {
			$field = '_' . $property;
			if ( ! array_key_exists( $field, $_POST ) ) {
				continue;
			}

			$value = self::posted_scalar( $_POST[ $field ] );
			if ( null === $value ) {
				continue;
			}

			$setter = 'set_' . $property;
			if ( is_callable( array( $product, $setter ) ) ) {
				$product->{$setter}( '' === $value ? '' : self::to_storage( $value ) );
			}
		}
	}

	/**
	 * Multiply only the current variation's submitted price fields.
	 *
	 * @param object $variation Variation object.
	 * @param int    $loop      Variation row index.
	 * @return void
	 */
	public static function prepare_variation_for_storage( $variation, $loop ) {
		if ( ! self::is_enabled() || ! is_object( $variation ) || ! isset( $_POST ) ) {
			return;
		}

		$loop = absint( $loop );
		foreach ( array( 'regular_price', 'sale_price' ) as $property ) {
			// WooCommerce's variation editor uses variable_regular_price and
			// variable_sale_price. Keep the underscored names as a compatibility
			// fallback for older/custom editors.
			$fields = 'regular_price' === $property
				? array( 'variable_regular_price', '_regular_price' )
				: array( 'variable_sale_price', '_sale_price' );
			$value = null;
			foreach ( $fields as $field ) {
				if ( ! array_key_exists( $field, $_POST ) ) {
					continue;
				}
				$value = self::posted_scalar( $_POST[ $field ], $loop );
				if ( null !== $value ) {
					break;
				}
			}
			if ( null === $value ) {
				continue;
			}

			$setter = 'set_' . $property;
			if ( is_callable( array( $variation, $setter ) ) ) {
				$variation->{$setter}( '' === $value ? '' : self::to_storage( $value ) );
			}
		}
	}

	/**
	 * Mark a checkout order whose cart totals are already in Toman.
	 *
	 * @param object $order    Order object.
	 * @param object $data     Checkout data (unused, retained for hook shape).
	 * @return void
	 */
	public static function mark_checkout_order_unit( $order, $data = null ) {
		if ( self::is_enabled() && is_object( $order ) && is_callable( array( $order, 'update_meta_data' ) ) ) {
			$order->update_meta_data( self::ORDER_UNIT_META, self::ORDER_UNIT_TOMAN );
		}
	}

	/**
	 * Mark orders created through an admin/API path as Toman as well. The
	 * checkout hook normally handles storefront orders, while this covers
	 * WooCommerce's other order creation paths without touching old orders.
	 *
	 * @param int         $order_id Order ID.
	 * @param object|null $order    Order object, when supplied by WooCommerce.
	 * @return void
	 */
	public static function mark_new_order_unit( $order_id, $order = null ) {
		if ( ! self::is_enabled() ) {
			return;
		}
		if ( ! is_object( $order ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( absint( $order_id ) );
		}
		if ( is_object( $order ) && is_callable( array( $order, 'update_meta_data' ) ) ) {
			$order->update_meta_data( self::ORDER_UNIT_META, self::ORDER_UNIT_TOMAN );
			if ( is_callable( array( $order, 'save' ) ) ) {
				$order->save();
			}
		}
	}

	/**
	 * Convert old order totals for display. New checkout orders are marked and
	 * already contain Toman amounts, so they pass through unchanged.
	 *
	 * @param mixed  $amount Amount.
	 * @param object $order  Order.
	 * @return mixed
	 */
	public static function filter_order_amount( $amount, $order = null ) {
		if ( ! is_numeric( $amount ) ) {
			return $amount;
		}

		if ( self::order_is_toman( $order ) ) {
			return self::is_enabled() ? $amount : self::format( (float) $amount * self::DIVISOR );
		}

		return self::is_enabled() ? self::format( (float) $amount / self::DIVISOR ) : $amount;
	}

	/**
	 * Convert old order line totals for display.
	 *
	 * @param mixed  $amount Item amount.
	 * @param object $item   Order item.
	 * @return mixed
	 */
	public static function filter_order_item_amount( $amount, $item = null ) {
		if ( ! is_numeric( $amount ) || ! is_object( $item ) ) {
			return $amount;
		}

		$order_id = is_callable( array( $item, 'get_order_id' ) ) ? absint( $item->get_order_id() ) : 0;
		$order    = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( self::order_is_toman( $order ) ) {
			return self::is_enabled() ? $amount : self::format( (float) $amount * self::DIVISOR );
		}

		return self::is_enabled() ? self::format( (float) $amount / self::DIVISOR ) : $amount;
	}

	/**
	 * Extract a scalar from simple or variation admin POST data.
	 *
	 * @param mixed    $value Value or indexed values.
	 * @param int|null $index Optional variation index.
	 * @return string|null
	 */
	private static function posted_scalar( $value, $index = null ) {
		if ( null !== $index ) {
			if ( ! is_array( $value ) || ! array_key_exists( $index, $value ) ) {
				return null;
			}
			$value = $value[ $index ];
		}

		if ( is_array( $value ) || is_object( $value ) || null === $value ) {
			return null;
		}

		$value = trim( (string) wp_unslash( $value ) );
		return '' === $value ? '' : $value;
	}

	/**
	 * Use WooCommerce's decimal formatter when available and retain a numeric
	 * string when the helper is called during a very early bootstrap phase.
	 *
	 * @param float $value Numeric value.
	 * @return string
	 */
	private static function format( $value ) {
		if ( function_exists( 'wc_format_decimal' ) ) {
			return wc_format_decimal( $value );
		}

		return (string) $value;
	}

	/**
	 * @param object|null $order Order object.
	 * @return bool
	 */
	private static function order_is_toman( $order ) {
		if ( ! is_object( $order ) || ! is_callable( array( $order, 'get_meta' ) ) ) {
			return false;
		}

		return self::ORDER_UNIT_TOMAN === (string) $order->get_meta( self::ORDER_UNIT_META, true );
	}
}
