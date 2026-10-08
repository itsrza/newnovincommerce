<?php

use MobinDev\Novin_Commerce\Common\Accounting\Inventory_Scope;
use MobinDev\Novin_Commerce\Common\Accounting\Unit_Engine;
use MobinDev\Novin_Commerce\Common\Accounting\WebPrd\WebPrd_Discount;
use MobinDev\Novin_Commerce\Common\Accounting\WebPrd\WebPrd_Parser;

/**
 * Official Product Data Model contract tests.
 *
 * A WordPress + WooCommerce PHPUnit bootstrap is required to execute the
 * integration tests. They are deliberately kept separate from static lint so
 * a missing staging database cannot be reported as a passing runtime test.
 */
class NovinCommerce_Product_Data_Model_Test extends WP_UnitTestCase {

	private function fixture( $name ) {
		$fixtures = require __DIR__ . '/fixtures/product-data-model-fixtures.php';
		return $fixtures[ $name ];
	}

	public function test_np567_keeps_mojodi_separate_from_warehouse_total() {
		$parser = WebPrd_Parser::from( $this->fixture( 'NP567' ) );
		$inventory = $parser->inventory();
		$this->assertSame( 84.0, $inventory['source_mojodi'] );
		$this->assertSame( 30.0, $inventory['warehouse_total_all'] );
		$this->assertNotSame( $inventory['source_mojodi'], $inventory['warehouse_total_all'] );
	}

	public function test_unknown_warehouse_scope_is_not_aggregated() {
		$parser = WebPrd_Parser::from( $this->fixture( 'Saffron' ) );
		$result = Inventory_Scope::aggregate( $parser, array( 'mode' => 'unknown', 'ids' => array() ) );
		$this->assertSame( 'unknown', $result['status'] );
		$this->assertNull( $result['total'] );
	}

	public function test_v2_and_v3_are_independent_price_conversion_inputs() {
		$units = WebPrd_Parser::from( $this->fixture( 'iPhone' ) )->units();
		$this->assertSame( 6.0, $units['v2_qty'] );
		$this->assertSame( 24.0, $units['v3_qty'] );
		$this->assertNotSame( $units['v2_qty'], $units['v3_qty'] );
	}

	public function test_discount_requires_schedule_and_valid_price() {
		$expired = WebPrd_Discount::evaluate( WebPrd_Parser::from( $this->fixture( 'ExpiredDiscount' ) )->discount(), 80, strtotime( '2021-01-01 UTC' ) );
		$missing = WebPrd_Discount::evaluate( WebPrd_Parser::from( $this->fixture( 'MissingSchedule' ) )->discount(), 80, strtotime( '2021-01-01 UTC' ) );
		$this->assertSame( 'expired', $expired['state'] );
		$this->assertSame( 'invalid', $missing['state'] );
	}

	public function test_embedded_role_price_list_supports_json_and_serialized_legacy_values() {
		$payload = $this->fixture( 'EightPriceLevels' );
		$entry = array( 'WordPressRoleName' => 'Editor', 'Price' => 101, 'salePrice' => 90 );
		$payload['PriceRoleList'] = wp_json_encode( array( $entry ) );
		$this->assertSame( 'Editor', WebPrd_Parser::from( $payload )->role_prices()[0]['name'] );
		$payload['PriceRoleList'] = serialize( array( $entry ) );
		$this->assertSame( 90.0, WebPrd_Parser::from( $payload )->role_prices()[0]['sale_price'] );
	}

	public function test_eight_accounting_levels_are_not_mapped_by_array_position() {
		$parser = WebPrd_Parser::from( $this->fixture( 'EightPriceLevels' ) );
		$levels = array_filter( $parser->price_levels(), static function ( $row ) { return $row['valid']; } );
		$this->assertCount( 8, $levels );
		$this->assertCount( 1, $parser->price_role_list() );
		$this->assertSame( 'Editor', $parser->price_role_list()[0]['WordPressRoleName'] );
	}

	public function test_composite_and_production_fields_are_not_unit_mode() {
		$parser = WebPrd_Parser::from( $this->fixture( 'Composite' ) );
		$this->assertSame( 7.0, $parser->production()['kind'] );
		$this->assertSame( 'formula-1', $parser->production()['formula_guid'] );
		$this->assertTrue( $parser->production()['composite'] );
		$this->assertNull( $parser->units()['v2_qty'] );
	}

	public function test_unit_calculation_is_floor_and_never_negative() {
		$this->assertSame( array( 'stock' => 4, 'remainder' => 0.0 ), Unit_Engine::calculate( 24, 6 ) );
		$this->assertSame( array( 'stock' => 1, 'remainder' => 2.0 ), Unit_Engine::calculate( 8, 6 ) );
		$this->assertNull( Unit_Engine::calculate( 8, 0 ) );
	}
}
