<?php
/**
 * Title: Home — Stay within the orbit (newsletter CTA)
 * Slug: vila-akoya/home-newsletter-orbit
 * Categories: vila-akoya, call-to-action
 * Keywords: newsletter, subscribe
 * Viewport Width: 1200
 *
 * @package vila-akoya
 */

$va_newsletter_next = home_url( '/thank-you/' );
$va_form_action     = 'https://formsubmit.co/Info@vila-akoya.com';
?>
<!-- wp:group {"align":"wide","className":"va-home-newsletter-wrap","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|80","left":"var:preset|spacing|60","right":"var:preset|spacing|60"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide va-home-newsletter-wrap" style="padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--60)">
	<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|90","bottom":"var:preset|spacing|90","left":"var:preset|spacing|70","right":"var:preset|spacing|70"}},"color":{"background":"var:preset|color|primary"},"border":{"radius":"3rem"}},"className":"va-home-newsletter-panel va-editorial-shadow"} -->
	<div class="wp-block-group va-home-newsletter-panel va-editorial-shadow has-primary-background-color has-background" style="border-radius:3rem;padding-top:var(--wp--preset--spacing--90);padding-right:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--90);padding-left:var(--wp--preset--spacing--70);box-shadow:var(--wp--preset--shadow--editorial)">
		<!-- wp:heading {"level":2,"style":{"typography":{"fontFamily":"var:preset|font-family|barrio","fontSize":"clamp(2.5rem,6vw,4.5rem)","lineHeight":"1.05"}},"textColor":"on-primary"} -->
		<h2 class="wp-block-heading has-on-primary-color has-text-color" style="font-family:var(--wp--preset--font-family--barrio);font-size:clamp(2.5rem,6vw,4.5rem);line-height:1.05">Stay within <br />the orbit.</h2>
		<!-- /wp:heading -->
		<!-- wp:paragraph {"style":{"typography":{"fontSize":"var:preset|font-size|large","fontWeight":"300","lineHeight":"1.6"}},"textColor":"on-primary"} -->
		<p class="has-on-primary-color has-text-color" style="font-size:var(--wp--preset--font-size--large);font-weight:300;line-height:1.6">Join our circle for seasonal updates, workshop announcements, and stories from the land. No noise, just soul.</p>
		<!-- /wp:paragraph -->
		<!-- wp:html -->
		<form class="va-newsletter-form" action="<?php echo esc_url( $va_form_action ); ?>" method="post">
			<input type="hidden" name="_subject" value="Vila Akoya — Home newsletter signup" />
			<input type="hidden" name="_captcha" value="false" />
			<input type="hidden" name="_replyto" value="email" />
			<input type="hidden" name="_template" value="table" />
			<input type="hidden" name="_next" value="<?php echo esc_attr( esc_url( $va_newsletter_next ) ); ?>" />
			<input type="hidden" name="messaging_from_page" value="" />
			<input type="text" name="_gotcha" class="va-newsletter-gotcha" tabindex="-1" autocomplete="off" aria-hidden="true" />
			<input type="hidden" name="message" value="Please add this email address to the mailing list. Signup: Home page newsletter." />
			<div class="va-newsletter-form__row">
				<label class="screen-reader-text" for="va-home-newsletter-email">Email for updates</label>
				<input id="va-home-newsletter-email" name="email" type="email" required autocomplete="email" class="va-newsletter-form__input" placeholder="your@email.com" />
				<button type="submit" class="va-newsletter-form__submit">Subscribe</button>
			</div>
		</form>
		<!-- /wp:html -->
		<!-- wp:paragraph {"style":{"typography":{"fontSize":"var:preset|font-size|small"}},"textColor":"on-primary"} -->
		<p class="has-on-primary-color has-text-color" style="font-size:var(--wp--preset--font-size--small)"><a href="/contact/">Prefer to talk? Visit our contact page</a></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
