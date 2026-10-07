<?php

namespace MobinDev\Novin_Commerce\Common\Accounting;

use MobinDev\Novin_Commerce\Common\Accounting\WebPrd\WebPrd_Parser;

/**
 * Resolves explicit accounting-unit definitions and performs only integer
 * complete-pack calculations. It never treats an arbitrary non-base
 * variation as a pack.
 */
final class Unit_Engine {

	const MODE_NONE       = 'none';
	const MODE_MULTI_UNIT = 'multi_unit';
	const MODE_UNRESOLVED = 'unresolved';

	/**
	 * @param object          $product WC_Product variable product.
	 * @param WebPrd_Parser   $parser
	 * @return array{mode:string,units:array<int,array<string,mixed>>,reason:string}
	 */
	public static function resolve_product( $product, WebPrd_Parser $parser ) {
		if ( ! is_object( $product ) || ! is_callable( array( $product, 'is_type' ) ) || ! $product->is_type( 'variable' ) ) {
			return array( 'mode' => self::MODE_NONE, 'units' => array(), 'reason' => 'not_variable' );
		}

		$explicit_mode = '';
		$locked = false;
		if ( is_callable( array( $product, 'get_meta' ) ) ) {
			$explicit_mode = sanitize_key( (string) $product->get_meta( '_novin_unit_mode', true ) );
			$locked = '1' === (string) $product->get_meta( '_novin_unit_mode_lock', true );
		}
		if ( $locked && self::MODE_NONE === $explicit_mode ) {
			return array( 'mode' => self::MODE_NONE, 'units' => array(), 'reason' => 'explicitly_locked' );
		}

		$raw_units = $parser->units();
		$unit_labels = self::explicit_unit_labels( $parser->attributes() );
		$definitions = array(
			array(
				'key'            => 'base',
				'label'          => $raw_units['base_name'],
				'guid'           => '',
				'units_per_pack' => 1,
			),
		);
		if ( null !== $raw_units['v2_qty'] && '' !== $raw_units['v2_guid'] ) {
			$definitions[] = array(
				'key'            => 'v2',
				'label'          => $unit_labels[ $raw_units['v2_guid'] ] ?? '',
				'guid'           => $raw_units['v2_guid'],
				'units_per_pack' => (float) $raw_units['v2_qty'],
			);
		}
		if ( null !== $raw_units['v3_qty'] && '' !== $raw_units['v3_guid'] ) {
			$definitions[] = array(
				'key'            => 'v3',
				'label'          => $unit_labels[ $raw_units['v3_guid'] ] ?? '',
				'guid'           => $raw_units['v3_guid'],
				'units_per_pack' => (float) $raw_units['v3_qty'],
			);
		}

		if ( count( $definitions ) < 2 ) {
			return array( 'mode' => self::MODE_NONE, 'units' => array(), 'reason' => 'no_explicit_multiple_units' );
		}

		$resolved = array();
		$children = is_callable( array( $product, 'get_children' ) ) ? (array) $product->get_children() : array();
		foreach ( $children as $child_id ) {
			$variation = function_exists( 'wc_get_product' ) ? wc_get_product( absint( $child_id ) ) : false;
			$unit      = self::resolve_variation( $variation, $definitions );
			if ( null === $unit ) {
				return array( 'mode' => self::MODE_UNRESOLVED, 'units' => $resolved, 'reason' => 'variation_unit_unmapped' );
			}
			$resolved[] = array_merge( $unit, array( 'variation_id' => absint( $child_id ) ) );
		}

		if ( empty( $resolved ) ) {
			return array( 'mode' => self::MODE_UNRESOLVED, 'units' => array(), 'reason' => 'no_variations' );
		}
		return array( 'mode' => self::MODE_MULTI_UNIT, 'units' => $resolved, 'reason' => 'explicit_definitions_and_mappings' );
	}

