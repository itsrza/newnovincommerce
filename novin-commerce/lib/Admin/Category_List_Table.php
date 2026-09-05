<?php

namespace MobinDev\Novin_Commerce\Admin;

class Category_List_Table extends List_Table {
	protected $name = 'category';

// just the barebone implementation.
	public function get_columns() {
		return [
			'cb'        => '<input type="checkbox" />',
			'name'      => 'نام دسته‌بندی',
			'slug'      => 'نامک',
			'quantity'  => 'تعداد',
			'id'        => 'ID',
			'guid'      => 'GUID',
			'sync_date' => 'زمان همگام‌سازی',
		];
	}

	/**
	 * @param \WP_Term $item
	 * @param string $column_name
	 *
	 * @return mixed|void
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'slug':
				return urldecode( $item->slug );
			case 'stock_quantity':
				return $item->count;
			case 'id':
				return $item->term_id;

		}
	}


	public function get_sortable_columns() {
		return [
			'id'        => array( 'id', true ),
			'name'      => 'name',
			'slug'      => 'slug',
			'quantity'  => 'quantity',
			'guid'      => 'guid',
			'sync_date' => 'sync_date',

		];
	}

	/**
	 * @return int[]|string|string[]|\WP_Error|\WP_Term[]
	 */
	public function fetchTableData() {
		$query_args = [];
		$search     = isset( $_REQUEST['s'] ) ? wp_unslash( trim( $_REQUEST['s'] ) ) : null;

		return get_terms( [
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'search'     => $search,
			'count'=>true,
		] );

	}

	/**
	 * @param \WP_Term $item
	 *
	 * @return string
	 */
	function getName( $item ) {
		return $item->name;
	}

	/**
	 * @param \WP_Term $item
	 *
	 * @return string|null
	 */
	function getSyncDate( $item ) {
		return get_term_meta( $item->term_id, '_np-api-sync-date',true );
	}

	/**
	 * @param \WP_Term $item
	 *
	 * @return int
	 */
	function getID( $item ) {
		return $item->term_id;
	}

	/**
	 * @param \WP_Term $item
	 *
	 * @return string|null
	 */
	function getGUID( $item ) {
		return get_term_meta( $item->term_id, 'guid' ,true);
	}
}
