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

namespace Novinwp\Novin_Commerce;

use As247\WpEloquent\Application;
use As247\WpEloquent\Database\Schema\Blueprint;
use As247\WpEloquent\Support\Facades\DB;
use As247\WpEloquent\Support\Facades\Schema;
use Novinwp\Novin_Commerce\Common\SettingAPI;

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    Novin_Commerce
 * @subpackage Novin_Commerce/includes
 * @author     Novinwp <info@npwp.ir>
 */
class Activator {

	const MAINTENANCE_HOOK = 'novin_commerce_daily_maintenance';

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function activate() {
		Application::bootWp();
		self::createTables();
		self::setSettings();
		self::scheduleMaintenance();
		update_option( 'novin_commerce_schema_version', '5' );
	}

	public static function createTables() {

		if ( ! Schema::hasTable( 'novin_commerce_syncs' ) ) {
			Schema::create( 'novin_commerce_syncs', function ( Blueprint $table ) {
				$table->increments( 'id' );
				$table->bigInteger( 'item_id' );
				$table->enum( 'item_type', [ 'product', 'category', 'user', 'order', 'variation' ] );
				$table->timestamp( 'created_at' )->default( DB::raw( 'CURRENT_TIMESTAMP' ) );
				$table->timestamp( 'updated_at' )->default( DB::raw( 'CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP' ) );
				$table->unique( [ 'item_id', 'item_type' ] );
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

		self::ensure_utf8_tables();
	}

	private static function ensure_utf8_tables() {
		global $wpdb;
		$charset = isset( $wpdb->charset ) && preg_match( '/^[a-z0-9_]+$/i', $wpdb->charset ) ? $wpdb->charset : 'utf8mb4';
		$collate = isset( $wpdb->collate ) && preg_match( '/^[a-z0-9_]+$/i', $wpdb->collate ) ? $wpdb->collate : '';
		foreach ( array( $wpdb->prefix . 'novin_commerce_sync_logs' ) as $table ) {
			$sql = "ALTER TABLE {$table} CONVERT TO CHARACTER SET {$charset}";
			if ( '' !== $collate ) {
				$sql .= " COLLATE {$collate}";
			}
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- identifiers are plugin-owned and charset values are allow-listed.
		}
	}

	public static function maybeUpgrade() {
		Application::bootWp();
		if ( '5' !== (string) get_option( 'novin_commerce_schema_version', '' ) ) {
			self::createTables();
			update_option( 'novin_commerce_schema_version', '5' );
		}
		self::scheduleMaintenance();
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
		if ( class_exists( '\Novinwp\Novin_Commerce\Common\SyncLog' ) ) {
			\Novinwp\Novin_Commerce\Common\SyncLog::prune( 30 );
		}
		if ( class_exists( '\Novinwp\Novin_Commerce\Digits\Common\Sms_Log' ) ) {
			\Novinwp\Novin_Commerce\Digits\Common\Sms_Log::prune( 30 );
		}
		if ( class_exists( '\Novinwp\Novin_Commerce\Digits\Common\Otp_Manager' ) ) {
			\Novinwp\Novin_Commerce\Digits\Common\Otp_Manager::prune( 2 );
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

		//turn off woocommerce tracking
		update_option( 'woocommerce_allow_tracking', 'no' );
		//turn off woocommerce marketplace suggestions
		update_option( 'woocommerce_show_marketplace_suggestions', 'no' );

		// update Permalink
		add_rewrite_endpoint( 'novin-commerce-transactions', EP_PAGES );
		flush_rewrite_rules();
	}
}
