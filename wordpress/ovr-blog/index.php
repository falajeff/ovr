<?php if (!defined('ABSPATH')) exit; get_header(); ?>

<section class="inverso" style="padding-block:clamp(48px,6vw,88px)">
  <div class="envelope pilha-16">
    <?php if (is_search()) : ?>
      <p class="t-etiqueta volt">Busca</p>
      <h1 class="t-h1"><?php echo esc_html(get_search_query()); ?></h1>
      <p class="t-corpo"><?php echo esc_html($GLOBALS['wp_query']->found_posts); ?> resultado(s).</p>
    <?php elseif (is_category() || is_tag() || is_archive()) : ?>
      <p class="t-etiqueta volt">Blog OVR</p>
      <h1 class="t-h1"><?php echo esc_html(wp_strip_all_tags(get_the_archive_title())); ?></h1>
      <?php if (get_the_archive_description()) : ?>
        <p class="t-corpo" style="max-width:52ch"><?php echo wp_kses_post(get_the_archive_description()); ?></p>
      <?php endif; ?>
    <?php else : ?>
      <p class="t-etiqueta volt">Bastidor e ofício</p>
      <h1 class="t-display">Blog.</h1>
      <p class="t-corpo-g" style="max-width:56ch">Como a peça é feita, o que decide o preço e o que separa uma estampa que dura de uma que descasca na terceira lavagem.</p>
    <?php endif; ?>
  </div>
</section>

<section class="secao">
  <div class="envelope">
    <?php if (have_posts()) : ?>

      <?php if (!is_paged() && !is_search() && !is_archive() && have_posts()) : the_post(); ?>
        <article class="destaque">
          <a class="destaque__media" href="<?php the_permalink(); ?>">
            <?php if (has_post_thumbnail()) {
              the_post_thumbnail('large', ['loading' => 'eager', 'alt' => esc_attr(get_the_title())]);
            } ?>
          </a>
          <div class="destaque__texto">
            <p class="t-meta"><?php echo esc_html(get_the_date('j \d\e F, Y')); ?> · <?php echo esc_html(ovr_tempo_leitura()); ?></p>
            <h2 class="t-h1"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
            <p class="t-corpo-g"><?php echo esc_html(get_the_excerpt()); ?></p>
            <a class="btn btn--ink" href="<?php the_permalink(); ?>">Ler o post
              <svg class="btn__seta" viewBox="0 0 14 14" fill="none" aria-hidden="true"><path d="M4 10L10 4M10 4H4.8M10 4V9.2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
          </div>
        </article>
      <?php endif; ?>

      <div class="lista-posts">
        <?php while (have_posts()) : the_post(); ?>
          <article class="cartao-post">
            <a class="cartao-post__media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
              <?php if (has_post_thumbnail()) {
                the_post_thumbnail('medium_large', ['loading' => 'lazy', 'alt' => '']);
              } ?>
            </a>
            <p class="t-meta"><?php echo esc_html(get_the_date('j M Y')); ?> · <?php echo esc_html(ovr_tempo_leitura()); ?></p>
            <h3 class="cartao-post__titulo"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
            <p class="t-corpo" style="font-size:14px"><?php echo esc_html(wp_trim_words(get_the_excerpt(), 20, '…')); ?></p>
            <a class="cartao-post__ler" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true"><span>Ler o post</span><svg viewBox="0 0 14 14" fill="none" aria-hidden="true"><path d="M4 10L10 4M10 4H4.8M10 4V9.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></a>
          </article>
        <?php endwhile; ?>
      </div>

      <?php the_posts_pagination([
        'mid_size'  => 1,
        'prev_text' => 'Anteriores',
        'next_text' => 'Próximos',
        'class'     => 'paginacao',
      ]); ?>

    <?php else : ?>
      <div class="pilha-24" style="padding-block:48px">
        <h2 class="t-h2">Ainda não tem post aqui.</h2>
        <p class="t-corpo" style="max-width:48ch">O primeiro texto sai em breve. Enquanto isso, o catálogo está no ar e o preço está aberto.</p>
        <div><a class="btn btn--ink" href="<?php echo esc_url(OVR_SITE); ?>/catalogo.html">Ver o catálogo</a></div>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="secao inverso">
  <div class="envelope pilha-24">
    <p class="t-etiqueta volt">Pronto para produzir?</p>
    <h2 class="t-h1">A próxima peça começa<br>com a sua ideia.</h2>
    <div class="linha">
      <a class="btn btn--volt" href="<?php echo esc_url(OVR_SITE); ?>/catalogo.html">Montar meu pedido</a>
      <a class="btn btn--linha" href="<?php echo esc_url(ovr_zap('Oi! Vim pelo blog da OVR e quero fazer um pedido.')); ?>" target="_blank" rel="noopener">Falar no WhatsApp</a>
    </div>
  </div>
</section>

<?php get_footer(); ?>
