<?php
/**
 * Re-run campsite finalize on recent paid-like orders (WP-CLI).
 *
 * Usage (wp-env): npx wp-env run cli wp eval-file wp-content/plugins/vila-campsite-booking/resync-orders-cli.php
 *
 * @package Vila_CB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

if ( ! function_exists( 'wc_get_orders' ) ) {
	echo "WooCommerce not loaded.\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

$orders = wc_get_orders(
	array(
		'limit'   => 300,
		'status'  => array( 'processing', 'completed', 'on-hold' ),
		'orderby' => 'date',
		'order'   => 'DESC',
	)
);

$n = 0;
foreach ( $orders as $order ) {
	\Vila_CB\WooCommerce_Integration::maybe_finalize_bookings( $order->get_id() );
	++$n;
}

echo 'Re-ran campsite finalize on ' . (int) $n . " orders.\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