	/**
	 * @param object|null $variation
	 * @param array<int,array<string,mixed>> $definitions
	 * @return array<string,mixed>|null
	 */
	private static function resolve_variation( $variation, array $definitions ) {
		if ( ! is_object( $variation ) ) return null;
		$explicit_guid = is_callable( array( $variation, 'get_meta' ) ) ? trim( (string) $variation->get_meta( '_novin_accounting_unit_guid', true ) ) : '';
		$attributes = is_callable( array( $variation, 'get_attributes' ) ) ? (array) $variation->get_attributes() : array();
		$values = array();
		foreach ( $attributes as $key => $value ) {
			$values[] = self::normalize( $key );
			$values[] = self::normalize( $value );
			$taxonomy = sanitize_key( str_replace( 'attribute_', '', (string) $key ) );
			if ( function_exists( 'taxonomy_exists' ) && taxonomy_exists( $taxonomy ) && is_scalar( $value ) ) {
				$term = get_term_by( 'slug', sanitize_title( (string) $value ), $taxonomy );
				if ( $term && ! is_wp_error( $term ) ) $values[] = self::normalize( $term->name );
			}
		}
		$matches = array();
		foreach ( $definitions as $definition ) {
			$guid_match = '' !== $definition['guid'] && ( $explicit_guid === $definition['guid'] || in_array( self::normalize( $definition['guid'] ), $values, true ) );
			$label_match = '' !== $definition['label'] && in_array( self::normalize( $definition['label'] ), $values, true );
			if ( ( 'base' === $definition['key'] && $label_match ) || $guid_match || ( 'base' !== $definition['key'] && $label_match ) ) $matches[] = $definition;
		}
		if ( 1 !== count( $matches ) ) return null;
		$definition = $matches[0];
		return array( 'unit_key' => $definition['key'], 'unit_label' => $definition['label'], 'unit_guid' => $definition['guid'], 'units_per_pack' => (float) $definition['units_per_pack'] );
	}

	/**
	 * @param float|int $base_stock
	 * @param float|int $units_per_pack
	 * @return array{stock:int,remainder:float}|null
	 */
	public static function calculate( $base_stock, $units_per_pack ) {
		if ( ! is_numeric( $base_stock ) || ! is_numeric( $units_per_pack ) || (float) $base_stock < 0 || (float) $units_per_pack <= 0 ) {
			return null;
		}
		$base_stock     = (float) $base_stock;
		$units_per_pack = (float) $units_per_pack;
		$stock          = (int) floor( $base_stock / $units_per_pack );
		return array(
			'stock'     => max( 0, $stock ),
			'remainder' => $base_stock - ( $stock * $units_per_pack ),
		);
	}

	/**
	 * Read only explicit GUID/name pairs from accounting attribute relations.
	 * No positional or "non-base" inference is performed.
	 *
	 * @param array<string,mixed> $attributes
	 * @return array<string,string>
	 */
	private static function explicit_unit_labels( array $attributes ) {
		$labels = array();
		$rows = array_merge(
			isset( $attributes['attribute_relations'] ) && is_array( $attributes['attribute_relations'] ) ? $attributes['attribute_relations'] : array(),
			isset( $attributes['related_attributes'] ) && is_array( $attributes['related_attributes'] ) ? $attributes['related_attributes'] : array()
		);
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$guid = '';
			foreach ( array( 'Guid', 'GuidValue', 'AttributeGuid', 'ValueGuid', 'UnitGuid' ) as $key ) {
				if ( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) && '' !== trim( (string) $row[ $key ] ) ) {
					$guid = trim( (string) $row[ $key ] );
					break;
				}
			}
			$name = '';
			foreach ( array( 'Name', 'Value', 'AttributeName', 'Title', 'UnitName' ) as $key ) {
				if ( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) && '' !== trim( (string) $row[ $key ] ) ) {
					$name = trim( (string) $row[ $key ] );
					break;
				}
			}
			if ( '' !== $guid && '' !== $name ) {
				$labels[ $guid ] = $name;
			}
		}
		return $labels;
	}

	private static function normalize( $value ) {
		$value = trim( (string) $value );
		$value = preg_replace( '/\s+/u', '', $value );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}
}
