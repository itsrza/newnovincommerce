<?php

namespace MobinDev\Novin_Commerce\Models;

use As247\WpEloquent\Database\Eloquent\Model;
use MobinDev\Novin_Commerce\Common\SyncLog;

/**
 * Persistent, deduplicated sync queue.
 *
 * The identity is the database unique key (item_id, item_type), not a
 * transient. Queue writes therefore remain idempotent across concurrent web
 * and cron requests. The lifecycle columns are intentionally kept compatible
 * with the existing table and REST/admin consumers.
 */
class Sync extends Model {
	protected $table = 'novin_commerce_syncs';
	protected $guarded = [ 'id' ];

	private static function allowed_types() {
		return [ 'product', 'category', 'user', 'order', 'variation' ];
	}

	private static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'novin_commerce_syncs';
	}

	public static function doAddAction() { do_action( 'novincommerce-add-sync-items' ); }
	public static function doDeleteAction() { do_action( 'novincommerce-delete-sync-items' ); }

	public static function queueItem( $item_id, $item_type, $priority = 0 ) {
		global $wpdb;

		$item_id   = absint( $item_id );
		$item_type = sanitize_key( $item_type );
		$priority  = max( 0, min( 100, (int) $priority ) );
		if ( ! $item_id || ! in_array( $item_type, self::allowed_types(), true ) ) {
			return false;
		}

		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (item_id, item_type, priority, status, attempts, available_at, locked_until, dead_at, created_at, updated_at)
				 VALUES (%d, %s, %d, 'pending', 0, %s, NULL, NULL, %s, %s)
				 ON DUPLICATE KEY UPDATE priority = GREATEST(priority, VALUES(priority)), status = IF(status = 'dead', 'pending', status), available_at = IF(status = 'processing' AND locked_until > %s, available_at, VALUES(available_at)), locked_until = IF(status = 'processing' AND locked_until > %s, locked_until, NULL), dead_at = NULL, updated_at = %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table is plugin-owned.
				$item_id,
				$item_type,
				$priority,
				$now,
				$now,
				$now,
				$now,
				$now,
				$now
			)
		);

		if ( false === $result ) {
			return false;
		}

		SyncLog::add(
			$priority > 0 ? 'requeue' : 'queued',
			'success',
			$item_type,
			$item_id,
			$priority > 0 ? 'مورد با اولویت بالا در صف تبادل قرار گرفت.' : 'مورد در صف تبادل قرار گرفت.',
			[ 'priority' => $priority ]
		);
		delete_transient( 'novin_commerce_health_v1' );
		delete_transient( 'novin_commerce_health_v2' );
		self::doAddAction();
		return true;
	}

	public static function insertItem( $item_id, $item_type ) { return self::queueItem( $item_id, $item_type, 0 ); }
	public static function requeue( $item_id, $item_type, $priority = 10 ) { return self::queueItem( $item_id, $item_type, $priority ); }

	public static function setPriority( $item_id, $item_type, $priority ) {
		$item_id   = absint( $item_id );
		$item_type = sanitize_key( $item_type );
		$priority  = max( 0, min( 100, (int) $priority ) );
		if ( ! $item_id || ! in_array( $item_type, self::allowed_types(), true ) ) {
			return false;
		}
		$result = self::where( 'item_id', $item_id )->where( 'item_type', $item_type )->update( [ 'priority' => $priority ] );
		if ( $result ) {
			SyncLog::add( 'priority', 'success', $item_type, $item_id, 'اولویت صف تغییر کرد.', [ 'priority' => $priority ] );
		}
		return $result;
	}

	public static function insertProduct( $item_id ) { return self::insertItem( $item_id, 'product' ); }
	public static function insertOrder( $item_id ) { return self::insertItem( $item_id, 'order' ); }
	public static function insertCategory( $item_id ) { return self::insertItem( $item_id, 'category' ); }
	public static function insertUser( $item_id ) { return self::insertItem( $item_id, 'user' ); }
	public static function insertVariation( $item_id ) { return self::insertItem( $item_id, 'variation' ); }

	public static function removeItem( $item_id, $item_type ) {
		$item_id   = absint( $item_id );
		$item_type = sanitize_key( $item_type );
		if ( ! $item_id || ! in_array( $item_type, self::allowed_types(), true ) ) {
			return false;
		}
		$result = self::where( 'item_id', $item_id )->where( 'item_type', $item_type )->delete();
		if ( $result ) {
			SyncLog::add( 'removed', 'success', $item_type, $item_id, 'مورد از صف تبادل حذف شد.' );
			delete_transient( 'novin_commerce_health_v1' );
			delete_transient( 'novin_commerce_health_v2' );
			self::doDeleteAction();
		}
		return $result;
	}

	/**
	 * Claim a bounded batch with a database lease. A crashed worker becomes
	 * claimable after the lease expires; it is not permanently lost.
	 *
	 * @param int $limit Number of rows.
	 * @param int $lease Lease seconds.
	 * @return array<int,object>
	 */
	public static function claimBatch( $limit = 20, $lease = 300 ) {
		global $wpdb;
		$limit = min( 100, max( 1, absint( $limit ) ) );
		$lease = min( HOUR_IN_SECONDS, max( 30, absint( $lease ) ) );
		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		$until = gmdate( 'Y-m-d H:i:s', time() + $lease );
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE (status IN ('pending','failed') OR (status = 'processing' AND (locked_until IS NULL OR locked_until < %s))) AND (available_at IS NULL OR available_at <= %s) ORDER BY priority DESC, id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$now,
				$now,
				$limit
			)
		);
		$claimed = [];
		foreach ( (array) $ids as $id ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'processing', locked_until = %s, updated_at = %s WHERE id = %d AND (status IN ('pending','failed') OR (status = 'processing' AND (locked_until IS NULL OR locked_until < %s)))", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$until,
					$now,
					absint( $id ),
					$now
				)
			);
			if ( 1 === (int) $updated ) {
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				if ( $row ) {
					$claimed[] = $row;
				}
			}
		}
		return $claimed;
	}

	public static function complete( $id ) {
		global $wpdb;
		return 1 === (int) $wpdb->query( $wpdb->prepare( "DELETE FROM " . self::table_name() . " WHERE id = %d AND status = 'processing'", absint( $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function fail( $id, $message, $max_attempts = 5 ) {
		global $wpdb;
		$id           = absint( $id );
		$max_attempts = max( 1, min( 20, absint( $max_attempts ) ) );
		$message      = mb_substr( sanitize_textarea_field( (string) $message ), 0, 1000 );
		$table        = self::table_name();
		$row          = $wpdb->get_row( $wpdb->prepare( "SELECT attempts FROM {$table} WHERE id = %d AND status = 'processing'", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $row ) {
			return false;
		}
		$attempts = (int) $row->attempts + 1;
		$dead     = $attempts >= $max_attempts;
		$delay    = min( DAY_IN_SECONDS, 30 * ( 2 ** min( 10, $attempts - 1 ) ) );
		$next     = gmdate( 'Y-m-d H:i:s', time() + $delay );
		return false !== $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET attempts = %d, status = %s, available_at = %s, locked_until = NULL, last_error = %s, dead_at = %s, updated_at = %s WHERE id = %d AND status = 'processing'", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$attempts,
				$dead ? 'dead' : 'failed',
				$dead ? null : $next,
				$message,
				$dead ? current_time( 'mysql', true ) : null,
				current_time( 'mysql', true ),
				$id
			)
		);
	}
}
