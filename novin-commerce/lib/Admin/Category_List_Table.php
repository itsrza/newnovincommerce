<?php

namespace MobinDev\Novin_Commerce\Admin;

class Category_List_Table extends List_Table {
	protected $name = 'category';

	public function get_columns() {
		return array(
			'cb'        => '<input type="checkbox" />',
			'name'      => 'نام دسته‌بندی',
			'slug'      => 'نامک',
			'quantity'  => 'تعداد کالا',
			'id'        => 'شناسه',
			'guid'      => 'شناسه حسابداری',
			'sync_date' => 'زمان همگام‌سازی',
		);
	}

	/**
	 * @param \WP_Term $item
	 * @param string   $column_name
	 * @return mixed
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'slug':
				return esc_html( urldecode( (string) $item->slug ) );
			case 'quantity':
				// WP_Term->count is populated by get_terms() and is the
				// authoritative object count for this taxonomy term.
				return number_format_i18n( (int) $item->count );
			case 'id':
				return absint( $item->term_id );
			case 'guid':
				return esc_html( (string) $this->getGUID( $item ) );
			case 'sync_date':
				return esc_html( (string) $this->getSyncDate( $item ) );
		}

		return '';
	}

	public function get_sortable_columns() {
		return array(
			'id'        => array( 'id', true ),
			'name'      => array( 'name', false ),
			'slug'      => array( 'slug', false ),
			'quantity'  => array( 'quantity', false ),
			'guid'      => array( 'guid', false ),
			'sync_date' => array( 'sync_date', false ),
		);
	}

	/**
	 * @return \WP_Error|\WP_Term[]
	 */
	public function fetchTableData() {
		$search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( trim( (string) $_REQUEST['s'] ) ) ) : '';
		$per_page     = min( 100, max( 1, (int) $this->get_items_per_page( 'per_page', 20 ) ) );
		$current_page = max( 1, (int) $this->get_pagenum() );
		$orderby      = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'name';
		$order        = isset( $_REQUEST['order'] ) && 'desc' === strtolower( (string) wp_unslash( $_REQUEST['order'] ) ) ? 'DESC' : 'ASC';
		$term_orderby = array( 'id' => 'term_id', 'name' => 'name', 'slug' => 'slug', 'quantity' => 'count' );
		$args         = array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'search'     => $search,
			'number'     => $per_page,
			'offset'     => ( $current_page - 1 ) * $per_page,
			'orderby'    => $term_orderby[ $orderby ] ?? 'name',
			'order'      => $order,
		);
		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			$this->total_items = 0;
			return array();
		}

		$count_args           = $args;
		$count_args['fields'] = 'count';
		$count_args['number'] = 0;
		$count_args['offset'] = 0;
		$total                = get_terms( $count_args );
		$this->total_items    = is_wp_error( $total ) ? 0 : (int) $total;
		return $terms; // LIMIT/OFFSET is applied by WP_Term_Query.
	}

	public function getName( $item ) {
		return $item->name;
	}

	public function getSyncDate( $item ) {
		return get_term_meta( $item->term_id, '_np-api-sync-date', true );
	}

	public function getID( $item ) {
		return $item->term_id;
	}

	public function getGUID( $item ) {
		return get_term_meta( $item->term_id, 'guid', true );
	}
}
