<?php
/**
 * Contact Form 7: thank-you redirect and wrapper classes.
 *
 * @package VilaAkoya
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wpcf7_form_class_attr',
	function ( $class ) {
		return trim( $class . ' vila-akoya-cf7' );
	}
);

add_action(
	'wp_footer',
	function () {
		if ( ! function_exists( 'wpcf7_contact_form' ) ) {
			return;
		}
		$url = esc_url( home_url( '/thank-you/' ) );
		?>
<script>
document.addEventListener('wpcf7mailsent', function () {
	window.location.href = <?php echo wp_json_encode( $url ); ?>;
}, false);
</script>
		<?php
	},
	50
);
