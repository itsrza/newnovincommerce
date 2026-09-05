<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NovinCommerce_RolePrice_Roles {

	const OPTION_KEY = 'wcpbr_roles_config';

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
			$config = self::default_config();
			update_option( self::OPTION_KEY, $config );
		}

		return $config;
	}

	public static function save_roles_config( array $config ) {
		update_option( self::OPTION_KEY, $config );
	}

	public static function get_roles() {
		$config = self::get_roles_config();
		$roles  = array();

		foreach ( $config as $role_key => $data ) {
			$role_key = sanitize_key( $role_key );
			if ( '' === $role_key || empty( $data['active'] ) ) {
				continue;
			}
			if ( ! self::wp_role_exists( $role_key ) ) {
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

		foreach ( $user->roles as $role ) {
			if ( in_array( $role, $defined_roles, true ) ) {
				return $role;
			}
		}

		return null;
	}

	public static function regular_meta_key( $role ) {
		return '_wcpbr_regular_price_' . $role;
	}

	public static function sale_meta_key( $role ) {
		return '_wcpbr_sale_price_' . $role;
	}
}
