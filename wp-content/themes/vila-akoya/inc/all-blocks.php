<?php
/**
 * Default block markup for seeded pages (edit in wp-admin).
 *
 * @package VilaAkoya
 */

defined( 'ABSPATH' ) || exit;

require_once get_template_directory() . '/inc/block-helpers.php';

/**
 * All page contents keyed by slug.
 *
 * @param array<string,int> $cf7 CF7 form IDs.
 * @return array<string,string>
 */
function vila_akoya_get_seeded_page_contents( $cf7 ) {
	$h = isset( $cf7['newsletter'] ) ? (int) $cf7['newsletter'] : 0;
	$v = isset( $cf7['village_waitlist'] ) ? (int) $cf7['village_waitlist'] : 0;
	$c = isset( $cf7['campus_host'] ) ? (int) $cf7['campus_host'] : 0;
	$b = isset( $cf7['booking'] ) ? (int) $cf7['booking'] : 0;
	$x = isset( $cf7['contact'] ) ? (int) $cf7['contact'] : 0;

	return array(
		'home'       => vila_akoya_blocks_home( $h ),
		'about'      => vila_akoya_blocks_about(),
		'village'    => vila_akoya_blocks_village( $v ),
		'campsite'   => vila_akoya_blocks_campsite(),
		'booking'    => vila_akoya_blocks_booking( $b ),
		'campus'     => vila_akoya_blocks_campus( $c ),
		'the-kids'   => vila_akoya_blocks_the_kids(),
		'contact'    => vila_akoya_blocks_contact( $x ),
		'thank-you'  => vila_akoya_blocks_thank_you(),
	);
}

/**
 * Home page blocks.
 *
 * @param int $cf7_newsletter Form ID.
 * @return string
 */
