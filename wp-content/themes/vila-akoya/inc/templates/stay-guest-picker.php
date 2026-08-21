<?php
/**
 * Guest picker for village stays: occupancy only (nightly rate is for the whole stay).
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
	$max_people = max( $min_people, 4 );
}

$stay_id    = function_exists( 'vila_akoya_stay_id_from_product_id' ) ? vila_akoya_stay_id_from_product_id( $product_id ) : 0;
$stay_data  = $stay_id && function_exists( 'vila_akoya_get_stay_data' ) ? vila_akoya_get_stay_data( $stay_id ) : array();
$pets_ok    = ! empty( $stay_data['pets'] );
$max_pets   = $pets_ok ? 2 : 0;

$def_ad = max( $min_people, min( 2, $max_people ) );
$uid    = 'vila-guest-' . $product_id;
?>
<div
	class="vila-guest-picker vila-airbnb-booking vila-airbnb-booking--who"
	id="<?php echo esc_attr( $uid ); ?>"
	data-vila-default-adults="<?php echo esc_attr( (string) $def_ad ); ?>"
	data-vila-min-adults="<?php echo esc_attr( (string) $min_people ); ?>"
	data-vila-max-people="<?php echo esc_attr( (string) $max_people ); ?>"
	data-vila-max-pets="<?php echo esc_attr( (string) $max_pets ); ?>"
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
			<?php
			$rows = array(
				'adults'   => array(
					'label'    => __( 'Adults', 'vila-akoya' ),
					'subtitle' => __( 'Ages 13 or above', 'vila-akoya' ),
				),
				'children' => array(
					'label'    => __( 'Children', 'vila-akoya' ),
					'subtitle' => __( 'Ages 2 – 12', 'vila-akoya' ),
				),
				'infants'  => array(
					'label'    => __( 'Babies', 'vila-akoya' ),
					'subtitle' => __( 'Under 2', 'vila-akoya' ),
				),
			);
			if ( $pets_ok ) {
				$rows['pets'] = array(
					'label'    => __( 'Pets', 'vila-akoya' ),
					'subtitle' => __( 'Welcome in this stay', 'vila-akoya' ),
				);
			}
			foreach ( $rows as $slug => $row ) :
				$value = 'adults' === $slug ? $def_ad : 0;
				?>
				<div class="vila-guest-picker__row flex flex-wrap items-center justify-between gap-4 border-b border-outline-variant/15 px-4 py-4 last:border-b-0" data-vila-row="<?php echo esc_attr( $slug ); ?>">
					<div class="min-w-0 flex-1">
						<div class="font-body font-semibold text-on-surface"><?php echo esc_html( $row['label'] ); ?></div>
						<?php if ( ! empty( $row['subtitle'] ) ) : ?>
						<div class="text-sm text-on-surface-variant"><?php echo esc_html( $row['subtitle'] ); ?></div>
						<?php endif; ?>
						<?php if ( 'adults' === $slug ) : ?>
							<div class="mt-1 text-xs text-on-surface-variant/90"><?php esc_html_e( 'Included in the nightly rate', 'vila-akoya' ); ?></div>
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
						<span class="vila-guest-picker__value w-6 text-center font-body text-on-surface tabular-nums" data-vila-value-for="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( (string) (int) $value ); ?></span>
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
