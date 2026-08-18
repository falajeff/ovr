<?php
/**
 * OVR — funções do tema
 *
 * Um tema magro de propósito. Quanto menos código roda no servidor,
 * menos porta existe para arrombar.
 */

if (!defined('ABSPATH')) exit;

/* Endereço do site principal. Troque aqui se o domínio mudar. */
if (!defined('OVR_SITE')) define('OVR_SITE', 'https://ovrcamisetas.com.br');
if (!defined('OVR_WHATSAPP')) define('OVR_WHATSAPP', '5514996548259');
if (!defined('OVR_INSTAGRAM')) define('OVR_INSTAGRAM', 'ovrcamisetas');
if (!defined('OVR_EMAIL')) define('OVR_EMAIL', 'ovrcamisetas@gmail.com');

/* ------------------------------------------------------------------
   1. O que o tema suporta
------------------------------------------------------------------ */
add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('responsive-embeds');
    add_theme_support('automatic-feed-links');
    add_theme_support('html5', ['search-form', 'gallery', 'caption', 'style', 'script']);
    add_theme_support('editor-styles');
    add_editor_style('editor.css');

    /* A paleta que aparece no editor é a da marca, e só ela.
       Assim o post nunca sai com uma cor que não é da OVR. */
    add_theme_support('editor-color-palette', [
        ['name' => 'Tinta',    'slug' => 'ink',    'color' => '#090a0c'],
        ['name' => 'Papel',    'slug' => 'paper',  'color' => '#fbfaf6'],
        ['name' => 'Volt',     'slug' => 'volt',   'color' => '#c9ff00'],
        ['name' => 'Cobalto',  'slug' => 'cobalt', 'color' => '#2749ff'],
        ['name' => 'Brasa',    'slug' => 'ember',  'color' => '#ff4a1a'],
        ['name' => 'Pedra',    'slug' => 'stone',  'color' => '#eae8e2'],
    ]);
    add_theme_support('disable-custom-colors');

    register_nav_menus(['principal' => 'Menu do topo']);
    set_post_thumbnail_size(1200, 675, true);
});

/* ------------------------------------------------------------------
   2. Estilos
------------------------------------------------------------------ */
add_action('wp_enqueue_scripts', function () {
    $v = wp_get_theme()->get('Version');
    wp_enqueue_style('ovr-fontes',
        'https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@700&family=Inter:wght@400;500&display=swap',
        [], null);
    wp_enqueue_style('ovr-sistema', get_theme_file_uri('/ovr.css'), [], $v);
    wp_enqueue_style('ovr-blog', get_stylesheet_uri(), ['ovr-sistema'], $v);
});

/* ------------------------------------------------------------------
   3. Resumo dos posts
------------------------------------------------------------------ */
add_filter('excerpt_length', fn() => 28);
add_filter('excerpt_more', fn() => '…');

/* ------------------------------------------------------------------
   4. Comentário desligado
   É a maior porta de spam do WordPress e o blog não precisa dele.
   A conversa acontece no WhatsApp. Para religar, apague este bloco.
------------------------------------------------------------------ */
add_filter('comments_open', '__return_false', 20, 2);
add_filter('pings_open', '__return_false', 20, 2);
add_filter('comments_array', '__return_empty_array', 10, 2);
add_action('admin_menu', function () { remove_menu_page('edit-comments.php'); });
add_action('init', function () {
    if (is_admin_bar_showing()) {
        remove_action('admin_bar_menu', 'wp_admin_bar_comments_menu', 60);
    }
});

/* ------------------------------------------------------------------
   5. Endurecimento
   Nada aqui muda o que o leitor vê. Tudo aqui fecha porta.
------------------------------------------------------------------ */

/* Some com a versão do WordPress do HTML e do RSS.
   Saber a versão é o primeiro passo de quem procura falha conhecida. */
remove_action('wp_head', 'wp_generator');
add_filter('the_generator', '__return_empty_string');

/* Tira da URL só a versão do WordPress, que é impressão digital para
   quem procura falha conhecida. A versão do tema FICA: o servidor manda
   o css com cache de sete dias, e sem esse número na URL uma correção
   de layout não chega em quem já visitou o blog antes.               */
