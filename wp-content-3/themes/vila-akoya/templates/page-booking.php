
<?php get_header(); ?>
<section class="page-hero simple-hero">
  <div class="ak-container grid-two align-center">
    <div>
      <span class="eyebrow">Booking & Enquiries</span>
      <h1>Reserve your place in the rhythm.</h1>
      <p>For V1 this is an enquiry-led booking flow for campsite plots, stays, workshops, and general interest.</p>
      <ul class="bullet-list"><li>Campsite plot enquiries</li><li>Village interest / waitlist</li><li>Workshop and campus enquiries</li><li>Forest school visits</li></ul>
    </div>
    <div><img class="rounded-hero" src="<?php echo esc_url(vila_akoya_asset('assets/images/campsite-page.jpg')); ?>" alt="Booking"></div>
  </div>
</section>
<section class="ak-container booking-grid">
  <div>
    <?php vila_akoya_section_intro('How it works', 'Tell us what you are looking for', 'Share your ideal dates, number of people, and what part of Vila Akoya interests you. This can later be upgraded into a fuller booking engine.'); ?>
  </div>
  <div><?php vila_akoya_render_enquiry_form(); ?></div>
</section>
<?php get_footer(); ?>
