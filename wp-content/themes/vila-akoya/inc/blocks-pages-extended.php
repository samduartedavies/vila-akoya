<?php
/**
 * Full brochure-aligned block content for key pages.
 *
 * @package VilaAkoya
 */

defined( 'ABSPATH' ) || exit;

require_once get_template_directory() . '/inc/block-helpers.php';

/**
 * The Kids — full page (brochure-website/the_kids.html).
 *
 * @return string
 */
function vila_akoya_blocks_the_kids() {
	$u = esc_url( home_url( '/' ) );
	$hero = 'https://lh3.googleusercontent.com/aida-public/AB6AXuDGJroKCy58HsD_qv6wMP0wc4JOnWTPtKGxmEOQspLdbrI5TAxt5lvJV4BCweDo0qUxCPL2C-r5-fd8IHWJL6teC0K4d0B7GT2bBQWnF-Bs14vr-9QylbT2Q7fo5xTqEwBYiuPHseb1RdPDJw7muBU5cZSgvXH8bY_ks143Znh8RQzUkesHhEliG0EHWiviIHPSrB2KQlSvk9EC7qZZCAmZisg8s-A4Cj3-rMSiYXGmXZ54toYkOwzS3Z-AiXmFLyViAZtiiBz81gVh';
	$img2 = 'https://lh3.googleusercontent.com/aida-public/AB6AXuBm_0MczpRcGBI9OJGkRgnO6Tt_5Mgd25_DWG-fUgTETnN8sFDs0JMR7n1SybR4ZvjlUhmBLLIGOx6wDACHEYTViPv-KsLxiyKoqbOxGnb4wRuzMZlS_hEIHeVjZR9jqBrClTOcU2VXJ_2VikSYJscoWN99uu_aWhxf5Y-c4gxTLhTCvHDcAXIxqOasL7f3Xi4M9dO_5VJPkADW4-zJMZwSnSHMaFBxKcJL-4fmhTqN2gd5_dtfREAt3sNuu3YRHhJR7cfqQbc2IQSi';
	$c1   = 'https://lh3.googleusercontent.com/aida-public/AB6AXuBFNpFpAWTS-HjaXgPcoUc5ap2Q9Id9qw-aRo4Y7zTXDk6MSP7JYoJ4E3x4BSs27YU0Gx5NAxA5qaJogVv96JgButQW_Ja8jKRWLldx0qSUiQgsXzomKFc_fPCDVB2X9PI_wXMKfLjboO3MzsZTAisJPL5OEG4ESD-xEflfb2TGu-omSxyWucfXt9MiPLBrdVR49-YKxTE_pyDU-b8Bf0eGWrGdorLVGQWGWD1MzNAvOc7w7TbRcIh5HrssyKKj6O5i4B-miMzJzGCj';
	$c2   = 'https://lh3.googleusercontent.com/aida-public/AB6AXuB8joh7EBQxw0w-2Ibpm_TXuDeMvFWdctJDqCrl9jh91UOLLqM4dBgfMqOysCuhaMagkzkEmz2S_WPiIzBcWh_mPSbF3RB3BBiOqCwgtuK_-wPF66wNyrrgEdsH0Si60aw5P8wGW_G7veAAzkiRFl6D8768hDYVY1qyCdjhzyNQI8ADKoGYxSb_bTWAfxU0e_eAemO45h3DDy-4HZNLV60Pe2FDCScQbl1o-wAG7w5a2Gxfqyupk77T0V8qU7dTyuXg6XnlEK-yw3ZA';
	$c3   = 'https://lh3.googleusercontent.com/aida-public/AB6AXuBSWIneVqa6_LAzvG9eXHLKT67yT3DxvE05yPlb_HlsUlzzBQ8_PduU6AN_oB0SpYRQrr9xavaVVefcr5Hm6WiNmr69qC93VrrAD2bIW1GvXTxdHo7PmdPor0Bthoy2aLZpi081DZQGYWsgt8IhQL_BtKyomKSogbgZlj-JXv_MOQYAc7NRLUYR-nmDTm-zgrF-Sg7vH13fXCMblPfsSep-hD6Qny5EiQuT---_glgh6Q4hckOHv-tbjulLSBjZJbMvdatWma-fqfbL';
	$bg   = 'https://lh3.googleusercontent.com/aida-public/AB6AXuCOoagvEROnCyYA2TRl2HdaQVr-phnQJi5Z7pcU0_b-KN656R7NRv8ZYnmaKZ0ygjcacLOM2YN4LggRiDD7aVBSaspVpuCdTb8tj2wtfBz2hiJWj8PJJE3CC2sy3u0EqyJSpeo5chTRVelNcCi4LhoG9atIZlwUcmfjUr7ZkO4-CL6soEnYKDKmYEZ9euHLzVlSAeLyBtw_zhic32td6Z7u1PdknysRf3-9jhtNBZ5QkzeiU98L_CewrNMEoxN18Ls7qBAPCOlKr-5T';

	return <<<BLOCK
<!-- wp:group {"className":"min-h-[calc(100vh-6rem)] flex flex-col justify-center px-6 md:px-12 lg:px-24 py-12 md:py-0 mb-32","layout":{"type":"constrained"}} -->
<div class="wp-block-group min-h-[calc(100vh-6rem)] flex flex-col justify-center px-6 md:px-12 lg:px-24 py-12 md:py-0 mb-32">
<!-- wp:columns {"className":"gap-6 items-center"} -->
<div class="wp-block-columns gap-6 items-center">
<!-- wp:column {"width":"58.33%"} -->
<div class="wp-block-column" style="flex-basis:58.33%">
<!-- wp:heading {"level":1,"className":"font-barrio text-6xl md:text-8xl text-primary mb-8 leading-none -rotate-2 origin-left"} -->
<h1 class="font-barrio text-6xl md:text-8xl text-primary mb-8 leading-none -rotate-2 origin-left">The Kids</h1>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"font-headline italic text-2xl md:text-3xl text-on-surface-variant max-w-xl leading-relaxed"} -->
<p class="font-headline italic text-2xl md:text-3xl text-on-surface-variant max-w-xl leading-relaxed">Where the ancient cork oaks become classrooms and the rhythm of the seasons dictates the curriculum.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column {"width":"41.67%"} -->
<div class="wp-block-column" style="flex-basis:41.67%">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"rounded-xl overflow-hidden aspect-[4/5] shadow-2xl rotate-3"} -->
<figure class="wp-block-image size-large rounded-xl overflow-hidden aspect-[4/5] shadow-2xl rotate-3"><img src="$hero" alt="Children among cork oaks"/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->

<!-- wp:group {"className":"bg-surface-container-low py-24 md:py-32 rounded-xl mx-4 md:mx-8 mb-32","layout":{"type":"constrained"}} -->
<div class="wp-block-group bg-surface-container-low py-24 md:py-32 rounded-xl mx-4 md:mx-8 mb-32">
<!-- wp:columns -->
<div class="wp-block-columns">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"rounded-md shadow-sm -rotate-3 mb-8"} -->
<figure class="wp-block-image size-large rounded-md shadow-sm -rotate-3 mb-8"><img src="$img2" alt="Children and parents in a circle"/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":2,"className":"font-headline text-4xl text-primary"} -->
<h2 class="font-headline text-4xl text-primary">The Homeschooling Collective</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"font-headline text-lg text-on-surface leading-relaxed"} -->
<p class="font-headline text-lg text-on-surface leading-relaxed">A supportive ecosystem for families dedicated to alternative education. We provide the physical space and the communal spirit for curated learning journeys.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"font-headline text-lg text-on-surface leading-relaxed"} -->
<p class="font-headline text-lg text-on-surface leading-relaxed">From collaborative project-based learning to shared resources, the collective operates on a philosophy of trust, autonomy, and the belief that every child is a natural researcher.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"pt-4"} -->
<p class="pt-4"><a class="font-headline font-bold text-tertiary border-b-2 border-tertiary/30 pb-1 text-xl" href="{$u}contact/">Join the Circle</a></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->

