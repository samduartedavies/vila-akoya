<?php
/**
 * Airbnb-style guest breakdown for campsite MWB booking (replaces default people number field).
 *
 * MWB uses `#mwb_mbfw_people_number` for adults. Children, babies, and pets are recorded on the
 * booking but do not change the price (tier rates cover the stay).
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

$def_ad = max( $min_people, 2 );
$def_ad = min( $def_ad, $max_people );

$uid = 'vila-guest-' . $product_id;

$rows = array(
	'adults'   => array(
		'label'    => __( 'Adults', 'vila-akoya' ),
		'subtitle' => __( 'Ages 13 or above', 'vila-akoya' ),
		'note'     => __( '1 adult on their own = solo traveller rate', 'vila-akoya' ),
		'value'    => $def_ad,
	),
	'children' => array(
		'label'    => __( 'Children', 'vila-akoya' ),
		'subtitle' => __( 'Ages 2 – 12', 'vila-akoya' ),
		'note'     => '',
		'value'    => 0,
	),
	'infants'  => array(
		'label'    => __( 'Babies', 'vila-akoya' ),
		'subtitle' => __( 'Under 2', 'vila-akoya' ),
		'note'     => '',
		'value'    => 0,
	),
	'pets'     => array(
		'label'    => __( 'Pets', 'vila-akoya' ),
		'subtitle' => '',
		'note'     => '',
		'value'    => 0,
	),
);
?>
<div
	class="vila-guest-picker vila-airbnb-booking vila-airbnb-booking--who"
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
		<p class="vila-airbnb-booking__legend mb-2 mt-0">
			<?php esc_html_e( 'Guests', 'vila-akoya' ); ?>
		</p>
		<div class="vila-guest-picker__bar rounded-[12px] border border-outline-variant/30 bg-surface-container-low flex flex-wrap items-center gap-3 min-h-[3.25rem]">
			<button
				type="button"
				class="vila-guest-picker__trigger flex min-w-0 flex-1 items-center gap-2 rounded-[10px] bg-transparent px-4 py-3.5 text-left font-body text-base text-on-background"
				aria-expanded="false"
				aria-haspopup="dialog"
				aria-controls="<?php echo esc_attr( $uid ); ?>-panel"
				id="<?php echo esc_attr( $uid ); ?>-trigger"
			>
				<span class="vila-guest-picker__summary font-medium truncate"><?php echo esc_html( vila_akoya_guest_picker_summary_string( $def_ad, 0, 0, 0 ) ); ?></span>
			</button>
		</div>

		<div
			class="vila-guest-picker__panel border border-outline-variant/20 bg-surface-container-low p-1 shadow-lg"
			id="<?php echo esc_attr( $uid ); ?>-panel"
			role="dialog"
			aria-labelledby="<?php echo esc_attr( $uid ); ?>-trigger"
			hidden
		>
			<?php foreach ( $rows as $slug => $row ) : ?>
				<div class="vila-guest-picker__row flex flex-wrap items-center justify-between gap-4 border-b border-outline-variant/15 px-4 py-4 last:border-b-0" data-vila-row="<?php echo esc_attr( $slug ); ?>">
					<div class="min-w-0 flex-1">
						<div class="font-body font-semibold text-on-surface"><?php echo esc_html( $row['label'] ); ?></div>
						<?php if ( '' !== $row['subtitle'] ) : ?>
							<div class="text-sm text-on-surface-variant"><?php echo esc_html( $row['subtitle'] ); ?></div>
						<?php endif; ?>
						<?php if ( '' !== $row['note'] ) : ?>
							<div class="mt-1 text-xs text-on-surface-variant/90"><?php echo esc_html( $row['note'] ); ?></div>
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
	</div>
</div>
