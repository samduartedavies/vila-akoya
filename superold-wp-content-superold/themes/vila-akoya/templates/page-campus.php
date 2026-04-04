
<?php get_header(); ?>
<section class="page-hero grid-hero">
  <div class="ak-container grid-two align-center">
    <div>
      <span class="eyebrow">The Campus</span>
      <h1>Workshops, practice, and shared learning.</h1>
      <p>An open-air hub for hands-on education, seasonal gatherings, and collaborative making.</p>
    </div>
    <div><img class="rounded-hero" src="<?php echo esc_url(vila_akoya_asset('assets/images/campus-page.jpg')); ?>" alt="Campus"></div>
  </div>
</section>
<section class="ak-container">
  <?php vila_akoya_section_intro('Programming', 'Designed for workshops and events', 'The Campus can evolve into a timetable-led experience later, but V1 focuses on the atmosphere, the offer, and enquiry capture.', true); ?>
  <div class="card-grid three-up">
    <article class="offer-card"><h3>Creative residencies</h3><p>Space for artists, makers, and independent practitioners.</p></article>
    <article class="offer-card"><h3>Weekend immersions</h3><p>Small-group gatherings with strong visual identity and warm hospitality.</p></article>
    <article class="offer-card"><h3>Land-based learning</h3><p>Practical, ecological skills woven into everyday life.</p></article>
  </div>
</section>
<section class="cta-panel-wrap ak-container">
  <div class="cta-panel dark-image" style="--hero-image:url('<?php echo esc_url(vila_akoya_asset('assets/images/campus-page.jpg')); ?>');">
    <div>
      <h2>Want to host something here?</h2>
      <p>Use the booking form to request a workshop, retreat, or campus visit.</p>
      <?php vila_akoya_render_button('Start an enquiry', home_url('/booking/')); ?>
    </div>
  </div>
</section>
<?php get_footer(); ?>
