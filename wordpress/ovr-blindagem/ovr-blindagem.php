<?php
/**
 * Plugin Name: OVR Blindagem
 * Description: Fecha os buracos padrão do WordPress: enumeração de usuário, XML-RPC, cabeçalhos de segurança e vazamento de versão. Serve nos dois sites da OVR, o painel e o blog.
 * Version: 1.0.0
 * Author: OVR
 * Requires PHP: 7.4
 *
 * Por que plugin e não .htaccess: erro de sintaxe no .htaccess derruba o
 * site inteiro com 500 e só se conserta por FTP. Erro num plugin cai no
 * modo de recuperação do WordPress, que desliga o plugin e manda e-mail.
 * E desinstalar é apagar um arquivo.
 *
 * O que ele NÃO faz de propósito: não bloqueia wp-login.php por IP, não
 * limita tentativa de senha e não instala firewall. Isso é trabalho de
 * quem vê o tráfego todo, não de um plugin no PHP. Se um dia precisar,
 * o caminho é o WAF da hospedagem.
 */

if (!defined('ABSPATH')) exit;

/* ------------------------------------------------------------------
   1. Enumeração de usuário

   /?author=1 redireciona para /author/jelopejrgmail-com/ e entrega o
   nome de login de graça. Com o login na mão, quem ataca só precisa da
   senha, e metade do trabalho já foi feita por nós.

   Três portas para a mesma sala: a query `author`, o REST de usuários
   e o sitemap de autores.
------------------------------------------------------------------ */
add_action('template_redirect', function () {
    if (is_admin() || !isset($_GET['author'])) return;
    if (is_user_logged_in()) return;             // logado pode navegar
    wp_safe_redirect(home_url('/'), 301);
    exit;
});

/* A URL bonita /author/fulano/ também revela. Quem entra por ela vai
   para a home, e o WordPress para de gerar esses links.              */
add_action('template_redirect', function () {
    if (is_author() && !is_user_logged_in()) {
        wp_safe_redirect(home_url('/'), 301);
        exit;
    }
}, 1);

add_filter('rest_endpoints', function ($rotas) {
    if (is_user_logged_in()) return $rotas;
    unset($rotas['/wp/v2/users'], $rotas['/wp/v2/users/(?P<id>[\d]+)']);
    return $rotas;
});

add_filter('wp_sitemaps_add_provider', function ($provedor, $nome) {
    return $nome === 'users' ? false : $provedor;
}, 10, 2);

/* O oEmbed devolve author_name e author_url mesmo com o resto fechado. */
add_filter('oembed_response_data', function ($dados) {
    unset($dados['author_name'], $dados['author_url']);
    return $dados;
});

/* ------------------------------------------------------------------
   2. XML-RPC

   system.multicall aceita centenas de tentativas de senha numa única
   requisição, o que passa por baixo de qualquer limitador que conte
   requisições. E o pingback vira arma para atacar terceiros a partir
   do seu servidor.

   ⚠️ Isto quebra o aplicativo WordPress no celular e o Jetpack. Se um
   dia você precisar de um dos dois, troque a linha do `false` por
   `true` e o XML-RPC volta.
------------------------------------------------------------------ */
add_filter('xmlrpc_enabled', '__return_false');

/* O filtro acima desliga os métodos, mas o arquivo continua respondendo
   e gastando PHP. Aqui a requisição morre antes disso.               */
add_action('init', function () {
    if (strpos((string) ($_SERVER['REQUEST_URI'] ?? ''), 'xmlrpc.php') === false) return;
    status_header(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('XML-RPC desligado neste site.');
}, 1);

/* Tira o cabeçalho que anuncia o endereço do XML-RPC. */
remove_action('wp_head', 'rsd_link');
remove_action('wp_head', 'wlwmanifest_link');

/* ------------------------------------------------------------------
   3. Vazamento de versão

   Saber a versão exata do WordPress, do PHP e dos plugins é o primeiro
   passo de quem procura uma falha conhecida. Nenhum desses números
   serve para o visitante.
------------------------------------------------------------------ */
remove_action('wp_head', 'wp_generator');
add_filter('the_generator', '__return_empty_string');

add_action('init', function () {
    if (!headers_sent()) header_remove('X-Powered-By');
}, 1);

/* A versão também viaja pendurada no ?ver= de cada css e js. */
function ovr_tirar_versao($url) {
    if (strpos($url, 'ver=') === false) return $url;
    /* Só a versão do WordPress. A do tema e a do plugin continuam,
       porque é ela que faz o navegador buscar arquivo novo.        */
    return remove_query_arg('ver', $url) === $url ? $url
         : (strpos($url, 'ver=' . get_bloginfo('version')) !== false ? remove_query_arg('ver', $url) : $url);
}
add_filter('style_loader_src', 'ovr_tirar_versao', 9999);
add_filter('script_loader_src', 'ovr_tirar_versao', 9999);

/* ------------------------------------------------------------------
   4. Cabeçalhos

   O site estático já sai da hospedagem com todos. Os dois WordPress
   saíam só com metade, e sem HSTS: sem ele, o primeiro acesso digitado
   sem https pode ser interceptado antes do redirecionamento.

   CSP fica de fora de propósito. Um WordPress com plugins e editor de
   blocos precisa de script inline, e uma CSP frouxa o bastante para
   não quebrar nada também não protege de nada. Melhor não fingir.
------------------------------------------------------------------ */
add_action('send_headers', function () {
    if (is_admin()) return;
    if (!is_ssl()) return;                       // HSTS só faz sentido em https
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');
});

/* ------------------------------------------------------------------
   5. Edição de arquivo pelo painel

   O editor de tema e de plugin transforma qualquer conta de admin
   invadida em execução de código no servidor. Você sobe arquivo pelo
   gerenciador da Hostinger, então esse editor só serve de porta.
------------------------------------------------------------------ */
if (!defined('DISALLOW_FILE_EDIT')) define('DISALLOW_FILE_EDIT', true);

/* ------------------------------------------------------------------
   6. Mensagem de erro no login

   "Senha incorreta para jefferson" confirma que o usuário existe. A
   mensagem passa a ser a mesma para usuário errado e senha errada.
------------------------------------------------------------------ */
add_filter('login_errors', function () {
    return 'Usuário ou senha não conferem.';
});
