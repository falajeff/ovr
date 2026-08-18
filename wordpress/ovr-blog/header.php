<?php if (!defined('ABSPATH')) exit; ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="<?php echo esc_url(get_theme_file_uri('/assets/marca/favicon.svg')); ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<meta name="theme-color" content="#090a0c">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<a class="pular" href="#conteudo">Pular para o conteúdo</a>

<div class="faixa-aviso">
  Estampa frente inclusa / A partir de 1 peça / Frete grátis acima de R$ 1.500 para SP, PR, RJ e SC
</div>

<header class="nav">
  <div class="envelope nav__interno">
    <a class="nav__marca" href="<?php echo esc_url(OVR_SITE); ?>" aria-label="OVR, página inicial">
      <?php ovr_marca('negative'); ?>
    </a>
    <nav class="nav__links" aria-label="Principal">
      <a href="<?php echo esc_url(OVR_SITE); ?>/catalogo.html">Catálogo</a>
      <a href="<?php echo esc_url(OVR_SITE); ?>/como-funciona.html">Como funciona</a>
      <a href="<?php echo esc_url(OVR_SITE); ?>/index.html#atacado">Atacado</a>
      <a href="<?php echo esc_url(OVR_SITE); ?>/impressao-especial.html">Impressão especial</a>
      <a href="<?php echo esc_url(OVR_SITE); ?>/filme-dtf.html">Filme DTF</a>
      <a href="<?php echo esc_url(home_url('/')); ?>" aria-current="page">Blog</a>
    </nav>
    <a class="btn btn--volt nav__acao" href="<?php echo esc_url(OVR_SITE); ?>/catalogo.html">Criar pedido
      <svg class="btn__seta" viewBox="0 0 14 14" fill="none" aria-hidden="true"><path d="M4 10L10 4M10 4H4.8M10 4V9.2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </a>
    <button class="nav__menu" data-menu-abrir aria-label="Abrir menu">Menu
      <svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true"><path d="M2 5h14M2 12h14" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/></svg>
    </button>
  </div>
</header>

<div class="menu" data-menu aria-hidden="true" role="dialog" aria-label="Menu">
  <div class="menu__topo">
    <?php ovr_marca('negative'); ?>
    <button data-menu-fechar aria-label="Fechar menu"><svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6L6 18" stroke="#FBFAF6" stroke-width="1.75" stroke-linecap="round"/></svg></button>
  </div>
  <nav class="menu__itens">
    <a href="<?php echo esc_url(OVR_SITE); ?>/catalogo.html"><strong>Catálogo</strong><span>151 bases em DTF</span></a>
    <a href="<?php echo esc_url(OVR_SITE); ?>/como-funciona.html"><strong>Como funciona</strong><span>Da arte à caixa em quatro passos</span></a>
    <a href="<?php echo esc_url(OVR_SITE); ?>/index.html#atacado"><strong>Atacado</strong><span>Preço por faixa, sem negociar</span></a>
    <a href="<?php echo esc_url(OVR_SITE); ?>/impressao-especial.html"><strong>Impressão especial</strong><span>DTG, a partir de 1 peça</span></a>
    <a href="<?php echo esc_url(OVR_SITE); ?>/filme-dtf.html"><strong>Filme DTF</strong><span>Só o filme, para quem já tem prensa</span></a>
    <a href="<?php echo esc_url(OVR_SITE); ?>/criacao-de-arte.html"><strong>Criação de arte</strong><span>Não tem estampa? A gente cria</span></a>
    <a href="<?php echo esc_url(home_url('/')); ?>"><strong>Blog</strong><span>O que decide o preço e o acabamento</span></a>
  </nav>
  <div class="menu__rodape">
    <a class="btn btn--volt btn--cheio" href="<?php echo esc_url(ovr_zap()); ?>" target="_blank" rel="noopener">Falar no WhatsApp</a>
  </div>
</div>

<main id="conteudo" tabindex="-1">
