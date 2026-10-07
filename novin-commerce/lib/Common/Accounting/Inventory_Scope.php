<?php

namespace MobinDev\Novin_Commerce\Common\Accounting;

use MobinDev\Novin_Commerce\Common\SettingAPI;
use MobinDev\Novin_Commerce\Common\Accounting\WebPrd\WebPrd_Parser;

/** Resolves warehouse scope from explicit site configuration. */
final class Inventory_Scope {

	/**
	 * @return array{mode:string,ids:array<int,string>}
	 */
	public static function configuration() {
		$mode = sanitize_key( (string) SettingAPI::get( 'warehouse_scope_mode', 'unknown' ) );
		if ( ! in_array( $mode, array( 'all', 'configured', 'unknown' ), true ) ) {
			$mode = 'unknown';
		}
		$ids = SettingAPI::get( 'warehouse_scope_ids', array() );
		if ( is_string( $ids ) ) {
			$ids = preg_split( '/[,\s]+/', $ids, -1, PREG_SPLIT_NO_EMPTY );
		}
		$ids = is_array( $ids ) ? array_values( array_filter( array_map( 'sanitize_text_field', $ids ) ) ) : array();
		if ( 'configured' === $mode && empty( $ids ) ) {
			$mode = 'unknown';
		}
		return array( 'mode' => $mode, 'ids' => $ids );
	}

	/**
	 * @param WebPrd_Parser $parser
	 * @param array|null    $configuration
	 * @return array{status:string,total:float|null,count:int,valid_count:int}
	 */
	public static function aggregate( WebPrd_Parser $parser, $configuration = null ) {
		$config = is_array( $configuration ) ? $configuration : self::configuration();
		if ( 'unknown' === ( $config['mode'] ?? 'unknown' ) ) {
			return array( 'status' => 'unknown', 'total' => null, 'count' => 0, 'valid_count' => 0 );
		}

		$inventory = $parser->inventory();
		$rows      = $inventory['valid_relations'];
		if ( 'configured' === $config['mode'] ) {
			$allowed = array_map( 'strval', $config['ids'] ?? array() );
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $allowed ) {
						return in_array( (string) $row['warehouse_guid'], $allowed, true );
					}
				)
			);
		}
		if ( empty( $rows ) ) {
			return array( 'status' => 'known', 'total' => 0.0, 'count' => 0, 'valid_count' => 0 );
		}
		return array(
			'status'      => 'known',
			'total'       => (float) array_sum( array_column( $rows, 'amount' ) ),
			'count'       => count( $rows ),
			'valid_count' => count( $rows ),
		);
	}
}
