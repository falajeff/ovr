<?php if (!defined('ABSPATH')) exit; get_header(); ?>
<section class="inverso" style="padding-block:clamp(80px,12vw,160px)">
  <div class="envelope pilha-24">
    <p class="t-etiqueta volt">Erro 404</p>
    <h1 class="t-display">Esse post<br>não existe.</h1>
    <p class="t-corpo-g" style="max-width:48ch">O endereço que você abriu não está no blog. Pode ter sido removido, ou o link veio quebrado.</p>
    <div class="linha">
      <a class="btn btn--volt" href="<?php echo esc_url(home_url('/')); ?>">Ver todos os posts</a>
      <a class="btn btn--linha" href="<?php echo esc_url(OVR_SITE); ?>/catalogo.html">Ir para o catálogo</a>
    </div>
  </div>
</section>
<?php get_footer(); ?>