<!-- wp:group {"className":"px-6 md:px-12 lg:px-24 mb-32","layout":{"type":"constrained"}} -->
<div class="wp-block-group px-6 md:px-12 lg:px-24 mb-32">
<!-- wp:heading {"level":2,"className":"font-headline text-5xl text-primary text-center mb-20"} -->
<h2 class="font-headline text-5xl text-primary text-center mb-20">Daily Extracurriculars</h2>
<!-- /wp:heading -->
<!-- wp:columns {"className":"gap-8"} -->
<div class="wp-block-columns gap-8">
<!-- wp:column {"className":"bg-surface-container rounded-xl p-8"} -->
<div class="wp-block-column bg-surface-container rounded-xl p-8">
<!-- wp:heading {"level":3,"className":"font-headline text-2xl text-primary mb-4"} -->
<h3 class="font-headline text-2xl text-primary mb-4">Manual Crafts</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"font-headline text-lg opacity-80 mb-8"} -->
<p class="font-headline text-lg opacity-80 mb-8">Pottery with local clay, weaving with natural fibers, and woodcarving using fallen branches.</p>
<!-- /wp:paragraph -->
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"aspect-video rounded-lg overflow-hidden"} -->
<figure class="wp-block-image size-large aspect-video rounded-lg overflow-hidden"><img src="$c1" alt="Clay and pottery"/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:column -->
<!-- wp:column {"className":"bg-secondary-container/30 rounded-xl p-8 md:translate-y-12"} -->
<div class="wp-block-column bg-secondary-container/30 rounded-xl p-8 md:translate-y-12">
<!-- wp:heading {"level":3,"className":"font-headline text-2xl text-secondary mb-4"} -->
<h3 class="font-headline text-2xl text-secondary mb-4">Eco-Tracking</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"font-headline text-lg opacity-80 mb-8"} -->
<p class="font-headline text-lg opacity-80 mb-8">Identifying flora and fauna, understanding soil health, and learning the language of the birds.</p>
<!-- /wp:paragraph -->
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"aspect-video rounded-lg overflow-hidden"} -->
<figure class="wp-block-image size-large aspect-video rounded-lg overflow-hidden"><img src="$c2" alt="Forest light"/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:column -->
<!-- wp:column {"className":"bg-tertiary-container/30 rounded-xl p-8"} -->
<div class="wp-block-column bg-tertiary-container/30 rounded-xl p-8">
<!-- wp:heading {"level":3,"className":"font-headline text-2xl text-tertiary mb-4"} -->
<h3 class="font-headline text-2xl text-tertiary mb-4">Garden-to-Table</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"font-headline text-lg opacity-80 mb-8"} -->
<p class="font-headline text-lg opacity-80 mb-8">Harvesting from our permaculture gardens and preparing wholesome, communal meals.</p>
<!-- /wp:paragraph -->
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"aspect-video rounded-lg overflow-hidden"} -->
<figure class="wp-block-image size-large aspect-video rounded-lg overflow-hidden"><img src="$c3" alt="Fresh vegetables"/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->

<!-- wp:group {"className":"relative py-32 overflow-hidden","layout":{"type":"constrained"}} -->
<div class="wp-block-group relative py-32 overflow-hidden">
<!-- wp:group {"className":"max-w-4xl mx-auto px-6 relative text-center"} -->
<div class="wp-block-group max-w-4xl mx-auto px-6 relative text-center">
<!-- wp:heading {"level":2,"className":"font-headline text-5xl md:text-7xl text-primary mb-12"} -->
<h2 class="font-headline text-5xl md:text-7xl text-primary mb-12">Wisdom of the Woods</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"font-headline text-2xl md:text-3xl italic text-on-surface leading-snug"} -->
<p class="font-headline text-2xl md:text-3xl italic text-on-surface leading-snug">"The trees are our elders. We teach children to listen to their silence, to respect their longevity, and to understand our place as stewards of this land."</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"not-italic text-lg font-label uppercase tracking-widest text-tertiary pt-8"} -->
<p class="not-italic text-lg font-label uppercase tracking-widest text-tertiary pt-8">Our Core Pillars</p>
<!-- /wp:paragraph -->
<!-- wp:columns {"className":"gap-8 pt-8"} -->
<div class="wp-block-columns gap-8 pt-8">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":4,"className":"font-headline text-2xl text-primary mb-2"} -->
<h4 class="font-headline text-2xl text-primary mb-2">Resilience</h4>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-base font-body opacity-70"} -->
<p class="text-base font-body opacity-70">Learning to navigate the outdoor elements with grace and confidence.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":4,"className":"font-headline text-2xl text-primary mb-2"} -->
<h4 class="font-headline text-2xl text-primary mb-2">Empathy</h4>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-base font-body opacity-70"} -->
<p class="text-base font-body opacity-70">Developing a deep connection with all living beings in the forest ecosystem.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":4,"className":"font-headline text-2xl text-primary mb-2"} -->
<h4 class="font-headline text-2xl text-primary mb-2">Curiosity</h4>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-base font-body opacity-70"} -->
<p class="text-base font-body opacity-70">Nurturing the innate drive to question, explore, and create meaning.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->

<!-- wp:group {"className":"px-6 md:px-12 lg:px-24 py-32","layout":{"type":"constrained"}} -->
<div class="wp-block-group px-6 md:px-12 lg:px-24 py-32">
<!-- wp:group {"className":"bg-surface-container-highest rounded-[3rem] p-12 md:p-24 overflow-hidden relative"} -->
<div class="wp-block-group bg-surface-container-highest rounded-[3rem] p-12 md:p-24 overflow-hidden relative">
<!-- wp:paragraph {"className":"font-headline text-primary text-xl mb-4"} -->
<p class="font-headline text-primary text-xl mb-4">Seasonal Intake</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"level":2,"className":"font-headline text-5xl md:text-6xl text-on-background mb-8 leading-tight"} -->
<h2 class="font-headline text-5xl md:text-6xl text-on-background mb-8 leading-tight">Enrol Your Child in Nature</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"font-headline text-xl mb-12 opacity-90"} -->
<p class="font-headline text-xl mb-12 opacity-90">We offer weekly programs and seasonal camps for children aged 5–12. Experience the magic of The Kids at Vila Akoya.</p>
<!-- /wp:paragraph -->
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"rounded-xl mb-8 lg:hidden"} -->
<figure class="wp-block-image size-large rounded-xl mb-8 lg:hidden"><img src="$bg" alt="Cork forest at dusk"/></figure>
<!-- /wp:image -->
<!-- wp:buttons {"layout":{"type":"flex","flexWrap":"wrap"}} -->
<div class="wp-block-buttons">
<!-- wp:button {"className":"bg-primary text-on-primary px-10 py-5 rounded-full font-label font-bold text-lg"} -->
<div class="wp-block-button bg-primary text-on-primary px-10 py-5 rounded-full font-label font-bold text-lg"><a class="wp-block-button__link wp-element-button" href="mailto:Info@vila-akoya.com?subject=The%20Kids%20brochure">Download Brochure</a></div>
<!-- /wp:button -->
<!-- wp:button {"className":"border-2 border-primary text-primary px-10 py-5 rounded-full font-label font-bold text-lg is-style-outline"} -->
<div class="wp-block-button border-2 border-primary text-primary px-10 py-5 rounded-full font-label font-bold text-lg is-style-outline"><a class="wp-block-button__link wp-element-button" href="{$u}booking/">Book a Visit</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->
BLOCK;
}

/**
 * Campsite — full page (brochure-website/campsite.html).
 *
 * @return string
 */
