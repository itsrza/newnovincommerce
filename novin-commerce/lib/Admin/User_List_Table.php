<?php

namespace MobinDev\Novin_Commerce\Admin;

class User_List_Table extends List_Table {
	protected $name = 'user';

// just the barebone implementation.
	public function get_columns() {
		return [
			'cb'        => '<input type="checkbox" />',
			'name'      => 'نام مشتری',
			'login'     => 'نام کاربری',
			'role'      => 'نقش',
			'mobile'    => 'موبایل',
			'email'     => 'ایمیل',
			'id'        => 'ID',
			'guid'      => 'GUID',
			'sync_date' => 'زمان همگام‌سازی',
		];
	}

	/**
	 * @param \WP_User $item
	 * @param string $column_name
	 *
	 * @return mixed|void
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'login':
				return urldecode( $item->user_login );
			case 'role':
				return implode( ', ', $item->roles );
			case 'id':
				return $item->ID;
			case 'mobile':
				return get_user_meta( $item->ID, 'billing_phone', true );
			case 'email':
				return $item->user_email;

		}
	}


	public function get_sortable_columns() {
		return [
			'id'        => array( 'id', true ),
			'name'      => 'name',
			'login'     => 'login',
			'role'      => 'role',
			'guid'      => 'guid',
			'sync_date' => 'sync_date',

		];
	}

	/**
	 * @return int[]|string|string[]|\WP_Error|\WP_User[]
	 */
	public function fetchTableData() {
		$search = isset( $_REQUEST['s'] ) ? wp_unslash( trim( $_REQUEST['s'] ) ) : null;

		return get_users( [
			'search' => $search
		] );

	}

	/**
	 * @param \WP_User $item
	 *
	 * @return string
	 */
	function getName( $item ) {
		return $item->first_name . ' ' . $item->last_name; //$item->display_name;
	}

	/**
	 * @param \WP_User $item
	 *
	 * @return string|null
	 */
	function getSyncDate( $item ) {
		return get_user_meta( $item->ID, '_np-api-sync-date', true );
	}

	/**
	 * @param \WP_User $item
	 *
	 * @return int
	 */
	function getID( $item ) {
		return $item->ID;
	}

	/**
	 * @param \WP_User $item
	 *
	 * @return string|null
	 */
	function getGUID( $item ) {
		return get_user_meta( $item->ID, 'guid', true );
	}
}
