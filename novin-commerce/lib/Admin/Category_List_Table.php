<?php

namespace MobinDev\Novin_Commerce\Admin;

class Category_List_Table extends List_Table {
	protected $name = 'category';

	public function get_columns() {
		return array(
			'cb'       => '<input type="checkbox" />',
			'name'     => 'نام دسته‌بندی',
			'slug'     => 'نامک',
			'quantity' => 'تعداد کالا',
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
		}

		return '';
	}

	public function get_sortable_columns() {
		return array(
			'name'     => array( 'name', false ),
			'slug'     => array( 'slug', false ),
			'quantity' => array( 'quantity', false ),
		);
	}

	/**
	 * @return \WP_Error|\WP_Term[]
	 */
	public function fetchTableData() {
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( trim( (string) $_REQUEST['s'] ) ) ) : '';

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'search'     => $search,
			)
		);

		return is_wp_error( $terms ) ? array() : $terms;
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
