<?php
/**
 * Coerce request / meta values to string for PHP 8.1+ (avoid null in sanitize_text_field / esc_*).
 *
 * @package Vila_CB
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * Safe string coercion.
 */
final class Request_String {

	/**
	 * Use before sanitize_text_field(), sanitize_key(), esc_attr(), etc.
	 *
	 * @param mixed $value Raw value (string, int, float, or null / array from bad requests).
	 * @return string
	 */
	public static function scalar( $value ) {
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		if ( is_bool( $value ) ) {
			return '';
		}
		return '';
	}
}
