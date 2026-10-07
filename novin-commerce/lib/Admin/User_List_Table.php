<?php

namespace Novinwp\Novin_Commerce\Admin;

use Novinwp\Novin_Commerce\Common\Text_Encoding;
use Morilog\Jalali\Jalalian;

class User_List_Table extends List_Table {
	protected $name = 'user';

	public function get_columns() {
		return array(
			'cb'        => '<input type="checkbox" />',
			'name'      => 'نام مشتری',
			'login'     => 'نام کاربری',
			'role'      => 'نقش',
			'mobile'    => 'موبایل',
			'email'     => 'ایمیل',
			'id'        => 'شناسه',
			'guid'      => 'شناسه حسابداری',
			'sync_date' => 'زمان همگام‌سازی',
		);
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'login':
				return esc_html( Text_Encoding::normalize( urldecode( (string) $item->user_login ) ) );
			case 'role':
				$labels = array(
					'administrator' => 'مدیرکل',
					'editor'        => 'ویرایشگر',
					'author'        => 'نویسنده',
					'contributor'   => 'مشارکت‌کننده',
					'subscriber'    => 'مشترک',
					'customer'      => 'مشتری',
					'shop_manager'  => 'مدیر فروشگاه',
				);
				$roles = array();
				foreach ( (array) $item->roles as $role ) {
					$roles[] = $labels[ $role ] ?? 'نقش کاربری';
				}
				return esc_html( implode( '، ', $roles ) );
			case 'id':
				return absint( $item->ID );
			case 'mobile':
				return esc_html( Text_Encoding::normalize( get_user_meta( $item->ID, 'billing_phone', true ) ) );
			case 'email':
				return esc_html( Text_Encoding::normalize( $item->user_email ) );
		}

		return '';
	}

	public function get_sortable_columns() {
		return array(
			'id'        => array( 'id', true ),
			'name'      => array( 'name', false ),
			'login'     => array( 'login', false ),
			'role'      => array( 'role', false ),
			'guid'      => array( 'guid', false ),
			'sync_date' => array( 'sync_date', false ),
		);
	}

	public function fetchTableData() {
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( trim( (string) $_REQUEST['s'] ) ) ) : '';

		return get_users(
			array(
				'search' => $search,
			)
		);
	}

	public function getName( $item ) {
		$name = trim( Text_Encoding::normalize( $item->first_name . ' ' . $item->last_name ) );
		return '' !== $name ? $name : Text_Encoding::normalize( $item->display_name );
	}

	/**
	 * Read the accounting modification time from WebCus JSON first. The old
	 * sync-date meta is retained only as a fallback for older users without a
	 * WebCus payload.
	 *
	 * @param \WP_User $item User.
	 * @return string
	 */
	public function getSyncDate( $item ) {
		$webcus = get_user_meta( $item->ID, 'WebCus', true );
		$data   = $this->decode_webcus( $webcus );
		$modified = $this->find_key( $data, 'Modified' );
		if ( '' !== trim( (string) $modified ) ) {
			return Text_Encoding::normalize( $modified );
		}

		return (string) get_user_meta( $item->ID, '_np-api-sync-date', true );
	}

	/**
	 * Format the WebCus Modified value in the site's local timezone and the
	 * familiar Jalali date format used by the other admin tables.
	 *
	 * @param \WP_User $item User.
	 * @return string
	 */
	public function column_sync_date( $item ) {
		$raw = $this->getSyncDate( $item );
		if ( '' === trim( $raw ) ) {
			return '—';
		}

		$timestamp = $this->parse_timestamp( $raw );
		if ( ! $timestamp ) {
			return esc_html( $raw );
		}

		try {
			$local = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() );
			return esc_html( Jalalian::fromDateTime( $local, wp_timezone() )->format( 'Y/m/d H:i' ) );
		} catch ( \Throwable $e ) {
			return esc_html( wp_date( 'Y/m/d H:i', $timestamp, wp_timezone() ) );
		}
	}

	public function getID( $item ) {
		return $item->ID;
	}

	public function getGUID( $item ) {
		return get_user_meta( $item->ID, 'guid', true );
	}

	private function decode_webcus( $raw ) {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_object( $raw ) ) {
			$raw = wp_json_encode( $raw );
		}
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}

		$decoded = json_decode( Text_Encoding::normalize( $raw ), true, 20 );
		return is_array( $decoded ) && JSON_ERROR_NONE === json_last_error() ? $decoded : array();
	}

	private function find_key( $data, $wanted ) {
		if ( ! is_array( $data ) ) {
			return '';
		}
		foreach ( $data as $key => $value ) {
			if ( strtolower( (string) $key ) === strtolower( $wanted ) && ! is_array( $value ) && ! is_object( $value ) ) {
				return (string) $value;
			}
			if ( is_array( $value ) ) {
				$nested = $this->find_key( $value, $wanted );
				if ( '' !== $nested ) {
					return $nested;
				}
			}
		}
		return '';
	}

	private function parse_timestamp( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}
		if ( ctype_digit( $value ) ) {
			$timestamp = (int) $value;
			return $timestamp > 100000000 ? $timestamp : 0;
		}

		// Some accounting installations send a Jalali Modified value.
		if ( preg_match( '/^14\d{2}[\/-]/', $value ) ) {
			foreach ( array( 'Y/m/d H:i:s', 'Y-m-d H:i:s', 'Y/m/d H:i', 'Y-m-d H:i', 'Y/m/d', 'Y-m-d' ) as $format ) {
				try {
					return Jalalian::fromFormat( $format, str_replace( '-', '/', $value ), wp_timezone() )->getTimestamp();
				} catch ( \Throwable $e ) {
					// Try the next provider format.
				}
			}
		}

		$timestamp = strtotime( str_replace( '/', '-', $value ) );
		return false === $timestamp ? 0 : (int) $timestamp;
	}
}
