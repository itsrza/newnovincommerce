<?php

namespace MobinDev\Novin_Commerce\Common\Accounting\WebPrd;

/**
 * Discount state is derived from a valid schedule and price, never from a
 * positive percentage alone.
 */
final class WebPrd_Discount {

	/**
	 * @param array<string,mixed> $discount Parser discount() result.
	 * @param mixed               $sale_price A sale price already normalized by the caller.
	 * @param int|null             $now UTC timestamp.
	 * @return array{state:string,source:string,start:int|null,end:int|null,valid_price:bool}
	 */
	public static function evaluate( array $discount, $sale_price = null, $now = null ) {
		$now   = null === $now ? time() : (int) $now;
		$start = WebPrd_Sync::timestamp( $discount['start_date'] ?? null );
		$end   = WebPrd_Sync::timestamp( $discount['end_date'] ?? null );
		$percent = isset( $discount['percent'] ) && is_numeric( $discount['percent'] ) ? (float) $discount['percent'] : null;
		$valid_price = is_numeric( $sale_price ) && (float) $sale_price >= 0;

		if ( null === $percent && null === $start && null === $end && ! $valid_price ) {
			return array( 'state' => 'none', 'source' => 'accounting', 'start' => null, 'end' => null, 'valid_price' => false );
		}
		if ( null !== $percent && ( $percent < 0 || $percent > 100 ) ) {
			return array( 'state' => 'invalid', 'source' => 'accounting', 'start' => $start, 'end' => $end, 'valid_price' => $valid_price );
		}
		if ( ( null !== $start && null !== $end && $start >= $end ) || ( null !== $start && null === $end ) || ( null === $start && null !== $end ) ) {
			return array( 'state' => 'invalid', 'source' => 'accounting', 'start' => $start, 'end' => $end, 'valid_price' => $valid_price );
		}
		if ( ! $valid_price ) {
			return array( 'state' => 'invalid', 'source' => 'accounting', 'start' => $start, 'end' => $end, 'valid_price' => false );
		}
		if ( null !== $end && $now >= $end ) {
			return array( 'state' => 'expired', 'source' => 'accounting', 'start' => $start, 'end' => $end, 'valid_price' => true );
		}
		if ( null !== $start && $now < $start ) {
			return array( 'state' => 'scheduled', 'source' => 'accounting', 'start' => $start, 'end' => $end, 'valid_price' => true );
		}
		// An accounting discount without an explicit complete schedule is not
		// active. This is intentionally different from a manual WooCommerce
		// sale, whose origin is unknown to this service.
		if ( null === $start || null === $end ) {
			return array( 'state' => 'invalid', 'source' => 'accounting', 'start' => $start, 'end' => $end, 'valid_price' => true );
		}
		return array( 'state' => 'active', 'source' => 'accounting', 'start' => $start, 'end' => $end, 'valid_price' => true );
	}
}
