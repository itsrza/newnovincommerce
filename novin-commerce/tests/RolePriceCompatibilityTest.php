<?php
/**
 * Role-price compatibility tests.
 *
 * These tests are intended for a WordPress/WooCommerce PHPUnit bootstrap. The
 * repository does not bundle that bootstrap, so they are not run in this
 * checkout; they document the important imported-product contract.
 *
 * @group novin-commerce
 */
class NovinCommerce_RolePrice_Compatibility_Test extends WP_UnitTestCase {

	public function test_festi_json_is_read_for_a_variation_role_price() {
		$variation_id = self::factory()->post->create( array( 'post_type' => 'product_variation' ) );
		update_post_meta(
			$variation_id,
			NovinCommerce_RolePrice_Roles::FESTI_META_KEY,
			wp_json_encode(
				array(
					'salePrice'   => array( 'editor' => '' ),
					'administrator' => '11.1',
					'editor'      => '66.6',
					'author'      => '55.5',
					'contributor' => '22.2',
					'subscriber'  => '44.4',
					'customer'    => '33.3',
				)
			)
		);

		$this->assertSame(
			'66.6',
			(string) NovinCommerce_RolePrice_Roles::get_compatible_role_price( $variation_id, 'editor', 'regular' )
		);
	}

	public function test_festi_update_preserves_unknown_and_schedule_data() {
		$product_id = self::factory()->post->create( array( 'post_type' => 'product' ) );
		update_post_meta(
			$product_id,
			NovinCommerce_RolePrice_Roles::FESTI_META_KEY,
			wp_json_encode(
				array(
					'schedule' => array( 'editor' => array( 'date_from' => '2026-01-01' ) ),
					'administrator' => '11.1',
					'editor' => '66.6',
				)
			)
		);

		NovinCommerce_RolePrice_Roles::update_festi_role_prices(
			$product_id,
			array(
				'editor' => array( 'regular' => '77.7', 'sale' => '70.0' ),
			)
		);

		$data = json_decode( get_post_meta( $product_id, NovinCommerce_RolePrice_Roles::FESTI_META_KEY, true ), true );
		$this->assertSame( '77.7', $data['editor'] );
		$this->assertSame( '70.0', $data['salePrice']['editor'] );
		$this->assertSame( '11.1', $data['administrator'] );
		$this->assertSame( '2026-01-01', $data['schedule']['editor']['date_from'] );
	}
}
