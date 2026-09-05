<?php

namespace MobinDev\Novin_Commerce\Admin;

class Order_List_Table extends List_Table {
	protected $name = 'order';

// just the barebone implementation.
	public function get_columns() {
		return [
			'cb'        => '<input type="checkbox" />',
			'name'      => 'نام',
			'total'     => 'مبلغ',
			'user'      => 'کاربر',
			'id'        => 'ID',
			'guid'      => 'GUID',
			'sync_date' => 'زمان همگام‌سازی',
		];
	}

	/**
	 * @param \WC_Order $item
	 * @param string $column_name
	 *
	 * @return mixed|void
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'total':
				return $item->get_total();
			case 'user':
				$user = $item->get_user();
				return $user instanceof \WP_User
					? esc_html($user->display_name)
					: 'مهمان';
		}
	}


	public function get_sortable_columns() {
		return [
			'id'        => array( 'id', true ),
			'name'      => 'name',
			'total'     => 'total',
			'user'      => 'user',
			'guid'      => 'guid',
			'sync_date' => 'sync_date',

		];
	}


	public function fetchTableData() {
		$query_args = [];
		$search     = isset( $_REQUEST['s'] ) ? wp_unslash( trim( $_REQUEST['s'] ) ) : null;

		if ( $search ) {
			$query_args['like_name'] = $search;
		}

		$query = new \WC_Order_Query(
			array_merge(
				[ 'limit' => - 1 ],
				$query_args
			)
		);

		return $query->get_orders();
	}

	/**
	 * @param \WC_Order $item
	 *
	 * @return mixed
	 */
	function getName( $item ) {
		return '#'.$item->get_id();
	}

	function getSyncDate( $item ) {
		return $item->get_meta( '_np-api-sync-date' );
	}

	function getID( $item ) {
		return $item->get_id();
	}

	function getGUID( $item ) {
		return $item->get_meta( 'guid' );
	}
}
