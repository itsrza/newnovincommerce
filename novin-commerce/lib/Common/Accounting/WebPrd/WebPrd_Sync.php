<?php

namespace MobinDev\Novin_Commerce\Common\Accounting\WebPrd;

/** Explicit synchronization helpers shared by dashboard and snapshot code. */
final class WebPrd_Sync {

	/**
	 * Parse common ISO, WordPress and accounting date representations into a
	 * UTC timestamp. Invalid dates return null instead of being guessed.
	 *
	 * @param mixed $value
	 * @return int|null
	 */
	public static function timestamp( $value ) {
		if ( is_int( $value ) || ( is_numeric( $value ) && (float) $value > 0 ) ) {
			$value = (int) $value;
			return $value > 0 ? $value : null;
		}
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		$value = trim( str_replace( '/', '-', $value ) );
		try {
			$date = new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
			return $date->getTimestamp();
		} catch ( \Throwable $exception ) {
			return null;
		}
	}

	/**
	 * @param mixed $modified Source Modified value.
	 * @param mixed $site_sync Last local synchronization value.
	 * @return string never_synced|synced|stale|unknown
	 */
	public static function state( $modified, $site_sync ) {
		$source = self::timestamp( $modified );
		$local  = self::timestamp( $site_sync );
		if ( null === $source || null === $local ) {
			return null === $local ? ( null === $source ? 'unknown' : 'never_synced' ) : 'unknown';
		}
		return $source > $local ? 'stale' : 'synced';
	}

	/**
	 * @param string $state
	 * @return string
	 */
	public static function label( $state ) {
		$labels = array(
			'never_synced' => 'never_synced',
			'synced'      => 'synced',
			'stale'       => 'stale',
			'queued'      => 'queued',
			'processing'  => 'processing',
			'failed'      => 'failed',
			'unknown'     => 'unknown',
		);
		return $labels[ $state ] ?? 'unknown';
	}
}
