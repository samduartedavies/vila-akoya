
<?php get_header(); ?>
<section class="page-hero simple-hero">
  <div class="ak-container grid-two">
    <div>
      <span class="eyebrow"><?php echo esc_html(get_the_title()); ?></span>
      <h1><?php the_title(); ?></h1>
      <p><?php echo esc_html(get_the_excerpt()); ?></p>
    </div>
  </div>
</section>
<section class="page-copy ak-container prose-section">
  <?php the_content(); ?>
</section>
<?php get_footer(); ?>
