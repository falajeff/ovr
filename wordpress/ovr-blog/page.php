<?php if (!defined('ABSPATH')) exit; get_header(); ?>
<?php while (have_posts()) : the_post(); ?>
  <header class="inverso" style="padding-block:clamp(48px,6vw,80px)">
    <div class="envelope"><h1 class="t-h1"><?php the_title(); ?></h1></div>
  </header>
  <div class="secao">
    <div class="envelope"><div class="texto-post"><?php the_content(); ?></div></div>
  </div>
<?php endwhile; ?>
<?php get_footer(); ?>
