
<?php
if (!defined('ABSPATH')) { exit; }

function vila_akoya_default_pages() {
    return [
        'home' => [
            'title' => 'Home',
            'template' => 'templates/page-home.php',
            'excerpt' => 'A sanctuary for the modern nomad, nestled in the heart of the Alentejo.',
            'content' => "Welcome to Vila Akoya. A place to breathe, create, and belong.",
        ],
        'village' => [
            'title' => 'Village',
            'template' => 'templates/page-village.php',
            'excerpt' => 'A curated community where the Alentejo soil meets architectural soul.',
            'content' => "The Village isn't just a place to live; it's a living ecosystem of quiet routines, shared tables, and thoughtful spaces.",
        ],
        'campsite' => [
            'title' => 'Campsite',
            'template' => 'templates/page-campsite.php',
            'excerpt' => 'A campsite for dreamers, makers, and wandering souls.',
            'content' => "Bring your van, your tent, and your curiosity. This is a slower, warmer way to travel.",
        ],
        'booking' => [
            'title' => 'Booking',
            'template' => 'templates/page-booking.php',
            'excerpt' => 'Register your interest for campsite plots, stays, and workshops.',
            'content' => "We are opening in May and would love to start hearing from future guests now.",
        ],
        'about' => [
            'title' => 'About Us',
            'template' => 'templates/page-about.php',
            'excerpt' => 'A personable, rooted story about the people and the vision behind Vila Akoya.',
            'content' => "Vila Akoya is an experiment in grounded, beautiful living: earth, creativity, education, and community woven together.",
        ],
        'contact' => [
            'title' => 'Contact',
            'template' => 'templates/page-contact.php',
            'excerpt' => 'Get in touch with the Vila Akoya team.',
            'content' => "Email us at info@vila-akoya.com or message us on WhatsApp.",
        ],
        'campus' => [
            'title' => 'The Campus',
            'template' => 'templates/page-campus.php',
            'excerpt' => 'Workshops, gatherings, and practical learning in an open-air setting.',
            'content' => "The Campus is where making, learning, and shared rhythm come together.",
        ],
        'forest-school' => [
            'title' => 'Forest School',
            'template' => 'templates/page-forest-school.php',
            'excerpt' => 'Where the cork oaks become classrooms and the seasons guide the curriculum.',
            'content' => "A homeschooling collective and nature-led learning environment for children and families.",
        ],
    ];
}
