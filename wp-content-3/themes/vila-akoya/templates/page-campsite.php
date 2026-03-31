
<?php get_header(); ?>
<section class="hero hero-page overlay-hero" style="--hero-image:url('<?php echo esc_url(vila_akoya_asset('assets/images/campsite-page.jpg')); ?>');">
  <div class="ak-container hero-inner">
    <div class="hero-copy wide light-copy">
      <span class="pill">The Alentejo Soul</span>
      <h1>Wild &amp; Connected</h1>
      <p>A campsite for the dreamers, the makers, and the wandering souls. Bring your van, your tent, and your curiosity.</p>
      <?php vila_akoya_render_button('Secure Your Plot', home_url('/booking/')); ?>
    </div>
  </div>
</section>
<section class="ak-container grid-two align-center roomy">
  <div>
    <?php vila_akoya_section_intro('', 'The Campus: Learning in Flow', 'At the heart of our campsite lies The Campus — an open-air cathedral of knowledge. No classrooms, just oak-shaded decks and riverside circles where masters and seekers meet.'); ?>
    <div class="mini-grid two-col compact-grid">
      <div><h4>Solar powered</h4><p>Clean energy for our digital and spiritual tools.</p></div>
      <div><h4>Living library</h4><p>A collection of nomadic wisdom and artisanal books.</p></div>
    </div>
  </div>
  <div><img class="circle-image" src="<?php echo esc_url(vila_akoya_asset('assets/images/campus-page.jpg')); ?>" alt="Campus"></div>
</section>
<section class="soft-section greenish">
  <div class="ak-container">
    <?php vila_akoya_section_intro('', 'Workshop Spheres', 'Diverse paths to mastery, hosted daily by our resident villagers and travelling sages.', true); ?>
    <div class="card-grid three-up">
      <article class="feature-card"><h3>Craft</h3><p>Ceramics, carpentry, weaving, and hands-on making.</p></article>
      <article class="feature-card"><h3>Body</h3><p>Movement, breathwork, and ritual practices under the cork trees.</p></article>
      <article class="feature-card"><h3>Earth</h3><p>Permaculture, herbalism, and land-led skills in daily rhythm.</p></article>
    </div>
  </div>
</section>
<section class="ak-container cta-panel-wrap">
  <div class="cta-panel dark-image" style="--hero-image:url('<?php echo esc_url(vila_akoya_asset('assets/images/home-hero.jpg')); ?>');">
    <div>
      <span class="eyebrow">Open season</span>
      <h2>Come camp <br>with intention.</h2>
      <p>Register your interest for plots, workshops, and upcoming dates.</p>
      <?php vila_akoya_render_button('Book your stay', home_url('/booking/')); ?>
    </div>
  </div>
</section>
<?php get_footer(); ?>