function vila_akoya_blocks_campsite() {
	$u    = esc_url( home_url( '/' ) );
	$hero = 'https://lh3.googleusercontent.com/aida-public/AB6AXuBQvQ9PtYWtkqOOcyDcN-HxChhLIXTOmfVZekjILz_kFMUXEHx8KhFKRo_guuswKjvutj5EG_sXUbQdfLrs9QOeBvCPiKLVj5nRUnIlKzNK8kWY-rVCSNcEdkCTF_wcHew6B696_Xeh-78YNCUhOcoBVaysQ_eeV43v1aSrJ2ZeBgO5lRRYUbVJUXtfKU31yU6KGjiL_LiIvwpHsj2XoyiSQXu7ogT7-CrWYZVVTB_ZCIk9eOxGQtlwHKCevyLoQFlPUMbHqtCvTn2l';
	$camp = 'https://lh3.googleusercontent.com/aida-public/AB6AXuAuXfz52Fjk-EUciGPep0ghHEn9Ag-nG332d3JZ4c0hSW4AMwFbH-1ZMuohzF5AZhYJ8kywiKGwFwRu1dlVxKQZo5CqFl-RMlb14ZT5UkBIRHnEQASNvVPZC-gsg5--h8XJjord5RR7IuMNWnPSDrdn78-vdTQp9KW7frSZmP-_-GIMxdfalap5cPgYt_yUYbI537WkDoNQFHRc7shEfYqS1BlMOjMc3J1dw1YNollJ1CaFXa5_fPkM2ZPEOHjXdaT-H8jtqbIf6du0';
	$w1   = 'https://lh3.googleusercontent.com/aida-public/AB6AXuDEK5Vius-MNFZISSrMxU-s55t8mLKvXXak0gEelOlZVzs5xLIzQmaXR0NLRxjmxFTcIMbxh9oEBd7uB3SlwzlsfnEA7e8QsuogF0DkFmom30SIrOkQnbTDNZI12R4UQNo5bYuxzYIZ3RKPD6xQsnVRvk-yqcKKBiix085QErWX7IUzAsMukzX2i6bMAU0L6iegTDxJ8AGBR_5yht5nPBTUma_bb0Yo_wAe7hXOhx54RQVubqH1pQO0riAyKtL63OMQZIHh6jPEUxUW';
	$w2   = 'https://lh3.googleusercontent.com/aida-public/AB6AXuDmcZMKEMcBVOg_9rj7-XnWawRSABH-o-VDvOaDIJ0NYIVDfYlfcuB5iLbH3n40Nhtoo5Q9FATCP4Hc5ooKBQgeM1-nz9BBxlS-CvvE7cUfwVbd5JR9fM6lMdYKzYrZVt7ofMSoRaDnuSMSxFcwKse451m5cwCBWEGhxN5V6NBcv5h-nC2X1vJkl1TpyZxtE0LUgNc3zZeypD5_u77SYEPcTumT4sbA_CScncazUVCcjpEtvyG1pZ7yarv4uJ0X8J2zHVcaOB8ighXL';
	$mov  = 'https://lh3.googleusercontent.com/aida-public/AB6AXuDJ9HvLKLFEWMA0xoZ8shiI53DoKr6Bp26zVvgI95PZJ0cmUd99ujx7URd8UslwcM1LGCphnbuEcxxzQICWmJyz6WkJ-LPKfKfM-FPyb4Sk1mBwEKKWojg5fCTmZGYWSOmMwd6_VRksFnIGwBccaUs9i97PRV9Dh6ws4P-mtptNz4rOkbYnyDPnpGEnkAAalnGLc_tcWBt-E2E0w-5Xv92Fk2J6fNCxCqiie3oMLqfwg08Mh8aszNnmTy3S1j92OkkTfxRXd8C6sU3z';
	$r1   = 'https://lh3.googleusercontent.com/aida-public/AB6AXuCTJ96v3cJfiokX_v5Rgygddxag9gsXeToa5Lzd4cMIp_pSdEYb3McoMjuZTcQVQFHBbYKhUchZViH6dfPFk4AKsDItDXbMTL8MTmNTkyXSR_1KZpq9QY31ED-nCsrRKkEx4krUhE-LV_wG5joUKbA_Qcc_LtdjiuGBUMaEHoL2WfGMLSi_ayGY_6r9650sJFTTnP5fB8I3h3tc4RunwzzR_97rtqHOiXpbUaRz1lwPexmOcQ8wTRGl4175Nl8GWE0i-mz0ENicH7S4';
	$r2   = 'https://lh3.googleusercontent.com/aida-public/AB6AXuA2TuLRT9S5PYACDDd4yNGH9GDUjTSteXQYdbsesECA3Bif20ita6UiCiLryVOBrWVVDvWV47iAqNmAMtDAoC2DEXDcJtjbLSlUYUku2ZmxXUqP4JVwhm_zMnD3AaCOKoLwofluC57Q1Z6ILl1qebG2KuXdR4Z4kt5-l0tynoEWXigLuElHwwO-V3EQfD3lwZQReXVunQcOa-C4LvjfVJi9U-Og5ZOxeQE2DzeZdHgCc_qqQUyBuDJ-KvSh5K8l6VDd12vDyh5iclwX';
	$r3   = 'https://lh3.googleusercontent.com/aida-public/AB6AXuCrBfekyCU0YkCPleHQKcuOYibKPxOPXo6fChQp3sP--Hhm8eD9KPncisx2tG0mmgvKa9VZ5EGtX6HWbjUbE4hSTr5WDGD1QCPy36JyvbEE7vm2JXApM8om4s-VJZZyhAq_1X1sikkOApgkL_Ca5Od-L7N9YLt6NLrM4EWAUVDJI2bBJ8cRI_-3BjtdBWvTfKgVYuRenf4NTf2xBP8h9VI0wK43qp5C-Od8-9X_aTRFQq2EayQjyRFDidvX0hzQA5mT1jXt3DytoRHF';
	$r4   = 'https://lh3.googleusercontent.com/aida-public/AB6AXuA3zTgXwCplXlKJZnwHARLh-P5iPFC4Uy_jsQkyOPtedE6Ya4YYPYsPWmV9F7TumThMG5LiOy9S_HClovlgjyAi-X6fnuh53W29g_1XNr9ydJnUYhqWdFSA01FLNdhvzEi6UOdo7QWNBcugfGCyeFStCD1NX2Dqbzby9EE8rWoL-93iPDlw6a6xkqzGVgts9QdP_-Vzuczn62S_Lur03r1DVlBEkq6ha4wA1nMi12YPxHoFr6RjgCx-19nFGaWRAD2jy2aQ9eAdpOds';

	return <<<BLOCK
<!-- wp:group {"className":"relative min-h-[calc(100vh-6rem)] flex items-center px-8 md:px-20 py-12 md:py-0 overflow-hidden","layout":{"type":"constrained"}} -->
<div class="wp-block-group relative min-h-[calc(100vh-6rem)] flex items-center px-8 md:px-20 py-12 md:py-0 overflow-hidden">
<!-- wp:html -->
<div class="absolute inset-0 z-0 overflow-hidden"><img class="w-full h-full object-cover brightness-75 scale-105" src="$hero" alt=""/><div class="absolute inset-0 bg-gradient-to-r from-primary/40 to-transparent"></div></div>
<!-- /wp:html -->
<!-- wp:group {"className":"relative z-10 max-w-3xl space-y-8"} -->
<div class="wp-block-group relative z-10 max-w-3xl space-y-8">
<!-- wp:paragraph {"className":"inline-block px-4 py-1.5 bg-tertiary/20 backdrop-blur-md rounded-full font-label text-xs uppercase tracking-widest border border-white/10"} -->
<p class="inline-block px-4 py-1.5 bg-tertiary/20 backdrop-blur-md rounded-full font-label text-xs uppercase tracking-widest border border-white/10">The Alentejo Soul</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"level":1,"className":"text-6xl md:text-8xl font-normal text-on-primary font-barrio leading-none drop-shadow-lg"} -->
<h1 class="text-6xl md:text-8xl font-normal text-on-primary font-barrio leading-none drop-shadow-lg">Wild &amp;<br/>Connected</h1>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-xl md:text-2xl text-on-primary font-light max-w-xl leading-relaxed"} -->
<p class="text-xl md:text-2xl text-on-primary font-light max-w-xl leading-relaxed">A campsite for the dreamers, the makers, and the wandering souls. Bring your van, your tent, and your curiosity.</p>
<!-- /wp:paragraph -->
<!-- wp:buttons -->
<div class="wp-block-buttons pt-6">
<!-- wp:button {"className":"px-10 py-5 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-xl text-lg font-semibold editorial-shadow"} -->
<div class="wp-block-button px-10 py-5 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-xl text-lg font-semibold editorial-shadow"><a class="wp-block-button__link wp-element-button" href="{$u}booking/">Secure Your Plot</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->

<!-- wp:group {"className":"py-32 px-8 md:px-20 bg-surface","layout":{"type":"constrained"}} -->
<div class="wp-block-group py-32 px-8 md:px-20 bg-surface">
<!-- wp:columns {"className":"gap-20 items-center"} -->
<div class="wp-block-columns gap-20 items-center">
<!-- wp:column {"width":"50%"} -->
<div class="wp-block-column" style="flex-basis:50%">
<!-- wp:heading {"level":2,"className":"text-5xl md:text-7xl font-normal text-primary font-headline leading-tight"} -->
<h2 class="text-5xl md:text-7xl font-normal text-primary font-headline leading-tight"><a href="{$u}campus/">The Campus</a>:<br/>Learning in Flow</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-xl text-on-surface-variant font-light leading-relaxed"} -->
<p class="text-xl text-on-surface-variant font-light leading-relaxed">At the heart of our campsite lies The Campus—an open-air cathedral of knowledge. No classrooms, just oak-shaded decks and riverside circles where masters and seekers meet.</p>
<!-- /wp:paragraph -->
<!-- wp:columns {"className":"grid grid-cols-2 gap-8 pt-4"} -->
<div class="wp-block-columns grid grid-cols-2 gap-8 pt-4">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":4,"className":"font-bold text-lg"} -->
<h4 class="font-bold text-lg">Solar Powered</h4>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-sm text-on-surface-variant"} -->
<p class="text-sm text-on-surface-variant">Clean energy for our digital and spiritual tools.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":4,"className":"font-bold text-lg"} -->
<h4 class="font-bold text-lg">Living Library</h4>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-sm text-on-surface-variant"} -->
<p class="text-sm text-on-surface-variant">A collection of nomadic wisdom and artisanal books.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:column -->
<!-- wp:column {"width":"50%"} -->
<div class="wp-block-column" style="flex-basis:50%">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"aspect-square rounded-full overflow-hidden border-8 border-surface-container shadow-2xl"} -->
<figure class="wp-block-image size-large aspect-square rounded-full overflow-hidden border-8 border-surface-container shadow-2xl"><img src="$camp" alt="Campus weaving"/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->

