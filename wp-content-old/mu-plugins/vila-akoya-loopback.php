<?php
/**
 * Plugin Name: Vila Akoya — Docker / wp-env loopback fix
 * Description: Rewrites self-HTTP requests from localhost:PORT (host-mapped port) to 127.0.0.1:80 inside the container so REST API and Site Health loopback succeed. Works with any mapped port (8888, 8889, etc.).
 * Version: 1.2.0
 *
 * @package vila-akoya
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param string $host Parsed URL host.
 */
function vila_akoya_loopback_is_loopback_host( $host ) {
	$h = strtolower( (string) $host );
	return in_array( $h, array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true );
}

/**
 * Default port for a scheme when omitted.
 *
 * @param string $scheme http or https.
 */
function vila_akoya_loopback_default_port( $scheme ) {
	return ( isset( $scheme ) && 'https' === strtolower( (string) $scheme ) ) ? 443 : 80;
}

/**
 * Numeric port from parsed URL parts.
 *
 * @param array $parsed wp_parse_url output.
 */
function vila_akoya_loopback_url_port( array $parsed ) {
	$scheme = isset( $parsed['scheme'] ) ? $parsed['scheme'] : 'http';
	if ( ! empty( $parsed['port'] ) ) {
		return (int) $parsed['port'];
	}
	return vila_akoya_loopback_default_port( $scheme );
}

/**
 * Ports WordPress advertises for this site on loopback (e.g. 8889 from wp-env).
 *
 * @return array<int, int>
 */
function vila_akoya_loopback_mapped_ports() {
	$ports = array();
	foreach ( array( home_url(), site_url() ) as $base ) {
		$p = wp_parse_url( $base );
		if ( empty( $p['host'] ) || ! vila_akoya_loopback_is_loopback_host( $p['host'] ) ) {
			continue;
		}
		$ports[] = vila_akoya_loopback_url_port( $p );
	}
	return array_values( array_unique( array_filter( $ports ) ) );
}

/**
 * Whether this request should be routed to the in-container web server (:80).
 */
function vila_akoya_loopback_should_rewrite( $url ) {
	$u = wp_parse_url( $url );
	if ( empty( $u['host'] ) || ! vila_akoya_loopback_is_loopback_host( $u['host'] ) ) {
		return false;
	}

	$env = defined( 'WP_ENVIRONMENT_TYPE' ) ? WP_ENVIRONMENT_TYPE : '';
	if ( in_array( $env, array( 'production', 'staging' ), true ) ) {
		return false;
	}

	$url_port = vila_akoya_loopback_url_port( $u );

	// Inside the container the daemon listens on 80; those requests do not need rewriting.
	if ( 80 === $url_port ) {
		return false;
	}

	$mapped = vila_akoya_loopback_mapped_ports();
	if ( empty( $mapped ) || ! in_array( $url_port, $mapped, true ) ) {
		return false;
	}

	return true;
}

/**
 * @param false|array|WP_Error|null $preempt Whether to preempt the HTTP request.
 * @param array                     $args    Request arguments.
 * @param string                    $url     URL.
 * @return false|array|WP_Error|null
 */
function vila_akoya_loopback_internal_request( $preempt, $args, $url ) {
	if ( null !== $preempt && false !== $preempt ) {
		return $preempt;
	}

	if ( ! vila_akoya_loopback_should_rewrite( $url ) ) {
		return $preempt;
	}

	$u = wp_parse_url( $url );

	$path = isset( $u['path'] ) ? $u['path'] : '/';
	if ( '' === $path ) {
		$path = '/';
	}

	$scheme = isset( $u['scheme'] ) ? $u['scheme'] : 'http';

	$query    = isset( $u['query'] ) ? '?' . $u['query'] : '';
	$fragment = isset( $u['fragment'] ) ? '#' . $u['fragment'] : '';

	// Web server inside Docker listens on port 80.
	$new_url = $scheme . '://127.0.0.1' . $path . $query . $fragment;

	$args = is_array( $args ) ? $args : array();
	if ( empty( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
		$args['headers'] = array();
	}

	$home = wp_parse_url( home_url() );
	if ( ! empty( $home['host'] ) ) {
		$host_header = $home['host'];
		if ( ! empty( $home['port'] ) ) {
			$host_header .= ':' . $home['port'];
		}
		$args['headers']['Host'] = $host_header;
	}

	$args['reject_unsafe_urls'] = false;

	if ( 'https' === strtolower( $scheme ) ) {
		$args['sslverify'] = false;
	}

	return wp_remote_request( $new_url, $args );
}
add_filter( 'pre_http_request', 'vila_akoya_loopback_internal_request', 1, 3 );