function vila_akoya_blocks_home( $cf7_newsletter ) {
	$u = esc_url( home_url( '/' ) );
	$hero = 'https://images.unsplash.com/photo-1500382017468-9049fed747ef?w=1600&q=80';
	$sc = vila_akoya_block_cf7( $cf7_newsletter );
	$w_joyaa = 'https://images.unsplash.com/photo-1503454537195-1dcabb73ffb9?w=800';
	$w_hans  = 'https://images.unsplash.com/photo-1511895426328-dc8714191300?w=800';
	$w_sammy = 'https://images.unsplash.com/photo-1544005313-94ddf0286df2?w=800';
	$w_akh   = 'https://images.unsplash.com/photo-1519689680058-324335c77eba?w=800';
	$img_village_tout  = 'https://images.unsplash.com/photo-1449158743715-0a90ebb6d2d8?w=1200&q=80';
	$img_campsite_tout = 'https://images.unsplash.com/photo-1504280396297-861d1b38fe59?w=1200&q=80';
	$img_deep          = 'https://images.unsplash.com/photo-1464226184884-fa280b87c399?w=900&q=80';
	return <<<BLOCK
<!-- wp:group {"className":"relative w-full p-4 md:p-8 mb-24","layout":{"type":"constrained"}} -->
<div class="wp-block-group relative w-full p-4 md:p-8 mb-24">
<!-- wp:group {"className":"vila-hero-shell relative w-full overflow-hidden editorial-shadow","layout":{"type":"default"}} -->
<div class="wp-block-group vila-hero-shell relative w-full overflow-hidden editorial-shadow">
<!-- wp:group {"className":"vila-hero-shell__media","layout":{"type":"default"}} -->
<div class="wp-block-group vila-hero-shell__media">
<!-- wp:image {"sizeSlug":"full","linkDestination":"none","className":"brightness-[0.85]"} -->
<figure class="wp-block-image size-full brightness-[0.85]"><img src="$hero" alt=""/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:group -->
<!-- wp:group {"className":"vila-hero-shell__overlay relative flex flex-col justify-end bg-gradient-to-b from-surface-container/95 to-surface-container p-8 md:bg-gradient-to-t md:from-on-surface/40 md:to-transparent md:p-20","layout":{"type":"constrained"}} -->
<div class="wp-block-group vila-hero-shell__overlay relative flex flex-col justify-end bg-gradient-to-b from-surface-container/95 to-surface-container p-8 md:bg-gradient-to-t md:from-on-surface/40 md:to-transparent md:p-20">
<!-- wp:group {"className":"max-w-4xl space-y-6 md:text-on-primary","layout":{"type":"default"}} -->
<div class="wp-block-group max-w-4xl space-y-6 md:text-on-primary">
<!-- wp:heading {"level":1,"className":"text-5xl md:text-8xl font-barrio text-on-surface md:text-on-primary leading-tight"} -->
<h1 class="text-5xl md:text-8xl font-barrio text-on-surface md:text-on-primary leading-tight">The little village of our dreams</h1>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-lg md:text-2xl text-on-surface-variant md:text-on-primary/90 font-light max-w-2xl leading-relaxed"} -->
<p class="text-lg md:text-2xl text-on-surface-variant md:text-on-primary/90 font-light max-w-2xl leading-relaxed">A tiny house village, glamping and creative community bordering the river Mira in the south of Alentejo. An extraordinary place of creation, relaxation and inspiration that we get to share with our friends and friends disguised as strangers.</p>
<!-- /wp:paragraph -->
<!-- wp:buttons {"className":"flex flex-wrap gap-4 pt-4 is-layout-flex"} -->
<div class="wp-block-buttons flex flex-wrap gap-4 pt-4 is-layout-flex">
<!-- wp:button {"className":"px-8 py-4 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-xl font-medium text-lg"} -->
<div class="wp-block-button px-8 py-4 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-xl font-medium text-lg"><a class="wp-block-button__link wp-element-button" href="{$u}booking/">Book your stay</a></div>
<!-- /wp:button -->
<!-- wp:button {"className":"px-8 py-4 bg-primary/10 border border-primary/20 text-primary md:bg-surface-bright/20 md:border-white/20 md:text-on-primary rounded-xl font-medium text-lg is-style-outline"} -->
<div class="wp-block-button px-8 py-4 bg-primary/10 border border-primary/20 text-primary md:bg-surface-bright/20 md:border-white/20 md:text-on-primary rounded-xl font-medium text-lg is-style-outline"><a class="wp-block-button__link wp-element-button" href="{$u}campus/">Tell me more!</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->

<!-- wp:group {"className":"mb-32","layout":{"type":"constrained"}} -->
<div class="wp-block-group mb-32">
<!-- wp:columns {"className":"vila-home-dual","style":{"spacing":{"blockGap":"0"}}} -->
<div class="wp-block-columns vila-home-dual">
<!-- wp:column {"className":"relative flex flex-col items-center p-12 md:p-24 bg-surface-container-low"} -->
<div class="wp-block-column relative flex flex-col items-center p-12 md:p-24 bg-surface-container-low">
<!-- wp:paragraph {"className":"text-tertiary text-2xl font-headline text-center"} -->
<p class="text-tertiary text-2xl font-headline text-center">A place to stay</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"level":2,"className":"text-6xl md:text-7xl font-barrio text-on-surface text-center narrow-header"} -->
<h2 class="text-6xl md:text-7xl font-barrio text-on-surface text-center narrow-header">The Village</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-xl font-light text-on-surface-variant italic text-center max-w-md mx-auto"} -->
<p class="text-xl font-light text-on-surface-variant italic text-center max-w-md mx-auto">Unique tiny homes in all shapes and forms. A feast for the eye and a home for the kind. This is the little village we've always been hoping to find.</p>
<!-- /wp:paragraph -->
<!-- wp:group {"className":"vila-crop-tout w-full grayscale-[0.2] hover:grayscale-0 transition-all","layout":{"type":"constrained"}} -->
<div class="wp-block-group vila-crop-tout w-full grayscale-[0.2] hover:grayscale-0 transition-all">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img src="$img_village_tout" alt=""/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:group -->
<!-- wp:paragraph {"className":"text-center pt-6"} -->
<p class="text-center pt-6"><a class="font-headline font-bold text-lg text-tertiary-dim underline decoration-tertiary/40 underline-offset-8" href="{$u}village/">Looking for a village?</a></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column {"className":"relative flex flex-col justify-center items-center p-12 md:p-24 bg-secondary-container/30"} -->
<div class="wp-block-column relative flex flex-col justify-center items-center p-12 md:p-24 bg-secondary-container/30">
<!-- wp:paragraph {"className":"text-secondary text-2xl font-headline text-center"} -->
<p class="text-secondary text-2xl font-headline text-center">...or to visit</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"level":2,"className":"text-6xl md:text-7xl font-barrio text-on-surface text-center narrow-header"} -->
<h2 class="text-6xl md:text-7xl font-barrio text-on-surface text-center narrow-header">The Campsite</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-xl font-light text-on-surface-variant italic text-center max-w-md mx-auto"} -->
<p class="text-xl font-light text-on-surface-variant italic text-center max-w-md mx-auto">Want to dip your toe in our home-away-from home? Swing by, join our table, have a swim, build a treehouse, make a friend… and leave forever changed.</p>
<!-- /wp:paragraph -->
<!-- wp:group {"className":"vila-crop-tout w-full hover:scale-[1.02] transition-transform","layout":{"type":"constrained"}} -->
<div class="wp-block-group vila-crop-tout w-full hover:scale-[1.02] transition-transform">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img src="$img_campsite_tout" alt=""/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:group -->
<!-- wp:paragraph {"className":"text-center pt-6"} -->
<p class="text-center pt-6"><a class="font-headline font-bold text-lg text-secondary-dim underline decoration-secondary/40 underline-offset-8" href="{$u}campsite/">Book your dates</a></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->

