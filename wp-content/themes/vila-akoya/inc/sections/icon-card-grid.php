<?php
/**
 * Grid of icon + title + body cards (optional link per card).
 *
 * Variants (layout): default | contact | campus
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

$layout  = (string) vila_akoya_section_field( 'layout', 'default' );
$heading = (string) vila_akoya_section_field( 'heading', '' );
$intro   = (string) vila_akoya_section_field( 'intro', '' );
$cards   = array();

if ( function_exists( 'have_rows' ) && have_rows( 'cards' ) ) {
	while ( have_rows( 'cards' ) ) {
		the_row();
		$cards[] = array(
			'icon'    => (string) vila_akoya_section_field( 'icon', '' ),
			'heading' => (string) vila_akoya_section_field( 'heading', '' ),
			'body'    => (string) vila_akoya_section_field( 'body', '' ),
			'link'    => vila_akoya_section_field( 'link', false ),
			'variant' => (string) vila_akoya_section_field( 'variant', '' ),
		);
	}
}

if ( 'contact' === $layout ) :
	$shells = array(
		'email' => 'bg-surface-container-low p-12 rounded-lg flex flex-col justify-between hover:bg-surface-container transition-colors duration-500 min-h-[320px]',
		'whatsapp' => 'bg-secondary text-on-secondary p-12 rounded-lg flex flex-col justify-between min-h-[320px]',
		'phone' => 'bg-surface-container-highest p-12 rounded-lg flex flex-col justify-between min-h-[320px]',
	);
	$icon_shells = array(
		'email' => 'inline-flex w-12 h-12 bg-primary/10 rounded-full items-center justify-center',
		'whatsapp' => 'inline-flex w-12 h-12 bg-white/10 rounded-full items-center justify-center',
		'phone' => 'inline-flex w-12 h-12 bg-on-surface/5 rounded-full items-center justify-center',
	);
	$link_classes = array(
		'email' => 'text-primary text-2xl font-body font-semibold break-all',
		'whatsapp' => 'text-on-secondary text-2xl font-body font-semibold',
		'phone' => 'text-on-surface text-2xl font-body font-semibold',
	);
	?>
<section class="wp-block-group max-w-[1236px] mx-auto box-border px-8 mt-40 mb-0">
	<div class="wp-block-columns gap-6 flex flex-col md:flex-row w-full">
		<?php foreach ( $cards as $i => $card ) : ?>
			<?php
			$key = $card['variant'];
			if ( '' === $key ) {
				$key = array( 'email', 'whatsapp', 'phone' )[ $i ] ?? 'email';
			}
			$shell = $shells[ $key ] ?? $shells['email'];
			$icon_shell = $icon_shells[ $key ] ?? $icon_shells['email'];
			$link_class = $link_classes[ $key ] ?? $link_classes['email'];
			$body_class = 'whatsapp' === $key ? 'text-on-secondary/80 leading-relaxed' : 'text-on-surface-variant leading-relaxed';
			$icon_color = 'whatsapp' === $key ? 'text-on-secondary' : ( 'phone' === $key ? 'text-on-surface' : 'text-primary' );
			?>
			<div class="wp-block-column <?php echo esc_attr( $shell ); ?> w-full md:flex-1">
				<div class="wp-block-group">
					<p class="mb-8"><span class="<?php echo esc_attr( $icon_shell ); ?>"><span class="material-symbols-outlined <?php echo esc_attr( $icon_color ); ?>" aria-hidden="true"><?php echo esc_html( $card['icon'] ); ?></span></span></p>
					<?php if ( '' !== $card['heading'] ) : ?>
						<h3 class="wp-block-heading font-headline text-3xl mb-4"><?php echo esc_html( $card['heading'] ); ?></h3>
					<?php endif; ?>
					<?php if ( '' !== $card['body'] ) : ?>
						<p class="<?php echo esc_attr( $body_class ); ?>"><?php echo esc_html( $card['body'] ); ?></p>
					<?php endif; ?>
				</div>
				<?php if ( is_array( $card['link'] ) && ! empty( $card['link']['url'] ) ) : ?>
					<p class="mt-8 mb-0"><?php vila_akoya_section_link( $card['link'], (string) ( $card['link']['title'] ?? $card['heading'] ), $link_class ); ?></p>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
</section>
	<?php
	return;
endif;

if ( 'campus' === $layout ) :
	?>
<section class="wp-block-group py-24 bg-surface-container-low textured-bg mb-0">
	<div class="wp-block-group max-w-[1236px] mx-auto box-border px-6 md:px-12">
		<?php if ( '' !== $heading || '' !== $intro ) : ?>
			<header class="text-center max-w-3xl mx-auto mb-12 md:mb-16 space-y-4">
				<?php if ( '' !== $heading ) : ?>
					<h2 class="wp-block-heading font-headline text-4xl md:text-5xl text-on-surface"><?php echo esc_html( $heading ); ?></h2>
				<?php endif; ?>
				<?php if ( '' !== $intro ) : ?>
					<p class="font-headline italic text-xl md:text-2xl text-tertiary"><?php echo esc_html( $intro ); ?></p>
				<?php endif; ?>
			</header>
		<?php endif; ?>
		<div class="grid grid-cols-1 md:grid-cols-3 gap-8 md:gap-10">
			<?php foreach ( $cards as $card ) : ?>
				<div class="wp-block-group bg-surface rounded-2xl p-8 md:p-10 space-y-4 flex flex-col editorial-shadow">
					<?php if ( '' !== $card['icon'] ) : ?>
						<span class="material-symbols-outlined text-4xl text-primary" aria-hidden="true"><?php echo esc_html( $card['icon'] ); ?></span>
					<?php endif; ?>
					<?php if ( '' !== $card['heading'] ) : ?>
						<h3 class="wp-block-heading font-headline text-2xl md:text-3xl text-on-surface"><?php echo esc_html( $card['heading'] ); ?></h3>
					<?php endif; ?>
					<?php if ( '' !== $card['body'] ) : ?>
						<p class="font-body text-lg text-on-surface-variant leading-relaxed flex-1"><?php echo esc_html( $card['body'] ); ?></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>
	<?php
	return;
endif;
?>
<section class="wp-block-group max-w-[1236px] mx-auto box-border px-8 mb-32 md:mb-40">
	<?php if ( '' !== $heading || '' !== $intro ) : ?>
		<header class="text-center max-w-3xl mx-auto mb-12 md:mb-16 space-y-4">
			<?php if ( '' !== $heading ) : ?>
				<h2 class="wp-block-heading font-headline text-4xl md:text-5xl text-on-surface"><?php echo esc_html( $heading ); ?></h2>
			<?php endif; ?>
			<?php if ( '' !== $intro ) : ?>
				<p class="font-headline italic text-xl md:text-2xl text-tertiary"><?php echo esc_html( $intro ); ?></p>
			<?php endif; ?>
		</header>
	<?php endif; ?>
	<div class="grid grid-cols-1 md:grid-cols-3 gap-8 md:gap-10">
		<?php foreach ( $cards as $card ) : ?>
			<div class="wp-block-group bg-surface-container-low rounded-2xl p-8 md:p-10 space-y-4 flex flex-col">
				<?php if ( '' !== $card['icon'] ) : ?>
					<span class="material-symbols-outlined text-4xl text-primary" aria-hidden="true"><?php echo esc_html( $card['icon'] ); ?></span>
				<?php endif; ?>
				<?php if ( '' !== $card['heading'] ) : ?>
					<h3 class="wp-block-heading font-headline text-2xl md:text-3xl text-on-surface"><?php echo esc_html( $card['heading'] ); ?></h3>
				<?php endif; ?>
				<?php if ( '' !== $card['body'] ) : ?>
					<p class="font-body text-lg text-on-surface-variant leading-relaxed flex-1"><?php echo esc_html( $card['body'] ); ?></p>
				<?php endif; ?>
				<?php if ( is_array( $card['link'] ) && ! empty( $card['link']['url'] ) ) : ?>
					<p class="pt-2"><?php vila_akoya_section_link( $card['link'], 'Learn more', 'font-headline font-bold text-primary underline underline-offset-4' ); ?></p>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
</section>