<!-- wp:group {"className":"py-24 px-8 md:px-20 bg-surface-container-low","layout":{"type":"constrained"}} -->
<div class="wp-block-group py-24 px-8 md:px-20 bg-surface-container-low">
<!-- wp:heading {"level":2,"className":"text-4xl md:text-6xl font-normal text-secondary font-headline mb-4"} -->
<h2 class="text-4xl md:text-6xl font-normal text-secondary font-headline mb-4">Workshop Spheres</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"max-w-md text-on-surface-variant italic mb-16"} -->
<p class="max-w-md text-on-surface-variant italic mb-16">Diverse paths to mastery, hosted daily by our resident villagers and traveling sages.</p>
<!-- /wp:paragraph -->
<!-- wp:columns {"className":"gap-6"} -->
<div class="wp-block-columns gap-6">
<!-- wp:column {"className":"bg-surface-container-high p-8 rounded-xl"} -->
<div class="wp-block-column bg-surface-container-high p-8 rounded-xl">
<!-- wp:heading {"level":3,"className":"text-3xl font-headline text-on-surface"} -->
<h3 class="text-3xl font-headline text-on-surface">Body &amp; Mind</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-on-surface-variant"} -->
<p class="text-on-surface-variant">Vinyasa at dawn, deep sound baths at dusk, and the art of silence.</p>
<!-- /wp:paragraph -->
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"rounded-lg overflow-hidden h-48 mt-8"} -->
<figure class="wp-block-image size-large rounded-lg overflow-hidden h-48 mt-8"><img src="$w1" alt="Yoga meadow"/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:column -->
<!-- wp:column {"className":"bg-surface p-8 rounded-xl editorial-shadow md:col-span-8"} -->
<div class="wp-block-column bg-surface p-8 rounded-xl editorial-shadow md:col-span-8">
<!-- wp:columns -->
<div class="wp-block-columns">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":3,"className":"text-4xl font-headline text-on-surface"} -->
<h3 class="text-4xl font-headline text-on-surface">Creative Arts</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-on-surface-variant"} -->
<p class="text-on-surface-variant">Natural pigment painting, clay sculpting with local earth, and nomadic jewelry making.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p><a class="text-primary font-bold underline" href="{$u}campus/#upcoming-journeys">View Schedule</a></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"rounded-xl"} -->
<figure class="wp-block-image size-large rounded-xl"><img src="$w2" alt="Pottery"/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
<!-- wp:columns {"className":"gap-6 mt-6"} -->
<div class="wp-block-columns gap-6 mt-6">
<!-- wp:column {"className":"bg-secondary p-10 rounded-xl text-on-secondary relative overflow-hidden"} -->
<div class="wp-block-column bg-secondary p-10 rounded-xl text-on-secondary relative overflow-hidden">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"absolute inset-0 opacity-40 w-full h-full object-cover"} -->
<figure class="wp-block-image size-large absolute inset-0 opacity-40 w-full h-full object-cover"><img src="$mov" alt="Movement"/></figure>
<!-- /wp:image -->
<!-- wp:heading {"level":3,"className":"text-4xl font-headline mb-4 relative z-10"} -->
<h3 class="text-4xl font-headline mb-4 relative z-10">Movement</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"max-w-sm font-light relative z-10"} -->
<p class="max-w-sm font-light relative z-10">Ecstatic dance, capoeira, and slacklining across the Akoya riverbanks.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:group {"className":"bg-surface-container p-8 rounded-xl mb-6"} -->
<div class="wp-block-group bg-surface-container p-8 rounded-xl mb-6">
<!-- wp:heading {"level":4,"className":"text-2xl font-headline uppercase tracking-tighter"} -->
<h4 class="text-2xl font-headline uppercase tracking-tighter">Practical Skills</h4>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-sm"} -->
<p class="text-sm">Solar oven building, regenerative gardening, and nomadic van maintenance.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
<!-- wp:group {"className":"bg-surface-container-highest p-8 rounded-xl"} -->
<div class="wp-block-group bg-surface-container-highest p-8 rounded-xl">
<!-- wp:heading {"level":4,"className":"text-2xl font-headline uppercase tracking-tighter"} -->
<h4 class="text-2xl font-headline uppercase tracking-tighter">Philosophy</h4>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-sm"} -->
<p class="text-sm">Ancient storytelling, ethical nomadism, and the physics of vibration.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->

<!-- wp:group {"className":"py-32 overflow-hidden","layout":{"type":"constrained"}} -->
<div class="wp-block-group py-32 overflow-hidden">
<!-- wp:heading {"level":2,"className":"text-4xl md:text-7xl font-normal text-on-surface font-headline text-center mb-20 px-8"} -->
<h2 class="text-4xl md:text-7xl font-normal text-on-surface font-headline text-center mb-20 px-8">Live with the Rhythm</h2>
<!-- /wp:heading -->
<!-- wp:columns {"className":"flex gap-8 overflow-x-auto pb-12 px-8"} -->
<div class="wp-block-columns flex gap-8 overflow-x-auto pb-12 px-8">
<!-- wp:column {"className":"min-w-[300px]"} -->
<div class="wp-block-column min-w-[300px]">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"aspect-[4/5] rounded-xl overflow-hidden"} -->
<figure class="wp-block-image size-large aspect-[4/5] rounded-xl overflow-hidden"><img src="$r1" alt="Van under cork tree"/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:column -->
<!-- wp:column {"className":"min-w-[300px]"} -->
<div class="wp-block-column min-w-[300px]">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"aspect-[4/5] rounded-xl overflow-hidden"} -->
<figure class="wp-block-image size-large aspect-[4/5] rounded-xl overflow-hidden"><img src="$r2" alt="Bell tent"/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:column -->
<!-- wp:column {"className":"min-w-[300px]"} -->
<div class="wp-block-column min-w-[300px]">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"aspect-[4/5] rounded-xl overflow-hidden"} -->
<figure class="wp-block-image size-large aspect-[4/5] rounded-xl overflow-hidden"><img src="$r3" alt="Campfire"/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:column -->
<!-- wp:column {"className":"min-w-[300px]"} -->
<div class="wp-block-column min-w-[300px]">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"aspect-[4/5] rounded-xl overflow-hidden"} -->
<figure class="wp-block-image size-large aspect-[4/5] rounded-xl overflow-hidden"><img src="$r4" alt="Nomadic woman at sunset"/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->

<!-- wp:group {"className":"py-24 px-8 md:px-20 bg-[#2f3628] text-on-primary","layout":{"type":"constrained"}} -->
<div class="wp-block-group py-24 px-8 md:px-20 bg-[#2f3628] text-on-primary">
<!-- wp:columns {"className":"gap-12"} -->
<div class="wp-block-columns gap-12">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:paragraph {"className":"text-2xl font-body italic"} -->
<p class="text-2xl font-body italic">"I came for a week and stayed for a season. The workshop energy is like nothing else—it's where your soul remembers how to play."</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"font-bold text-lg font-label uppercase tracking-widest text-secondary-fixed"} -->
<p class="font-bold text-lg font-label uppercase tracking-widest text-secondary-fixed">Luna</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"text-sm text-secondary-fixed/60"} -->
<p class="text-sm text-secondary-fixed/60">Digital Weaver &amp; Van-lifer</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:paragraph {"className":"text-2xl font-body italic"} -->
<p class="text-2xl font-body italic">"Vila Akoya isn't just a campsite; it's a high-vibration node. Learning permaculture by day and dancing under the stars by night transformed me."</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"font-bold text-lg font-label uppercase tracking-widest text-secondary-fixed"} -->
<p class="font-bold text-lg font-label uppercase tracking-widest text-secondary-fixed">Mateo</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"text-sm text-secondary-fixed/60"} -->
<p class="text-sm text-secondary-fixed/60">Practical Philosopher</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:paragraph {"className":"text-2xl font-body italic"} -->
<p class="text-2xl font-body italic">"Found my tribe here. The workshops are authentic, raw, and deeply grounded in the Alentejo soil."</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"font-bold text-lg font-label uppercase tracking-widest text-secondary-fixed"} -->
<p class="font-bold text-lg font-label uppercase tracking-widest text-secondary-fixed">Sia</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"text-sm text-secondary-fixed/60"} -->
<p class="text-sm text-secondary-fixed/60">Ceramicist &amp; Nomad</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->

