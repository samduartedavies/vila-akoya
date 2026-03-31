
<?php get_header(); ?>
<section class="page-hero simple-hero">
  <div class="ak-container">
    <span class="eyebrow">Contact</span>
    <h1>Let’s talk.</h1>
    <p>Questions about stays, workshops, the village, or the homeschooling collective are all welcome.</p>
  </div>
</section>
<section class="ak-container contact-grid">
  <div class="contact-card">
    <h2>Get in touch</h2>
    <p><strong>Email:</strong> <a href="mailto:info@vila-akoya.com">info@vila-akoya.com</a></p>
    <p><strong>Phone / WhatsApp:</strong> <a href="tel:+351960446285">+351 960 446 285</a></p>
    <p>For WhatsApp, messages are usually the easiest.</p>
  </div>
  <div>
    <?php vila_akoya_render_enquiry_form(true); ?>
  </div>
</section>
<?php get_footer(); ?>
