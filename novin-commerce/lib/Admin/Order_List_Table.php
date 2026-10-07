<?php

namespace Novinwp\Novin_Commerce\Admin;

class Order_List_Table extends List_Table {
	protected $name = 'order';

// just the barebone implementation.
	public function get_columns() {
		return [
			'cb'        => '<input type="checkbox" />',
			'name'      => 'نام',
			'total'     => 'مبلغ',
			'user'      => 'کاربر',
			'id'        => 'شناسه',
			'guid'      => 'شناسه حسابداری',
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
				$total = $item->get_total();
				return function_exists( 'wc_price' ) ? wc_price( $total ) : esc_html( (string) $total );
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
			'name'      => array( 'name', true ),
			'total'     => array( 'total', false ),
			'user'      => array( 'user', false ),
			'guid'      => array( 'guid', false ),
			'sync_date' => array( 'sync_date', false ),
		];
	}


	public function fetchTableData() {
		$search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( trim( (string) $_REQUEST['s'] ) ) ) : '';
		$per_page     = min( 100, max( 1, (int) $this->get_items_per_page( 'per_page', 20 ) ) );
		$current_page = max( 1, (int) $this->get_pagenum() );
		$requested_orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'id';
		$requested_order   = isset( $_REQUEST['order'] ) && 'asc' === strtolower( (string) wp_unslash( $_REQUEST['order'] ) ) ? 'ASC' : 'DESC';
		$order_map         = array( 'id' => 'ID', 'name' => 'date', 'total' => 'total', 'user' => 'customer_id' );
		$query_args   = array(
			'limit'    => $per_page,
			'paged'    => $current_page,
			'paginate' => true,
			'orderby'  => $order_map[ $requested_orderby ] ?? 'ID',
			'order'    => $requested_order,
		);
		if ( $search ) {
			// WooCommerce's CRUD query keeps this path HPOS-safe. The wildcard
			// search is intentionally bounded by the page query above.
			$query_args['search'] = '*' . $search . '*';
		}
		$query  = new \WC_Order_Query( $query_args );
		$result = $query->get_orders();
		if ( is_object( $result ) && isset( $result->orders, $result->total ) ) {
			$this->total_items = (int) $result->total;
			return (array) $result->orders;
		}
		// Older WooCommerce versions may not expose paginate. Keep the page
		// bounded and make the limitation visible to pagination rather than
		// loading every order into memory.
		$orders            = is_array( $result ) ? $result : array();
		$this->total_items = count( $orders );
		return $orders;
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
