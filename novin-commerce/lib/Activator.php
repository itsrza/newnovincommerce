<?php

/**
 * Fired during plugin activation
 *
 * @link       http://example.com
 * @since      1.0.0
 *
 * @package    Novin_Commerce
 * @subpackage Novin_Commerce/includes
 */

namespace MobinDev\Novin_Commerce;

use As247\WpEloquent\Application;
use As247\WpEloquent\Database\Schema\Blueprint;
use As247\WpEloquent\Support\Facades\DB;
use As247\WpEloquent\Support\Facades\Schema;
use MobinDev\Novin_Commerce\Common\SettingAPI;

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    Novin_Commerce
 * @subpackage Novin_Commerce/includes
 * @author     MobinDev <mobin7332@gmail.com>
 */
class Activator {

	const MAINTENANCE_HOOK = 'novin_commerce_daily_maintenance';
	const CURRENT_SCHEMA  = '9';

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function activate() {
		Application::bootWp();
		// Activation is deliberately lightweight. Tables and step migrations
		// are retried from admin_init or the single background migration event;
		// a failed migration never advances the schema option.
		/* Migration is intentionally not run inline here. */
		self::setSettings();
		self::scheduleMaintenance();
		if ( ! wp_next_scheduled( 'novin_commerce_run_migration' ) ) {
			wp_schedule_single_event( time() + 10, 'novin_commerce_run_migration' );
		}
	}

	public static function createTables() {

		if ( ! Schema::hasTable( 'novin_commerce_syncs' ) ) {
			Schema::create( 'novin_commerce_syncs', function ( Blueprint $table ) {
				$table->increments( 'id' );
				$table->bigInteger( 'item_id' );
				$table->enum( 'item_type', [ 'product', 'category', 'user', 'order', 'variation' ] );
				$table->timestamp( 'created_at' )->default( DB::raw( 'CURRENT_TIMESTAMP' ) );
				$table->timestamp( 'updated_at' )->default( DB::raw( 'CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP' ) );
				$table->string( 'status', 20 )->default( 'pending' );
				$table->unsignedTinyInteger( 'attempts' )->default( 0 );
				$table->timestamp( 'available_at' )->nullable();
				$table->timestamp( 'locked_until' )->nullable();
				$table->text( 'last_error' )->nullable();
				$table->timestamp( 'dead_at' )->nullable();
				$table->integer( 'priority' )->default( 0 );
				$table->unique( [ 'item_id', 'item_type' ] );
				$table->index( [ 'status', 'available_at', 'priority' ] );
			} );
		}

		if ( Schema::hasTable( 'novin_commerce_syncs' ) ) {
			try {
				if ( ! Schema::hasColumn( 'novin_commerce_syncs', 'priority' ) ) {
					Schema::table( 'novin_commerce_syncs', function ( Blueprint $table ) {
						$table->integer( 'priority' )->default( 0 );
					} );
				}
			} catch ( \Throwable $e ) {
				// A concurrent upgrade or an older schema adapter may report the
				// column as unavailable. The next request can retry safely.
			}
		}

		if ( ! Schema::hasTable( 'novin_commerce_sync_logs' ) ) {
			Schema::create( 'novin_commerce_sync_logs', function ( Blueprint $table ) {
				$table->increments( 'id' );
				$table->string( 'event_type', 50 );
				$table->string( 'status', 20 )->default( 'info' );
				$table->string( 'item_type', 30 )->nullable();
				$table->bigInteger( 'item_id' )->default( 0 );
				$table->text( 'message' )->nullable();
				$table->longText( 'context' )->nullable();
				$table->timestamp( 'created_at' )->default( DB::raw( 'CURRENT_TIMESTAMP' ) );
				$table->index( [ 'item_type', 'item_id' ] );
				$table->index( [ 'status', 'created_at' ] );
			} );
		}

		if ( ! self::ensureStockOperationTable() || ! self::ensureHealthSnapshotTable() || ! self::ensure_utf8_tables() ) {
			return false;
		}
		return Schema::hasTable( 'novin_commerce_syncs' )
			&& Schema::hasTable( 'novin_commerce_sync_logs' )
			&& Schema::hasTable( 'novin_commerce_stock_ops' )
			&& Schema::hasTable( 'novin_commerce_product_health' );
	}

