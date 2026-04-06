<?php
/**
 * Logs PHP 8.1+ "Passing null to …" deprecations with a backtrace so the real caller can be found
 * (the notice points at wp-includes/functions.php, not the offending theme/plugin line).
 *
 * Enable: `WP_DEBUG` + `WP_DEBUG_LOG`, or:
 * `add_filter( 'vila_cb_trace_null_string_deprecations', '__return_true' );`
 *
 * WooCommerce → Status → Logs: source `vila-cb-null-trace`.
 *
 * @package Vila_CB
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * Deprecation / null-parameter tracer.
 */
class Deprecation_Trace {

	/** @var callable|true|null */
	private static $previous_handler = null;

	/**
	 * Register early.
	 */
	public static function init() {
		// Late priority so we wrap any handler set by other plugins on the same hook.
		add_action( 'plugins_loaded', array( __CLASS__, 'register' ), 99999 );
	}

	/**
	 * @return bool
	 */
	private static function is_enabled() {
		return (bool) apply_filters(
			'vila_cb_trace_null_string_deprecations',
			( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG )
		);
	}

	/**
	 * Install error handler (chains previous handler).
	 */
	public static function register() {
		if ( ! self::is_enabled() ) {
			return;
		}
		self::$previous_handler = set_error_handler( array( __CLASS__, 'handle_error' ), E_DEPRECATED | E_USER_DEPRECATED );
	}

	/**
	 * @param int         $errno      Level.
	 * @param string      $errstr     Message.
	 * @param string|null $errfile    File.
	 * @param int|null    $errline    Line.
	 * @param mixed       $errcontext Deprecated in PHP 8; still passed on 7.4.
	 * @return bool
	 */
	public static function handle_error( $errno, $errstr, $errfile = '', $errline = 0, $errcontext = null ) {
		if ( self::is_interesting_deprecation( (string) $errstr ) ) {
			$summary = self::format_trace_summary();
			$line    = sprintf(
				'[vila-cb-null-trace] %s | at %s:%d | %s',
				$errstr,
				(string) $errfile,
				(int) $errline,
				$summary
			);
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( $line, array( 'source' => 'vila-cb-null-trace' ) );
			} else {
				error_log( $line );
			}
		}

		if ( self::$previous_handler && is_callable( self::$previous_handler ) ) {
			return (bool) call_user_func( self::$previous_handler, $errno, $errstr, $errfile, $errline, $errcontext );
		}

		return false;
	}

	/**
	 * @param string $errstr Message.
	 * @return bool
	 */
	private static function is_interesting_deprecation( $errstr ) {
		$e = strtolower( $errstr );
		if ( strpos( $e, 'passing null' ) === false ) {
			return false;
		}
		return (bool) preg_match( '/\b(strpos|str_replace|strlen|preg_replace|substr|htmlspecialchars|trim|explode|strcmp)\b/', $e );
	}

	/**
	 * Compact backtrace for logs (newest frames first after skipping this handler).
	 *
	 * @return string
	 */
	private static function format_trace_summary() {
		$bt = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 40 );
		$out = array();
		$n   = 0;
		foreach ( $bt as $i => $f ) {
			if ( $i < 2 ) {
				continue;
			}
			if ( $n >= 18 ) {
				break;
			}
			$file = isset( $f['file'] ) ? $f['file'] : '';
			$line = isset( $f['line'] ) ? (int) $f['line'] : 0;
			$fn   = isset( $f['function'] ) ? $f['function'] : '';
			$cls  = '';
			if ( isset( $f['class'] ) ) {
				$cls = (string) $f['class'] . ( isset( $f['type'] ) ? (string) $f['type'] : '::' );
			}
			$out[] = basename( (string) $file ) . ':' . $line . ' ' . $cls . $fn . '()';
			++$n;
		}
		return 'trace: ' . implode( ' ← ', $out );
	}
}