<!-- wp:group {"className":"max-w-screen-xl mx-auto px-8 mb-32","layout":{"type":"constrained"}} -->
<div class="wp-block-group max-w-screen-xl mx-auto px-8 mb-32">
<!-- wp:columns -->
<div class="wp-block-columns">
<!-- wp:column {"width":"41.67%"} -->
<div class="wp-block-column" style="flex-basis:41.67%">
<!-- wp:group {"className":"vila-crop-editorial relative z-10 rotate-[-2deg]","layout":{"type":"constrained"}} -->
<div class="wp-block-group vila-crop-editorial relative z-10 rotate-[-2deg]">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img src="$img_deep" alt=""/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->
<!-- wp:column {"width":"58.33%"} -->
<div class="wp-block-column" style="flex-basis:58.33%">
<!-- wp:heading {"level":3,"className":"text-4xl md:text-6xl font-headline text-on-surface font-light leading-tight"} -->
<h3 class="text-4xl md:text-6xl font-headline text-on-surface font-light leading-tight">That little village <br><span class="text-primary italic">we've been looking for</span></h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-xl font-light text-on-surface-variant leading-relaxed"} -->
<p class="text-xl font-light text-on-surface-variant leading-relaxed">We wanted to live in the forest, and raise our kids amongst the trees. But we did not want to do it alone. Frankly, we're done with doing everything alone! Who came up with that? Lucky for us, there are loads more people like us. So we got a bunch of fantastic humans together and set out to build the little village of our dreams.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"text-xl font-light text-on-surface-variant leading-relaxed"} -->
<p class="text-xl font-light text-on-surface-variant leading-relaxed">Expect to find something fantastic in every corner of this space. With playgrounds for children and for grown-ups, areas for wellness and relaxation, spaces designed for connection, creation and celebration, and a full agenda of workshops, classes and activities for humans of all ages.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"pt-4"} -->
<p class="pt-4"><a class="group flex items-center gap-4 text-primary font-bold text-xl" href="{$u}campus/">Learn more about the activities for adults and kids</a></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->

<!-- wp:group {"className":"relative mb-32 overflow-x-hidden bg-gradient-to-b from-[#ebe4d4] via-[#e8dcc8] to-[#e5d6c4] text-[#3d2a22]","layout":{"type":"constrained"}} -->
<div class="wp-block-group relative mb-32 overflow-x-hidden bg-gradient-to-b from-[#ebe4d4] via-[#e8dcc8] to-[#e5d6c4] text-[#3d2a22]">
<!-- wp:group {"className":"relative max-w-screen-xl mx-auto px-8 py-20 md:py-28","layout":{"type":"constrained"}} -->
<div class="wp-block-group relative max-w-screen-xl mx-auto px-8 py-20 md:py-28">
<!-- wp:heading {"level":2,"className":"font-barrio text-5xl md:text-7xl text-on-surface text-center"} -->
<h2 class="font-barrio text-5xl md:text-7xl text-on-surface text-center">Who are we?</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"font-headline text-tertiary text-xl md:text-2xl tracking-wide text-center"} -->
<p class="font-headline text-tertiary text-xl md:text-2xl tracking-wide text-center">The family behind Vila Akoya</p>
<!-- /wp:paragraph -->

<!-- wp:columns {"className":"gap-12 md:gap-16 items-center mb-20 md:mb-28"} -->
<div class="wp-block-columns gap-12 md:gap-16 items-center mb-20 md:mb-28">
<!-- wp:column {"className":"order-2 md:order-1"} -->
<div class="wp-block-column order-2 md:order-1">
<!-- wp:heading {"level":3,"className":"font-barrio text-3xl md:text-4xl text-on-surface"} -->
<h3 class="font-barrio text-3xl md:text-4xl text-on-surface">Joyaa</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-lg md:text-xl font-light text-on-surface-variant"} -->
<p class="text-lg md:text-xl font-light text-on-surface-variant">It takes a village to raise a child, and for us it took a child to build a village. Vila Akoya wouldn't be anything without our daughter Joyaa. It's with her in mind that we envision the village in which we want to raise her; a green and inspiring place with a vibrant community where she — together with her little brother — will get to grow, learn and adventure.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"text-lg md:text-xl font-light text-on-surface-variant"} -->
<p class="text-lg md:text-xl font-light text-on-surface-variant">Joyaa is 10 years old now. She's creative, funny, kind, smart and a great friend to have… and did I mention she's creative?</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"text-lg md:text-xl font-light text-on-surface-variant"} -->
<p class="text-lg md:text-xl font-light text-on-surface-variant">Joyaa spends her days building castles for beetles with leaves and sticks that she finds, while singing songs about the things that she sees on her way, and what she thinks about them. She loves reading, making music and climbing trees; she carries a big smile and an even bigger heart. You'll feel it when you meet her — this little lady is someone special.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column {"className":"order-1 md:order-2 flex justify-center"} -->
<div class="wp-block-column order-1 md:order-2 flex justify-center">
<!-- wp:group {"className":"who-we-portrait w-full max-w-md mx-auto"} -->
<div class="wp-block-group who-we-portrait w-full max-w-md mx-auto">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img src="$w_joyaa" alt="Joyaa"/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->