<!-- wp:group {"className":"py-32 px-8 text-center relative overflow-hidden bg-surface-container-low","layout":{"type":"constrained"}} -->
<div class="wp-block-group py-32 px-8 text-center relative overflow-hidden bg-surface-container-low">
<!-- wp:heading {"level":2,"className":"text-5xl md:text-7xl font-normal text-primary font-headline leading-none"} -->
<h2 class="text-5xl md:text-7xl font-normal text-primary font-headline leading-none">Ready to plug<br/>into the energy?</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-xl font-light text-on-surface-variant max-w-2xl mx-auto"} -->
<p class="text-xl font-light text-on-surface-variant max-w-2xl mx-auto">Plot bookings for the creative season are opening soon. Join our inner circle for early access and first dibs on the best oak-shaded spots.</p>
<!-- /wp:paragraph -->
<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center","flexWrap":"wrap"},"className":"gap-6 justify-center"} -->
<div class="wp-block-buttons gap-6 justify-center">
<!-- wp:button {"className":"px-12 py-5 bg-primary text-on-primary rounded-xl font-bold text-lg editorial-shadow"} -->
<div class="wp-block-button px-12 py-5 bg-primary text-on-primary rounded-xl font-bold text-lg editorial-shadow"><a class="wp-block-button__link wp-element-button" href="{$u}booking/">Secure Your Plot</a></div>
<!-- /wp:button -->
<!-- wp:button {"className":"px-12 py-5 border-2 border-primary text-primary rounded-xl font-bold text-lg is-style-outline"} -->
<div class="wp-block-button px-12 py-5 border-2 border-primary text-primary rounded-xl font-bold text-lg is-style-outline"><a class="wp-block-button__link wp-element-button" href="{$u}campus/">View Campus Map</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
</div>
<!-- /wp:group -->
BLOCK;
}

/**
 * Village — full page (brochure-website/village.html).
 *
 * @param int $cf7 Waitlist form ID.
 * @return string
 */
function vila_akoya_blocks_village( $cf7 ) {
	$u    = esc_url( home_url( '/' ) );
	$sc   = vila_akoya_block_cf7( $cf7 );
	$hero = 'https://lh3.googleusercontent.com/aida-public/AB6AXuDyzk4TOL5610Gk-U9uxmuFiuXWf0EEG15Xw-iX2hqh5Wep3o1hqVyMKj_ZT17nNOFIgwyWf-2oQmedvlBi0zNL-SKi_13lSiT4M7536GetnixyRDlkzIPSDe0w6F49hwZo0Z1y7KpbygeWSvYkCC1Qhar3gyjBM8ueVsGSMsbbda3U7zXOMeEk1Al_7esbqRzHTDrGSzknOhJYJCac_VO7CKQBSX315KFjwpxUZnyY9Q5nIW7HqDlWihvAI8FYUEk7bvKwbVeSLGy1';
	$phil = 'https://lh3.googleusercontent.com/aida-public/AB6AXuASlQzNgSNlI6HfEsyt5pn4Ck4HxtP7xhuAzRkafmQ4f1YLNuS25Uhllro6Zi5U2t44QMcp7p9GtJi8Oh8jr9cVpWk8s2hZxWosbnOwp98GqajVK1Q5VjtPg2kqhHYNWCkQiG8VarK_fVKZPAi_MzZKPEl5On41MOt1uPBpoc_c9G5UtLHiQhpE0iOgl7NCQsZfXqE8pQ3bFJV4rFF627PjAXhIwXTSO5e3EakyZCDbOxHaOSzO1KteqyR8V5Hi6_Lc5VUPNnnXTJr1';
	$p1   = 'https://lh3.googleusercontent.com/aida-public/AB6AXuAE7Raea_JCeM9mXfbdxfcQMUGi6Qwzx7THGg5g-2ToE_T90uBDkGQfQzXeiW04psPudyIRFpjdX6tVDTMiHdwrKrGXwM3LPucEGjGkrOBEOkaBFxY1m2FhSIsYAkUPbKakcHrO1oHHmcfEqTAODgzKMdYtjFjN8Zi9tSlrWsZzRMq3wj6_v50Y6CRLxu6yDveT4sVJBlPAFFrBD6zOktYVYNw-q8hW_5Ec19LGZYcdfYf9mh1PfUebKrAduNCqZrtH7z4vOuaz9OAt';
	$p2   = 'https://lh3.googleusercontent.com/aida-public/AB6AXuCh5TiCLIclVaglb-Kg9GQNZ-qOaaoZDH2GJMNEQq6X37KEmQnWIp17Dm253ScZBuOVHYLxwgeWd5ZQ2OC4qNymU2sIUbGXeMC3-uSFYaRcfsGNYZsYnXgEqpwz15fhfQLBkfo2J-gqxU1_MFIGozXORZvTearEywfknEdlGsK9XHSTjUIMqquw6aVmFy3_AjRdzEhm7ai6H_oVsHPy91j7rLvP1vyfDE6a9wpRDEy95yTe7apPnAmeSIsFH2l8ZwZK5NpstKH1Wtg6';
	$p3   = 'https://lh3.googleusercontent.com/aida-public/AB6AXuDQLMbLru5EUusoLtPnGH56PjlEJWhHe9GGeLuucLGxd6GVp1inhjYaO_ifJSU7UoWp90XDsdTXWdfo4OD99N0wHp21iRvkmHLIGTXTVUw3eFxUwO49qqYiTmaqrxBH36huLbD9MXmBUYTVzfexcLWaDUT6zWL9dYWZKnOtluos-6KbXJuaV0Lpsk1TvoE1xvb1gSHOakvhB0vrIFWHC7HH9UdRLHC1Mx-aw-cyy6mTclmGgNFZwTtjA5EZXs9LBtpyXx20WIQr675s';
	$com  = 'https://lh3.googleusercontent.com/aida-public/AB6AXuCjQr0IEVmAieGg4n__8JlL_h0v6S_CyeI0-WOqyBh5XMF6NCDilf9vg0k5o6o9tTBFkP1R75P0ydCAIOObwzA0xCAWeHl3euH-zQKSmJttiBoIDEmRjx3GKCRGJrbND1iiHx_GisYxZKCfF_26d5FbB2ueNggJlrmjmaqVqV71N3EdbYHxU2bE4oYsWkBphoqwc3V_Gvaw0P4ZmFq5GaWYl7Mhfmm_d01T2DxHzst2cQ5ReUDKs6wL_3Sxz1TFXuDcwf5nKVOXw1r6';
	$for1 = 'https://lh3.googleusercontent.com/aida-public/AB6AXuASlQzNgSNlI6HfEsyt5pn4Ck4HxtP7xhuAzRkafmQ4f1YLNuS25Uhllro6Zi5U2t44QMcp7p9GtJi8Oh8jr9cVpWk8s2hZxWosbnOwp98GqajVK1Q5VjtPg2kqhHYNWCkQiG8VarK_fVKZPAi_MzZKPEl5On41MOt1uPBpoc_c9G5UtLHiQhpE0iOgl7NCQsZfXqE8pQ3bFJV4rFF627PjAXhIwXTSO5e3EakyZCDbOxHaOSzO1KteqyR8V5Hi6_Lc5VUPNnnXTJr1';
	$for2 = 'https://lh3.googleusercontent.com/aida-public/AB6AXuCnamYfvjqz7ywTpJXqFsBDa531N5N8vTrakSeyWRrwlbyok_WrLTsL3i6H93rKALadJmOp1Tzu3kMCnI-VdqYMc0u-crZZC-xoONLBYL2ke4o98-acqZllI_OA4J6BXJGfaSeMUM8qY1XLH-TZG0WVnNANBVPtfNROo2XWehg5JcaJJhQ0hQRyE7Nq6cG63OOoO-Os2ZIsfnd5_Lc22kWe3_g7kjUX8V3CcaHCsUj0XDHH5B1ouwBUSExQ_3QRTU10-2fX9nnyJzPO';

	return <<<BLOCK
<!-- wp:group {"className":"min-h-[calc(100vh-6rem)] flex flex-col justify-center px-6 md:px-20 mb-20","layout":{"type":"constrained"}} -->
<div class="wp-block-group min-h-[calc(100vh-6rem)] flex flex-col justify-center px-6 md:px-20 mb-20">
<!-- wp:columns {"className":"gap-6 items-end md:items-center"} -->
<div class="wp-block-columns gap-6 items-end md:items-center">
<!-- wp:column {"width":"58.33%"} -->
<div class="wp-block-column" style="flex-basis:58.33%">
<!-- wp:heading {"level":1,"className":"font-barrio text-6xl md:text-8xl text-primary leading-none mb-8 -rotate-1 origin-left"} -->
<h1 class="font-barrio text-6xl md:text-8xl text-primary leading-none mb-8 -rotate-1 origin-left">Our tiny house eco village</h1>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"font-headline text-2xl md:text-3xl italic text-tertiary max-w-xl mb-12"} -->
<p class="font-headline text-2xl md:text-3xl italic text-tertiary max-w-xl mb-12">Unique tiny houses and other out-of-the-ordinary living spaces. A little place to call home.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column {"width":"41.67%"} -->
<div class="wp-block-column" style="flex-basis:41.67%">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"aspect-[4/5] rounded-xl overflow-hidden editorial-shadow"} -->
<figure class="wp-block-image size-large aspect-[4/5] rounded-xl overflow-hidden editorial-shadow"><img src="$hero" alt="Tiny house in cork forest"/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->

