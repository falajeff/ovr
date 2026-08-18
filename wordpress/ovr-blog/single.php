<?php if (!defined('ABSPATH')) exit; get_header(); ?>

<?php while (have_posts()) : the_post(); ?>
<article>
  <header class="inverso" style="padding-block:clamp(48px,6vw,80px)">
    <div class="envelope pilha-16 post-cabecalho">
      <p class="t-meta">
        <a href="<?php echo esc_url(home_url('/')); ?>">Blog</a>
        <?php $cats = get_the_category(); if ($cats) : ?>
          / <a href="<?php echo esc_url(get_category_link($cats[0]->term_id)); ?>"><?php echo esc_html($cats[0]->name); ?></a>
        <?php endif; ?>
      </p>
      <h1 class="t-h1"><?php the_title(); ?></h1>
    </div>
  </header>

  <?php if (has_post_thumbnail()) : ?>
    <figure class="capa-post"><?php the_post_thumbnail('full', ['alt' => esc_attr(get_the_title())]); ?></figure>
  <?php endif; ?>

  <div class="secao">
    <div class="envelope post-corpo">
      <div class="texto-post"><?php the_content(); ?></div>

      <aside class="trilho">
        <a class="trilho__voltar" href="<?php echo esc_url(home_url('/')); ?>">
          <svg width="13" height="13" viewBox="0 0 14 14" fill="none" aria-hidden="true"><path d="M11 7H3M3 7l3.4-3.4M3 7l3.4 3.4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <span>Todos os posts</span>
        </a>
        <dl>
          <?php if ($cats) : ?>
            <div><dt>Categoria</dt><dd><?php echo esc_html($cats[0]->name); ?></dd></div>
          <?php endif; ?>
          <div><dt>Publicado</dt><dd><?php echo esc_html(get_the_date('j \d\e F \d\e Y')); ?></dd></div>
          <div><dt>Leitura</dt><dd><?php echo esc_html(str_replace(' de leitura', '', ovr_tempo_leitura())); ?></dd></div>
        </dl>
      </aside>

      <?php $tags = get_the_tags(); if ($tags) : ?>
        <div class="etiquetas">
          <?php foreach ($tags as $t) : ?>
            <a class="chip" href="<?php echo esc_url(get_tag_link($t->term_id)); ?>"><?php echo esc_html($t->name); ?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <section class="secao superficie">
    <div class="envelope pilha-24" style="max-width:760px">
      <h2 class="t-h2">Quer isso na sua peça?</h2>
      <p class="t-corpo">Escolha a base no catálogo, monte a grade e o preço aparece na hora, já com a estampa.</p>
      <div class="linha">
        <a class="btn btn--ink" href="<?php echo esc_url(OVR_SITE); ?>/catalogo.html">Ver o catálogo</a>
        <a class="btn btn--linha" href="<?php echo esc_url(ovr_zap('Oi! Li um post no blog da OVR e queria falar sobre um pedido.')); ?>" target="_blank" rel="noopener">Falar no WhatsApp</a>
      </div>
    </div>
  </section>

  <?php
  $relacionados = new WP_Query([
    'posts_per_page'      => 3,
    'post__not_in'        => [get_the_ID()],
    'ignore_sticky_posts' => true,
    'category__in'        => wp_get_post_categories(get_the_ID()) ?: [],
  ]);
  if ($relacionados->have_posts()) : ?>
    <section class="secao">
      <div class="envelope">
        <div class="cabecalho-secao"><h2 class="t-h2">Leia também.</h2></div>
        <div class="lista-posts">
          <?php while ($relacionados->have_posts()) : $relacionados->the_post(); ?>
            <article class="cartao-post">
              <a class="cartao-post__media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
                <?php if (has_post_thumbnail()) the_post_thumbnail('medium_large', ['loading' => 'lazy', 'alt' => '']); ?>
              </a>
              <p class="t-meta"><?php echo esc_html(get_the_date('j M Y')); ?></p>
              <h3 class="cartao-post__titulo"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
              <a class="cartao-post__ler" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true"><span>Ler o post</span><svg viewBox="0 0 14 14" fill="none" aria-hidden="true"><path d="M4 10L10 4M10 4H4.8M10 4V9.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></a>
            </article>
          <?php endwhile; wp_reset_postdata(); ?>
        </div>
      </div>
    </section>
  <?php endif; ?>
</article>
<?php endwhile; ?>

<?php get_footer(); ?>
