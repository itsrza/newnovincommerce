<?php

use MobinDev\Novin_Commerce\Common\Accounting\Product_Health_Snapshot;
use MobinDev\Novin_Commerce\Models\Sync;

/**
 * Database-backed safety tests. Execute only with the plugin's WP/Woo
 * integration bootstrap and a verified schema.
 */
class NovinCommerce_Concurrency_And_Snapshot_Test extends WP_UnitTestCase {

	public function test_sync_queue_identity_is_idempotent_under_repeated_enqueue() {
		$product_id = self::factory()->post->create( array( 'post_type' => 'product', 'post_status' => 'publish' ) );
		$this->assertTrue( Sync::queueItem( $product_id, 'product' ) );
		$this->assertTrue( Sync::queueItem( $product_id, 'product', 20 ) );

		global $wpdb;
		$table = $wpdb->prefix . 'novin_commerce_syncs';
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE item_id = %d AND item_type = %s", $product_id, 'product' ) );
		$this->assertSame( 1, $count );
		$priority = (int) $wpdb->get_var( $wpdb->prepare( "SELECT priority FROM {$table} WHERE item_id = %d AND item_type = %s LIMIT 1", $product_id, 'product' ) );
		$this->assertSame( 20, $priority );
	}

	public function test_snapshot_backfill_is_bounded() {
		$result = Product_Health_Snapshot::backfill( 3, 0 );
		$this->assertLessThanOrEqual( 3, $result['processed'] + $result['failed'] );
		$this->assertArrayHasKey( 'next_offset', $result );
	}
}
