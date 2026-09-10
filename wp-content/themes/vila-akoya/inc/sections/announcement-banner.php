<?php
/**
 * Full-bleed homepage announcement banner.
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

$until = (string) vila_akoya_section_field( 'until_date', '2026-09-28' );
if ( '' !== $until && current_time( 'Y-m-d' ) > $until ) {
	return;
}

$eyebrow = (string) vila_akoya_section_field( 'eyebrow', '' );
$heading = (string) vila_akoya_section_field( 'heading', 'Launch festival' );
$dates   = (string) vila_akoya_section_field( 'dates', '23–27 September' );
$body    = (string) vila_akoya_section_field(
	'body',
	'Musicians, DJs, workshops and experiences all week long — come stay, dance, make things, and help us open Vila Akoya.'
);

$event     = vila_akoya_section_field( 'event', null );
$event_url = '';
if ( $event instanceof WP_Post ) {
	$event_url = get_permalink( $event );
} elseif ( is_numeric( $event ) ) {
	$event_url = get_permalink( (int) $event );
}
?>
<aside class="vila-launch-banner" role="region" aria-label="<?php echo esc_attr( $heading ); ?>">
	<div class="vila-launch-banner__glow" aria-hidden="true"></div>
	<div class="vila-launch-banner__inner">
		<p class="vila-launch-banner__dates">
			<span class="vila-launch-banner__dates-num"><?php echo esc_html( $dates ); ?></span>
		</p>
		<div class="vila-launch-banner__copy">
			<?php if ( '' !== $eyebrow ) : ?>
				<p class="vila-launch-banner__eyebrow"><?php echo esc_html( $eyebrow ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $heading ) : ?>
				<h2 class="vila-launch-banner__heading"><?php echo esc_html( $heading ); ?></h2>
			<?php endif; ?>
			<?php if ( '' !== $body ) : ?>
				<p class="vila-launch-banner__body"><?php echo esc_html( $body ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $event_url ) : ?>
				<p class="vila-launch-banner__actions">
					<a class="vila-launch-banner__btn vila-launch-banner__btn--primary" href="<?php echo esc_url( $event_url ); ?>">
						<?php esc_html_e( 'More info', 'vila-akoya' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
	</div>
</aside>