	private static function ensureStockOperationTable() {
		global $wpdb;
		$table = $wpdb->prefix . 'novin_commerce_stock_ops';
		if ( Schema::hasTable( 'novin_commerce_stock_ops' ) ) {
			return true;
		}

		$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : 'DEFAULT CHARACTER SET utf8mb4';
		$sql = "CREATE TABLE {$table} (
			operation_key varchar(100) NOT NULL,
			entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
			operation_type varchar(30) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (operation_key),
			KEY entity_operation (entity_id, operation_type)
		) {$charset}";

		if ( ! function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) ) {
			$upgrade = ABSPATH . 'wp-admin/includes/upgrade.php';
			if ( file_exists( $upgrade ) ) {
				require_once $upgrade;
			}
		}
		if ( function_exists( 'dbDelta' ) ) {
			dbDelta( $sql, false );
		} else {
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- plugin-owned identifier and fixed DDL.
		}

		return Schema::hasTable( 'novin_commerce_stock_ops' );
	}

	private static function ensureHealthSnapshotTable() {
		global $wpdb;
		$table = $wpdb->prefix . 'novin_commerce_product_health';
		if ( Schema::hasTable( 'novin_commerce_product_health' ) ) {
			return self::ensureHealthSnapshotColumns();
		}

		$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : 'DEFAULT CHARACTER SET utf8mb4';
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			item_id bigint(20) unsigned NOT NULL,
			item_type varchar(20) NOT NULL,
			parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			woo_type varchar(20) NOT NULL DEFAULT '',
			post_status varchar(20) NOT NULL DEFAULT '',
			woo_guid varchar(191) NOT NULL DEFAULT '',
			source_guid varchar(191) NOT NULL DEFAULT '',
			woo_sku varchar(191) NOT NULL DEFAULT '',
			source_sku varchar(191) NOT NULL DEFAULT '',
			source_modified datetime NULL,
			site_sync_date datetime NULL,
			woo_stock decimal(20,6) NULL,
			source_mojodi decimal(20,6) NULL,
			warehouse_stock decimal(20,6) NULL,
			warehouse_count int unsigned NOT NULL DEFAULT 0,
			unit_mode varchar(20) NOT NULL DEFAULT 'unknown',
			is_base_unit tinyint(1) NOT NULL DEFAULT 0,
			units_per_pack decimal(20,6) NULL,
			source_price_count tinyint unsigned NOT NULL DEFAULT 0,
			role_price_count tinyint unsigned NOT NULL DEFAULT 0,
			role_price_missing tinyint unsigned NOT NULL DEFAULT 0,
			role_price_local tinyint unsigned NOT NULL DEFAULT 0,
			price_needs_review tinyint(1) NOT NULL DEFAULT 0,
			discount_state varchar(20) NOT NULL DEFAULT 'unknown',
			media_state varchar(30) NOT NULL DEFAULT 'unknown',
			accounting_kind varchar(30) NOT NULL DEFAULT 'unknown',
			identity_state varchar(30) NOT NULL DEFAULT 'unknown',
			inventory_state varchar(30) NOT NULL DEFAULT 'unknown',
			pricing_state varchar(30) NOT NULL DEFAULT 'unknown',
			sync_state varchar(30) NOT NULL DEFAULT 'unknown',
			catalog_state varchar(30) NOT NULL DEFAULT 'unknown',
			source_version varchar(100) NOT NULL DEFAULT '',
			client_version varchar(100) NOT NULL DEFAULT '',
			server_status varchar(100) NOT NULL DEFAULT '',
			health_status varchar(20) NOT NULL DEFAULT 'unknown',
			health_flags longtext NULL,
			source_hash char(32) NOT NULL DEFAULT '',
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY item_identity (item_id, item_type),
			KEY item_type_status (item_type, post_status),
			KEY health_status (health_status),
			KEY sync_state (sync_state),
			KEY parent_id (parent_id),
			KEY source_guid (source_guid),
			KEY updated_at (updated_at)
		) {$charset}";

		if ( ! function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) ) {
			$upgrade = ABSPATH . 'wp-admin/includes/upgrade.php';
			if ( file_exists( $upgrade ) ) {
				require_once $upgrade;
			}
		}
		if ( function_exists( 'dbDelta' ) ) {
			dbDelta( $sql, false );
		} else {
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- plugin-owned fixed DDL.
		}
		return Schema::hasTable( 'novin_commerce_product_health' );
	}

	/**
	 * Add only missing snapshot columns. Existing rows are never rewritten or
	 * dropped; a failed ALTER leaves the schema version unchanged.
	 *
	 * @return bool
	 */
	private static function ensureHealthSnapshotColumns() {
		global $wpdb;
		$table = $wpdb->prefix . 'novin_commerce_product_health';
		// A table without the identity columns cannot be migrated safely: do
		// not fill it with default IDs and risk collapsing unrelated data.
		if ( ! Schema::hasColumn( 'novin_commerce_product_health', 'id' ) || ! Schema::hasColumn( 'novin_commerce_product_health', 'item_id' ) || ! Schema::hasColumn( 'novin_commerce_product_health', 'item_type' ) ) {
			return false;
		}
		$columns = array(
			'item_id'            => 'ADD COLUMN item_id bigint(20) unsigned NOT NULL DEFAULT 0',
			'item_type'          => "ADD COLUMN item_type varchar(20) NOT NULL DEFAULT ''",
			'parent_id'          => 'ADD COLUMN parent_id bigint(20) unsigned NOT NULL DEFAULT 0',
			'woo_type'           => "ADD COLUMN woo_type varchar(20) NOT NULL DEFAULT ''",
			'post_status'        => "ADD COLUMN post_status varchar(20) NOT NULL DEFAULT ''",
			'woo_guid'           => "ADD COLUMN woo_guid varchar(191) NOT NULL DEFAULT ''",
			'source_guid'        => "ADD COLUMN source_guid varchar(191) NOT NULL DEFAULT ''",
			'woo_sku'            => "ADD COLUMN woo_sku varchar(191) NOT NULL DEFAULT ''",
			'source_sku'         => "ADD COLUMN source_sku varchar(191) NOT NULL DEFAULT ''",
			'source_modified'    => 'ADD COLUMN source_modified datetime NULL',
			'site_sync_date'     => 'ADD COLUMN site_sync_date datetime NULL',
			'woo_stock'          => 'ADD COLUMN woo_stock decimal(20,6) NULL',
			'source_mojodi'      => 'ADD COLUMN source_mojodi decimal(20,6) NULL',
			'warehouse_stock'    => 'ADD COLUMN warehouse_stock decimal(20,6) NULL',
			'warehouse_count'    => 'ADD COLUMN warehouse_count int unsigned NOT NULL DEFAULT 0',
			'unit_mode'          => "ADD COLUMN unit_mode varchar(20) NOT NULL DEFAULT 'unknown'",
			'is_base_unit'       => 'ADD COLUMN is_base_unit tinyint(1) NOT NULL DEFAULT 0',
			'units_per_pack'     => 'ADD COLUMN units_per_pack decimal(20,6) NULL',
			'source_price_count' => 'ADD COLUMN source_price_count tinyint unsigned NOT NULL DEFAULT 0',
			'role_price_count'   => 'ADD COLUMN role_price_count tinyint unsigned NOT NULL DEFAULT 0',
			'role_price_missing' => 'ADD COLUMN role_price_missing tinyint unsigned NOT NULL DEFAULT 0',
			'role_price_local'   => 'ADD COLUMN role_price_local tinyint unsigned NOT NULL DEFAULT 0',
			'price_needs_review' => 'ADD COLUMN price_needs_review tinyint(1) NOT NULL DEFAULT 0',
			'discount_state'     => "ADD COLUMN discount_state varchar(20) NOT NULL DEFAULT 'unknown'",
			'media_state'        => "ADD COLUMN media_state varchar(30) NOT NULL DEFAULT 'unknown'",
			'accounting_kind'    => "ADD COLUMN accounting_kind varchar(30) NOT NULL DEFAULT 'unknown'",
			'identity_state'     => "ADD COLUMN identity_state varchar(30) NOT NULL DEFAULT 'unknown'",
			'inventory_state'    => "ADD COLUMN inventory_state varchar(30) NOT NULL DEFAULT 'unknown'",
			'pricing_state'      => "ADD COLUMN pricing_state varchar(30) NOT NULL DEFAULT 'unknown'",
			'sync_state'         => "ADD COLUMN sync_state varchar(30) NOT NULL DEFAULT 'unknown'",
			'catalog_state'      => "ADD COLUMN catalog_state varchar(30) NOT NULL DEFAULT 'unknown'",
			'source_version'     => "ADD COLUMN source_version varchar(100) NOT NULL DEFAULT ''",
			'client_version'     => "ADD COLUMN client_version varchar(100) NOT NULL DEFAULT ''",
			'server_status'      => "ADD COLUMN server_status varchar(100) NOT NULL DEFAULT ''",
			'health_status'      => "ADD COLUMN health_status varchar(20) NOT NULL DEFAULT 'unknown'",
			'health_flags'       => 'ADD COLUMN health_flags longtext NULL',
			'source_hash'        => "ADD COLUMN source_hash char(32) NOT NULL DEFAULT ''",
			'updated_at'         => 'ADD COLUMN updated_at datetime NULL',
		);
		foreach ( $columns as $name => $definition ) {
			if ( ! Schema::hasColumn( 'novin_commerce_product_health', $name ) ) {
				$result = $wpdb->query( "ALTER TABLE {$table} {$definition}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- allow-listed plugin schema.
				if ( false === $result && ! Schema::hasColumn( 'novin_commerce_product_health', $name ) ) return false;
			}
		}
		if ( ! self::ensureHealthSnapshotIdentity() ) return false;
		return self::verifyHealthSnapshotSchema();
	}

	private static function ensureHealthSnapshotIdentity() {
		global $wpdb;
		$table = $wpdb->prefix . 'novin_commerce_product_health';
		$indexes = $wpdb->get_results( $wpdb->prepare( "SELECT INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s GROUP BY INDEX_NAME, NON_UNIQUE", $table ) );
		foreach ( (array) $indexes as $index ) {
			if ( 0 === (int) $index->NON_UNIQUE && 'item_id,item_type' === (string) $index->columns_list ) return true;
		}
		$duplicates = $wpdb->get_results( "SELECT item_id, item_type, MAX(id) AS keep_id FROM {$table} GROUP BY item_id, item_type HAVING COUNT(*) > 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- plugin-owned table.
		foreach ( (array) $duplicates as $duplicate ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE item_id = %d AND item_type = %s AND id <> %d", absint( $duplicate->item_id ), sanitize_key( $duplicate->item_type ), absint( $duplicate->keep_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$result = $wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY novin_health_identity (item_id, item_type)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- plugin-owned table.
		if ( false === $result ) {
			$check = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'novin_health_identity' AND NON_UNIQUE = 0", $table ) );
			return (bool) $check;
		}
		return true;
	}

	private static function ensure_utf8_tables() {
		global $wpdb;
		$charset = isset( $wpdb->charset ) && preg_match( '/^[a-z0-9_]+$/i', $wpdb->charset ) ? $wpdb->charset : 'utf8mb4';
		$collate = isset( $wpdb->collate ) && preg_match( '/^[a-z0-9_]+$/i', $wpdb->collate ) ? $wpdb->collate : '';
		foreach ( array( $wpdb->prefix . 'novin_commerce_syncs', $wpdb->prefix . 'novin_commerce_sync_logs', $wpdb->prefix . 'novin_commerce_stock_ops', $wpdb->prefix . 'novin_commerce_product_health' ) as $table ) {
			$sql = "ALTER TABLE {$table} CONVERT TO CHARACTER SET {$charset}";
			if ( '' !== $collate ) {
				$sql .= " COLLATE {$collate}";
			}
			if ( false === $wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- identifiers are plugin-owned and charset values are allow-listed.
				return false;
			}
		}
		return true;
	}

	/**
	 * Add queue lifecycle columns to an existing installation. Each ALTER is
	 * independently retryable and the schema option advances only after the
	 * columns are observable.
	 *
	 * @return bool
	 */
	public static function ensureQueueSchema() {
		global $wpdb;
		$table = $wpdb->prefix . 'novin_commerce_syncs';
		if ( ! Schema::hasTable( 'novin_commerce_syncs' ) ) {
			return false;
		}

		$columns = array(
			'priority'     => 'ADD COLUMN priority int NOT NULL DEFAULT 0',
			'status'       => "ADD COLUMN status varchar(20) NOT NULL DEFAULT 'pending'",
			'attempts'     => 'ADD COLUMN attempts tinyint unsigned NOT NULL DEFAULT 0',
			'available_at' => 'ADD COLUMN available_at timestamp NULL DEFAULT NULL',
			'locked_until' => 'ADD COLUMN locked_until timestamp NULL DEFAULT NULL',
			'last_error'   => 'ADD COLUMN last_error text NULL',
			'dead_at'      => 'ADD COLUMN dead_at timestamp NULL DEFAULT NULL',
		);
		foreach ( $columns as $name => $definition ) {
			if ( ! Schema::hasColumn( 'novin_commerce_syncs', $name ) ) {
				$result = $wpdb->query( "ALTER TABLE {$table} {$definition}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- plugin-owned table/column identifiers.
				if ( false === $result && ! Schema::hasColumn( 'novin_commerce_syncs', $name ) ) {
					return false;
				}
			}
		}
		if ( ! self::ensureQueueIdentity() ) {
			return false;
		}
		return self::verifyQueueSchema();
	}

	/**
	 * Repair duplicate queue identities before adding the unique constraint.
	 * The newest row is retained so a retry cannot resurrect an older state.
	 *
	 * @return bool
	 */
	private static function hasQueueIdentityIndex() {
		global $wpdb;
		$table = $wpdb->prefix . 'novin_commerce_syncs';
		$indexes = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s GROUP BY INDEX_NAME, NON_UNIQUE",
				$table
			)
		);
		foreach ( (array) $indexes as $index ) {
			if ( 0 === (int) $index->NON_UNIQUE && 'item_id,item_type' === (string) $index->columns_list ) {
				return true;
			}
		}
		return false;
	}

	private static function ensureQueueIdentity() {
		global $wpdb;
		$table = $wpdb->prefix . 'novin_commerce_syncs';
		if ( self::hasQueueIdentityIndex() ) {
			return true;
		}

		$duplicates = $wpdb->get_results( "SELECT item_id, item_type, MAX(id) AS keep_id FROM {$table} GROUP BY item_id, item_type HAVING COUNT(*) > 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- plugin-owned table.
		foreach ( (array) $duplicates as $duplicate ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE item_id = %d AND item_type = %s AND id <> %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					absint( $duplicate->item_id ),
					sanitize_key( $duplicate->item_type ),
					absint( $duplicate->keep_id )
				)
			);
		}
		$result = $wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY novin_item_identity (item_id, item_type)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- plugin-owned table.
		if ( false === $result ) {
			$check = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'novin_item_identity' AND NON_UNIQUE = 0",
					$table
				)
			);
			return (bool) $check;
		}
		return true;
	}

	private static function verifyStockOperationSchema() {
		global $wpdb;
		$table = $wpdb->prefix . 'novin_commerce_stock_ops';
		if ( ! Schema::hasTable( 'novin_commerce_stock_ops' ) ) {
			return false;
		}
		$columns = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
				$table
			)
		);
		$required = array( 'operation_key', 'entity_id', 'operation_type', 'created_at' );
		if ( count( array_intersect( $required, (array) $columns ) ) !== count( $required ) ) {
			return false;
		}
		$primary = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'PRIMARY' AND NON_UNIQUE = 0 AND COLUMN_NAME = 'operation_key' AND SEQ_IN_INDEX = 1",
				$table
			)
		);
		return (bool) $primary;
	}

	private static function verifyHealthSnapshotSchema() {
		global $wpdb;
		$table = $wpdb->prefix . 'novin_commerce_product_health';
		if ( ! Schema::hasTable( 'novin_commerce_product_health' ) ) {
			return false;
		}
		$columns = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
				$table
			)
		);
		$required = array( 'item_id', 'item_type', 'woo_type', 'source_mojodi', 'warehouse_stock', 'warehouse_count', 'woo_stock', 'unit_mode', 'units_per_pack', 'source_price_count', 'role_price_count', 'discount_state', 'media_state', 'accounting_kind', 'sync_state', 'health_status', 'health_flags', 'updated_at' );
		return count( array_intersect( $required, (array) $columns ) ) === count( $required );
	}

	public static function verifyQueueSchema() {
		return Schema::hasTable( 'novin_commerce_syncs' )
			&& self::hasQueueIdentityIndex()
			&& Schema::hasColumn( 'novin_commerce_syncs', 'priority' )
			&& Schema::hasColumn( 'novin_commerce_syncs', 'status' )
			&& Schema::hasColumn( 'novin_commerce_syncs', 'attempts' )
			&& Schema::hasColumn( 'novin_commerce_syncs', 'available_at' )
			&& Schema::hasColumn( 'novin_commerce_syncs', 'locked_until' )
			&& Schema::hasColumn( 'novin_commerce_syncs', 'last_error' )
			&& Schema::hasColumn( 'novin_commerce_syncs', 'dead_at' )
			&& self::verifyStockOperationSchema()
			&& self::verifyHealthSnapshotSchema();
	}

	public static function maybeUpgrade() {
		try {
			Application::bootWp();
			$version = (string) get_option( 'novin_commerce_schema_version', '' );
			$ready   = self::createTables() && self::ensureQueueSchema();
			if ( $ready && self::verifyQueueSchema() && self::CURRENT_SCHEMA === $version ) {
				self::scheduleMaintenance();
				return true;
			}
			if ( $ready && self::verifyQueueSchema() ) {
				if ( update_option( 'novin_commerce_schema_version', self::CURRENT_SCHEMA ) ) {
					self::scheduleMaintenance();
					return true;
				}
				return self::CURRENT_SCHEMA === (string) get_option( 'novin_commerce_schema_version', '' );
			}
		} catch ( \Throwable $exception ) {
			// Keep the prior schema version and allow the next admin/cron retry.
			return false;
		}
		return false;
	}

	/**
	 * Background migration entry point. Each owner performs its own verified,
	 * retryable steps; neither owner advances its version on failure.
	 *
	 * @return bool
	 */
	public static function run_migration() {
		$core_ready = self::maybeUpgrade();
		$digits_ready = true;
		if ( class_exists( '\MobinDev\\Novin_Commerce\\Digits\\Common\\Digits_Activator' ) ) {
			$digits_ready = \MobinDev\Novin_Commerce\Digits\Common\Digits_Activator::maybe_upgrade();
		}
		$ready = $core_ready && $digits_ready;
		if ( ! $ready && function_exists( 'wp_schedule_single_event' ) && ! wp_next_scheduled( 'novin_commerce_run_migration' ) ) {
			wp_schedule_single_event( time() + 300, 'novin_commerce_run_migration' );
		}
		return $ready;
	}

	public static function scheduleMaintenance() {
		if ( ! wp_next_scheduled( self::MAINTENANCE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::MAINTENANCE_HOOK );
		}
	}

	public static function unscheduleMaintenance() {
		// Clear every copy in case an older release scheduled the hook more
		// than once; deactivation must not leave a background maintenance job.
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::MAINTENANCE_HOOK );
			return;
		}
		$timestamp = wp_next_scheduled( self::MAINTENANCE_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::MAINTENANCE_HOOK );
		}
	}

	public static function run_maintenance() {
		if ( class_exists( '\MobinDev\Novin_Commerce\Common\SyncLog' ) ) {
			\MobinDev\Novin_Commerce\Common\SyncLog::prune( 30 );
		}
		if ( class_exists( '\MobinDev\Novin_Commerce\Digits\Common\Sms_Log' ) ) {
			\MobinDev\Novin_Commerce\Digits\Common\Sms_Log::prune( 30 );
		}
		if ( class_exists( '\MobinDev\Novin_Commerce\Digits\Common\Otp_Manager' ) ) {
			\MobinDev\Novin_Commerce\Digits\Common\Otp_Manager::prune( 2 );
		}
	}

	public static function setSettings() {
		if ( ! SettingAPI::get( 'woocommerce_analytics' ) ) {
			SettingAPI::set( 'woocommerce_analytics', 'off' );
		}
		if ( ! SettingAPI::get( 'api_url' ) ) {
			SettingAPI::set( 'api_url', 'https://novinrank.ir/' );
		}
		if ( null === SettingAPI::get( 'novin_toman_conversion_enabled', null ) ) {
			SettingAPI::set( 'novin_toman_conversion_enabled', 'on' );
		}

	}
}