<!-- wp:group {"className":"bg-surface-container-low py-32 px-6 md:px-20 overflow-hidden mb-32","layout":{"type":"constrained"}} -->
<div class="wp-block-group bg-surface-container-low py-32 px-6 md:px-20 overflow-hidden mb-32">
<!-- wp:columns {"className":"gap-20 items-center"} -->
<div class="wp-block-columns gap-20 items-center">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"relative z-10 aspect-square rounded-full border-[1.5rem] border-surface-container-high overflow-hidden"} -->
<figure class="wp-block-image size-large relative z-10 aspect-square rounded-full border-[1.5rem] border-surface-container-high overflow-hidden"><img src="$phil" alt="Alentejo hills"/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":2,"className":"font-headline text-5xl font-bold text-on-surface mb-8"} -->
<h2 class="font-headline text-5xl font-bold text-on-surface mb-8">A little home of your own<br/>To stay as long as you like</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-xl text-on-surface-variant leading-relaxed"} -->
<p class="text-xl text-on-surface-variant leading-relaxed">The Village is the more community-like part of our project, and consists of a wonderfully diverse crowd of fantastic humans of all sorts of different backgrounds, cultures and ages.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"text-xl text-on-surface-variant leading-relaxed"} -->
<p class="text-xl text-on-surface-variant leading-relaxed">Here we offer plots for people to join us for a longer term in their own tiny houses and all sorts of other unique living spaces. What is important to us is that it's built in a sustainable way, that it doesn't have a concrete foundation, and that it's beautiful, unique, weird or absolutely fantastic.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"text-xl text-on-surface-variant leading-relaxed"} -->
<p class="text-xl text-on-surface-variant leading-relaxed">Every now and then we have some plots available for sale or for long-term-rental in our little village. If you'd like to be considered for one of these spots, please come and visit us and get to know us and this beautiful land.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->

<!-- wp:group {"className":"py-32 px-6 md:px-20 mb-32","layout":{"type":"constrained"}} -->
<div class="wp-block-group py-32 px-6 md:px-20 mb-32">
<!-- wp:heading {"level":2,"className":"font-headline text-5xl text-primary mb-4 text-center"} -->
<h2 class="font-headline text-5xl text-primary mb-4 text-center">How to join our little village</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"font-headline italic text-2xl text-tertiary text-center mb-20"} -->
<p class="font-headline italic text-2xl text-tertiary text-center mb-20">There are three ways in which to join our tiny house village</p>
<!-- /wp:paragraph -->
<!-- wp:columns {"className":"gap-8 max-w-7xl mx-auto"} -->
<div class="wp-block-columns gap-8 max-w-7xl mx-auto">
<!-- wp:column {"className":"bg-surface-container rounded-xl p-8 editorial-shadow"} -->
<div class="wp-block-column bg-surface-container rounded-xl p-8 editorial-shadow">
<!-- wp:heading {"level":3,"className":"font-headline text-3xl font-bold mb-4"} -->
<h3 class="font-headline text-3xl font-bold mb-4">Tiny House Rental</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"font-body text-lg text-on-surface-variant mb-8"} -->
<p class="font-body text-lg text-on-surface-variant mb-8">Always wanted to stay in a tiny house, a converted double decker or a boat? Find here our out-of-the-ordinary stays, available for short- and long term rental.</p>
<!-- /wp:paragraph -->
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"aspect-video rounded-lg overflow-hidden mb-8"} -->
<figure class="wp-block-image size-large aspect-video rounded-lg overflow-hidden mb-8"><img src="$p1" alt="Plot for tiny home"/></figure>
<!-- /wp:image -->
<!-- wp:paragraph -->
<p><a class="text-primary font-headline font-bold text-xl" href="{$u}booking/">Find the available rentals</a></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column {"className":"bg-surface-container rounded-xl p-8 editorial-shadow md:scale-105 z-10"} -->
<div class="wp-block-column bg-surface-container rounded-xl p-8 editorial-shadow md:scale-105 z-10">
<!-- wp:heading {"level":3,"className":"font-headline text-3xl font-bold mb-4"} -->
<h3 class="font-headline text-3xl font-bold mb-4">5-year-lease</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"font-body text-lg text-on-surface-variant mb-8"} -->
<p class="font-body text-lg text-on-surface-variant mb-8">Have you fallen in love with our project and people, and want to put down some roots with us? Check here for 5-year-lease spots to put down your own tiny home.</p>
<!-- /wp:paragraph -->
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"aspect-video rounded-lg overflow-hidden mb-8"} -->
<figure class="wp-block-image size-large aspect-video rounded-lg overflow-hidden mb-8"><img src="$p2" alt="Tiny house interior"/></figure>
<!-- /wp:image -->
<!-- wp:buttons -->
<div class="wp-block-buttons">
<!-- wp:button {"className":"bg-primary text-on-primary px-8 py-3 rounded-full font-label font-semibold w-full"} -->
<div class="wp-block-button bg-primary text-on-primary px-8 py-3 rounded-full font-label font-semibold w-full"><a class="wp-block-button__link wp-element-button" href="{$u}contact/">Get in touch</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
</div>
<!-- /wp:column -->
<!-- wp:column {"className":"bg-surface-container rounded-xl p-8 editorial-shadow"} -->
<div class="wp-block-column bg-surface-container rounded-xl p-8 editorial-shadow">
<!-- wp:heading {"level":3,"className":"font-headline text-3xl font-bold mb-4"} -->
<h3 class="font-headline text-3xl font-bold mb-4">Plot Purchase</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"font-body text-lg text-on-surface-variant mb-8"} -->
<p class="font-body text-lg text-on-surface-variant mb-8">Every now and then we have plots in our little village available for sale. If you're interested, please get in touch with us.</p>
<!-- /wp:paragraph -->
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"aspect-video rounded-lg overflow-hidden mb-8"} -->
<figure class="wp-block-image size-large aspect-video rounded-lg overflow-hidden mb-8"><img src="$p3" alt="Villa at sunset"/></figure>
<!-- /wp:image -->
<!-- wp:paragraph -->
<p><a class="text-primary font-headline font-bold text-xl" href="{$u}contact/">Here</a></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->

<!-- wp:group {"className":"py-32 bg-white/30 mb-32","layout":{"type":"constrained"}} -->
<div class="wp-block-group py-32 bg-white/30 mb-32">
<!-- wp:columns {"className":"gap-8 items-center px-6 md:px-20"} -->
<div class="wp-block-columns gap-8 items-center px-6 md:px-20">
<!-- wp:column {"width":"33.33%"} -->
<div class="wp-block-column" style="flex-basis:33.33%">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"aspect-square rounded-[3rem] overflow-hidden mb-8 rotate-3 editorial-shadow"} -->
<figure class="wp-block-image size-large aspect-square rounded-[3rem] overflow-hidden mb-8 rotate-3 editorial-shadow"><img src="$com" alt="Communal meal"/></figure>
<!-- /wp:image -->
<!-- wp:group {"className":"bg-secondary-container/50 p-8 rounded-xl"} -->
<div class="wp-block-group bg-secondary-container/50 p-8 rounded-xl">
<!-- wp:heading {"level":4,"className":"font-headline text-2xl font-bold mb-2"} -->
<h4 class="font-headline text-2xl font-bold mb-2">Communal Spirit</h4>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-on-secondary-container"} -->
<p class="text-on-secondary-container">Weekly harvests, shared fire pits, and seasonal celebrations form the heartbeat of our village life.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->
<!-- wp:column {"width":"66.67%"} -->
<div class="wp-block-column" style="flex-basis:66.67%">
<!-- wp:heading {"level":2,"className":"font-headline text-6xl text-on-surface mb-8"} -->
<h2 class="font-headline text-6xl text-on-surface mb-8">The Landscape is <br/> our living room.</h2>
<!-- /wp:heading -->
<!-- wp:columns {"className":"gap-8"} -->
<div class="wp-block-columns gap-8">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"aspect-[3/2] rounded-xl overflow-hidden mb-4 editorial-shadow"} -->
<figure class="wp-block-image size-large aspect-[3/2] rounded-xl overflow-hidden mb-4 editorial-shadow"><img src="$for1" alt="Forest paths"/></figure>
<!-- /wp:image -->
<!-- wp:heading {"level":5,"className":"font-headline text-xl font-bold"} -->
<h5 class="font-headline text-xl font-bold">Sacred Forest Paths</h5>
<!-- /wp:heading -->
</div>
<!-- /wp:column -->
<!-- wp:column {"className":"mt-12 md:mt-24"} -->
<div class="wp-block-column mt-12 md:mt-24">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"aspect-[3/2] rounded-xl overflow-hidden mb-4 editorial-shadow"} -->
<figure class="wp-block-image size-large aspect-[3/2] rounded-xl overflow-hidden mb-4 editorial-shadow"><img src="$for2" alt="Natural swimming pool"/></figure>
<!-- /wp:image -->
<!-- wp:heading {"level":5,"className":"font-headline text-xl font-bold"} -->
<h5 class="font-headline text-xl font-bold">Natural River Basins</h5>
<!-- /wp:heading -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->