<!-- wp:columns {"className":"gap-12 md:gap-16 items-center mb-20 md:mb-28"} -->
<div class="wp-block-columns gap-12 md:gap-16 items-center mb-20 md:mb-28">
<!-- wp:column {"className":"flex justify-center"} -->
<div class="wp-block-column flex justify-center">
<!-- wp:group {"className":"who-we-portrait who-we-portrait--blob-2 w-full max-w-md mx-auto"} -->
<div class="wp-block-group who-we-portrait who-we-portrait--blob-2 w-full max-w-md mx-auto">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img src="$w_hans" alt="Hans with family"/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":3,"className":"font-barrio text-3xl md:text-4xl text-on-surface"} -->
<h3 class="font-barrio text-3xl md:text-4xl text-on-surface">Hans</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-lg md:text-xl font-light text-on-surface-variant"} -->
<p class="text-lg md:text-xl font-light text-on-surface-variant">Hans is the <em class="text-on-surface">brains</em> and the <em class="text-on-surface">muscles</em> behind Vila Akoya. He knows and learns about the possibles and impossibles surrounding our projects. He always finds answers to the difficult questions, and he does so by tirelessly reading through piles of documents, underlining all that's relevant and questioning all that can be questioned, then puts on his gloves and starts lifting whatever heavy needs lifting before brushing off his shirt to sit patiently with Joyaa working on her homework, or playing tirelessly with little Akhoda.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"text-lg md:text-xl font-light text-on-surface-variant"} -->
<p class="text-lg md:text-xl font-light text-on-surface-variant">Hans has spent the last 8 years working in real estate. Before that he was a national champion fencer and fencing coach in Canada, ran an organic farm, holds a degree in Cognitive Science and Philosophy and studied and taught an Indian martial art called Kalaripayattu — pretty impressive, right?</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->

<!-- wp:columns {"className":"gap-12 md:gap-16 items-center mb-20 md:mb-28"} -->
<div class="wp-block-columns gap-12 md:gap-16 items-center mb-20 md:mb-28">
<!-- wp:column {"className":"order-2 md:order-1"} -->
<div class="wp-block-column order-2 md:order-1">
<!-- wp:heading {"level":3,"className":"font-barrio text-3xl md:text-4xl text-on-surface"} -->
<h3 class="font-barrio text-3xl md:text-4xl text-on-surface">Sammy</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-lg md:text-xl font-light text-on-surface-variant"} -->
<p class="text-lg md:text-xl font-light text-on-surface-variant">My name is Sammy. I am Joyaa and Akhoda's mother, Hans's partner and the person you've most likely been speaking to along the way.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"text-lg md:text-xl font-light text-on-surface-variant"} -->
<p class="text-lg md:text-xl font-light text-on-surface-variant">Besides a mother and partner, I am many other things. I have an entrepreneurial mind and a heart for sustainability; I founded and run sustainable clothing and baby brands, and just like Joyaa I am very creative, love making music and like to work with my hands.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"text-lg md:text-xl font-light text-on-surface-variant"} -->
<p class="text-lg md:text-xl font-light text-on-surface-variant">I spent most of my adult life travelling the world, had Joyaa along the way, and about 6 years ago Hans joined us — or we him! Together we decided to settle down in Portugal to build our dream: Vila Akoya.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"text-lg md:text-xl font-light text-on-surface-variant"} -->
<p class="text-lg md:text-xl font-light text-on-surface-variant">Hans said I should call myself the heart and soul of the project, and I will do so with a humble pride. I have a clear vision of the village we wish to build for our children and ourselves. A vision that I am grateful to get to share with many.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"text-lg md:text-xl font-light text-on-surface-variant"} -->
<p class="text-lg md:text-xl font-light text-on-surface-variant">Within Vila Akoya I focus on the commercial aspects of the project, on being in touch with the people interested in joining us, designing the spaces, finding and building relationships with suppliers, partners and builders, building the community and helping tell the story of this place.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column {"className":"order-1 md:order-2 flex justify-center"} -->
<div class="wp-block-column order-1 md:order-2 flex justify-center">
<!-- wp:group {"className":"who-we-portrait who-we-portrait--blob-3 w-full max-w-md mx-auto"} -->
<div class="wp-block-group who-we-portrait who-we-portrait--blob-3 w-full max-w-md mx-auto">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img src="$w_sammy" alt="Sammy and Joyaa"/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->

