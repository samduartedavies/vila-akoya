
<?php get_header(); ?>
<section class="page-hero grid-hero">
  <div class="ak-container grid-two align-center">
    <div>
      <span class="eyebrow">Forest School</span>
      <h1>Where the ancient cork oaks become classrooms.</h1>
      <p>Learning in nature, guided by rhythm, curiosity, and close attention to the living world.</p>
    </div>
    <div><img class="rounded-hero" src="<?php echo esc_url(vila_akoya_asset('assets/images/forest-school.jpg')); ?>" alt="Forest School"></div>
  </div>
</section>
<section class="soft-panel-wrap ak-container">
  <div class="soft-panel grid-two align-center">
    <div><img class="rounded-hero tilt" src="<?php echo esc_url(vila_akoya_asset('assets/images/forest-school.jpg')); ?>" alt="Homeschooling collective"></div>
    <div>
      <?php vila_akoya_section_intro('', 'The Homeschooling Collective', 'A supportive ecosystem for families dedicated to alternative education. From collaborative projects to shared resources, the collective operates on trust, autonomy, and a belief that every child is a natural researcher.'); ?>
      <?php vila_akoya_render_button('Join the Circle', home_url('/contact/'), 'button-link'); ?>
    </div>
  </div>
</section>
<section class="ak-container">
  <?php vila_akoya_section_intro('', 'Daily Extracurriculars', '', true); ?>
  <div class="card-grid three-up">
    <article class="offer-card soft"><h3>Manual crafts</h3><p>Pottery, weaving, wood carving, and tactile making.</p></article>
    <article class="offer-card mint"><h3>Eco-tracking</h3><p>Reading animal signs, forest patterns, and the language of birds.</p></article>
    <article class="offer-card peach"><h3>Garden-to-table</h3><p>Harvesting from permaculture gardens and preparing wholesome meals.</p></article>
  </div>
</section>
<section class="quote-section">
  <div class="ak-container">
    <h2>Wisdom of the Woods</h2>
    <blockquote>“The trees are our elders. We teach children to listen to their silence, to respect their longevity, and to understand our place as stewards of this land.”</blockquote>
    <div class="pillar-row">
      <div><h4>Resilience</h4><p>Learning to navigate the outdoors with grace and confidence.</p></div>
      <div><h4>Empathy</h4><p>Developing a deep connection with all living beings in the forest ecosystem.</p></div>
      <div><h4>Curiosity</h4><p>Nurturing the instinct to question, explore, and create meaning.</p></div>
    </div>
  </div>
</section>
<section class="cta-panel-wrap ak-container">
  <div class="cta-panel split-image" style="--hero-image:url('<?php echo esc_url(vila_akoya_asset('assets/images/home-hero.jpg')); ?>');">
    <div>
      <span class="eyebrow">Seasonal intake</span>
      <h2>Enrol your child in nature</h2>
      <p>We offer weekly programmes and seasonal camps for children aged 5–12.</p>
      <div class="button-row">
        <?php vila_akoya_render_button('Download brochure', '#'); ?>
        <?php vila_akoya_render_button('Book a visit', home_url('/booking/'), 'button-secondary'); ?>
      </div>
    </div>
  </div>
</section>
<?php get_footer(); ?>
