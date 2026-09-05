<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NovinCommerce_RolePrice_Accounting_Sync {

	const SYNC_MARKER_META = '_wcpbr_last_synced_np_date';


	private static function role_name_map() {
		$map = array();

		foreach ( array_keys( NovinCommerce_RolePrice_Roles::get_roles() ) as $role_key ) {
			if ( 'customer' === $role_key ) {
				continue;
			}
			$accounting_name         = ucfirst( $role_key );
			$map[ $accounting_name ] = $role_key;
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

		if ( '' === $np_sync_date ) {
			return;
		}

		$our_last_sync = get_post_meta( $product_id, self::SYNC_MARKER_META, true );

		if ( $our_last_sync === $np_sync_date ) {
			return;
		}

		$web_prd_raw = get_post_meta( $product_id, 'WebPrd', true );

		$role_prices = $this->extract_role_prices_from_webprd( $web_prd_raw );

		if ( $wc_product->is_type( 'variable' ) ) {
			$this->apply_prices_to_variable_product( $wc_product, $role_prices );
		} else {
			$this->apply_prices_to_target( $product_id, $role_prices );
		}

		update_post_meta( $product_id, self::SYNC_MARKER_META, $np_sync_date );

		wc_delete_product_transients( $product_id );
	}


	private function extract_role_prices_from_webprd( $web_prd_raw ) {
		$role_map = self::role_name_map();

		$result = array();
		foreach ( $role_map as $internal_role ) {
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

			$np_role_name = sanitize_text_field( $entry['WordPressRoleName'] );

			if ( ! isset( $role_map[ $np_role_name ] ) ) {
				continue;
			}

			$internal_role = $role_map[ $np_role_name ];

			if ( ! isset( $entry['Price'] ) || '' === $entry['Price'] || ! is_numeric( $entry['Price'] ) ) {
				continue;
			}

			$regular_price = (float) $entry['Price'];

			if ( $regular_price < 0 ) {
				continue;
			}

			$result[ $internal_role ]['regular'] = wc_format_decimal( $regular_price );

			if ( $discount_percent > 0 && $regular_price > 0 ) {
				$sale_price                      = round( $regular_price * ( 1 - ( $discount_percent / 100 ) ) );
				$result[ $internal_role ]['sale'] = wc_format_decimal( $sale_price );
			}
		}

		return $result;
	}

	private function apply_prices_to_target( $target_id, $role_prices ) {
		$target_id = absint( $target_id );
		if ( ! $target_id || ! is_array( $role_prices ) ) {
			return;
		}

		$known_roles = NovinCommerce_RolePrice_Roles::get_roles();

		foreach ( $role_prices as $internal_role => $prices ) {
			if ( ! array_key_exists( $internal_role, $known_roles ) ) {
				continue;
			}

			$regular_value = isset( $prices['regular'] ) ? $prices['regular'] : '';
			$sale_value    = isset( $prices['sale'] ) ? $prices['sale'] : '';

			update_post_meta( $target_id, NovinCommerce_RolePrice_Roles::regular_meta_key( $internal_role ), $regular_value );
			update_post_meta( $target_id, NovinCommerce_RolePrice_Roles::sale_meta_key( $internal_role ), $sale_value );
		}
	}

	private function apply_prices_to_variable_product( $wc_product, $role_prices ) {
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

			$this->apply_prices_to_target( $variation_id, $role_prices );

			wc_delete_product_transients( $variation_id );
		}

		wc_delete_product_transients( $wc_product->get_id() );
	}
}
