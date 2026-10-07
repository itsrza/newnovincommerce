<?php
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

		add_action( 'woocommerce_before_single_product', array( $this, 'maybe_sync_on_front' ) );
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

	public function maybe_sync_on_front() {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

		$this->sync_product( $product_id );
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

		$role_prices = $this->extract_role_prices_from_webprd( $web_prd_raw );

		if ( $wc_product->is_type( 'variable' ) ) {
			// The parent has its own role-price data.  Do not blindly copy it
			// over every variation: a variation can have a different price.
			$this->apply_prices_to_target( $product_id, $role_prices );
			$this->apply_prices_to_variable_product( $wc_product, $role_prices );
		} else {
			$this->apply_prices_to_target( $product_id, $role_prices );
		}

		update_post_meta( $product_id, self::SYNC_MARKER_META, $sync_marker );

		wc_delete_product_transients( $product_id );
	}

	private function extract_role_prices_from_webprd( $web_prd_raw ) {
		$role_map = self::role_name_map();

		$result = array();
		foreach ( array_unique( array_values( $role_map ) ) as $internal_role ) {
			$result[ $internal_role ] = array(
				'regular' => '',
				'sale'    => '',
			);
		}

		if ( empty( $web_prd_raw ) || ! is_string( $web_prd_raw ) ) {
			return $result;
		}

		if ( strlen( $web_prd_raw ) > 200000 ) {
			return $result;
		}

		$data = json_decode( $web_prd_raw, true, 8 );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return $result;
		}
		if ( ! is_array( $data ) || empty( $data['PriceRoleList'] ) || ! is_array( $data['PriceRoleList'] ) ) {
			return $result;
		}

		$discount_percent = isset( $data['DiscountPercent'] ) && is_numeric( $data['DiscountPercent'] ) ? (float) $data['DiscountPercent'] : 0.0;
		$discount_percent = max( 0.0, min( 100.0, $discount_percent ) );

		foreach ( $data['PriceRoleList'] as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['WordPressRoleName'] ) || ! is_string( $entry['WordPressRoleName'] ) ) {
				continue;
			}

			$np_role_name = trim( sanitize_text_field( $entry['WordPressRoleName'] ) );
			$internal_role = isset( $role_map[ $np_role_name ] ) ? $role_map[ $np_role_name ] : '';

			if ( '' === $internal_role ) {
				foreach ( $role_map as $mapped_name => $mapped_role ) {
					if ( 0 === strcasecmp( $mapped_name, $np_role_name ) ) {
						$internal_role = $mapped_role;
						break;
					}
				}
			}

			if ( '' === $internal_role || ! isset( $result[ $internal_role ] ) ) {
				continue;
			}

			if ( ! isset( $entry['Price'] ) || '' === $entry['Price'] || ! is_numeric( $entry['Price'] ) ) {
				continue;
			}

			$regular_price = (float) $entry['Price'];

			if ( $regular_price <= 0 ) {
				continue;
			}

			$result[ $internal_role ]['regular'] = wc_format_decimal( $regular_price );

			if ( $discount_percent > 0 ) {
				$sale_price                      = round( $regular_price * ( 1 - ( $discount_percent / 100 ) ), wc_get_price_decimals() );
				$result[ $internal_role ]['sale'] = wc_format_decimal( $sale_price );
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

	private function apply_prices_to_target( $target_id, $role_prices ) {
		$target_id = absint( $target_id );
		if ( ! $target_id || ! is_array( $role_prices ) ) {
			return;
		}

		$legacy_prices = array();

		foreach ( $role_prices as $internal_role => $prices ) {
			$internal_role = sanitize_key( $internal_role );
			if ( '' === $internal_role || ! is_array( $prices ) ) {
				continue;
			}

			$regular_value = isset( $prices['regular'] ) ? $prices['regular'] : '';
			if ( ! is_numeric( $regular_value ) || (float) $regular_value <= 0 ) {
				// An empty role in WebPrd must not erase a manual/legacy price.
				continue;
			}

			$regular_value = wc_format_decimal( $regular_value );
			$sale_value    = isset( $prices['sale'] ) && is_numeric( $prices['sale'] ) && (float) $prices['sale'] > 0
				? wc_format_decimal( $prices['sale'] )
				: '';

			update_post_meta( $target_id, NovinCommerce_RolePrice_Roles::regular_meta_key( $internal_role ), $regular_value );
			update_post_meta( $target_id, NovinCommerce_RolePrice_Roles::sale_meta_key( $internal_role ), $sale_value );
			$legacy_prices[ $internal_role ] = array(
				'regular' => $regular_value,
				'sale'    => $sale_value,
			);
		}

		if ( ! empty( $legacy_prices ) ) {
			NovinCommerce_RolePrice_Roles::update_festi_role_prices( $target_id, $legacy_prices );
		}
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
					$this->apply_prices_to_target( $variation_id, $existing_prices );
				} else {
					// If a variation has no own price, inherit the accounting
					// value and write it to the variation as a separate record.
					$this->apply_prices_to_target( $variation_id, $parent_role_prices );
				}
			}

			wc_delete_product_transients( $variation_id );
		}

		wc_delete_product_transients( $wc_product->get_id() );
	}
}
