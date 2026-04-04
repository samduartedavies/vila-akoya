<?php
/**
 * CLI: php scripts/brochure-to-blocks.php
 * Reads brochure-website/*.html and writes inc/page-content/{slug}.txt block markup.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
// Theme: wp-content/themes/vila-akoya → repo root is three levels up.
$brochure = dirname($root, 3) . '/brochure-website';
$outDir = $root . '/inc/page-content';
if (! is_dir($outDir)) {
	mkdir($outDir, 0775, true);
}

$map = [
	'home.html'       => 'home',
	'about.html'      => 'about',
	'village.html'    => 'village',
	'campsite.html'   => 'campsite',
	'booking.html'    => 'booking',
	'campus.html'     => 'campus',
	'the_kids.html'   => 'the-kids',
	'contact.html'    => 'contact',
	'thank-you.html'  => 'thank-you',
];

$formPlaceholders = [
	'home.html'     => [ '/<form\b[^>]*>.*?<\/form>/is' => '%%VILA_CF7_HOME_NEWSLETTER%%' ],
	'village.html'  => [ '/<form\b[^>]*>.*?<\/form>/is' => '%%VILA_CF7_VILLAGE_WAITLIST%%' ],
	'campus.html'   => [ '/<form\b[^>]*>.*?<\/form>/is' => '%%VILA_CF7_CAMPUS_HOST%%' ],
	'booking.html'  => [ '/<form\b[^>]*>.*?<\/form>/is' => '%%VILA_CF7_BOOKING%%' ],
	'contact.html'  => [ '/<form\b[^>]*>.*?<\/form>/is' => '%%VILA_CF7_CONTACT%%' ],
];

foreach ($map as $file => $slug) {
	$path = $brochure . '/' . $file;
	if (! is_readable($path)) {
		fwrite(STDERR, "Skip missing: $path\n");
		continue;
	}
	$html = file_get_contents($path);
	if (! preg_match('/<main\b[^>]*>(.*)<\/main>/is', $html, $m)) {
		fwrite(STDERR, "No main in $file\n");
		continue;
	}
	$inner = trim($m[1]);

	// Theme image URLs (setup replaces THEME_URI).
	$inner = str_replace('src="/images/', 'src="THEME_URI/assets/images/', $inner);
	$inner = preg_replace('/href="\/(?!\/)([^"]*)"/', 'href="/$1/"', $inner) ?? $inner;

	if (isset($formPlaceholders[ $file ])) {
		foreach ($formPlaceholders[ $file ] as $pattern => $replacement) {
			$inner = preg_replace($pattern, '<div class="vila-cf7-wrap">' . $replacement . '</div>', $inner, 1) ?? $inner;
		}
	}

	// Strip inline scripts tied to old forms.
	$inner = preg_replace('/<script>\s*\(\s*function\s*\(\)\s*\{[^<]*booking-form[^<]*<\/script>/is', '', $inner) ?? $inner;
	$inner = preg_replace('/<script>\s*\(\s*function\s*\(\)\s*\{[^<]*campus-form[^<]*<\/script>/is', '', $inner) ?? $inner;
	$inner = preg_replace('/<script>\s*\(\s*function\s*\(\)\s*\{[^<]*contact-form[^<]*<\/script>/is', '', $inner) ?? $inner;
	$inner = preg_replace('/<script>\s*\(\s*function\s*\(\)\s*\{[^<]*home-newsletter[^<]*<\/script>/is', '', $inner) ?? $inner;
	$inner = preg_replace('/<script>\s*\(\s*function\s*\(\)\s*\{[^<]*village-newsletter[^<]*<\/script>/is', '', $inner) ?? $inner;

	// Split into sections for manageable HTML blocks.
	$parts = preg_split('/(?=<section\b)/i', $inner, -1, PREG_SPLIT_NO_EMPTY);
	$blocks = [];
	foreach ($parts as $part) {
		$part = trim($part);
		if ($part === '') {
			continue;
		}
		// Shortcode placeholders need a shortcode block, not HTML.
		if (str_contains($part, '%%VILA_CF7_')) {
			$segments = preg_split('/(<div class="vila-cf7-wrap">%%VILA_CF7_[A-Z0-9_]+%%<\/div>)/', $part, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
			foreach ($segments as $seg) {
				$seg = trim($seg);
				if ($seg === '') {
					continue;
				}
				if (preg_match('/^<div class="vila-cf7-wrap">(%%VILA_CF7_[A-Z0-9_]+%%)<\/div>$/', $seg, $mm)) {
					$blocks[] = [ 'type' => 'shortcode', 'content' => $mm[1] ];
				} else {
					$blocks[] = [ 'type' => 'html', 'content' => $seg ];
				}
			}
			continue;
		}
		$blocks[] = [ 'type' => 'html', 'content' => $part ];
	}

	$serialized = '';
	foreach ($blocks as $b) {
		if ($b['type'] === 'html') {
			$serialized .= "<!-- wp:html -->\n" . $b['content'] . "\n<!-- /wp:html -->\n\n";
		} else {
			$token = $b['content'];
			$serialized .= "<!-- wp:shortcode -->\n" . $token . "\n<!-- /wp:shortcode -->\n\n";
		}
	}

	file_put_contents($outDir . '/' . $slug . '.txt', $serialized);
	echo "Wrote $slug\n";
}

echo "Done.\n";