<!-- wp:columns {"className":"gap-12 md:gap-16 items-center mb-16 md:mb-24"} -->
<div class="wp-block-columns gap-12 md:gap-16 items-center mb-16 md:mb-24">
<!-- wp:column {"className":"flex justify-center"} -->
<div class="wp-block-column flex justify-center">
<!-- wp:group {"className":"who-we-portrait who-we-portrait--blob-4 w-full max-w-md mx-auto"} -->
<div class="wp-block-group who-we-portrait who-we-portrait--blob-4 w-full max-w-md mx-auto">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img src="$w_akh" alt="Akhoda"/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":3,"className":"font-barrio text-3xl md:text-4xl text-on-surface"} -->
<h3 class="font-barrio text-3xl md:text-4xl text-on-surface">Little Akhoda</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-lg md:text-xl font-light text-on-surface-variant"} -->
<p class="text-lg md:text-xl font-light text-on-surface-variant">In summer 2024 this little guy made our family complete. Akhoda is such a wonderful little person; he is strong but kind, he loves his family and the forest and meets plants and creatures with gentle hands and great curiosity.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"text-lg md:text-xl font-light text-on-surface-variant"} -->
<p class="text-lg md:text-xl font-light text-on-surface-variant">For us he is a big inspiration to want to be our best selves — so that when he looks his big eyes up at us, he sees good people who work hard doing something beautiful and meaningful in the world. This project is exactly that, for us.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->

<!-- wp:paragraph {"className":"text-center mt-12"} -->
<p class="text-center mt-12"><a class="inline-flex items-center gap-2 text-primary font-bold text-lg hover:underline underline-offset-4" href="{$u}about/">How we got here</a></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->

<!-- wp:group {"className":"max-w-screen-xl mx-auto px-8 mb-32 vila-akoya-newsletter-form","layout":{"type":"constrained"}} -->
<div class="wp-block-group max-w-screen-xl mx-auto px-8 mb-32 vila-akoya-newsletter-form">
<!-- wp:group {"className":"flex flex-col md:flex-row rounded-[3rem] overflow-hidden md:min-h-[min(28rem,70vh)] editorial-shadow"} -->
<div class="wp-block-group flex flex-col md:flex-row rounded-[3rem] overflow-hidden md:min-h-[min(28rem,70vh)] editorial-shadow">
<!-- wp:group {"className":"bg-primary text-on-primary w-full md:w-2/3 p-12 md:p-16 flex flex-col justify-center vila-akoya-cf7--on-primary"} -->
<div class="wp-block-group bg-primary text-on-primary w-full md:w-2/3 p-12 md:p-16 flex flex-col justify-center vila-akoya-cf7--on-primary">
<!-- wp:heading {"level":2,"className":"text-5xl md:text-7xl font-headline leading-tight"} -->
<h2 class="text-5xl md:text-7xl font-headline leading-tight">Join our digi-family</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-xl opacity-90 leading-relaxed font-light"} -->
<p class="text-xl opacity-90 leading-relaxed font-light">Want to stay in the loop about what's happening on our little slice of paradise? Enter your email here, and we will try not to spam you too much with the things we are excited to share.</p>
<!-- /wp:paragraph -->
$sc
<!-- wp:paragraph {"className":"text-sm opacity-80"} -->
<p class="text-sm opacity-80"><a class="underline hover:opacity-100" href="{$u}contact/">Want to get in touch? Find our contact info here</a></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
<!-- wp:group {"className":"relative hidden md:block md:w-1/3 md:min-h-[min(20rem,50vh)] bg-primary overflow-hidden"} -->
<div class="wp-block-group relative hidden md:block md:w-1/3 md:min-h-[min(20rem,50vh)] bg-primary overflow-hidden">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"absolute inset-0 w-full h-full object-cover opacity-[0.4]"} -->
<figure class="wp-block-image size-large absolute inset-0 w-full h-full object-cover opacity-[0.4]"><img src="https://images.unsplash.com/photo-1506905925346-21bda4d32df4?w=800" alt=""/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->
BLOCK;
}

/**
 * About page.
 *
 * @return string
 */
