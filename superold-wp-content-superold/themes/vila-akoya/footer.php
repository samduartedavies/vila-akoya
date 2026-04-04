
</main>
<footer class="site-footer">
  <div class="ak-container footer-top">
    <div>
      <div class="footer-brand">Vila Akoya</div>
      <p>A tiny house village and creative campsite.</p>
    </div>
    <nav class="footer-nav" aria-label="Footer">
      <?php wp_nav_menu([
        'theme_location' => 'footer',
        'container' => false,
        'fallback_cb' => 'vila_akoya_menu_fallback',
      ]); ?>
    </nav>
  </div>
  <div class="ak-container footer-bottom">
    <p>© <?php echo esc_html(date('Y')); ?> Vila Akoya. All rights reserved.</p>
  </div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
