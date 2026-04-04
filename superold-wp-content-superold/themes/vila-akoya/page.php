<?php get_header(); while (have_posts()) : the_post(); get_template_part('templates/page', 'default'); endwhile; get_footer(); ?>