function vila_akoya_blocks_about() {
	$u = esc_url( home_url( '/' ) );
	return <<<BLOCK
<!-- wp:group {"className":"min-h-[calc(100vh-6rem)] flex flex-col justify-center max-w-screen-2xl mx-auto px-8 py-12 md:py-0 mb-32","layout":{"type":"constrained"}} -->
<div class="wp-block-group min-h-[calc(100vh-6rem)] flex flex-col justify-center max-w-screen-2xl mx-auto px-8 py-12 md:py-0 mb-32">
<!-- wp:columns -->
<div class="wp-block-columns">
<!-- wp:column {"width":"58.33%"} -->
<div class="wp-block-column" style="flex-basis:58.33%">
<!-- wp:paragraph {"className":"font-label text-primary uppercase tracking-[0.2em] text-sm font-bold mb-4"} -->
<p class="font-label text-primary uppercase tracking-[0.2em] text-sm font-bold mb-4">Our Story</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"level":1,"className":"font-headline text-6xl md:text-8xl text-on-surface leading-[1.1] mb-8"} -->
<h1 class="font-headline text-6xl md:text-8xl text-on-surface leading-[1.1] mb-8">Rooted in Passion, Built for the Future.</h1>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"font-body text-xl text-on-surface-variant max-w-xl leading-relaxed"} -->
<p class="font-body text-xl text-on-surface-variant max-w-xl leading-relaxed">A tiny house village where creativity meets the earth, born from a dream to create a playground for our children and a sanctuary for the community.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column {"width":"41.67%"} -->
<div class="wp-block-column" style="flex-basis:41.67%">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"rounded-xl overflow-hidden aspect-[4/5] shadow-2xl"} -->
<figure class="wp-block-image size-large rounded-xl overflow-hidden aspect-[4/5] shadow-2xl"><img src="https://lh3.googleusercontent.com/aida-public/AB6AXuBlUgYMyX1OvCEoPwQQlJnZdEI1RO7hI9ie7ZzlV9-LKqly81fdreXJoFvr3Yf-Ix_S_0K4GD9e23_kZYulsXoCw5_BxE1kQ0jhGFA_p-WlKNUvGnqk0rjvdZb3xCXocPGI0rZF_wPaiP6jAiaoSN_EAzmEO5_ngBRUMc4oV696A8-Vp3UVi08UaBtgUuwi4KOaWOBzNg3R5z3Hw_H4vXmBPypkHShmsexIWwQQWvViplLkHZLihjnVx0vb9Rqzb1v6IgBD9Lhs5jgR" alt="Founders in Alentejo"/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->

<!-- wp:group {"className":"bg-surface-container-low py-32 rounded-xl mx-8 mb-32","layout":{"type":"constrained"}} -->
<div class="wp-block-group bg-surface-container-low py-32 rounded-xl mx-8 mb-32">
<!-- wp:heading {"level":2,"className":"font-headline text-5xl text-on-surface mb-8 text-center"} -->
<h2 class="font-headline text-5xl text-on-surface mb-8 text-center">What is Vila Akoya?</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"font-body text-lg text-on-surface-variant leading-relaxed max-w-3xl mx-auto text-center"} -->
<p class="font-body text-lg text-on-surface-variant leading-relaxed max-w-3xl mx-auto text-center">Vila Akoya is more than a destination; it is a creative ecosystem. Conceived as a tiny house village, it represents a shift toward intentional living—where the architecture is as compact as the possibilities are vast.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->

<!-- wp:group {"className":"max-w-4xl mx-auto px-8 text-center py-20","layout":{"type":"constrained"}} -->
<div class="wp-block-group max-w-4xl mx-auto px-8 text-center py-20">
<!-- wp:heading {"level":2,"className":"font-headline text-5xl text-on-surface mb-8"} -->
<h2 class="font-headline text-5xl text-on-surface mb-8">A Vision of Shared Space</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"font-body text-xl text-on-surface-variant leading-relaxed"} -->
<p class="font-body text-xl text-on-surface-variant leading-relaxed">We believe that the future is collaborative. Vila Akoya was built for our children to grow up in a village—a real village where neighbors are friends and the forest is the classroom.</p>
<!-- /wp:paragraph -->
<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"},"className":"mt-12 flex flex-wrap justify-center gap-6"} -->
<div class="wp-block-buttons mt-12 flex flex-wrap justify-center gap-6">
<!-- wp:button {"className":"bg-primary text-on-primary px-10 py-4 rounded-xl"} -->
<div class="wp-block-button bg-primary text-on-primary px-10 py-4 rounded-xl"><a class="wp-block-button__link wp-element-button" href="{$u}contact/">Join the Community</a></div>
<!-- /wp:button -->
<!-- wp:button {"className":"bg-surface-container-high text-on-surface px-10 py-4 rounded-xl is-style-outline"} -->
<div class="wp-block-button bg-surface-container-high text-on-surface px-10 py-4 rounded-xl is-style-outline"><a class="wp-block-button__link wp-element-button" href="{$u}campus/">Visit the Campus</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
</div>
<!-- /wp:group -->
BLOCK;
}

