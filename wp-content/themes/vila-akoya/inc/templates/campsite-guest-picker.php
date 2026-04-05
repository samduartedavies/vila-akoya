<?php
/**
 * Airbnb-style guest breakdown for campsite MWB booking (replaces default people number field).
 *
 * MWB uses `#mwb_mbfw_people_number` for adults only (per-guest nightly rate). Children, infants, and pets
 * are priced via theme fees (see booking-extras.php).
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $product_id ) ) {
	return;
}

$product_id = (int) $product_id;
$min_people = (int) wps_booking_get_meta_data( $product_id, 'mwb_mbfw_minimum_people_per_booking', true );
$max_people = (int) wps_booking_get_meta_data( $product_id, 'mwb_mbfw_maximum_people_per_booking', true );
if ( $min_people < 1 ) {
	$min_people = 1;
}
if ( $max_people < $min_people ) {
	$max_people = max( $min_people, 8 );
}

$rates   = vila_akoya_get_guest_category_nightly_rates( $product_id );
$def_ad  = max( $min_people, 2 );
$def_ad  = min( $def_ad, $max_people );

$uid = 'vila-guest-' . $product_id;
?>
<div
	class="vila-guest-picker"
	id="<?php echo esc_attr( $uid ); ?>"
	data-vila-default-adults="<?php echo esc_attr( (string) $def_ad ); ?>"
	data-vila-min-adults="<?php echo esc_attr( (string) $min_people ); ?>"
	data-vila-max-people="<?php echo esc_attr( (string) $max_people ); ?>"
	data-vila-max-pets="5"
>
	<input
		type="number"
		class="sr-only"
		name="mwb_mbfw_people_number"
		id="mwb_mbfw_people_number"
		value="<?php echo esc_attr( (string) $def_ad ); ?>"
		min="<?php echo esc_attr( (string) $min_people ); ?>"
		max="<?php echo esc_attr( (string) $max_people ); ?>"
		step="1"
		required
		aria-hidden="true"
		tabindex="-1"
	/>
	<input type="hidden" name="vila_guest_children" id="vila_guest_children" value="0" />
	<input type="hidden" name="vila_guest_infants" id="vila_guest_infants" value="0" />
	<input type="hidden" name="vila_guest_pets" id="vila_guest_pets" value="0" />

	<div class="mbfw-additionl-detail-listing-section__wrapper vila-guest-picker__shell">
		<p class="font-label text-xs uppercase tracking-widest text-on-surface-variant mb-2 mt-4">
			<?php esc_html_e( 'Who', 'vila-akoya' ); ?>
		</p>
		<div class="vila-guest-picker__bar rounded-full border border-outline-variant/25 bg-surface-container-low/90 shadow-sm px-4 py-3 flex flex-wrap items-center gap-3">
			<button
				type="button"
				class="vila-guest-picker__trigger flex min-w-0 flex-1 items-center gap-2 rounded-full bg-transparent text-left font-body text-on-surface"
				aria-expanded="false"
				aria-haspopup="dialog"
				aria-controls="<?php echo esc_attr( $uid ); ?>-panel"
				id="<?php echo esc_attr( $uid ); ?>-trigger"
			>
				<span class="vila-guest-picker__summary font-medium truncate"><?php echo esc_html( vila_akoya_guest_picker_summary_string( $def_ad, 0, 0, 0 ) ); ?></span>
			</button>
			<button
				type="button"
				class="vila-guest-picker__clear text-on-surface-variant hover:text-on-surface rounded-full p-1 text-sm"
				aria-label="<?php esc_attr_e( 'Reset guests', 'vila-akoya' ); ?>"
			>
				×
			</button>
		</div>

		<div
			class="vila-guest-picker__panel mt-3 rounded-2xl border border-outline-variant/20 bg-surface-container-lowest p-1 shadow-lg"
			id="<?php echo esc_attr( $uid ); ?>-panel"
			role="dialog"
			aria-labelledby="<?php echo esc_attr( $uid ); ?>-trigger"
			hidden
		>
			<?php
			$rows = array(
				'adults'   => array(
					'label'    => __( 'Adults', 'vila-akoya' ),
					'subtitle' => __( 'Ages 13 or above', 'vila-akoya' ),
					'min'      => $min_people,
					'max'      => $max_people,
					'value'    => $def_ad,
					'rate_key' => null,
				),
				'children' => array(
					'label'    => __( 'Children', 'vila-akoya' ),
					'subtitle' => __( 'Ages 2 – 12', 'vila-akoya' ),
					'min'      => 0,
					'max'      => $max_people,
					'value'    => 0,
					'rate_key' => 'children',
				),
				'infants'  => array(
					'label'    => __( 'Infants', 'vila-akoya' ),
					'subtitle' => __( 'Under 2', 'vila-akoya' ),
					'min'      => 0,
					'max'      => $max_people,
					'value'    => 0,
					'rate_key' => 'infants',
				),
				'pets'     => array(
					'label'    => __( 'Pets', 'vila-akoya' ),
					'subtitle' => __( 'Service animals welcome — contact us if you need assistance.', 'vila-akoya' ),
					'min'      => 0,
					'max'      => 5,
					'value'    => 0,
					'rate_key' => 'pets',
				),
			);
			foreach ( $rows as $slug => $row ) :
				$rate_note = '';
				if ( ! empty( $row['rate_key'] ) && isset( $rates[ $row['rate_key'] ] ) && (float) $rates[ $row['rate_key'] ] > 0 && function_exists( 'wc_price' ) ) {
					$rate_note = sprintf(
						/* translators: %s: formatted price per night */
						__( '+ %s / night', 'vila-akoya' ),
						wp_strip_all_tags( wc_price( $rates[ $row['rate_key'] ] ) )
					);
				} elseif ( ! empty( $row['rate_key'] ) && isset( $rates[ $row['rate_key'] ] ) && (float) $rates[ $row['rate_key'] ] <= 0 ) {
					$rate_note = __( 'No nightly fee', 'vila-akoya' );
				}
				?>
				<div class="vila-guest-picker__row flex flex-wrap items-center justify-between gap-4 border-b border-outline-variant/15 px-4 py-4 last:border-b-0" data-vila-row="<?php echo esc_attr( $slug ); ?>">
					<div class="min-w-0 flex-1">
						<div class="font-body font-semibold text-on-surface"><?php echo esc_html( $row['label'] ); ?></div>
						<div class="text-sm text-on-surface-variant"><?php echo esc_html( $row['subtitle'] ); ?></div>
						<?php if ( $rate_note ) : ?>
							<div class="mt-1 text-xs text-on-surface-variant/90"><?php echo wp_kses_post( $rate_note ); ?></div>
						<?php endif; ?>
					</div>
					<div class="vila-guest-picker__stepper flex shrink-0 items-center gap-3">
						<button
							type="button"
							class="vila-guest-picker__btn vila-guest-picker__btn--minus h-9 w-9 rounded-full border border-outline-variant/30 bg-surface-container-low text-lg leading-none text-on-surface transition-opacity disabled:opacity-35"
							data-vila-delta="-1"
							data-vila-slug="<?php echo esc_attr( $slug ); ?>"
							aria-label="<?php echo esc_attr( sprintf( /* translators: guest category */ __( 'Decrease %s', 'vila-akoya' ), $row['label'] ) ); ?>"
						>
							−
						</button>
						<span class="vila-guest-picker__value w-6 text-center font-body text-on-surface tabular-nums" data-vila-value-for="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( (string) (int) $row['value'] ); ?></span>
						<button
							type="button"
							class="vila-guest-picker__btn vila-guest-picker__btn--plus h-9 w-9 rounded-full border border-outline-variant/30 bg-surface-container-low text-lg leading-none text-on-surface transition-opacity disabled:opacity-35"
							data-vila-delta="1"
							data-vila-slug="<?php echo esc_attr( $slug ); ?>"
							aria-label="<?php echo esc_attr( sprintf( /* translators: guest category */ __( 'Increase %s', 'vila-akoya' ), $row['label'] ) ); ?>"
						>
							+
						</button>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<p class="vila-guest-picker__estimate mt-2 text-xs text-on-surface-variant mb-0" aria-live="polite"></p>
	</div>
</div>
