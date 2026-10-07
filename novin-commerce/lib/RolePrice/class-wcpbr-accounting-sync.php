<?php

use MobinDev\Novin_Commerce\Common\Accounting\Product_Health_Snapshot;
use MobinDev\Novin_Commerce\Common\Accounting\WebPrd\WebPrd_Discount;
use MobinDev\Novin_Commerce\Common\Accounting\WebPrd\WebPrd_Parser;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NovinCommerce_RolePrice_Accounting_Sync {

	const SYNC_MARKER_META = '_wcpbr_last_synced_np_date';

	private static function role_name_map() {
		$map = array(
			'Administrator' => 'administrator',
			'Editor'        => 'editor',
			'Author'        => 'author',
			'Contributor'   => 'contributor',
			'Subscriber'    => 'subscriber',
			'Customer'      => 'customer',
		);

		if ( class_exists( 'NovinCommerce_RolePrice_Roles' ) ) {
			foreach ( NovinCommerce_RolePrice_Roles::get_all_wp_roles() as $role_key => $role_label ) {
				$role_key = sanitize_key( $role_key );
				if ( '' === $role_key ) {
					continue;
				}
				$map[ ucfirst( $role_key ) ] = $role_key;
				if ( is_string( $role_label ) && '' !== trim( $role_label ) ) {
					$map[ trim( $role_label ) ] = $role_key;
				}
			}
		}

		return $map;
	}

	public function __construct() {
		add_action( 'admin_init', array( $this, 'maybe_sync_on_admin' ) );
		// Accounting imports arrive through explicit meta writes. Reconcile the
		// role-price/discount boundary there, never from a product page view.
		add_action( 'updated_post_meta', array( $this, 'sync_on_accounting_meta' ), 100, 4 );
		add_action( 'added_post_meta', array( $this, 'sync_on_accounting_meta' ), 100, 4 );
		add_action( 'novin_commerce_daily_maintenance', array( $this, 'cleanup_expired_accounting_discounts' ), 20 );
	}

	public function cleanup_expired_accounting_discounts() {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id ASC LIMIT %d", '_novin_commerce_sale_origin', 100 ) );
		foreach ( (array) $ids as $id ) {
			$product = wc_get_product( absint( $id ) );
			if ( ! $product || 'accounting' !== (string) $product->get_meta( '_novin_commerce_sale_origin', true ) ) continue;
			$this->sync_accounting_discount( $product, WebPrd_Parser::from( $product->get_meta( 'WebPrd', true ) ) );
		}
	}

	public function sync_on_accounting_meta( $meta_id, $object_id, $meta_key, $meta_value ) {
		if ( ! in_array( $meta_key, array( 'WebPrd', '_np-api-sync-date' ), true ) || ! function_exists( 'wc_get_product' ) ) return;
		$product = wc_get_product( absint( $object_id ) );
		if ( ! $product ) return;
		$this->sync_product( $product->get_id() );
	}

	public function maybe_sync_on_admin() {
		global $pagenow;

		if ( ! is_admin() ) {
			return;
		}

		if ( 'post.php' !== $pagenow || empty( $_GET['post'] ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}

		$post_id = absint( wp_unslash( $_GET['post'] ) );
		if ( ! $post_id || 'product' !== get_post_type( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}

		$this->sync_product( $post_id );
	}

	public function sync_product( $product_id ) {
		if ( ! $product_id ) {
			return;
		}

		$wc_product = wc_get_product( $product_id );
		if ( ! $wc_product ) {
			return;
		}

		if ( $wc_product->is_type( 'variation' ) ) {
			$product_id = $wc_product->get_parent_id();
			$wc_product = wc_get_product( $product_id );
			if ( ! $wc_product ) {
				return;
			}
		}

		$np_sync_date = get_post_meta( $product_id, '_np-api-sync-date', true );
		$web_prd_raw  = get_post_meta( $product_id, 'WebPrd', true );

		if ( '' === $np_sync_date && ( ! is_string( $web_prd_raw ) || '' === trim( $web_prd_raw ) ) ) {
			return;
		}

		// Imported records normally have _np-api-sync-date. For older records
		// that only contain WebPrd, use its content hash as a safe one-time
		// marker instead of skipping role-price migration altogether.
		$sync_marker   = '' !== (string) $np_sync_date ? (string) $np_sync_date : md5( (string) $web_prd_raw );
		$our_last_sync = get_post_meta( $product_id, self::SYNC_MARKER_META, true );

		if ( $our_last_sync === $sync_marker ) {
			return;
		}

		$parser      = WebPrd_Parser::from( $web_prd_raw );
		$role_prices = $this->extract_role_prices_from_webprd( $web_prd_raw );

		if ( $wc_product->is_type( 'variable' ) ) {
			// The parent has its own role-price data.  Do not blindly copy it
			// over every variation: a variation can have a different price.
			$this->apply_prices_to_target( $product_id, $role_prices );
			$this->apply_prices_to_variable_product( $wc_product, $role_prices );
		} else {
			$this->apply_prices_to_target( $product_id, $role_prices );
		}

		$this->sync_accounting_discount( $wc_product, $parser );
		update_post_meta( $product_id, self::SYNC_MARKER_META, $sync_marker );

		wc_delete_product_transients( $product_id );
		Product_Health_Snapshot::rebuild( $product_id, 'product' );
	}

	private function extract_role_prices_from_webprd( $web_prd_raw ) {
		$role_map = self::role_name_map();
		$result   = array();
		foreach ( array_unique( array_values( $role_map ) ) as $internal_role ) {
			$result[ $internal_role ] = array( 'regular' => '', 'sale' => '' );
		}

		$parser = WebPrd_Parser::from( $web_prd_raw );
		if ( ! $parser->is_valid() ) {
			return $result;
		}

		$discount_data = $parser->discount();
		$discount = WebPrd_Discount::evaluate( $discount_data, 1 );
		$percent = isset( $discount_data['percent'] ) && is_numeric( $discount_data['percent'] ) ? (float) $discount_data['percent'] : null;
		$role_list = $parser->role_prices();
		foreach ( $role_list as $entry ) {
			$name = isset( $entry['name'] ) ? (string) $entry['name'] : '';
			if ( '' === $name ) {
				// There is no safe role mapping when the accounting payload only
				// carries a level/index. Never map PriceRoleList by array order.
				continue;
			}
			$internal_role = $role_map[ $name ] ?? '';
			if ( '' === $internal_role ) {
				foreach ( $role_map as $mapped_name => $mapped_role ) {
					if ( 0 === strcasecmp( $mapped_name, $name ) ) { $internal_role = $mapped_role; break; }
				}
			}
			if ( '' === $internal_role || ! isset( $result[ $internal_role ] ) || null === $entry['price'] ) continue;
			$price = (float) $entry['price'];
			$result[ $internal_role ]['regular'] = wc_format_decimal( $price );
			// Role sale prices are only active when the explicit accounting
			// schedule is active. A positive percentage without a valid
			// schedule is never an active sale.
			if ( 'active' === $discount['state'] && null !== $entry['sale_price'] ) {
				$result[ $internal_role ]['sale'] = wc_format_decimal( $entry['sale_price'] );
			} elseif ( 'active' === $discount['state'] && null !== $percent && $percent > 0 && $percent < 100 ) {
				$result[ $internal_role ]['sale'] = wc_format_decimal( round( $price * ( 1 - ( $percent / 100 ) ), wc_get_price_decimals() ) );
			}
		}
		return $result;
	}

	private function get_sync_role_keys() {
		$roles = array();

		if ( class_exists( 'NovinCommerce_RolePrice_Roles' ) ) {
			$roles = array_merge( $roles, array_keys( NovinCommerce_RolePrice_Roles::get_all_wp_roles() ) );
			$roles = array_merge( $roles, array_keys( NovinCommerce_RolePrice_Roles::get_all_configured_roles() ) );
		}

		return array_values( array_unique( array_filter( array_map( 'sanitize_key', $roles ) ) ) );
	}

	private function has_role_price_values( $role_prices ) {
		if ( ! is_array( $role_prices ) ) {
			return false;
		}

		foreach ( $role_prices as $prices ) {
			if ( ! is_array( $prices ) || ! isset( $prices['regular'] ) ) {
				continue;
			}
			if ( is_numeric( $prices['regular'] ) && (float) $prices['regular'] > 0 ) {
				return true;
			}
		}

		return false;
	}

	private function get_existing_role_prices( $post_id ) {
		$prices = array();

		foreach ( $this->get_sync_role_keys() as $role_key ) {
			$regular = NovinCommerce_RolePrice_Roles::get_compatible_role_price( $post_id, $role_key, 'regular' );
			if ( ! is_numeric( $regular ) || (float) $regular <= 0 ) {
				continue;
			}

			$prices[ $role_key ] = array(
				'regular' => wc_format_decimal( $regular ),
				'sale'    => NovinCommerce_RolePrice_Roles::get_compatible_role_price( $post_id, $role_key, 'sale' ),
			);
		}

		return $prices;
	}

	private function apply_prices_to_target( $target_id, $role_prices, $origin = 'accounting' ) {
		$target_id = absint( $target_id );
		if ( ! $target_id || ! is_array( $role_prices ) ) {
			return;
		}

		$legacy_prices = array();
		foreach ( $role_prices as $internal_role => $prices ) {
			$internal_role = sanitize_key( $internal_role );
			if ( '' === $internal_role || ! is_array( $prices ) ) continue;
			$raw_origin = (string) get_post_meta( $target_id, NovinCommerce_RolePrice_Roles::origin_meta_key( $internal_role ), true );
			$existing_origin = NovinCommerce_RolePrice_Roles::normalize_origin( $raw_origin );
			if ( '' !== $raw_origin && in_array( $existing_origin, array( 'manual', 'unknown' ), true ) ) {
				// Explicit/manual or unresolved ownership is never silently
				// replaced by an accounting payload.
				continue;
			}
			$regular_value = isset( $prices['regular'] ) ? $prices['regular'] : '';
			if ( ! is_numeric( $regular_value ) || (float) $regular_value <= 0 ) {
				// Only accounting-owned values may be cleared by a missing source
				// role. Manual/unknown values are deliberately preserved.
				if ( 'accounting' === $existing_origin || 'inherited' === $existing_origin ) {
					update_post_meta( $target_id, NovinCommerce_RolePrice_Roles::regular_meta_key( $internal_role ), '' );
					update_post_meta( $target_id, NovinCommerce_RolePrice_Roles::sale_meta_key( $internal_role ), '' );
					NovinCommerce_RolePrice_Roles::set_origin( $target_id, $internal_role, $existing_origin );
					$legacy_prices[ $internal_role ] = array( 'regular' => '', 'sale' => '' );
				}
				continue;
			}
			$regular_value = wc_format_decimal( $regular_value );
			$sale_value = isset( $prices['sale'] ) && is_numeric( $prices['sale'] ) && (float) $prices['sale'] > 0 ? wc_format_decimal( $prices['sale'] ) : '';
			update_post_meta( $target_id, NovinCommerce_RolePrice_Roles::regular_meta_key( $internal_role ), $regular_value );
			update_post_meta( $target_id, NovinCommerce_RolePrice_Roles::sale_meta_key( $internal_role ), $sale_value );
			NovinCommerce_RolePrice_Roles::set_origin( $target_id, $internal_role, $origin );
			$legacy_prices[ $internal_role ] = array( 'regular' => $regular_value, 'sale' => $sale_value );
		}
		if ( ! empty( $legacy_prices ) ) NovinCommerce_RolePrice_Roles::update_festi_role_prices( $target_id, $legacy_prices );
	}

	private function apply_prices_to_variable_product( $wc_product, $parent_role_prices ) {
		if ( ! ( $wc_product instanceof WC_Product ) ) {
			return;
		}

		$variation_ids = $wc_product->get_children();

		if ( empty( $variation_ids ) || ! is_array( $variation_ids ) ) {
			return;
		}

		foreach ( $variation_ids as $variation_id ) {
			$variation_id = absint( $variation_id );
			if ( ! $variation_id ) {
				continue;
			}

			// Prefer variation-specific WebPrd data.  The legacy
			// festiUserRolePrices value is also variation-specific and must win
			// over the parent's prices (for example variation 16316).
			$variation_webprd = get_post_meta( $variation_id, 'WebPrd', true );
			$variation_prices = $this->extract_role_prices_from_webprd( $variation_webprd );
			if ( $this->has_role_price_values( $variation_prices ) ) {
				$this->apply_prices_to_target( $variation_id, $variation_prices );
			} else {
				$existing_prices = $this->get_existing_role_prices( $variation_id );
				if ( ! empty( $existing_prices ) ) {
					// Migrate an existing legacy value to our canonical meta, but
					// never replace it with the parent value.
					$this->apply_prices_to_target( $variation_id, $existing_prices, 'legacy' );
				} else {
					// If a variation has no own price, inherit the accounting
					// value and write it to the variation as a separate record.
					$this->apply_prices_to_target( $variation_id, $parent_role_prices, 'inherited' );
				}
			}

			$variation_product = wc_get_product( $variation_id );
			if ( $variation_product ) {
				$this->sync_accounting_discount( $variation_product, WebPrd_Parser::from( $variation_webprd ) );
				Product_Health_Snapshot::rebuild( $variation_id, 'variation' );
			}
			wc_delete_product_transients( $variation_id );
		}

		wc_delete_product_transients( $wc_product->get_id() );
	}

	/**
	 * Synchronize only accounting-owned standard WooCommerce sale fields.
	 * Manual sales are never overwritten. Invalid/expired/missing schedules
	 * actively clear a sale that this connector previously created.
	 *
	 * @param WC_Product  $product
	 * @param WebPrd_Parser $parser
	 * @return void
	 */
	private function sync_accounting_discount( $product, WebPrd_Parser $parser ) {
		if ( ! ( $product instanceof WC_Product ) ) return;
		$origin = (string) $product->get_meta( '_novin_commerce_sale_origin', true );
		if ( ! $parser->is_valid() ) {
			if ( 'accounting' === $origin ) {
				$product->set_sale_price( '' );
				$product->set_date_on_sale_from( null );
				$product->set_date_on_sale_to( null );
				$product->update_meta_data( '_novin_commerce_sale_state', 'inactive' );
				$product->save();
			}
			return;
		}
		$discount = $parser->discount();
		$percent = isset( $discount['percent'] ) && is_numeric( $discount['percent'] ) ? (float) $discount['percent'] : null;
		$has_source = null !== $percent || '' !== (string) $discount['start_date'] || '' !== (string) $discount['end_date'];
		$current_sale = $product->get_sale_price( 'edit' );
		// An existing non-accounting sale is merchant-owned, even if an
		// accounting payload also contains an invalid or expired discount.
		if ( '' !== (string) $current_sale && 'accounting' !== $origin ) return;
		if ( ! $has_source && 'accounting' !== $origin ) return;

		$regular = $product->get_regular_price( 'edit' );
		$sale_candidate = null;
		if ( null !== $percent && $percent > 0 && $percent < 100 && is_numeric( $regular ) ) {
			$sale_candidate = wc_format_decimal( round( (float) $regular * ( 1 - ( $percent / 100 ) ), wc_get_price_decimals() ) );
		}
		$state = WebPrd_Discount::evaluate( $discount, $sale_candidate );
		if ( in_array( $state['state'], array( 'active', 'scheduled' ), true ) && null !== $sale_candidate ) {
			$product->set_sale_price( $sale_candidate );
			$product->set_date_on_sale_from( gmdate( 'Y-m-d H:i:s', (int) $state['start'] ) );
			$product->set_date_on_sale_to( gmdate( 'Y-m-d H:i:s', (int) $state['end'] ) );
			$product->update_meta_data( '_novin_commerce_sale_origin', 'accounting' );
			$product->update_meta_data( '_novin_commerce_sale_state', $state['state'] );
			$product->save();
			return;
		}

		// scheduled/expired/invalid/no-schedule accounting discounts must not
		// leave an old accounting sale active. CRUD setters clear both local
		// and GMT schedule fields in HPOS/post storage.
		if ( 'accounting' === $origin ) {
			$product->set_sale_price( '' );
			$product->set_date_on_sale_from( null );
			$product->set_date_on_sale_to( null );
			$product->update_meta_data( '_novin_commerce_sale_origin', 'accounting' );
			$product->update_meta_data( '_novin_commerce_sale_state', 'inactive' );
			$product->save();
		}
	}
}
