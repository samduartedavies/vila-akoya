<?php
/**
 * Quick sanity check for tier decomposition (run: php bin/test-campsite-tier-pricing.php).
 */

$root = dirname( __DIR__ );
require_once $root . '/includes/class-campsite-tier-pricing.php';

use Vila_CB\Campsite_Tier_Pricing;

$assert = static function ( $label, $expected, $actual ) {
	$ok = abs( (float) $expected - (float) $actual ) < 0.01;
	echo ( $ok ? 'OK  ' : 'FAIL' ) . " $label: expected $expected, got $actual\n";
	if ( ! $ok ) {
		exit( 1 );
	}
};

$assert( 'standard 1 night', 27, Campsite_Tier_Pricing::accommodation_total( 1, false ) );
$assert( 'standard 7 nights', 165, Campsite_Tier_Pricing::accommodation_total( 7, false ) );
$assert( 'standard 28 nights', 555, Campsite_Tier_Pricing::accommodation_total( 28, false ) );
$assert( 'standard 30 nights', 609, Campsite_Tier_Pricing::accommodation_total( 30, false ) );
$assert( 'solo 1 night', 15, Campsite_Tier_Pricing::accommodation_total( 1, true ) );
$assert( 'solo 28 nights', 330, Campsite_Tier_Pricing::accommodation_total( 28, true ) );
$assert( 'standard no elec 7 nights', 147, Campsite_Tier_Pricing::accommodation_total( 7, false, true ) );
$assert( 'solo no elec 28 nights', 270, Campsite_Tier_Pricing::accommodation_total( 28, true, true ) );

$guests_one = array( 'adults' => 1, 'children' => 0, 'infants' => 0 );
echo ( Campsite_Tier_Pricing::is_solo_tent_stay( $guests_one, array(), false ) ? 'OK  ' : 'FAIL' ) . " solo tent without opt-in: false\n";
echo ( Campsite_Tier_Pricing::is_solo_tent_stay( $guests_one, array(), true ) ? 'OK  ' : 'FAIL' ) . " solo tent with opt-in: true\n";
echo ( Campsite_Tier_Pricing::is_solo_tent_stay( array( 'adults' => 2 ), array(), true ) ? 'FAIL' : 'OK  ' ) . " two adults opt-in rejected\n";

echo "All tier pricing checks passed.\n";