add_filter('style_loader_src', 'ovr_limpa_versao', 9999);
add_filter('script_loader_src', 'ovr_limpa_versao', 9999);
function ovr_limpa_versao($src) {
    if (strpos($src, 'ver=' . get_bloginfo('version')) !== false) {
        $src = remove_query_arg('ver', $src);
    }
    return $src;
}

/* XML-RPC é usado para ataque de força bruta em massa e o blog não precisa dele */
add_filter('xmlrpc_enabled', '__return_false');
add_filter('wp_headers', function ($h) { unset($h['X-Pingback']); return $h; });
remove_action('wp_head', 'rsd_link');
remove_action('wp_head', 'wlwmanifest_link');

/* Fecha a listagem de usuários pela API pública.
   Sem isso, /wp-json/wp/v2/users entrega os logins de bandeja. */
add_filter('rest_endpoints', function ($rotas) {
    unset($rotas['/wp/v2/users'], $rotas['/wp/v2/users/(?P<id>[\d]+)']);
    return $rotas;
});
/* Bloqueia /?author=1, que revela o login pelo redirecionamento */
add_action('template_redirect', function () {
    if (!is_admin() && isset($_GET['author'])) {
        wp_safe_redirect(home_url('/'), 301);
        exit;
    }
});

/* Mensagem de erro de login genérica: não conta se o usuário existe */
add_filter('login_errors', fn() => 'Não foi possível entrar. Confira usuário e senha.');

/* Editor de arquivo do painel desligado.
   Se alguém entrar no admin, não consegue escrever PHP direto pelo navegador. */
if (!defined('DISALLOW_FILE_EDIT')) define('DISALLOW_FILE_EDIT', true);

/* Emoji do WordPress: dois arquivos que ninguém usa */
remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('wp_print_styles', 'print_emoji_styles');

/* Cabeçalhos de segurança, caso o servidor não os aplique */
add_action('send_headers', function () {
    if (is_admin()) return;
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
});

/* ------------------------------------------------------------------
   6. Atalhos usados nos modelos
------------------------------------------------------------------ */
function ovr_zap($texto = 'Oi! Vim pelo blog da OVR.') {
    return 'https://api.whatsapp.com/send/?phone=' . OVR_WHATSAPP
         . '&text=' . rawurlencode($texto) . '&type=phone_number&app_absent=0';
}

function ovr_marca($variante = 'negative', $classe = '') {
    $url = get_theme_file_uri("/assets/marca/wordmark-{$variante}.svg");
    printf('<img src="%s" alt="OVR" class="%s">', esc_url($url), esc_attr($classe));
}

/* Tempo de leitura, calculado no texto real do post.
   Conta por espaço em branco porque str_word_count quebra palavra
   acentuada em duas e inflaria o tempo em português. */
function ovr_tempo_leitura($post = null) {
    $texto = wp_strip_all_tags(get_post_field('post_content', $post ?: get_the_ID()));
    $palavras = preg_split('/\s+/u', trim($texto), -1, PREG_SPLIT_NO_EMPTY);
    $min = max(1, (int) ceil(count($palavras) / 200));
    return $min . ' min de leitura';
}

/* ------------------------------------------------------------------
   7. Busca
   O WordPress sozinho não conta nada para o Google além do título.
   Estas funções escrevem a descrição, o cartão de compartilhamento e
   a ficha estruturada. É o que um plugin de SEO faria, sem o plugin.
------------------------------------------------------------------ */

/* Separador do título: "Post — OVR" */
add_filter('document_title_separator', fn() => '—');

/* A frase que aparece embaixo do link no Google e no WhatsApp.
   Vem do Resumo do post. Sem resumo, o WordPress monta um a partir
   do começo do texto. */