/**
 * Booking page.
 *
 * @param int $cf7 Form ID.
 * @return string
 */
function vila_akoya_blocks_booking( $cf7 ) {
	$u = esc_url( home_url( '/' ) );
	$sc = vila_akoya_block_cf7( $cf7 );
	return <<<BLOCK
<!-- wp:group {"className":"max-w-screen-xl mx-auto pt-24 pb-20 px-6","layout":{"type":"constrained"}} -->
<div class="wp-block-group max-w-screen-xl mx-auto pt-24 pb-20 px-6">
<!-- wp:columns -->
<div class="wp-block-columns">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:paragraph {"className":"text-primary font-label uppercase tracking-[0.2em] text-xs mb-4"} -->
<p class="text-primary font-label uppercase tracking-[0.2em] text-xs mb-4">Begin Your Journey</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"level":1,"className":"font-headline text-5xl md:text-7xl text-on-background leading-[1.1] mb-8"} -->
<h1 class="font-headline text-5xl md:text-7xl text-on-background leading-[1.1] mb-8">An Invitation to <br><span class="font-serif-italic">Slow Living</span></h1>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"font-body text-lg md:text-xl text-on-surface-variant max-w-xl leading-relaxed"} -->
<p class="font-body text-lg md:text-xl text-on-surface-variant max-w-xl leading-relaxed">Whether you're seeking a permanent plot in our village or a soul-recharging stay at our campsite, we welcome your curiosity.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"rounded-xl overflow-hidden shadow-2xl"} -->
<figure class="wp-block-image size-large rounded-xl overflow-hidden shadow-2xl"><img src="https://lh3.googleusercontent.com/aida-public/AB6AXuB0YO7RwzDaUBYXKWatnP-c3s1YNuEUYt9nNLqgcJUySFbisQ2mXzUAT24CCyVBwx9DoUq4rSTBoXh_rs86cenlRIxZ4kH-iF9V7HFXY3Z5UU19DlS_5Dhi1KZ1wrTKDiZZnwPWQ0fGDkZOAycv95LFi8ZMTDFQhulSQw5Vy291KYk80nSgOQ_pFPH_dUh9OShoPFYq53tA6pQpmpbrK0Mb_BLqqSGrPC_IxJTfNW6AwVOeSJlSWwna2x4Cgyc0QNxyPvVBWTB4Dy3v" alt=""/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->

<!-- wp:columns {"className":"grid grid-cols-1 lg:grid-cols-2 gap-20 mt-16"} -->
<div class="wp-block-columns grid grid-cols-1 lg:grid-cols-2 gap-20 mt-16">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":2,"className":"font-headline text-3xl text-primary"} -->
<h2 class="font-headline text-3xl text-primary">Inquiry Details</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-on-surface-variant leading-relaxed"} -->
<p class="text-on-surface-variant leading-relaxed">We are building more than just homes; we are cultivating a community centered on creativity and nature. Share your vision with us.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column {"className":"bg-white p-8 md:p-12 rounded-xl editorial-shadow"} -->
<div class="wp-block-column bg-white p-8 md:p-12 rounded-xl editorial-shadow">
$sc
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->
BLOCK;
}

/**
 * Contact page.
 *
 * @param int $cf7 Form ID.
 * @return string
 */
function vila_akoya_blocks_contact( $cf7 ) {
	$sc = vila_akoya_block_cf7( $cf7 );
	return <<<BLOCK
<!-- wp:group {"className":"max-w-screen-2xl mx-auto px-8 grid grid-cols-1 lg:grid-cols-12 gap-12 items-center min-h-[calc(100vh-6rem)]","layout":{"type":"constrained"}} -->
<div class="wp-block-group max-w-screen-2xl mx-auto px-8 grid grid-cols-1 lg:grid-cols-12 gap-12 items-center min-h-[calc(100vh-6rem)]">
<!-- wp:group {"className":"lg:col-span-5 z-10"} -->
<div class="wp-block-group lg:col-span-5 z-10">
<!-- wp:paragraph {"className":"font-label text-primary tracking-[0.2em] uppercase text-sm mb-4"} -->
<p class="font-label text-primary tracking-[0.2em] uppercase text-sm mb-4">Get in Touch</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"level":1,"className":"font-headline text-6xl md:text-8xl text-on-surface leading-tight mb-8"} -->
<h1 class="font-headline text-6xl md:text-8xl text-on-surface leading-tight mb-8">Let's start <br><span class="text-primary font-headline">your journey.</span></h1>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"font-body text-xl text-on-surface-variant max-w-md leading-relaxed mb-12"} -->
<p class="font-body text-xl text-on-surface-variant max-w-md leading-relaxed mb-12">Vila Akoya is a labor of love tucked into the heart of the Alentejo. We'd love to welcome you to our community.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
<!-- wp:group {"className":"lg:col-span-7"} -->
<div class="wp-block-group lg:col-span-7">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"rounded-xl w-full h-[400px] object-cover"} -->
<figure class="wp-block-image size-large rounded-xl w-full h-[400px] object-cover"><img src="https://lh3.googleusercontent.com/aida-public/AB6AXuBzANp2j1ZfB_2xUj0I_XqB7NY5G0Xa3o_fTxRO6kCDU9xotu1tVM-k54s3yjn6EFozvNNkfupJCCdW9KzhL2erg23hOVvyL0K9TDMR3MpNx-SVM8OXtw8J3ZNtftD7cDYYrV3iKOcKTGPs7MYqd2z-CRaDZrICte1Aix-9sgp4la8NFN13tcbORWiADgolM0MbbkO3I9CTvYpyFpR7hyWLTZe5IviVCN_7CIlAA9wnd9M4rd_CSc0kS6PgLpfFLgnUmvYAznP30MRIb" alt="Alentejo fields"/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->

