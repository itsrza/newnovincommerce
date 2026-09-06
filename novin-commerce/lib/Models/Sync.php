<?php

namespace MobinDev\Novin_Commerce\Models;

use As247\WpEloquent\Database\Eloquent\Model;
use MobinDev\Novin_Commerce\Common\SyncLog;

class Sync extends Model {
	protected $table = 'novin_commerce_syncs';
	protected $guarded = ['id'];

	public static function doAddAction() { do_action( 'novincommerce-add-sync-items' ); }
	public static function doDeleteAction() { do_action( 'novincommerce-delete-sync-items' ); }

	/**
	 * Invalidate every cached snapshot of the connection dashboard.
	 *
	 * Older releases cached only under "v1"/"v2" while the dashboard has been
	 * reading a "v3" transient for a long time, so queue/activity changes
	 * could stay hidden on the dashboard until the transient expired.
	 */
	public static function flushHealthCache() {
		delete_transient( 'novin_commerce_health_v1' );
		delete_transient( 'novin_commerce_health_v2' );
		delete_transient( 'novin_commerce_health_v3' );
	}

	public static function queueItem( $item_id, $item_type, $priority = 0 ) {
		$item_id = absint( $item_id );
		$item_type = sanitize_key( $item_type );
		$priority = max( 0, min( 100, (int) $priority ) );
		$lock_key = 'novin_commerce_sync_lock_' . $item_type . '_' . $item_id;
		if ( get_transient( $lock_key ) ) return false;
		set_transient( $lock_key, 1, 3 );
		if ( ! $item_id || ! in_array( $item_type, [ 'product', 'category', 'user', 'order', 'variation' ], true ) ) return false;
		$result = self::updateOrInsert( [ 'item_id' => $item_id, 'item_type' => $item_type ], [ 'priority' => $priority ] );
		self::where( 'item_id', $item_id )->where( 'item_type', $item_type )->update( [ 'priority' => $priority ] );
		SyncLog::add( $priority > 0 ? 'requeue' : 'queued', 'success', $item_type, $item_id, $priority > 0 ? 'مورد با اولویت بالا در صف تبادل قرار گرفت.' : 'مورد در صف تبادل قرار گرفت.', [ 'priority' => $priority ] );
		self::flushHealthCache();
		self::doAddAction();
		return $result;
	}

	public static function insertItem( $item_id, $item_type ) { return self::queueItem( $item_id, $item_type, 0 ); }
	public static function requeue( $item_id, $item_type, $priority = 10 ) { return self::queueItem( $item_id, $item_type, $priority ); }
	public static function setPriority( $item_id, $item_type, $priority ) {
		$priority = max( 0, min( 100, (int) $priority ) );
		$result = self::where( 'item_id', absint( $item_id ) )->where( 'item_type', sanitize_key( $item_type ) )->update( [ 'priority' => $priority ] );
		if ( $result ) {
			SyncLog::add( 'priority', 'success', $item_type, $item_id, 'اولویت صف تغییر کرد.', [ 'priority' => $priority ] );
			self::flushHealthCache();
		}
		return $result;
	}
	public static function insertProduct( $item_id ) { return self::insertItem( $item_id, 'product' ); }
	public static function insertOrder( $item_id ) { return self::insertItem( $item_id, 'order' ); }
	public static function insertCategory( $item_id ) { return self::insertItem( $item_id, 'category' ); }
	public static function insertUser( $item_id ) { return self::insertItem( $item_id, 'user' ); }
	public static function insertVariation( $item_id ) { return self::insertItem( $item_id, 'variation' ); }

	public static function removeItem( $item_id, $item_type ) {
		$item_id = absint( $item_id );
		$item_type = sanitize_key( $item_type );
		if ( ! $item_id || ! in_array( $item_type, [ 'product', 'category', 'user', 'order', 'variation' ], true ) ) return false;
		$result = self::where( 'item_id', $item_id )->where( 'item_type', $item_type )->delete();
		if ( $result ) {
			SyncLog::add( 'removed', 'success', $item_type, $item_id, 'مورد از صف تبادل حذف شد.' );
			self::flushHealthCache();
			self::doDeleteAction();
		}
		return $result;
	}
}
