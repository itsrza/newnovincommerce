<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NovinCommerce_RolePrice_Roles {

	const OPTION_KEY          = 'wcpbr_roles_config';
	const FESTI_META_KEY      = 'festiUserRolePrices';
	const FESTI_SALE_KEY      = 'salePrice';
	const ORIGIN_META_PREFIX  = '_novin_role_price_origin_';
	const ORIGINS              = array( 'accounting', 'manual', 'inherited', 'legacy', 'unknown' );

	private static function default_config() {
		return array(
			'editor'      => array(
				'label'  => __( 'Editor', 'novin-commerce' ),
				'active' => true,
			),
			'contributor' => array(
				'label'  => __( 'Contributor', 'novin-commerce' ),
				'active' => true,
			),
			'subscriber'  => array(
				'label'  => __( 'Subscriber', 'novin-commerce' ),
				'active' => true,
			),
		);
	}

	public static function get_roles_config() {
		$config = get_option( self::OPTION_KEY, null );

		if ( null === $config || ! is_array( $config ) ) {
			// Reading prices on a frontend request must be side-effect free.
			// Activation/admin settings can persist the defaults explicitly.
			$config = self::default_config();
		}

		return $config;
	}

	public static function save_roles_config( array $config ) {
		update_option( self::OPTION_KEY, $config );
	}

	public static function get_roles() {
		$config       = self::get_roles_config();
		$roles        = array();
		$known_roles  = self::get_all_wp_roles();

		foreach ( $config as $role_key => $data ) {
			$role_key = sanitize_key( $role_key );
			if ( '' === $role_key || empty( $data['active'] ) ) {
				continue;
			}

			// get_all_wp_roles() is the authoritative list on admin product
			// screens. Some custom-role plugins register a role name there
			// before get_role() becomes available, so do not hide the price
			// fields merely because the latter returns false for one request.
			if ( ! array_key_exists( $role_key, $known_roles ) && ! self::wp_role_exists( $role_key ) ) {
				continue;
			}

			$label              = isset( $data['label'] ) && '' !== trim( (string) $data['label'] ) ? $data['label'] : $role_key;
			$roles[ $role_key ] = $label;
		}

		return $roles;
	}

	public static function get_all_configured_roles() {
		return self::get_roles_config();
	}

	public static function wp_role_exists( $role_key ) {
		return (bool) get_role( $role_key );
	}

	public static function get_all_wp_roles() {
		global $wp_roles;
		if ( ! isset( $wp_roles ) ) {
			$wp_roles = wp_roles();
		}
		return $wp_roles->get_names();
	}

	public static function get_current_user_role() {
		if ( ! is_user_logged_in() ) {
			return null;
		}

		$user = wp_get_current_user();
		if ( empty( $user->roles ) || ! is_array( $user->roles ) ) {
			return null;
		}

		$defined_roles = array_keys( self::get_roles() );

		// Configuration order is the explicit priority. Do not depend on the
		// order in which another plugin happened to attach roles to the user.
		foreach ( $defined_roles as $role ) {
			if ( in_array( $role, $user->roles, true ) ) {
				return $role;
			}
		}

		return null;
	}

	public static function regular_meta_key( $role ) {
		return '_wcpbr_regular_price_' . sanitize_key( $role );
	}

	public static function sale_meta_key( $role ) {
		return '_wcpbr_sale_price_' . sanitize_key( $role );
	}

	public static function origin_meta_key( $role ) {
		return self::ORIGIN_META_PREFIX . sanitize_key( $role );
	}

	public static function normalize_origin( $origin ) {
		$origin = sanitize_key( $origin );
		return in_array( $origin, self::ORIGINS, true ) ? $origin : 'unknown';
	}

	public static function set_origin( $post_id, $role, $origin ) {
		update_post_meta( absint( $post_id ), self::origin_meta_key( $role ), self::normalize_origin( $origin ) );
	}

	/**
	 * Decode the JSON used by the legacy WooCommerce Prices By User Role plugin.
	 *
	 * The legacy plugin stores this value as JSON (WordPress returns it as a
	 * string), while a few installations have an array/serialized value.  Keep
	 * both formats readable so migrating the price engine cannot discard data.
	 */
	public static function get_festi_role_prices( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return array();
		}

		$raw = self::get_festi_meta_raw_value( $post_id );
		return self::decode_price_data( $raw );
	}

	/**
	 * Read the legacy value without WordPress's automatic maybe_unserialize().
	 * This keeps an unexpected serialized object from being instantiated before
	 * the allow-list in decode_price_data() can reject it.
	 *
	 * @param int    $post_id  Product or variation ID.
	 * @param string $meta_key Metadata key.
	 * @return string
	 */
	private static function get_raw_meta_value( $post_id, $meta_key ) {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1",
				absint( $post_id ),
				$meta_key
			)
		);

		return null === $value ? '' : (string) $value;
	}

	private static function get_festi_meta_raw_value( $post_id ) {
		return self::get_raw_meta_value( $post_id, self::FESTI_META_KEY );
	}

	private static function decode_price_data( $raw ) {
		if ( is_array( $raw ) ) {
			return $raw;
		}

		if ( is_object( $raw ) ) {
			$raw = json_decode( wp_json_encode( $raw ), true );
			return is_array( $raw ) ? $raw : array();
		}

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
			return $decoded;
		}

		// Some export tools add WordPress-style escaping around the JSON.
		$decoded = json_decode( stripslashes( $raw ), true );
		if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
			return $decoded;
		}

		// WordPress's maybe_unserialize() permits object instantiation. This
		// metadata is expected to contain an array only, so explicitly disable
		// classes when reading legacy serialized exports.
		$decoded = @unserialize( trim( $raw ), [ 'allowed_classes' => false ] );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		return array();
	}

	private static function non_empty_price( $value ) {
		if ( is_array( $value ) || is_object( $value ) || null === $value ) {
			return false;
		}

		return '' !== trim( (string) $value );
	}

	/**
	 * Read a role price from festiUserRolePrices.
	 *
	 * Regular prices are stored under the role key itself.  Sale prices are
	 * stored under salePrice[role], with schedule and other legacy keys kept
	 * untouched.
	 */
	public static function get_festi_role_price( $post_id, $role, $type = 'regular' ) {
		$data     = self::get_festi_role_prices( $post_id );
		$role_key = sanitize_key( $role );
		if ( '' === $role_key || empty( $data ) ) {
			return '';
		}

		if ( 'sale' === $type ) {
			$sale_raw   = isset( $data[ self::FESTI_SALE_KEY ] ) ? $data[ self::FESTI_SALE_KEY ] : array();
			$sale_prices = self::decode_price_data( $sale_raw );
			if ( isset( $sale_prices[ $role_key ] ) && self::non_empty_price( $sale_prices[ $role_key ] ) ) {
				return $sale_prices[ $role_key ];
			}
			foreach ( $sale_prices as $key => $value ) {
				if ( $role_key === sanitize_key( $key ) && self::non_empty_price( $value ) ) {
					return $value;
				}
			}
			// Older exports occasionally used a scalar salePrice. It is a
			// common sale value, so it is safe to use as a fallback for the
			// role while retaining the normal per-role structure on write.
			if ( self::non_empty_price( $sale_raw ) ) {
				return $sale_raw;
			}
			return '';
		}

		if ( isset( $data[ $role_key ] ) && self::non_empty_price( $data[ $role_key ] ) ) {
			return $data[ $role_key ];
		}

		foreach ( $data as $key => $value ) {
			if ( self::FESTI_SALE_KEY === $key || $role_key !== sanitize_key( $key ) ) {
				continue;
			}
			if ( self::non_empty_price( $value ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Return a compatible role price from the canonical meta, Festi's JSON,
	 * or the common Alg/WooCommerce role-price meta names.
	 */
	public static function get_compatible_role_price( $post_id, $role, $type = 'regular' ) {
		$post_id  = absint( $post_id );
		$role_key = sanitize_key( $role );
		if ( ! $post_id || '' === $role_key ) {
			return '';
		}

		$canonical_key = 'sale' === $type ? self::sale_meta_key( $role_key ) : self::regular_meta_key( $role_key );
		$canonical     = self::get_raw_meta_value( $post_id, $canonical_key );
		if ( self::non_empty_price( $canonical ) ) {
			return $canonical;
		}

		$festi = self::get_festi_role_price( $post_id, $role_key, $type );
		if ( self::non_empty_price( $festi ) ) {
			return $festi;
		}

		// Keep existing installations using the other popular role-price
		// plugin readable.  The new canonical keys remain our write target.
		global $wpdb;
		$alg_pattern = $wpdb->esc_like( '_alg_wc_price_' ) . '%';
		$meta_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
				$post_id,
				$alg_pattern
			)
		);
		$all_meta = array();
		foreach ( (array) $meta_rows as $meta_row ) {
			$all_meta[ (string) $meta_row->meta_key ][] = (string) $meta_row->meta_value;
		}

		$wanted = 'sale' === $type ? 'sale' : 'regular';
		foreach ( $all_meta as $meta_key => $values ) {
			$meta_key = (string) $meta_key;
			$matched  = false;
			$meta_role = '';

			if ( preg_match( '/^_alg_wc_price_(?:badminiy_)?user_role_(regular|sale)_price_(.+)$/', $meta_key, $matches ) ) {
				$matched   = true;
				$meta_type = $matches[1];
				$meta_role = sanitize_key( $matches[2] );
			} elseif ( preg_match( '/^_alg_wc_price_(?:badminiy_)?user_role_(.+)_(regular|sale)_price$/', $meta_key, $matches ) ) {
				$matched   = true;
				$meta_type = $matches[2];
				$meta_role = sanitize_key( $matches[1] );
			}

			if ( ! $matched || $meta_type !== $wanted || $meta_role !== $role_key || ! is_array( $values ) ) {
				continue;
			}

			foreach ( $values as $value ) {
				if ( self::non_empty_price( $value ) ) {
					return $value;
				}
			}
		}

		return '';
	}

	/**
	 * Update the compatible Festi JSON without dropping schedule/unknown keys.
	 * $prices is role => array( 'regular' => ..., 'sale' => ... ).
	 */
	public static function update_festi_role_prices( $post_id, array $prices ) {
		$post_id = absint( $post_id );
		if ( ! $post_id || empty( $prices ) ) {
			return false;
		}

		$data = self::get_festi_role_prices( $post_id );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$sale_prices = isset( $data[ self::FESTI_SALE_KEY ] ) ? self::decode_price_data( $data[ self::FESTI_SALE_KEY ] ) : array();
		if ( ! is_array( $sale_prices ) ) {
			$sale_prices = array();
		}

		foreach ( $prices as $role => $values ) {
			$role = sanitize_key( $role );
			if ( '' === $role || ! is_array( $values ) ) {
				continue;
			}

			if ( array_key_exists( 'regular', $values ) ) {
				$data[ $role ] = (string) $values['regular'];
			}
			if ( array_key_exists( 'sale', $values ) ) {
				$sale_prices[ $role ] = (string) $values['sale'];
			}
		}

		if ( ! empty( $sale_prices ) || isset( $data[ self::FESTI_SALE_KEY ] ) ) {
			$data[ self::FESTI_SALE_KEY ] = $sale_prices;
		}

		$json = wp_json_encode( $data );
		if ( false === $json ) {
			return false;
		}

		update_post_meta( $post_id, self::FESTI_META_KEY, $json );
		return true;
	}
}
