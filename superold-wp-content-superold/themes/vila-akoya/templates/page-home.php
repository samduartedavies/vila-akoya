
<?php get_header(); ?>
<section class="hero hero-home" style="--hero-image:url('<?php echo esc_url(vila_akoya_asset('assets/images/home-hero.jpg')); ?>');">
  <div class="ak-container hero-inner">
    <div class="hero-copy">
      <span class="pill">EST. 2024 • PORTUGAL</span>
      <h1>Where the Earth <br>Whispers Home.</h1>
      <p>A sanctuary for the modern nomad, nestled in the heart of the Alentejo. A place to breathe, create, and belong.</p>
      <div class="button-row">
        <?php vila_akoya_render_button('Begin Your Journey', home_url('/booking/')); ?>
        <?php vila_akoya_render_button('Explore the Map', '#story', 'button-secondary'); ?>
      </div>
    </div>
  </div>
</section>

<section class="split-panels">
  <div class="split-panel split-panel--village">
    <span class="eyebrow">Serenity & Living</span>
    <h2>The<br>Village</h2>
    <p>Rooted in silence. Soft ochre pathways leading to your private sanctuary. Community dining under the cork trees.</p>
    <img src="<?php echo esc_url(vila_akoya_asset('assets/images/village-page.jpg')); ?>" alt="Village preview">
    <?php vila_akoya_render_button('Enter the Sanctuary', home_url('/village/'), 'button-link'); ?>
  </div>
  <div class="split-panel split-panel--campsite">
    <span class="eyebrow">Vibrant Energy</span>
    <h2>The<br>Campsite</h2>
    <p>Sparks fly at the forge and the fireside. Collaborative workshops, nomadic soul, and artisan spirit.</p>
    <img src="<?php echo esc_url(vila_akoya_asset('assets/images/campsite-page.jpg')); ?>" alt="Campsite preview">
    <?php vila_akoya_render_button('Join the Connection', home_url('/campsite/'), 'button-link'); ?>
  </div>
</section>

<section id="story" class="story-band ak-container grid-two align-center">
  <div class="stacked-visual">
    <img class="rounded-large" src="<?php echo esc_url(vila_akoya_asset('assets/images/forest-school.jpg')); ?>" alt="Rooted living">
    <div class="floating-note">❤ A little village with a big heart.</div>
  </div>
  <div>
    <h2>Deeply Rooted in the <em>Spirit of Alentejo</em></h2>
    <p>Vila Akoya isn't just a destination; it's an experiment in living. We believe that when you strip away the noise of the modern world, what remains is essential: community, creativity, and the earth beneath our feet.</p>
    <p>Our project was born from a dream of creating a self-sustaining ecosystem where artists, thinkers, and seekers can come together.</p>
    <?php vila_akoya_render_button('Read Our Manifesto', home_url('/about/'), 'button-link'); ?>
  </div>
</section>

<section class="pillars">
  <div class="ak-container">
    <?php vila_akoya_section_intro('The Sacred Four', 'Pillars of our Existence', '', true); ?>
    <div class="card-grid four-up">
      <article class="feature-card"><h3>Earth-bound</h3><p>Sustainable living practices and permaculture principles in every structure.</p></article>
      <article class="feature-card"><h3>Kinsfolk</h3><p>Shared meals, collective wisdom, and the warmth of true belonging.</p></article>
      <article class="feature-card"><h3>Creativity</h3><p>A blank canvas for your art, your craft, and your wildest dreams.</p></article>
      <article class="feature-card"><h3>Soul Space</h3><p>Quiet corners for meditation and loud fields for celebration.</p></article>
    </div>
  </div>
</section>

<section class="newsletter-band ak-container">
  <div class="newsletter-panel">
    <div>
      <h2>Stay within <br>the Orbit.</h2>
      <p>Join our circle for seasonal updates, workshop announcements, and stories from the land.</p>
    </div>
    <form class="newsletter-form" onsubmit="return false;">
      <input type="email" placeholder="your@email.com">
      <button class="ak-button button-cream" type="submit">Subscribe</button>
    </form>
  </div>
</section>
<?php get_footer(); ?>
