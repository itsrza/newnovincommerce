<?php

namespace Novinwp\Novin_Commerce\Admin;

use MobinDev\Novin_Commerce\Common\Accounting\WebPrd\WebPrd_Parser;

use MobinDev\Novin_Commerce\Common\Accounting\WebPrd\WebPrd_Parser;

/**
 * Optimized WooCommerce product/variation list for Novin Commerce.
 *
 * Variations are loaded only for the current page. This avoids loading the
 * complete catalog into PHP memory when a store has many products.
 */
class Product_List_Table extends List_Table {
	protected $name = 'product';
	/** @var int|null Total rows returned by the SQL query before pagination. */
	protected $total_items = null;

	public function get_columns() {
		return [
			'cb'              => '<input type="checkbox" />',
			'name'            => 'نام کالا',
			'type'            => 'نوع',
			'sku'             => 'شناسه کالا',
			'category'        => 'دسته‌بندی',
			'price'           => 'قیمت',
			'stock_quantity'  => 'موجودی',
			'accounting'      => 'وضعیت حسابداری',
			'sync_date'       => 'زمان همگام‌سازی',
		];
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'name':
				return $this->column_name_with_slug( $item );
			case 'type':
				if ( $item->is_type( 'variation' ) ) {
					return '<span class="novin-product-type novin-product-variation">تنوع متغیر</span>';
				}
				if ( $item->is_type( 'variable' ) ) {
					return '<span class="novin-product-type novin-product-variable">کالای متغیر</span>';
				}
				return '<span class="novin-product-type">کالای ساده</span>';
			case 'sku':
				return $this->column_sku( $item );
			case 'category':
				if ( $item->is_type( 'variation' ) ) {
					$parent_id = $item->get_parent_id();
					return $parent_id ? wc_get_product_category_list( $parent_id ) : '—';
				}
				return wc_get_product_category_list( $item->get_id() );
			case 'price':
				$price = $item->get_price();
				return '' !== (string) $price ? '<span class="novin-num">' . wc_price( $price ) . '</span>' : '—';
			case 'stock_quantity':
				$stock = $item->get_stock_quantity();
				return null === $stock ? '—' : '<span class="novin-num">' . esc_html( (string) $stock ) . '</span>';
			case 'id':
				return '<span class="novin-num">' . absint( $item->get_id() ) . '</span>';
			case 'accounting':
				return $this->column_accounting_name( $item );
		default:
				return '';
		}
	}

	public function column_name_with_slug( $item ) {
		$name = esc_html( $this->getName( $item ) );
		$edit_url = esc_url( $this->getEditUrl( $item ) );
		$slug = (string) $item->get_slug();
		$slug_html = '' !== $slug
			? sprintf(
				'<button type="button" class="copyable novin-slug-copy" data-copy="%1$s" title="برای کپی کلیک کنید">%2$s</button>',
				esc_attr( $slug ),
				esc_html( urldecode( $slug ) )
			)
			: '';
		return sprintf(
			'<strong><a class="row-title" href="%1$s">%2$s</a></strong>%3$s',
			$edit_url,
			$name,
			$slug_html ? '<div class="novin-row-sub">' . $slug_html . '</div>' : ''
		);
	}

	public function get_sortable_columns() {
		return [
			'id'              => [ 'id', true ],
			'name'            => [ 'name', true ],
			'sku'             => [ 'sku', true ],
			'price'           => [ 'price', true ],
			'stock_quantity'  => [ 'stock_quantity', true ],
			'accounting'      => [ 'guid', false ],
			'sync_date'       => [ 'sync_date', true ],
		];
	}

	/**
	 * Fetch only the current page of products and variations.
	 *
	 * @return \WC_Product[]
	 */
	public function fetchTableData() {
		global $wpdb;

		$per_page     = min( 100, max( 1, (int) $this->get_items_per_page( 'per_page', 20 ) ) );
		$current_page = max( 1, (int) $this->get_pagenum() );
		$offset       = ( $current_page - 1 ) * $per_page;
		$search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$guid_filter  = isset( $_REQUEST['guid_filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['guid_filter'] ) ) : 'all';
		$type_filter  = isset( $_REQUEST['type_filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['type_filter'] ) ) : 'all';
		$sync_filter  = isset( $_REQUEST['sync_filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['sync_filter'] ) ) : 'all';
		$orderby      = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'name';
		$order        = isset( $_REQUEST['order'] ) && 'desc' === strtolower( wp_unslash( $_REQUEST['order'] ) ) ? 'DESC' : 'ASC';

		$allowed_orderby = [
			'id'             => 'p.ID',
			'name'           => 'p.post_title',
			'slug'           => 'p.post_name',
			'sku'            => 'sku.meta_value',
			'price'          => 'price.meta_value+0',
			'stock_quantity' => 'stock.meta_value+0',
				'guid'           => 'guid.meta_value',
				'accounting'     => 'guid.meta_value',
				'sync_date'      => 'sync.meta_value',
		];
		$order_by_sql = $allowed_orderby[ $orderby ] ?? 'p.post_title';

		$where = [
			"p.post_type IN ('product', 'product_variation')",
			"p.post_status = 'publish'",
		];
		$join  = [];
		$args  = [];

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$join[] = "LEFT JOIN {$wpdb->postmeta} search_sku ON search_sku.post_id = p.ID AND search_sku.meta_key = '_sku'";
			$join[] = "LEFT JOIN {$wpdb->postmeta} search_guid ON search_guid.post_id = p.ID AND search_guid.meta_key = 'guid'";
			$where[] = '(p.post_title LIKE %s OR p.post_name LIKE %s OR search_sku.meta_value LIKE %s OR search_guid.meta_value LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
		}

		if ( 'variation' === $type_filter ) $where[] = "p.post_type = 'product_variation'";
		if ( 'product' === $type_filter ) $where[] = "p.post_type = 'product'";
		if ( 'pending' === $sync_filter ) { $join[] = "LEFT JOIN {$wpdb->postmeta} sync_filter ON sync_filter.post_id=p.ID AND sync_filter.meta_key='_np-api-sync-date'"; $where[] = "(sync_filter.meta_id IS NULL OR sync_filter.meta_value='')"; }
		if ( 'synced' === $sync_filter ) { $join[] = "LEFT JOIN {$wpdb->postmeta} sync_filter ON sync_filter.post_id=p.ID AND sync_filter.meta_key='_np-api-sync-date'"; $where[] = "sync_filter.meta_id IS NOT NULL AND sync_filter.meta_value<>''"; }

		if ( in_array( $guid_filter, [ 'has_guid', 'no_guid' ], true ) ) {
			$join[] = "LEFT JOIN {$wpdb->postmeta} filter_guid ON filter_guid.post_id = p.ID AND filter_guid.meta_key = 'guid'";
			if ( 'has_guid' === $guid_filter ) {
				$where[] = "filter_guid.meta_value IS NOT NULL AND filter_guid.meta_value <> ''";
			} else {
				$where[] = "(filter_guid.meta_id IS NULL OR filter_guid.meta_value = '')";
			}
		}

		// Join sortable meta only when needed. This keeps the normal list query lean.
		if ( 'sku' === $orderby ) {
			$join[] = "LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'";
		}
		if ( 'price' === $orderby ) {
			$join[] = "LEFT JOIN {$wpdb->postmeta} price ON price.post_id = p.ID AND price.meta_key = '_price'";
		}
		if ( 'stock_quantity' === $orderby ) {
			$join[] = "LEFT JOIN {$wpdb->postmeta} stock ON stock.post_id = p.ID AND stock.meta_key = '_stock'";
		}
		if ( in_array( $orderby, [ 'guid', 'accounting' ], true ) ) {
			$join[] = "LEFT JOIN {$wpdb->postmeta} guid ON guid.post_id = p.ID AND guid.meta_key = 'guid'";
		}
		if ( 'sync_date' === $orderby ) {
			$join[] = "LEFT JOIN {$wpdb->postmeta} sync ON sync.post_id = p.ID AND sync.meta_key = '_np-api-sync-date'";
		}

		$join_sql  = implode( ' ', array_unique( $join ) );
		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$join_sql} WHERE {$where_sql}";
		$count     = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) );
		$this->total_items = $count;

		$data_sql = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p {$join_sql} WHERE {$where_sql} ORDER BY {$order_by_sql} {$order}, p.ID ASC LIMIT %d OFFSET %d";
		$data_args = $args;
		$data_args[] = $per_page;
		$data_args[] = $offset;
		$ids = $wpdb->get_col( $wpdb->prepare( $data_sql, $data_args ) );

		$products = [];
		foreach ( $ids as $id ) {
			$product = wc_get_product( absint( $id ) );
			if ( $product ) {
				$products[] = $product;
			}
		}

		$this->set_pagination_args([
			'total_items' => $count,
			'per_page'    => $per_page,
			'total_pages' => $count > 0 ? (int) ceil( $count / $per_page ) : 0,
		]);

		return $products;
	}

	private function decode_value( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		// Variation attribute values can arrive URL-encoded. Decode only when it is actually encoded.
		$decoded = rawurldecode( $value );
		return $decoded !== $value ? $decoded : $value;
	}

	public function getName( $item ) {
		$name = $this->decode_value( $item->get_name() );
		if ( $item->is_type( 'variation' ) ) {
			$parent_id = absint( $item->get_parent_id() );
			$parent = $parent_id ? wc_get_product( $parent_id ) : false;
			if ( $parent ) {
				$attributes = $item->get_variation_attributes();
				$parts = [];
				foreach ( $attributes as $key => $value ) {
					$value = $this->decode_value( $value );
					if ( '' === trim( $value ) ) continue;
					$taxonomy = str_replace( 'attribute_', '', $key );
					$taxonomy = sanitize_key( $taxonomy );
					$label = taxonomy_exists( $taxonomy ) ? $this->decode_value( wc_attribute_label( $taxonomy ) ) : $this->decode_value( $taxonomy );
					if ( taxonomy_exists( $taxonomy ) ) {
						$term = get_term_by( 'slug', sanitize_title( $value ), $taxonomy );
						if ( $term && ! is_wp_error( $term ) ) $value = $term->name;
					}
					$parts[] = $label . ': ' . $value;
				}
				$name = $this->decode_value( $parent->get_name() ) . ( $parts ? ' — ' . implode( ', ', $parts ) : ' — تنوع متغیر' );
			}
		}
		return $name;
	}

	public function getID( $item ) {
		return $item->get_id();
	}

	public function getGUID( $item ) {
		$guid = trim( (string) $item->get_meta( 'guid', true ) );
		if ( '' !== $guid ) return $guid;
		return trim( (string) WebPrd_Parser::from( $item->get_meta( 'WebPrd', true ) )->identity()['guid'] );
	}

	public function getSourceGUID( $item ) {
		return trim( (string) WebPrd_Parser::from( $item->get_meta( 'WebPrd', true ) )->identity()['guid'] );
	}

	public function getSyncDate( $item ) {
		return $item->get_meta( '_np-api-sync-date', true );
	}

	public function getItemType( $item ) {
		return $item->is_type( 'variation' ) ? 'variation' : 'product';
	}

	public function getEditUrl( $item ) {
		if ( $item->is_type( 'variation' ) && $item->get_parent_id() ) {
			return admin_url( 'post.php?post=' . absint( $item->get_parent_id() ) . '&action=edit' );
		}
		return admin_url( 'post.php?post=' . absint( $item->get_id() ) . '&action=edit' );
	}

	public function column_slug( $item ) {
		$slug = (string) $item->get_slug();
		return sprintf(
			'<code class="copyable" data-copy="%1$s" style="cursor:pointer;" title="برای کپی کلیک کنید">%2$s</code>',
			esc_attr( $slug ),
			esc_html( urldecode( $slug ) )
		);
	}

	public function column_sku( $item ) {
		$sku = (string) $item->get_sku();
		if ( '' === $sku ) {
			return '<span style="color:#999;">—</span>';
		}
		return sprintf(
			'<code class="copyable" data-copy="%1$s" style="cursor:pointer;" title="برای کپی کلیک کنید">%2$s</code>',
			esc_attr( $sku ),
			esc_html( urldecode( $sku ) )
		);
	}

	public function column_accounting_name( $item ) {
		$meta = $item->get_meta( 'WebPrd', true );
		$product_name = trim( $this->getName( $item ) );
		$guid = $this->getGUID( $item );

		if ( '' === (string) $meta && '' === $guid ) {
			return '<span class="novin-no-guid">بدون اتصال</span>';
		}

		$rows = [];

		if ( '' !== $guid ) {
			$rows[] = '<code class="copyable" data-copy="' . esc_attr( $guid ) . '" title="برای کپی کلیک کنید">' . esc_html( $guid ) . '</code>';
		}

			if ( '' !== (string) $meta ) {
				$acc_name = trim( (string) WebPrd_Parser::from( $meta )->catalog()['name'] );
				if ( '' === $acc_name ) $acc_name = trim( (string) $meta );
				$color = mb_strtolower( $acc_name ) !== mb_strtolower( $product_name ) ? 'novin-acc-mismatch' : 'novin-acc-match';
				$rows[] = '<span class="' . $color . '">' . esc_html( $acc_name ) . '</span>';
			}

		$output = implode( '<br>', $rows );

		if ( '' !== $guid || '' !== (string) $meta ) {
			$nonce = wp_create_nonce( 'remove_guid_' . $item->get_id() );
			$item_type = $this->getItemType( $item );
			$output .= sprintf(
				'<br><a href="#" class="remove-guid novin-disconnect-link" data-id="%d" data-type="%s" data-nonce="%s">قطع ارتباط با حسابداری</a>',
				absint( $item->get_id() ),
				esc_attr( $item_type ),
				esc_attr( $nonce )
			);
		}
		return $output;
	}
}
