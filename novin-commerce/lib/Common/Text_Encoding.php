<?php

namespace MobinDev\Novin_Commerce\Common;

/**
 * Small UTF-8 boundary helper for values coming from accounting/SMS APIs.
 * WordPress normally runs as UTF-8, but an old provider or a legacy table can
 * still hand the plugin Windows-1256/ISO-8859-6 bytes. Normalize those bytes
 * before sanitizing, JSON decoding or rendering them in the admin.
 */
final class Text_Encoding {

	/**
	 * Return a valid UTF-8 string without changing already-valid Persian text.
	 *
	 * @param mixed $value Text value.
	 * @return string
	 */
	public static function normalize( $value ) {
		if ( null === $value || is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}

		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $value, 'UTF-8' ) ) {
			$encoding = function_exists( 'mb_detect_encoding' )
				? mb_detect_encoding( $value, array( 'Windows-1256', 'ISO-8859-6', 'Windows-1252' ), true )
				: false;
			if ( $encoding && function_exists( 'mb_convert_encoding' ) ) {
				$value = mb_convert_encoding( $value, 'UTF-8', $encoding );
			}
		}

		if ( function_exists( 'wp_check_invalid_utf8' ) ) {
			$value = wp_check_invalid_utf8( $value, true );
		}

		return (string) $value;
	}
}
