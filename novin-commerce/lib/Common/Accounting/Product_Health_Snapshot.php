<?php

namespace MobinDev\Novin_Commerce\Common\Accounting;

use MobinDev\Novin_Commerce\Common\Accounting\WebPrd\WebPrd_Discount;
use MobinDev\Novin_Commerce\Common\Accounting\WebPrd\WebPrd_Parser;
use MobinDev\Novin_Commerce\Common\Accounting\WebPrd\WebPrd_Sync;
use MobinDev\Novin_Commerce\Common\SettingAPI;

/**
 * Read model for product health. Heavy WebPrd parsing happens when a product
 * changes or an explicit rebuild is requested; Dashboard requests only run
 * bounded aggregate SQL against this table.
 */
final class Product_Health_Snapshot {

	const TABLE_SUFFIX = 'novin_commerce_product_health';
	const TYPE_PRODUCT = 'product';
	const TYPE_VARIATION = 'variation';

	private static $booted = false;

	/** Register write-side invalidation/rebuild hooks. */
	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'save_post_product', array( __CLASS__, 'on_product_save' ), 99, 3 );
		add_action( 'save_post_product_variation', array( __CLASS__, 'on_variation_save' ), 99, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_post_delete' ), 99, 1 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta_change' ), 99, 4 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta_change' ), 99, 4 );
		add_action( 'admin_post_novin_health_backfill', array( __CLASS__, 'admin_backfill' ) );
	}

	/**
	 * Explicit bounded backfill. It is never called from a dashboard page view.
	 *
	 * @return array{processed:int,failed:int,next_offset:int}
	 */
	public static function backfill( $limit = 100, $offset = 0 ) {
		global $wpdb;
		$limit = min( 100, max( 1, absint( $limit ) ) );
		$offset = max( 0, absint( $offset ) );
		if ( ! self::table_exists() ) return array( 'processed' => 0, 'failed' => $limit, 'next_offset' => $offset );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status IN ('publish','draft','private') ORDER BY ID ASC LIMIT %d OFFSET %d", $limit, $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed post types and bounded pagination.
		$processed = 0; $failed = 0;
		foreach ( (array) $ids as $id ) {
			if ( self::rebuild( $id ) ) $processed++; else $failed++;
		}
		return array( 'processed' => $processed, 'failed' => $failed, 'next_offset' => $offset + count( (array) $ids ) );
	}

	public static function admin_backfill() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( esc_html__( 'دسترسی غیرمجاز.', 'novin-commerce' ) );
		check_admin_referer( 'novin_health_backfill' );
		$limit = isset( $_POST['limit'] ) ? min( 100, max( 1, absint( wp_unslash( $_POST['limit'] ) ) ) ) : 50;
		$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$result = self::backfill( $limit, $offset );
		$url = add_query_arg( array( 'page' => 'novin-commerce-dashboard', 'health_backfill' => $result['processed'], 'health_backfill_failed' => $result['failed'], 'health_backfill_offset' => $result['next_offset'] ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * @param int $post_id
	 * @param string $post_type product|variation
	 * @return bool
	 */
	public static function rebuild( $post_id, $post_type = '' ) {
		$post_id = absint( $post_id );
		if ( ! $post_id || ! function_exists( 'wc_get_product' ) || ! self::table_exists() ) {
			return false;
		}
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			self::delete( $post_id, self::TYPE_PRODUCT );
			self::delete( $post_id, self::TYPE_VARIATION );
			return false;
		}
		$type = $product->is_type( 'variation' ) ? self::TYPE_VARIATION : self::TYPE_PRODUCT;
		if ( '' !== $post_type && $post_type !== $type ) {
			return false;
		}
		$row = self::build_row( $product, $type );
		$columns = array_keys( $row );
		$formats = array();
		foreach ( $columns as $column ) {
			$formats[] = in_array( $column, array( 'item_id', 'parent_id', 'warehouse_count', 'source_price_count', 'role_price_count', 'role_price_missing', 'role_price_local', 'price_needs_review', 'is_base_unit' ), true ) ? '%d' : '%s';
		}
		$result = $GLOBALS['wpdb']->replace( self::table(), $row, $formats );
		return false !== $result;
	}

	public static function delete( $item_id, $item_type = '' ) {
		global $wpdb;
		if ( ! self::table_exists() ) {
			return false;
		}
		$where = array( 'item_id' => absint( $item_id ) );
		$formats = array( '%d' );
		if ( '' !== $item_type ) {
			$where['item_type'] = sanitize_key( $item_type );
			$formats[] = '%s';
		}
		return false !== $wpdb->delete( self::table(), $where, $formats );
	}

	public static function on_product_save( $post_id, $post, $update ) {
		if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( ! self::write_context_allowed() ) {
			return;
		}
		self::rebuild( $post_id, self::TYPE_PRODUCT );
	}

	public static function on_variation_save( $post_id, $post, $update ) {
		if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( ! self::write_context_allowed() ) {
			return;
		}
		self::rebuild( $post_id, self::TYPE_VARIATION );
	}

	public static function on_meta_change( $meta_id, $object_id, $meta_key, $meta_value ) {
		if ( 'WebPrd' !== $meta_key || ! self::write_context_allowed() ) {
			return;
		}
		self::rebuild( $object_id );
	}

	public static function on_post_delete( $post_id ) {
		$post_type = get_post_type( $post_id );
		if ( in_array( $post_type, array( 'product', 'product_variation' ), true ) ) {
			self::delete( $post_id );
		}
	}

	private static function write_context_allowed() {
		if ( is_admin() || wp_doing_cron() ) {
			return true;
		}
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	/**
	 * Return aggregate values only. No WebPrd is parsed in this method.
	 *
	 * @return array<string,mixed>
	 */
	public static function summary() {
		global $wpdb;
		$empty = array(
			'ready' => false,
			'catalog' => array( 'products' => 0, 'simple' => 0, 'variable' => 0, 'variations' => 0 ),
			'health' => array( 'healthy' => 0, 'attention' => 0, 'critical' => 0, 'unknown' => 0 ),
			'health_by_type' => array(
				'product' => array( 'healthy' => 0, 'attention' => 0, 'critical' => 0, 'unknown' => 0 ),
				'variation' => array( 'healthy' => 0, 'attention' => 0, 'critical' => 0, 'unknown' => 0 ),
			),
			'actions' => array( 'guid_mismatch' => 0, 'stale' => 0, 'inventory_mismatch' => 0, 'unit_unresolved' => 0, 'manual_price' => 0, 'role_without_price' => 0, 'price_review' => 0, 'sync_failed' => 0 ),
			'pricing' => array( 'accounting_levels' => 0, 'role_connected' => 0, 'role_without_price' => 0, 'manual' => 0, 'needs_review' => 0 ),
			'inventory' => array( 'known' => 0, 'mismatch' => 0, 'unknown' => 0, 'multi_unit' => 0, 'unresolved' => 0 ),
			'sync' => array( 'queued' => 0, 'processing' => 0, 'failed' => 0, 'stale' => 0 ),
			'last_updated' => null,
		);
		if ( ! self::table_exists() ) {
			return $empty;
		}
		$table = self::table();
		// The dashboard is a read model consumer: even catalog mix counts come
		// from the snapshot table, not from a second posts/term query that could
		// disagree with the Product/Variation identity rows.
		$catalog = $wpdb->get_row( "SELECT
			SUM(item_type = 'product') AS products,
			SUM(item_type = 'product' AND woo_type = 'simple') AS simple_count,
			SUM(item_type = 'product' AND woo_type = 'variable') AS variable_count,
			SUM(item_type = 'variation') AS variations
			FROM {$table} WHERE post_status = 'publish'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- bounded read-model aggregate.
		$health = $wpdb->get_results( "SELECT item_type, health_status, COUNT(*) AS total FROM {$table} WHERE post_status = 'publish' GROUP BY item_type, health_status" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- bounded read-model aggregate.
		$aggregate = $wpdb->get_row( "SELECT
			COUNT(*) AS snapshot_count,
			SUM(identity_state = 'guid_mismatch') AS guid_mismatch,
			SUM(sync_state = 'stale') AS stale,
			SUM(sync_state = 'failed') AS sync_failed,
			SUM(inventory_state = 'mismatch') AS inventory_mismatch,
			SUM(inventory_state = 'unknown') AS inventory_unknown,
			SUM(unit_mode = 'multi_unit') AS multi_unit,
			SUM(unit_mode = 'unresolved') AS unit_unresolved,
			SUM(price_needs_review = 1) AS price_review,
			SUM(role_price_local) AS manual_price,
			SUM(role_price_count) AS role_connected,
			SUM(role_price_missing) AS role_without_price,
			MAX(source_price_count) AS accounting_levels,
			SUM(sync_state = 'queued') AS queued,
			SUM(sync_state = 'processing') AS processing,
			SUM(inventory_state = 'known') AS inventory_known
			FROM {$table} WHERE post_status = 'publish'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- aggregate read model query.
		$last   = $wpdb->get_var( "SELECT MAX(updated_at) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
		$out = $empty;
		$out['ready'] = (bool) ( $aggregate && (int) $aggregate->snapshot_count > 0 );
		$out['catalog'] = array(
			'products'   => (int) ( $catalog->products ?? 0 ),
			'simple'     => (int) ( $catalog->simple_count ?? 0 ),
			'variable'   => (int) ( $catalog->variable_count ?? 0 ),
			'variations' => (int) ( $catalog->variations ?? 0 ),
		);
		foreach ( (array) $health as $row ) {
			$key  = sanitize_key( (string) $row->health_status );
			$type = sanitize_key( (string) $row->item_type );
			if ( array_key_exists( $key, $out['health'] ) ) {
				$out['health'][ $key ] += (int) $row->total;
			}
			if ( isset( $out['health_by_type'][ $type ] ) && array_key_exists( $key, $out['health_by_type'][ $type ] ) ) {
				$out['health_by_type'][ $type ][ $key ] = (int) $row->total;
			}
		}
		if ( $aggregate ) {
			$out['actions']['guid_mismatch'] = (int) $aggregate->guid_mismatch;
			$out['actions']['stale'] = (int) $aggregate->stale;
			$out['actions']['sync_failed'] = (int) $aggregate->sync_failed;
			$out['actions']['inventory_mismatch'] = (int) $aggregate->inventory_mismatch;
			$out['actions']['unit_unresolved'] = (int) $aggregate->unit_unresolved;
			$out['actions']['manual_price'] = (int) $aggregate->manual_price;
			$out['actions']['role_without_price'] = (int) $aggregate->role_without_price;
			$out['actions']['price_review'] = (int) $aggregate->price_review;
			$out['pricing']['accounting_levels'] = (int) $aggregate->accounting_levels;
			$out['pricing']['role_connected'] = (int) $aggregate->role_connected;
			$out['pricing']['role_without_price'] = (int) $aggregate->role_without_price;
			$out['pricing']['manual'] = (int) $aggregate->manual_price;
			$out['pricing']['needs_review'] = (int) $aggregate->price_review;
			$out['inventory']['known'] = (int) $aggregate->inventory_known;
			$out['inventory']['mismatch'] = (int) $aggregate->inventory_mismatch;
			$out['inventory']['unknown'] = (int) $aggregate->inventory_unknown;
			$out['inventory']['multi_unit'] = (int) $aggregate->multi_unit;
			$out['inventory']['unresolved'] = (int) $aggregate->unit_unresolved;
			$out['sync']['queued'] = (int) $aggregate->queued;
			$out['sync']['processing'] = (int) $aggregate->processing;
			$out['sync']['failed'] = (int) $aggregate->sync_failed;
			$out['sync']['stale'] = (int) $aggregate->stale;
		}
		$out['last_updated'] = $last;
		return $out;
	}

	/**
	 * @param int $item_id
	 * @param string $item_type
	 * @return object|null
	 */
	public static function find( $item_id, $item_type = '' ) {
		global $wpdb;
		if ( ! self::table_exists() ) return null;
		$sql = "SELECT * FROM " . self::table() . ' WHERE item_id = %d';
		$args = array( absint( $item_id ) );
		if ( '' !== $item_type ) { $sql .= ' AND item_type = %s'; $args[] = sanitize_key( $item_type ); }
		$sql .= ' LIMIT 1';
		return $wpdb->get_row( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL shape is fixed.
	}

	/**
	 * Bounded list read for the product table/detail pages.
	 *
	 * @param array<string,mixed> $filters
	 * @param int $page
	 * @param int $per_page
	 * @return array{rows:array<int,object>,total:int}
	 */
	public static function list_rows( array $filters = array(), $page = 1, $per_page = 20 ) {
		global $wpdb;
		$page = min( 5000, max( 1, absint( $page ) ) );
		$per_page = min( 100, max( 1, absint( $per_page ) ) );
		$where = array( '1=1' ); $args = array();
		$map = array( 'item_type', 'woo_type', 'identity_state', 'sync_state', 'inventory_state', 'unit_mode', 'discount_state', 'accounting_kind' );
		foreach ( $map as $key ) {
			if ( isset( $filters[ $key ] ) && '' !== (string) $filters[ $key ] && 'all' !== $filters[ $key ] ) { $where[] = "{$key} = %s"; $args[] = sanitize_key( $filters[ $key ] ); }
		}
		if ( ! empty( $filters['search'] ) ) { $where[] = '(woo_guid LIKE %s OR source_guid LIKE %s OR woo_sku LIKE %s OR source_sku LIKE %s)'; $like = '%' . $wpdb->esc_like( sanitize_text_field( $filters['search'] ) ) . '%'; $args = array_merge( $args, array( $like, $like, $like, $like ) ); }
		$base = ' FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*)' . $base, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed clauses/allow-list.
		$sql = 'SELECT *' . $base . ' ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d';
		$args[] = $per_page; $args[] = ( $page - 1 ) * $per_page;
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array( 'rows' => (array) $rows, 'total' => $total );
	}

	private static function db_datetime( $timestamp ) {
		return null === $timestamp ? null : gmdate( 'Y-m-d H:i:s', (int) $timestamp );
	}

	private static function table_exists() {
		global $wpdb;
		$table = self::table();
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/** @return array<string,mixed> */
	private static function build_row( $product, $type ) {
		$parent_id = self::TYPE_VARIATION === $type && is_callable( array( $product, 'get_parent_id' ) ) ? absint( $product->get_parent_id() ) : 0;
		$source_raw = is_callable( array( $product, 'get_meta' ) ) ? $product->get_meta( 'WebPrd', true ) : '';
		$parser = WebPrd_Parser::from( $source_raw );
		$identity = $parser->identity();
		$woo_guid = trim( (string) $product->get_meta( 'guid', true ) );
		$source_guid = trim( (string) $identity['guid'] );
		$identity_state = 'unknown';
		if ( ! $parser->is_valid() ) {
			$identity_state = 'source_missing';
		} elseif ( '' === $woo_guid || '' === $source_guid ) {
			$identity_state = 'missing_guid';
		} elseif ( $woo_guid !== $source_guid ) {
			$identity_state = 'guid_mismatch';
		} else {
			$identity_state = 'connected';
		}

		$sync = $parser->synchronization();
		$site_sync = $product->get_meta( '_np-api-sync-date', true );
		$sync_state = WebPrd_Sync::state( $sync['modified'], $site_sync );
		$queue_state = self::queue_state( $product->get_id(), $type );
		if ( '' !== $queue_state ) $sync_state = $queue_state;
		$inventory_state = 'unknown';
		$source_mojodi = null; $warehouse_stock = null; $warehouse_count = 0;
		$inventory = $parser->inventory();
		$source_mojodi = $inventory['source_mojodi'];
		$warehouse_count = (int) $inventory['warehouse_count_all'];
		$scope = Inventory_Scope::aggregate( $parser );
		if ( 'known' === $scope['status'] ) { $warehouse_stock = $scope['total']; $warehouse_count = (int) $scope['count']; }

		$unit_mode = Unit_Engine::MODE_NONE; $is_base = 0; $units_per_pack = null;
		$available_children = null;
		$unit_result = null;
		if ( self::TYPE_VARIATION === $type && $parent_id && function_exists( 'wc_get_product' ) ) {
			$parent = wc_get_product( $parent_id );
			$parent_parser = $parent ? WebPrd_Parser::from( $parent->get_meta( 'WebPrd', true ) ) : null;
			if ( $parent && $parent_parser && $parent_parser->is_valid() ) $unit_result = Unit_Engine::resolve_product( $parent, $parent_parser );
			if ( $unit_result && Unit_Engine::MODE_MULTI_UNIT === $unit_result['mode'] ) {
				$unit_mode = Unit_Engine::MODE_MULTI_UNIT;
				foreach ( $unit_result['units'] as $unit ) if ( (int) $unit['variation_id'] === (int) $product->get_id() ) { $is_base = 'base' === $unit['unit_key'] ? 1 : 0; $units_per_pack = $unit['units_per_pack']; break; }
			} elseif ( $unit_result && Unit_Engine::MODE_UNRESOLVED === $unit_result['mode'] ) { $unit_mode = Unit_Engine::MODE_UNRESOLVED; }
		} elseif ( self::TYPE_PRODUCT === $type && 'variable' === $product->get_type() ) {
			$unit_result = Unit_Engine::resolve_product( $product, $parser );
			$unit_mode = $unit_result['mode'];
			// A variable parent has no independent stock source. Its availability
			// is the aggregate of published, purchasable, in-stock variations.
			$available_children = self::available_variation_count( $product );
		}

		$woo_stock = $product->get_stock_quantity();
		if ( self::TYPE_VARIATION === $type && Unit_Engine::MODE_MULTI_UNIT === $unit_mode && null !== $warehouse_stock && null !== $units_per_pack ) {
			$calculated = Unit_Engine::calculate( $warehouse_stock, $units_per_pack );
			$inventory_state = null !== $woo_stock && $calculated && (int) $woo_stock === (int) $calculated['stock'] ? 'known' : 'mismatch';
		} elseif ( self::TYPE_VARIATION === $type && Unit_Engine::MODE_NONE === $unit_mode ) {
			// A normal variation owns its Woo stock independently; it is not
			// compared with an accounting warehouse total.
			$inventory_state = null !== $woo_stock ? 'known' : 'unknown';
		} elseif ( self::TYPE_PRODUCT === $type && 'variable' === $product->get_type() && Unit_Engine::MODE_MULTI_UNIT === $unit_mode && null !== $warehouse_stock ) {
			// The variable parent is an availability aggregate, never a second
			// stock source. Its child rows carry the unit calculation; the parent
			// state is still derived from purchasable child availability.
			$inventory_state = null !== $available_children ? 'known' : 'unknown';
		} elseif ( self::TYPE_PRODUCT === $type && 'variable' === $product->get_type() && Unit_Engine::MODE_NONE === $unit_mode ) {
			$inventory_state = null !== $available_children ? 'known' : 'unknown';
		} elseif ( 'variable' !== $product->get_type() && null !== $warehouse_stock && null !== $woo_stock ) {
			$inventory_state = (float) $woo_stock === (float) $warehouse_stock ? 'known' : 'mismatch';
		} elseif ( Unit_Engine::MODE_UNRESOLVED === $unit_mode ) {
			$inventory_state = 'unknown';
		}

		$pricing = $parser->pricing();
		$source_price_count = count( array_filter( $pricing['levels'], static function ( $level ) { return ! empty( $level['valid'] ); } ) );
		$invalid_price = count( array_filter( $pricing['levels'], static function ( $level ) { return null !== $level['value'] && ! $level['valid']; } ) );
		$role_price_count = 0; $role_price_missing = 0; $role_price_local = 0; $accounting_role_price_count = 0;
		if ( class_exists( '\NovinCommerce_RolePrice_Roles' ) ) {
			$roles = \NovinCommerce_RolePrice_Roles::get_roles();
			foreach ( $roles as $role => $label ) {
				$value = get_post_meta( $product->get_id(), \NovinCommerce_RolePrice_Roles::regular_meta_key( $role ), true );
				if ( is_numeric( $value ) && (float) $value > 0 ) {
					$role_price_count++;
					$origin = (string) get_post_meta( $product->get_id(), \NovinCommerce_RolePrice_Roles::origin_meta_key( $role ), true );
					if ( 'manual' === $origin || 'unknown' === $origin || '' === $origin ) $role_price_local++;
					if ( 'accounting' === $origin || 'inherited' === $origin || 'legacy' === $origin ) $accounting_role_price_count++;
				} else { $role_price_missing++; }
			}
		}
		$source_role_price_count = count( array_filter( $parser->role_prices(), static function ( $entry ) { return isset( $entry['price'] ) && null !== $entry['price']; } ) );
		$pricing_state = $parser->is_valid() ? ( $invalid_price > 0 || ( $accounting_role_price_count > 0 && 0 === $source_role_price_count ) ? 'attention' : 'known' ) : 'unknown';

		$sale_origin = (string) get_post_meta( $product->get_id(), '_novin_commerce_sale_origin', true );
		$discount = WebPrd_Discount::evaluate( $parser->discount(), $product->get_sale_price( 'edit' ) );
		$discount_state = 'none';
		if ( 'accounting' === $sale_origin ) $discount_state = $discount['state'];
		elseif ( '' !== (string) $product->get_sale_price( 'edit' ) ) $discount_state = 'manual';
		elseif ( null !== $parser->field( 'DiscountPercent', null ) || '' !== $parser->field( 'DiscountStartDate', '' ) || '' !== $parser->field( 'DiscountEndDate', '' ) ) $discount_state = $discount['state'];

		$media = $parser->media();
		$has_media = ! empty( $media['files'] ) || ! empty( $media['image_list_data'] ) || ! empty( $media['other_pics'] ) || null !== $media['pic'];
		$media_state = $has_media ? ( has_post_thumbnail( $product->get_id() ) ? 'available' : 'source_available' ) : 'none';
		$production = $parser->production();
		$kind_map = SettingAPI::get( 'accounting_kind_map', array() );
		$kind_key = null !== $production['kind'] ? (string) (int) $production['kind'] : '';
		$accounting_kind = is_array( $kind_map ) && isset( $kind_map[ $kind_key ] ) ? sanitize_key( $kind_map[ $kind_key ] ) : 'unknown';

		$flags = array();
		if ( in_array( $identity_state, array( 'guid_mismatch', 'missing_guid' ), true ) ) $flags[] = $identity_state;
		if ( 'mismatch' === $inventory_state ) $flags[] = 'inventory_mismatch';
		if ( Unit_Engine::MODE_UNRESOLVED === $unit_mode ) $flags[] = 'unit_mapping_unresolved';
		if ( in_array( $discount_state, array( 'expired', 'invalid' ), true ) ) $flags[] = 'accounting_discount_' . $discount_state;
		if ( 'stale' === $sync_state ) $flags[] = 'stale';
		if ( 'failed' === $sync_state ) $flags[] = 'sync_failed';
		if ( $invalid_price > 0 ) $flags[] = 'invalid_accounting_price';
		$health_status = self::overall_status( $identity_state, $inventory_state, $pricing_state, $unit_mode, $sync_state, $discount_state, $parser->is_valid() );

		return array(
			'item_id'            => absint( $product->get_id() ),
			'item_type'          => $type,
			'parent_id'          => $parent_id,
			'woo_type'           => $product->get_type(),
			'post_status'        => get_post_status( $product->get_id() ) ?: '',
			'woo_guid'           => $woo_guid,
			'source_guid'        => $source_guid,
			'woo_sku'            => (string) $product->get_sku(),
			'source_sku'         => (string) $identity['sku'],
			'source_modified'    => self::db_datetime( WebPrd_Sync::timestamp( $sync['modified'] ) ),
			'site_sync_date'     => self::db_datetime( WebPrd_Sync::timestamp( $site_sync ) ),
			'woo_stock'          => null === $woo_stock ? null : (string) $woo_stock,
			'source_mojodi'      => null === $source_mojodi ? null : (string) $source_mojodi,
			'warehouse_stock'    => null === $warehouse_stock ? null : (string) $warehouse_stock,
			'warehouse_count'    => $warehouse_count,
			'unit_mode'          => $unit_mode,
			'is_base_unit'       => $is_base,
			'units_per_pack'     => null === $units_per_pack ? null : (string) $units_per_pack,
			'source_price_count' => $source_price_count,
			'role_price_count'   => $role_price_count,
			'role_price_missing' => $role_price_missing,
			'role_price_local'   => $role_price_local,
			'price_needs_review' => ( $invalid_price > 0 || ( $accounting_role_price_count > 0 && 0 === $source_role_price_count ) ) ? 1 : 0,
			'discount_state'     => $discount_state,
			'media_state'        => $media_state,
			'accounting_kind'    => $accounting_kind,
			'identity_state'     => $identity_state,
			'inventory_state'    => $inventory_state,
			'pricing_state'      => $pricing_state,
			'sync_state'         => WebPrd_Sync::label( $sync_state ),
			'catalog_state'      => $product->get_type(),
			'source_version'     => (string) $sync['version'],
			'client_version'     => (string) $sync['client_version'],
			'server_status'      => (string) $sync['server_status'],
			'health_status'      => $health_status,
			'health_flags'       => wp_json_encode( array_values( array_unique( $flags ) ) ),
			'source_hash'        => $parser->hash(),
			'updated_at'         => current_time( 'mysql', true ),
		);
	}

	/**
	 * Return null when a parent has no child rows; otherwise return the count
	 * of children that can actually be purchased. This keeps the parent a
	 * derived availability row instead of treating every child as available.
	 *
	 * @param object $product
	 * @return int|null
	 */
	private static function available_variation_count( $product ) {
		$children = is_callable( array( $product, 'get_children' ) ) ? (array) $product->get_children() : array();
		if ( empty( $children ) || ! function_exists( 'wc_get_product' ) ) {
			return empty( $children ) ? null : 0;
		}
		$count = 0;
		foreach ( $children as $child_id ) {
			$variation = wc_get_product( absint( $child_id ) );
			if ( ! $variation || ( is_callable( array( $variation, 'get_status' ) ) && 'publish' !== $variation->get_status() ) ) {
				continue;
			}
			if ( is_callable( array( $variation, 'is_purchasable' ) ) && $variation->is_purchasable() && is_callable( array( $variation, 'is_in_stock' ) ) && $variation->is_in_stock() ) {
				$count++;
			}
		}
		return $count;
	}

	private static function overall_status( $identity, $inventory, $pricing, $unit_mode, $sync, $discount, $source_valid ) {
		if ( in_array( $identity, array( 'guid_mismatch' ), true ) || 'mismatch' === $inventory || 'failed' === $sync ) return 'critical';
		if ( ! $source_valid || in_array( 'unknown', array( $inventory, $pricing ), true ) || in_array( $unit_mode, array( Unit_Engine::MODE_UNRESOLVED ), true ) || in_array( $sync, array( 'unknown', 'never_synced' ), true ) ) return 'unknown';
		if ( in_array( $identity, array( 'missing_guid', 'source_missing' ), true ) || in_array( $discount, array( 'expired', 'invalid' ), true ) || in_array( $sync, array( 'stale', 'queued', 'processing' ), true ) || 'attention' === $pricing ) return 'attention';
		return 'healthy';
	}

	private static function queue_state( $item_id, $type ) {
		global $wpdb;
		$table = $wpdb->prefix . 'novin_commerce_syncs';
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) return '';
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE item_id = %d AND item_type = %s LIMIT 1", absint( $item_id ), self::TYPE_VARIATION === $type ? 'variation' : 'product' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return in_array( $value, array( 'pending', 'processing', 'failed', 'dead' ), true ) ? ( 'pending' === $value ? 'queued' : ( 'dead' === $value ? 'failed' : $value ) ) : '';
	}
}