<!-- wp:group {"className":"py-32 px-6 md:px-20 text-center","layout":{"type":"constrained"}} -->
<div class="wp-block-group py-32 px-6 md:px-20 text-center">
<!-- wp:group {"className":"max-w-4xl mx-auto bg-surface-container-high rounded-[4rem] p-12 md:p-24 relative overflow-hidden"} -->
<div class="wp-block-group max-w-4xl mx-auto bg-surface-container-high rounded-[4rem] p-12 md:p-24 relative overflow-hidden">
<!-- wp:heading {"level":2,"className":"font-headline text-5xl md:text-7xl text-primary mb-8"} -->
<h2 class="font-headline text-5xl md:text-7xl text-primary mb-8">Ready to come home?</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"font-body text-2xl italic text-tertiary mb-12"} -->
<p class="font-body text-2xl italic text-tertiary mb-12">Join our waitlist for the next phase of Village development.</p>
<!-- /wp:paragraph -->
$sc
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->
BLOCK;
}

/**
 * Campus — full page (brochure-website/campus.html).
 *
 * @param int $cf7 Host form ID.
 * @return string
 */
function vila_akoya_blocks_campus( $cf7 ) {
	$u    = esc_url( home_url( '/' ) );
	$sc   = vila_akoya_block_cf7( $cf7 );
	$hero = 'https://lh3.googleusercontent.com/aida-public/AB6AXuDkeVBlzSRkYoA2PRW_04xDyX_sT3yS2p7Q2H4WgzWz3v9vxxFRl-8_lpuXAl-LoekYqmgmBY6fpMdKa25aZc0vzIQf1nT6GGWzU2tmHdNjgplbIKyDCZiXPFwNBxo-zr62PpHoZPVpilp5SmfKTwY9LCljy451rK6Al36LlrhEnBbM0QdnaPSrS6K_SxNmSTkc17Drgx7lvosGNSW-L6Ko5ZwZNUBoW4vs6kyfEVtDgwl9sImnC8O0OHrjiLhjPdp4vC6kUgPFlQyo';
	$uj1  = 'https://lh3.googleusercontent.com/aida-public/AB6AXuCDZdwXE8CWT-V_feq70zM4vc0OrqWGeyNFiWc-3Uu0a63Xe-J_MjjWdpjjnK2jQRigvf_nf6uU8BEu36BlEpoDW-z8Sb-_DWzxS9lvLBmxBY6BnzdM15F9z9On_WPTqrP2b0G9otDFmS4wNnLOoVJLglwCp-lLPMfDkvVbSq6LK3SU9VtfszdF1KHh7iYjFM_G0PmZtNVGgow1NNvHujNSlTGbBM2SyNkbVdwr25J0jPegRtHosiSuEvfE_Ha7MiqBSY6iptvL0JEn';
	$uj2  = 'https://lh3.googleusercontent.com/aida-public/AB6AXuD4DSUcSG-yI35ltZmRQQHN78dyLSjnvK26OatIaXMbv__MJXUzyKvJSLQ55K8vVp0c3Yr1rcfwqEQQYmXHDcmnSIgSTWFT9kj6zbNotPWBsLanBu7-Ukn-uZOkZ0XyB2EL8IlNmurA99O0ZFDxbA2rLlsIz8ObUZZNkNO85V13G6kAjHJaOnUzpaVE4hqVlP_HfLhdCn-EvCmjFKwx9hWBFoXxSs3EhdsvlxA65Ck2wzXt9TZeZs6Sd_HVas5nNw1MrQkI3J6g8976';
	$earn = 'https://lh3.googleusercontent.com/aida-public/AB6AXuAjiHintXKg8g-xsSKCjtKKPn_FSZ6ZOt8B5jNPdyHQAWDBf62tkQCQ6sZmMHWIgYamd7Yk1_tt4QXwKGQVnWog3YmI8f5YbT4P6H9KDA8bBTUtWb3Z3IwVozRrkGbUI_GGocbbK2BTCDkqMegRpfdgnzu20-HByILByIUOJwjCSrxpfFD50DqFOuRiNiopUFu4HZueiHPW2zJgxDIvryzGKijBIvPfPObwLNOmoYdxFwyb8xnx_n-WMX9y8g2FmzQoDXQu7L3T5Chj';
	$forimg = 'https://lh3.googleusercontent.com/aida-public/AB6AXuBZtrhITMbN4VqyTGDYxNNcIDFc6pn98iB_29RJ6OAmxNTPuI8jt3fJPZjJApIihScJ5t9cL9vfi0pMqkVdZIxDEnj4oJhl-YS8AAR8cVTvjX7GMVV9PynaRxptZRqku2J8uxSO5BkpnEbZx9pd7REZEXs0McS-G8-Hd5M3NfL84ikmVm1lmOR3izv8hKRGvT0aHjzoT-t6grr-sdWnFM7IGfidRvbC82RxOkGkC6ayEXSc79nxsAyGwz-hi9bmFtxrguV4CvPAruhT';

	return <<<BLOCK
<!-- wp:group {"className":"relative min-h-[calc(100vh-6rem)] flex flex-col justify-center px-6 md:px-12 py-12 md:py-0 max-w-screen-2xl mx-auto overflow-hidden","layout":{"type":"constrained"}} -->
<div class="wp-block-group relative min-h-[calc(100vh-6rem)] flex flex-col justify-center px-6 md:px-12 py-12 md:py-0 max-w-screen-2xl mx-auto overflow-hidden">
<!-- wp:columns {"className":"gap-12 items-center"} -->
<div class="wp-block-columns gap-12 items-center">
<!-- wp:column {"width":"58.33%"} -->
<div class="wp-block-column" style="flex-basis:58.33%">
<!-- wp:paragraph {"className":"inline-block px-4 py-1 rounded-full bg-secondary-container text-on-secondary-container text-sm font-label mb-6"} -->
<p class="inline-block px-4 py-1 rounded-full bg-secondary-container text-on-secondary-container text-sm font-label mb-6">Creative Sanctuaries</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"level":1,"className":"text-6xl md:text-8xl font-normal font-barrio text-primary leading-none mb-8"} -->
<h1 class="text-6xl md:text-8xl font-normal font-barrio text-primary leading-none mb-8">The Heart of <br/>The Campus</h1>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-xl md:text-2xl font-light text-on-surface-variant max-w-xl leading-relaxed mb-12 italic"} -->
<p class="text-xl md:text-2xl font-light text-on-surface-variant max-w-xl leading-relaxed mb-12 italic">"An inviting workshop space where the scent of sawdust meets the cool touch of wet clay. Here, we don't just teach; we remember."</p>
<!-- /wp:paragraph -->
<!-- wp:buttons -->
<div class="wp-block-buttons flex flex-wrap gap-6">
<!-- wp:button {"className":"px-8 py-4 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-xl font-medium shadow-lg"} -->
<div class="wp-block-button px-8 py-4 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-xl font-medium shadow-lg"><a class="wp-block-button__link wp-element-button" href="{$u}campus/#upcoming-journeys">Explore Workshops</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
</div>
<!-- /wp:column -->
<!-- wp:column {"width":"41.67%"} -->
<div class="wp-block-column" style="flex-basis:41.67%">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"aspect-[4/5] rounded-xl overflow-hidden shadow-2xl relative rotate-2"} -->
<figure class="wp-block-image size-large aspect-[4/5] rounded-xl overflow-hidden shadow-2xl relative rotate-2"><img src="$hero" alt="Campus pottery studio"/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->

