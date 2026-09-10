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
$body    = (string) vila_akoya_section_field(
	'body',
	'Musicians, DJs, workshops and experiences all week long — come stay, dance, make things, and help us open Vila Akoya.'
);

$event      = vila_akoya_section_field( 'event', null );
$event_post = null;
if ( $event instanceof WP_Post ) {
	$event_post = $event;
} elseif ( is_numeric( $event ) ) {
	$event_post = get_post( (int) $event );
}

$event_url  = $event_post instanceof WP_Post ? get_permalink( $event_post ) : '';
$has_poster = $event_post instanceof WP_Post && has_post_thumbnail( $event_post );
$poster_alt = $has_poster ? get_the_title( $event_post ) : '';
?>
<aside class="vila-launch-banner" role="region" aria-label="<?php echo esc_attr( $heading ); ?>">
	<div class="vila-launch-banner__inner<?php echo $has_poster ? ' vila-launch-banner__inner--has-poster' : ''; ?>">
		<?php if ( $has_poster ) : ?>
			<div class="vila-launch-banner__poster">
				<?php if ( '' !== $event_url ) : ?>
					<a class="vila-launch-banner__poster-link" href="<?php echo esc_url( $event_url ); ?>">
						<?php
						echo get_the_post_thumbnail(
							$event_post,
							'large',
							array(
								'class' => 'vila-launch-banner__poster-img',
								'alt'   => $poster_alt,
							)
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</a>
				<?php else : ?>
					<?php
					echo get_the_post_thumbnail(
						$event_post,
						'large',
						array(
							'class' => 'vila-launch-banner__poster-img',
							'alt'   => $poster_alt,
						)
					); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				<?php endif; ?>
			</div>
		<?php endif; ?>
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
