
<?php get_header(); ?>
<section class="page-hero grid-hero">
  <div class="ak-container grid-two align-center">
    <div>
      <h1>Life at the Edge <br>of the Forest</h1>
      <p>A curated community where the Alentejo soil meets architectural soul.</p>
    </div>
    <div><img class="rounded-hero" src="<?php echo esc_url(vila_akoya_asset('assets/images/village-page.jpg')); ?>" alt="Village"></div>
  </div>
</section>
<section class="ak-container grid-two align-center roomy">
  <div><img class="circle-image" src="<?php echo esc_url(vila_akoya_asset('assets/images/home-hero.jpg')); ?>" alt="Alentejo landscape"></div>
  <div>
    <?php vila_akoya_section_intro('Our Ethos', 'Deeply Rooted in the Alentejo Earth', 'The Village is not just a place to live; it is an ecosystem of shared intentions. Every structure is built with low-impact materials and designed to breathe with the seasons.'); ?>
  </div>
</section>
<section class="soft-section">
  <div class="ak-container">
    <?php vila_akoya_section_intro('', 'Three Paths to Joining', 'Choose how you weave your story into ours.', true); ?>
    <div class="card-grid three-up">
      <article class="offer-card"><h3>Plot Rental</h3><p>Bring your own soulful dwelling. Rent a prepared plot with utility hookups and join our seasonal community.</p><?php vila_akoya_render_button('Inquire now', home_url('/booking/'), 'button-link'); ?></article>
      <article class="offer-card"><h3>Tiny House Rental</h3><p>Experience village life in one of our handcrafted tiny homes, fully furnished with curated local goods.</p><?php vila_akoya_render_button('Explore rentals', home_url('/booking/')); ?></article>
      <article class="offer-card"><h3>Ownership</h3><p>Plant your roots permanently. Invest in a bespoke village property and become a foundational member.</p><?php vila_akoya_render_button('View properties', home_url('/contact/'), 'button-link'); ?></article>
    </div>
  </div>
</section>
<section class="ak-container landscape-band">
  <div class="landscape-copy">
    <h2>The landscape is <br>our living room.</h2>
    <div class="mini-grid">
      <div><h4>Sacred forest paths</h4></div>
      <div><h4>Natural river basins</h4></div>
      <div><h4>Communal spirit</h4><p>Weekly harvests, shared fire pits, and seasonal celebrations form the heartbeat of village life.</p></div>
    </div>
  </div>
</section>
<section class="cta-panel-wrap ak-container">
  <div class="cta-panel light">
    <h2>Ready to come home?</h2>
    <p>Join our waitlist for the next phase of Village development.</p>
    <?php vila_akoya_render_button('Join the Waitlist', home_url('/booking/')); ?>
  </div>
</section>
<?php get_footer(); ?>