<!-- wp:group {"className":"py-24 bg-surface-container-low","layout":{"type":"constrained"}} -->
<div class="wp-block-group py-24 bg-surface-container-low">
<!-- wp:heading {"level":2,"className":"text-5xl font-normal font-headline text-tertiary mb-4 text-center"} -->
<h2 class="text-5xl font-normal font-headline text-tertiary mb-4 text-center">Nurture Every Aspect</h2>
<!-- /wp:heading -->
<!-- wp:columns {"className":"max-w-screen-2xl mx-auto px-6 md:px-12 gap-12"} -->
<div class="wp-block-columns max-w-screen-2xl mx-auto px-6 md:px-12 gap-12">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":3,"className":"text-3xl font-normal font-headline text-on-surface"} -->
<h3 class="text-3xl font-normal font-headline text-on-surface">The Earth</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-on-surface-variant leading-relaxed text-lg"} -->
<p class="text-on-surface-variant leading-relaxed text-lg">Pottery, soil health, and natural pigments. Working with the Alentejo clay is a meditative journey back to the roots of the peninsula.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":3,"className":"text-3xl font-normal font-headline text-on-surface"} -->
<h3 class="text-3xl font-normal font-headline text-on-surface">The Craft</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-on-surface-variant leading-relaxed text-lg"} -->
<p class="text-on-surface-variant leading-relaxed text-lg">Woodwork, weaving, and metalwork. We value the patience of the hand and the honesty of raw, sustainably sourced materials.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":3,"className":"text-3xl font-normal font-headline text-on-surface"} -->
<h3 class="text-3xl font-normal font-headline text-on-surface">The Spirit</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-on-surface-variant leading-relaxed text-lg"} -->
<p class="text-on-surface-variant leading-relaxed text-lg">Movement, meditation, and storytelling. Aligning the creative body with the rhythm of the seasons and the cycle of the moon.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->

<!-- wp:group {"className":"py-24 px-6 md:px-12 max-w-screen-2xl mx-auto","layout":{"type":"constrained"}} -->
<div class="wp-block-group py-24 px-6 md:px-12 max-w-screen-2xl mx-auto" id="upcoming-journeys">
<!-- wp:heading {"level":2,"className":"text-4xl md:text-5xl font-headline font-bold mb-16 text-center text-on-surface"} -->
<h2 class="text-4xl md:text-5xl font-headline font-bold mb-16 text-center text-on-surface">Upcoming Journeys</h2>
<!-- /wp:heading -->
<!-- wp:columns {"className":"gap-8"} -->
<div class="wp-block-columns gap-8">
<!-- wp:column {"width":"66.67%"} -->
<div class="wp-block-column" style="flex-basis:66.67%">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"rounded-xl overflow-hidden aspect-[16/9] shadow-lg"} -->
<figure class="wp-block-image size-large rounded-xl overflow-hidden aspect-[16/9] shadow-lg"><img src="$uj1" alt="Ancestral pottery"/></figure>
<!-- /wp:image -->
<!-- wp:paragraph {"className":"text-sm font-label tracking-widest uppercase text-primary mb-2"} -->
<p class="text-sm font-label tracking-widest uppercase text-primary mb-2">12–18 October</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"level":3,"className":"text-4xl font-headline font-semibold mb-2 text-on-surface"} -->
<h3 class="text-4xl font-headline font-semibold mb-2 text-on-surface">Ancestral Pottery</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-on-surface-variant"} -->
<p class="text-on-surface-variant">Unearth the secrets of Alentejo pit-firing and coiling techniques.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column {"width":"33.33%"} -->
<div class="wp-block-column" style="flex-basis:33.33%">
<!-- wp:group {"className":"bg-surface-container rounded-xl p-8 h-full"} -->
<div class="wp-block-group bg-surface-container rounded-xl p-8 h-full">
<!-- wp:paragraph {"className":"text-primary font-label text-sm uppercase tracking-widest mb-4"} -->
<p class="text-primary font-label text-sm uppercase tracking-widest mb-4">Weekend Retreat</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"level":3,"className":"text-3xl font-headline font-bold text-on-surface mb-4 leading-tight"} -->
<h3 class="text-3xl font-headline font-bold text-on-surface mb-4 leading-tight">Natural Flow: Movement &amp; Ink</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-on-surface-variant"} -->
<p class="text-on-surface-variant">A somatic exploration pairing dance with large-scale charcoal drawing.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"mt-8"} -->
<p class="mt-8"><a class="text-tertiary font-semibold underline" href="{$u}booking/">Learn more</a></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
<!-- wp:columns {"className":"gap-8 mt-8"} -->
<div class="wp-block-columns gap-8 mt-8">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"rounded-xl overflow-hidden aspect-square shadow-lg"} -->
<figure class="wp-block-image size-large rounded-xl overflow-hidden aspect-square shadow-lg"><img src="$uj2" alt="Willow weaving"/></figure>
<!-- /wp:image -->
<!-- wp:heading {"level":3,"className":"text-2xl font-headline font-bold text-on-surface"} -->
<h3 class="text-2xl font-headline font-bold text-on-surface">Willow Weaving</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-sm text-on-surface-variant"} -->
<p class="text-sm text-on-surface-variant">Traditional basketry for modern lives.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:group {"className":"bg-secondary-container/20 border border-secondary/10 rounded-xl p-8"} -->
<div class="wp-block-group bg-secondary-container/20 border border-secondary/10 rounded-xl p-8">
<!-- wp:heading {"level":3,"className":"text-3xl font-headline font-bold text-secondary mb-4"} -->
<h3 class="text-3xl font-headline font-bold text-secondary mb-4">Forest Architecture</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-on-secondary-container text-lg italic"} -->
<p class="text-on-secondary-container text-lg italic">"Build with the trees, not against them. A deep dive into sustainable woodcrafting and cabin design."</p>
<!-- /wp:paragraph -->
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"rounded-full w-32 h-32 mt-6"} -->
<figure class="wp-block-image size-large rounded-full w-32 h-32 mt-6"><img src="$forimg" alt="Woodcraft"/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->

<!-- wp:group {"className":"py-32 relative overflow-hidden bg-white","layout":{"type":"constrained"}} -->
<div class="wp-block-group py-32 relative overflow-hidden bg-white">
<!-- wp:group {"className":"max-w-screen-xl mx-auto px-6 md:px-12"} -->
<div class="wp-block-group max-w-screen-xl mx-auto px-6 md:px-12">
<!-- wp:group {"className":"bg-surface-container-low rounded-xl p-12 md:p-20 shadow-2xl flex flex-col md:flex-row items-center gap-16"} -->
<div class="wp-block-group bg-surface-container-low rounded-xl p-12 md:p-20 shadow-2xl flex flex-col md:flex-row items-center gap-16">
<!-- wp:group {"className":"flex-1"} -->
<div class="wp-block-group flex-1">
<!-- wp:heading {"level":2,"className":"text-4xl md:text-5xl font-headline text-primary mb-8"} -->
<h2 class="text-4xl md:text-5xl font-headline text-primary mb-8">Earn Your Stay</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-xl text-on-surface-variant font-light italic"} -->
<p class="text-xl text-on-surface-variant font-light italic">Are you a master of a craft, a keeper of ancient seeds, or a guide for the soul? Our community thrives on the circular exchange of wisdom.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"text-xl text-on-surface-variant font-light italic"} -->
<p class="text-xl text-on-surface-variant font-light italic">Join our <strong>Skillshare Exchange</strong>. In exchange for hosting daily workshops or maintaining our gardens, residents can enjoy a reduced stay or full scholarship at Vila Akoya.</p>
<!-- /wp:paragraph -->
<!-- wp:buttons -->
<div class="wp-block-buttons mt-10">
<!-- wp:button {"className":"px-10 py-5 bg-tertiary text-on-tertiary rounded-xl font-bold shadow-md"} -->
<div class="wp-block-button px-10 py-5 bg-tertiary text-on-tertiary rounded-xl font-bold shadow-md"><a class="wp-block-button__link wp-element-button" href="{$u}contact/">Propose an Exchange</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
</div>
<!-- /wp:group -->
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"w-64 h-80 rounded-[4rem] overflow-hidden border-8 border-white shadow-xl rotate-6"} -->
<figure class="wp-block-image size-large w-64 h-80 rounded-[4rem] overflow-hidden border-8 border-white shadow-xl rotate-6"><img src="$earn" alt="Creative workshop"/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->

<!-- wp:group {"className":"py-24 px-6 md:px-12 max-w-screen-xl mx-auto","layout":{"type":"constrained"}} -->
<div class="wp-block-group py-24 px-6 md:px-12 max-w-screen-xl mx-auto">
<!-- wp:heading {"level":2,"className":"text-5xl font-headline text-on-surface mb-6 text-center"} -->
<h2 class="text-5xl font-headline text-on-surface mb-6 text-center">Host Your Own</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"text-on-surface-variant max-w-2xl mx-auto text-lg text-center mb-12"} -->
<p class="text-on-surface-variant max-w-2xl mx-auto text-lg text-center mb-12">Looking for a sanctuary to host your retreat? The Campus is open to outside facilitators who share our ethos of connection and creation.</p>
<!-- /wp:paragraph -->
<!-- wp:group {"className":"max-w-3xl mx-auto bg-surface-container rounded-xl p-8 md:p-12 shadow-sm"} -->
<div class="wp-block-group max-w-3xl mx-auto bg-surface-container rounded-xl p-8 md:p-12 shadow-sm">
$sc
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->
BLOCK;
}