function ovr_descricao() {
    if (is_singular()) {
        /* Lê o post pelo id da consulta, não pelo loop: no wp_head o
           loop ainda não rodou e get_the_content() voltaria vazio. */
        $id = get_queried_object_id();
        $d  = has_excerpt($id)
            ? get_the_excerpt($id)
            : wp_trim_words(wp_strip_all_tags(strip_shortcodes(
                  get_post_field('post_content', $id)
              )), 32, '…');
    } elseif (is_category() || is_tag()) {
        $d = term_description() ?: 'Posts sobre ' . wp_strip_all_tags(get_the_archive_title()) . ' no blog da OVR.';
    } else {
        $d = get_bloginfo('description')
            ?: 'Como a peça é feita, o que decide o preço e o que separa uma estampa que dura de uma que descasca.';
    }
    return trim(wp_strip_all_tags($d));
}

/* Imagem do cartão: a destacada do post, ou a da marca */
function ovr_imagem_social() {
    if (is_singular() && has_post_thumbnail()) {
        $u = get_the_post_thumbnail_url(null, 'full');
        if ($u) return $u;
    }
    return OVR_SITE . '/assets/img/marca/og.jpg';
}

add_action('wp_head', function () {
    if (is_404()) return;

    $titulo = wp_get_document_title();
    $desc   = ovr_descricao();
    $img    = ovr_imagem_social();

    if (is_singular()) {
        $url = get_permalink(get_queried_object_id());
    } elseif (is_category() || is_tag()) {
        $url = get_term_link(get_queried_object_id());
        if (is_wp_error($url)) $url = home_url('/');
    } else {
        $url = home_url('/');
    }

    printf('<meta name="description" content="%s">' . "\n", esc_attr($desc));

    printf('<meta property="og:type" content="%s">' . "\n", is_singular('post') ? 'article' : 'website');
    printf('<meta property="og:site_name" content="%s">' . "\n", esc_attr(get_bloginfo('name')));
    echo '<meta property="og:locale" content="pt_BR">' . "\n";
    printf('<meta property="og:title" content="%s">' . "\n", esc_attr($titulo));
    printf('<meta property="og:description" content="%s">' . "\n", esc_attr($desc));
    printf('<meta property="og:url" content="%s">' . "\n", esc_url($url));
    printf('<meta property="og:image" content="%s">' . "\n", esc_url($img));
    echo '<meta name="twitter:card" content="summary_large_image">' . "\n";

    if (is_singular('post')) {
        printf('<meta property="article:published_time" content="%s">' . "\n", esc_attr(get_the_date('c')));
        printf('<meta property="article:modified_time" content="%s">' . "\n", esc_attr(get_the_modified_date('c')));
    }
}, 5);

/* Ficha estruturada. É o que faz o Google entender que existe uma
   empresa por trás do blog e mostrar data e título direito. */
add_action('wp_head', function () {
    if (is_404()) return;

    $marca = [
        '@type'    => 'Organization',
        '@id'      => OVR_SITE . '/#organizacao',
        'name'     => 'OVR',
        'url'      => OVR_SITE,
        'logo'     => OVR_SITE . '/assets/img/marca/og.jpg',
        'email'    => OVR_EMAIL,
        'areaServed' => 'BR',
        'address'  => [
            '@type'           => 'PostalAddress',
            'addressLocality' => 'Marília',
            'addressRegion'   => 'SP',
            'addressCountry'  => 'BR',
        ],
        'sameAs'   => ['https://www.instagram.com/' . OVR_INSTAGRAM . '/'],
    ];

    $grafo = [$marca];

    if (is_singular('post')) {
        $grafo[] = array_filter([
            '@type'            => 'BlogPosting',
            'headline'         => wp_strip_all_tags(get_the_title()),
            'description'      => ovr_descricao(),
            'datePublished'    => get_the_date('c'),
            'dateModified'     => get_the_modified_date('c'),
            'mainEntityOfPage' => get_permalink(),
            'image'            => ovr_imagem_social(),
            'inLanguage'       => 'pt-BR',
            'author'           => ['@id' => OVR_SITE . '/#organizacao'],
            'publisher'        => ['@id' => OVR_SITE . '/#organizacao'],
        ]);
    }

    printf(
        '<script type="application/ld+json">%s</script>' . "\n",
        wp_json_encode(['@context' => 'https://schema.org', '@graph' => $grafo],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
}, 6);