<!-- wp:group {"className":"max-w-screen-2xl mx-auto px-8 mt-24 mb-4","layout":{"type":"constrained"}} -->
<div class="wp-block-group max-w-screen-2xl mx-auto px-8 mt-24 mb-4">
<!-- wp:group {"className":"max-w-2xl mx-auto bg-surface-container-low rounded-xl p-8 md:p-12 border border-outline-variant/20 shadow-sm"} -->
<div class="wp-block-group max-w-2xl mx-auto bg-surface-container-low rounded-xl p-8 md:p-12 border border-outline-variant/20 shadow-sm">
<!-- wp:heading {"level":2,"className":"font-headline text-4xl md:text-5xl text-on-surface mb-4 text-center"} -->
<h2 class="font-headline text-4xl md:text-5xl text-on-surface mb-4 text-center">Send a message</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-on-surface-variant text-lg leading-relaxed text-center mb-10"} -->
<p class="text-on-surface-variant text-lg leading-relaxed text-center mb-10">Share a question or idea and we will reply by email.</p>
<!-- /wp:paragraph -->
$sc
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->
BLOCK;
}

/**
 * Thank you page.
 *
 * @return string
 */
function vila_akoya_blocks_thank_you() {
	$u = esc_url( home_url( '/' ) );
	return <<<BLOCK
<!-- wp:group {"className":"max-w-screen-lg mx-auto min-h-[calc(100vh-6rem)] flex flex-col justify-center px-6 text-center space-y-8","layout":{"type":"constrained"}} -->
<div class="wp-block-group max-w-screen-lg mx-auto min-h-[calc(100vh-6rem)] flex flex-col justify-center px-6 text-center space-y-8">
<!-- wp:paragraph {"className":"text-primary font-label uppercase tracking-[0.2em] text-xs"} -->
<p class="text-primary font-label uppercase tracking-[0.2em] text-xs">Thank you</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"level":1,"className":"font-barrio text-4xl md:text-5xl text-on-background leading-tight"} -->
<h1 class="font-barrio text-4xl md:text-5xl text-on-background leading-tight">We received your <span class="font-serif-italic">inquiry</span></h1>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"font-body text-lg text-on-surface-variant max-w-xl mx-auto leading-relaxed"} -->
<p class="font-body text-lg text-on-surface-variant max-w-xl mx-auto leading-relaxed">We will get back to you soon. If you need anything urgent, message us on WhatsApp or email hello@vilaakoya.pt.</p>
<!-- /wp:paragraph -->
<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"},"className":"flex flex-col sm:flex-row gap-4 justify-center pt-4"} -->
<div class="wp-block-buttons flex flex-col sm:flex-row gap-4 justify-center pt-4">
<!-- wp:button {"className":"rounded-full bg-primary text-white px-8 py-3"} -->
<div class="wp-block-button rounded-full bg-primary text-white px-8 py-3"><a class="wp-block-button__link wp-element-button" href="{$u}">Back to home</a></div>
<!-- /wp:button -->
<!-- wp:button {"className":"rounded-full border-2 border-primary/30 text-primary px-8 py-3 is-style-outline"} -->
<div class="wp-block-button rounded-full border-2 border-primary/30 text-primary px-8 py-3 is-style-outline"><a class="wp-block-button__link wp-element-button" href="{$u}booking/">Send another message</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
</div>
<!-- /wp:group -->
BLOCK;
}

require_once get_template_directory() . '/inc/blocks-pages-extended.php';
