<?php
/**
 * A gestão: as telas do painel servidas fora do wp-admin, em /gestao.
 *
 * Por que fora do wp-admin: o wp-admin é uma ferramenta de publicação,
 * não de operação. O menu cinza, a barra preta e a largura fixa roubam
 * espaço de quem só quer saber o que atrasou e quanto entrou. Aqui a
 * tela é da OVR e cabe no celular, que é de onde ele olha.
 *
 * O que NÃO mudou: a regra de negócio continua no plugin. Esta camada
 * só lê e escreve pelas mesmas funções que o wp-admin usa, então as
 * duas telas nunca divergem. Se um cálculo mudar, muda nos dois.
 */

if (!defined('ABSPATH')) exit;

/* ------------------------------------------------------------------
   1. A rota
   /gestao, /gestao/pedidos, /gestao/pedido, /gestao/financeiro...
------------------------------------------------------------------ */
add_action('init', function () {
    add_rewrite_rule('^gestao/?$', 'index.php?ovr_gestao=resumo', 'top');
    add_rewrite_rule('^gestao/([a-z-]+)/?$', 'index.php?ovr_gestao=$matches[1]', 'top');
});

add_filter('query_vars', function ($vars) {
    $vars[] = 'ovr_gestao';
    return $vars;
});

function ovr_gestao_telas() {
    return [
        'resumo'     => 'Resumo',
        'pedidos'    => 'Pedidos',
        'clientes'   => 'Clientes',
        'compras'    => 'Compras',
        'financeiro' => 'Financeiro',
        'frete'      => 'Frete',
    ];
}

/* Junta os pedidos por cliente. Não existe cadastro de cliente: o nome
   é digitado no pedido. Em vez de inventar uma tabela e migrar o que já
   está lançado, a agregação acontece na leitura, com o nome normalizado
   para as três grafias do mesmo Zé caírem na mesma linha. Quem resolve
   o problema na raiz é o campo de nome, que agora sugere os nomes já
   usados em vez de deixar digitar tudo de novo.                       */
function ovr_g_clientes() {
    $ids = get_posts([
        'post_type' => 'pedido', 'post_status' => ['publish', 'draft', 'private'],
        'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC',
    ]);
    $mapa = [];
    foreach ($ids as $id) {
        $nome = get_post_meta($id, '_ovr_cliente_nome', true);
        $chave = ovr_chave_cliente($nome);
        if ($chave === '') continue;
        if (!isset($mapa[$chave])) {
            $mapa[$chave] = [
                'nome' => $nome, 'zap' => '', 'cidade' => '',
                'pedidos' => 0, 'receita' => 0, 'custo' => 0, 'aReceber' => 0,
                'ultimo' => '', 'grafias' => [], 'ids' => [],
            ];
        }
        $c =& $mapa[$chave];
        $c['grafias'][trim((string) $nome)] = true;
        if (!$c['zap'])    $c['zap']    = get_post_meta($id, '_ovr_cliente_zap', true);
        if (!$c['cidade']) $c['cidade'] = get_post_meta($id, '_ovr_cliente_cidade', true);
        if (!$c['ultimo']) $c['ultimo'] = get_the_date('Y-m-d', $id);
        $sit = get_post_meta($id, '_ovr_situacao', true) ?: 'novo';
        if ($sit !== 'cancelado') {
            $c['pedidos']++;
            $c['receita'] += (int) get_post_meta($id, '_ovr_valor_itens', true)
                           + (int) get_post_meta($id, '_ovr_valor_frete', true)
                           - (int) get_post_meta($id, '_ovr_desconto', true);
            $c['custo']   += (int) get_post_meta($id, '_ovr_custo', true)
                           + (int) get_post_meta($id, '_ovr_frete_fornecedor', true);
            $c['aReceber'] += ovr_a_receber($id);
        }
        $c['ids'][] = $id;
        unset($c);
    }
    uasort($mapa, function ($a, $b) { return $b['receita'] <=> $a['receita']; });
    return $mapa;
}

function ovr_g_url($tela = 'resumo', $args = []) {
    $url = home_url('/gestao/' . $tela . '/');
    return $args ? add_query_arg($args, $url) : $url;
}

/* ------------------------------------------------------------------
   2. O porteiro e o despachante
------------------------------------------------------------------ */
add_action('template_redirect', function () {
    $tela = get_query_var('ovr_gestao');
    if (!$tela) return;

    /* Nada aqui pode ser guardado por cache de hospedagem. A tela mostra
       nome, telefone e valor de pedido de cliente: uma cópia guardada e
       servida para outra pessoa vaza dado alheio.

       DONOTCACHEPAGE é a bandeira que LiteSpeed, WP Rocket e W3TC
       entendem. O nocache_headers() mais abaixo só cobre o navegador;
       esta constante é o que fala com a camada de cache do servidor, e
       ela precisa ser definida ANTES de qualquer saída.               */
    if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
    /* Sem ouvinte, do_action não faz nada. Com o LiteSpeed ligado, é
       assim que se pede para ele não guardar esta resposta.           */
    do_action('litespeed_control_set_nocache', 'gestao OVR');

    if (!is_user_logged_in()) {
        wp_safe_redirect(wp_login_url(ovr_g_url($tela)));
        exit;
    }
    if (!current_user_can('edit_pedidos')) {
        status_header(403);
        ovr_g_cabecalho('Sem acesso', '');
        echo '<div class="g-vazio"><h2>Esta área é da operação.</h2>'
           . '<p>A sua conta não tem permissão para ver pedidos. Se isso está errado, '
           . 'peça para alguém com acesso de administrador te colocar no papel Operação OVR.</p></div>';
        ovr_g_rodape();
        exit;
    }

    /* Sem post correspondente, o WordPress já marcou 404 antes de chegar
       aqui. Como quem responde somos nós, o status volta a ser 200 e a
       página sai sem cache: número de pedido velho engana.             */
    status_header(200);
    nocache_headers();

    /* Gravação antes de desenhar: quem salva sai por um redirecionamento,
       senão o F5 relança o pedido.                                      */
    if ($_SERVER['REQUEST_METHOD'] === 'POST') ovr_g_salvar();

    switch ($tela) {
        case 'clientes':   ovr_g_tela_clientes();   break;
        case 'compras':    ovr_g_tela_compras();    break;
        case 'pedidos':    ovr_g_tela_pedidos();    break;
        case 'pedido':     ovr_g_tela_pedido();     break;
        case 'financeiro': ovr_g_tela_financeiro(); break;
        case 'frete':      ovr_g_tela_frete();      break;
        default:           ovr_g_tela_resumo();
    }
    exit;
});

/* ------------------------------------------------------------------
   3. Gravar
   Mesma sanitização do wp-admin, campo por campo, pela mesma tabela
   em ovr_campos_pedido(). Duplicar a lista aqui seria criar uma
   segunda verdade sobre o que é um pedido.
------------------------------------------------------------------ */
function ovr_g_salvar() {
    if (!isset($_POST['ovr_g_nonce']) || !wp_verify_nonce($_POST['ovr_g_nonce'], 'ovr_gestao_salvar')) {
        wp_die('Sessão expirada. Volte, recarregue a página e tente de novo.');
    }

    $id = isset($_POST['pedido_id']) ? (int) $_POST['pedido_id'] : 0;
    $acao = isset($_POST['acao']) ? sanitize_key($_POST['acao']) : 'salvar';

    /* Lixeira e desfazer. Não existe apagar de vez aqui de propósito:
       o definitivo se faz no wp-admin, com a fricção que uma ação
       irreversível merece. E para venda que não fechou o certo é a
       situação Cancelado, que tira da receita e mantém o histórico. */
    /* Mudança de situação direto da lista. É o gesto mais repetido do
       dia e não deve custar abrir o pedido, rolar, salvar e voltar.  */
    if ($acao === 'situacao') {
        if (!$id || get_post_type($id) !== 'pedido') wp_die('Pedido não encontrado.');
        if (!current_user_can('edit_post', $id)) wp_die('Sem permissão para editar este pedido.');
        $nova = isset($_POST['_ovr_situacao']) ? sanitize_key($_POST['_ovr_situacao']) : '';
        if (isset(ovr_situacoes()[$nova])) {
            $antiga = get_post_meta($id, '_ovr_situacao', true) ?: 'novo';
            update_post_meta($id, '_ovr_situacao', $nova);
            ovr_registrar_historico($id, 'Situação',
                ovr_situacoes()[$antiga]['rotulo'] ?? $antiga, ovr_situacoes()[$nova]['rotulo']);
        }
        $volta = isset($_POST['volta']) ? esc_url_raw(wp_unslash($_POST['volta'])) : ovr_g_url('pedidos');
        wp_safe_redirect($volta);
        exit;
    }

    if ($acao === 'lixeira' || $acao === 'restaurar') {
        if (!$id || get_post_type($id) !== 'pedido') wp_die('Pedido não encontrado.');
        if (!current_user_can('delete_post', $id)) wp_die('Sem permissão para mexer neste pedido.');
        $titulo = get_the_title($id);
        if ($acao === 'lixeira') {
            wp_trash_post($id);
            wp_safe_redirect(ovr_g_url('pedidos', ['feito' => 'lixeira', 'alvo' => $id, 'nome' => rawurlencode($titulo)]));
        } else {
            wp_untrash_post($id);
            /* Desde o WordPress 5.6 o post volta para a situação que
               tinha antes; se vier como rascunho, publica de novo para
               ele reaparecer nas contas.                              */
            if (get_post_status($id) !== 'publish') wp_update_post(['ID' => $id, 'post_status' => 'publish']);
            wp_safe_redirect(ovr_g_url('pedido', ['id' => $id, 'ok' => 1]));
        }
        exit;
    }

    if ($id) {
        if (!current_user_can('edit_post', $id)) wp_die('Sem permissão para editar este pedido.');
    } else {
        if (!current_user_can('create_pedidos')) wp_die('Sem permissão para lançar pedido.');
        $id = wp_insert_post(['post_type' => 'pedido', 'post_status' => 'publish', 'post_title' => '']);
        if (is_wp_error($id) || !$id) wp_die('Não deu para criar o pedido.');
    }

    $antes = [];
    foreach (array_keys(ovr_campos_com_historico()) as $k) $antes[$k] = get_post_meta($id, $k, true);

    foreach (ovr_campos_pedido() as $grupo) {
        foreach ($grupo[1] as $chave => $def) {
            if ($def[1] === 'anexo') continue;
            if (!isset($_POST[$chave])) continue;
            $bruto = wp_unslash($_POST[$chave]);
            switch ($def[1]) {
                case 'dinheiro': $valor = ovr_centavos($bruto); break;
                case 'email':    $valor = sanitize_email($bruto); break;
                case 'url':      $valor = esc_url_raw($bruto); break;
                case 'textarea': $valor = sanitize_textarea_field($bruto); break;
                default:         $valor = sanitize_text_field($bruto);
            }
            update_post_meta($id, $chave, $valor);
        }
    }
    ovr_aplicar_frete_automatico($id);
    $prazo = ovr_aplicar_prazo_automatico($id);

    foreach (ovr_campos_com_historico() as $k => $def) {
        $lista = function_exists($def[1]) ? call_user_func($def[1]) : [];
        $de   = $antes[$k] ?? '';
        $para = get_post_meta($id, $k, true);
        ovr_registrar_historico($id, $def[0], $lista[$de] ?? $de, $lista[$para] ?? $para);
    }

    wp_safe_redirect(ovr_g_url('pedido', array_filter(['id' => $id, 'ok' => 1, 'prazo' => $prazo])));
    exit;
}

/* ------------------------------------------------------------------
   4. Moldura
------------------------------------------------------------------ */
function ovr_g_cabecalho($titulo, $ativa = 'resumo') {
    $u = wp_get_current_user();
    ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html($titulo); ?> · Gestão OVR</title>
<?php /* Ícone embutido como data URI, e não como link para o site: assim
         a aba mostra a marca sem depender de outro domínio estar de pé,
         sem uma requisição a mais, e sem quebrar se o caminho do site
         mudar. É o mesmo favicon.svg de assets/img/marca. */ ?>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<?php echo rawurlencode('<svg width="160" height="160" viewBox="0 0 160 160" xmlns="http://www.w3.org/2000/svg"><rect width="160" height="160" fill="#090A0C"/><g transform="translate(24,24) scale(0.7)"><path d="M152 34V126L126 152H48L68.7773 118H105L118 105V55L111.345 48.3447L132.206 14.2061L152 34Z" fill="#C9FF00"/><path d="M112 8L91.2227 42H55L42 55V105L48.6543 111.654L27.793 145.793L8 126V34L34 8H112Z" fill="#C9FF00"/></g></svg>'); ?>">
<meta name="theme-color" content="#090a0c">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@700&family=Inter:wght@400;500&display=swap" rel="stylesheet">
<style><?php echo ovr_g_estilo(); ?></style>
</head>
<body>
<header class="g-topo">
  <div class="g-envelope g-topo__interno">
    <a class="g-marca" href="<?php echo esc_url(ovr_g_url('resumo')); ?>">OVR <span>gestão</span></a>
    <nav class="g-nav">
      <?php foreach (ovr_gestao_telas() as $id => $rotulo) : ?>
        <a href="<?php echo esc_url(ovr_g_url($id)); ?>"<?php echo $ativa === $id ? ' class="g-nav--ativa"' : ''; ?>><?php echo esc_html($rotulo); ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="g-topo__acoes">
      <a class="g-btn g-btn--volt" href="<?php echo esc_url(ovr_g_url('pedido')); ?>">Lançar pedido</a>
      <span class="g-quem"><?php echo esc_html($u->display_name); ?> · <a href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">sair</a></span>
    </div>
  </div>
</header>
<main class="g-envelope g-corpo">
<?php
}

function ovr_g_rodape() {
    ?>
</main>
<footer class="g-rodape"><div class="g-envelope">
  Gestão OVR · <a href="<?php echo esc_url(admin_url('edit.php?post_type=pedido')); ?>">abrir no wp-admin</a>
</div></footer>
</body>
</html>
<?php
}

/* ------------------------------------------------------------------
   5. Peças de tela
------------------------------------------------------------------ */
function ovr_g_cartao($rotulo, $valor, $nota = '', $tom = '') {
    printf('<div class="g-cartao%s"><span class="g-cartao__rot">%s</span>'
         . '<strong class="g-cartao__val">%s</strong>%s</div>',
        $tom ? ' g-cartao--' . esc_attr($tom) : '',
        esc_html($rotulo), esc_html($valor),
        $nota ? '<span class="g-cartao__nota">' . esc_html($nota) . '</span>' : '');
}

function ovr_g_selo($situacao) {
    $s = ovr_situacoes()[$situacao] ?? ['rotulo' => $situacao, 'cor' => '#6b6b66'];
    return sprintf('<span class="g-selo" style="--selo:%s">%s</span>',
                   esc_attr($s['cor']), esc_html($s['rotulo']));
}

/* ------------------------------------------------------------------
   6. Resumo
------------------------------------------------------------------ */
/* Dias desde a última mexida no pedido. Pedido parado é o que precisa
   de você, e "há 6 dias" empurra mais que um contador. */
function ovr_g_parado($id) {
    $t = get_post_modified_time('U', true, $id);
    return $t ? (int) floor((time() - $t) / DAY_IN_SECONDS) : 0;
}

/* Uma fila é uma lista de pedidos que estão esperando a MESMA coisa de
   você. Contador diz que existe problema; fila diz qual é o próximo. */
function ovr_g_fila($titulo, $explica, array $ids, $limite = 5) {
    if (!$ids) return;
    echo '<section class="g-fila">';
    printf('<div class="g-fila__topo"><h3>%s</h3><span class="g-fila__n">%d</span></div>', esc_html($titulo), count($ids));
    printf('<p class="g-dica">%s</p>', esc_html($explica));
    echo '<ul class="g-fila__lista">';
    foreach (array_slice($ids, 0, $limite) as $id) {
        $dias = ovr_g_parado($id);
        printf('<li><a class="g-link" href="%s">%s</a><span>%s</span><em>%s</em></li>',
            esc_url(ovr_g_url('pedido', ['id' => $id])),
            esc_html(get_the_title($id)),
            esc_html(get_post_meta($id, '_ovr_cliente_nome', true) ?: 'sem nome'),
            esc_html($dias === 0 ? 'hoje' : ($dias === 1 ? 'há 1 dia' : 'há ' . $dias . ' dias')));
    }
    echo '</ul>';
    if (count($ids) > $limite) printf('<p class="g-dica">e mais %d.</p>', count($ids) - $limite);
    echo '</section>';
}

function ovr_g_tela_resumo() {
    $mes  = ovr_somar(date('Y-m-01'));
    $tudo = ovr_somar();
    $pct  = $mes['receita'] > 0 ? round(($mes['margem'] / $mes['receita']) * 100) : 0;

    ovr_g_cabecalho('Resumo', 'resumo');
    echo '<h1 class="g-h1">Resumo</h1>';
    echo '<p class="g-lead">Sai do que está lançado em Pedidos. Cancelado não entra na conta, '
       . 'e pedido sem custo preenchido infla a margem: o Financeiro marca quais são.</p>';

    /* A receber é de todos os tempos, não do mês: dívida velha não
       some da conta só porque virou o calendário.                   */
    $aReceber = 0;
    foreach (get_posts(['post_type' => 'pedido', 'post_status' => ['publish', 'draft', 'private'],
                        'posts_per_page' => -1, 'fields' => 'ids']) as $pid) {
        $aReceber += ovr_a_receber($pid);
    }

    echo '<h2 class="g-h2">Este mês</h2><div class="g-cartoes">';
    ovr_g_cartao('Pedidos', (string) $mes['qtd']);
    ovr_g_cartao('Receita', ovr_reais($mes['receita']));
    ovr_g_cartao('A receber', ovr_reais($aReceber), $aReceber ? 'de todos os pedidos' : 'nada em aberto',
                 $aReceber ? 'atencao' : '');
    ovr_g_cartao('Custo', ovr_reais($mes['custo']));
    /* Mês sem pedido não é prejuízo, é ausência. Pintar esse zero de
       vermelho ensina a ignorar o vermelho, que é a cor que precisa
       assustar quando a margem for negativa de verdade.              */
    ovr_g_cartao('Margem', ovr_reais($mes['margem']),
                 $mes['qtd'] ? $pct . '% da receita' : 'nenhum pedido no mês',
                 $mes['qtd'] ? ($mes['margem'] <= 0 ? 'ruim' : 'bom') : '');
    echo '</div>';

    /* Filas: quem está esperando o quê. Uma varredura só, repartida em
       montes, em vez de quatro consultas para os mesmos pedidos.     */
    $abertos = get_posts([
        'post_type' => 'pedido', 'post_status' => ['publish', 'draft', 'private'],
        'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'modified', 'order' => 'ASC',
    ]);
    $filas = ['arte' => [], 'atraso' => [], 'comprar' => [], 'cobrar' => [], 'parado' => []];
    foreach ($abertos as $pid) {
        $sit = get_post_meta($pid, '_ovr_situacao', true) ?: 'novo';
        if ($sit === 'cancelado') continue;
        $emAberto = ovr_situacoes()[$sit]['aberto'] ?? true;
        $arte = get_post_meta($pid, '_ovr_arte_situacao', true) ?: 'sem';
        $prom = get_post_meta($pid, '_ovr_prometido', true);

        if ($emAberto && in_array($arte, ['sem', 'problema'], true)) $filas['arte'][] = $pid;
        if ($emAberto && $prom && strtotime($prom) < strtotime('today')) $filas['atraso'][] = $pid;
        if ($sit === 'aprovado') $filas['comprar'][] = $pid;
        if (ovr_a_receber($pid) > 0 && !$emAberto) $filas['cobrar'][] = $pid;
        if ($emAberto && ovr_g_parado($pid) >= 7) $filas['parado'][] = $pid;
    }

    echo '<h2 class="g-h2">Precisa de você</h2>';
    $temFila = array_filter($filas);
    if ($temFila) {
        echo '<div class="g-filas">';
        ovr_g_fila('Esperando arte', 'Sem arquivo ou com problema apontado. É o que trava a produção.', $filas['arte']);
        ovr_g_fila('Atrasados', 'Passaram da data prometida e ainda não foram entregues.', $filas['atraso']);
        ovr_g_fila('Comprar peça', 'Aprovados esperando a compra no fornecedor. Junte tudo na aba Compras.', $filas['comprar']);
        ovr_g_fila('Cobrar', 'Já entregues e ainda com saldo em aberto.', $filas['cobrar']);
        ovr_g_fila('Parados', 'Sem nenhuma mexida há uma semana ou mais.', $filas['parado']);
        echo '</div>';
    } else {
        echo '<div class="g-vazio g-vazio--curto"><h2>Nada esperando você.</h2>'
           . '<p>Nenhum pedido sem arte, atrasado, sem peça comprada ou por cobrar.</p></div>';
    }

    echo '<h2 class="g-h2">Funil</h2><div class="g-funil">';
    foreach (ovr_situacoes() as $id => $s) {
        if ($id === 'cancelado') continue;
        printf('<a class="g-funil__item" href="%s"><span style="color:%s">%s</span><strong>%d</strong></a>',
            esc_url(ovr_g_url('pedidos', ['situacao' => $id])),
            esc_attr($s['cor']), esc_html($s['rotulo']), $tudo['por_situacao'][$id] ?? 0);
    }
    echo '</div>';

    if ($tudo['qtd'] === 0) {
        echo '<div class="g-vazio"><h2>Nenhum pedido ainda.</h2>'
           . '<p>Quando entrar o primeiro, pelo site ou lançado à mão, ele aparece aqui.</p>'
           . '<a class="g-btn g-btn--volt" href="' . esc_url(ovr_g_url('pedido')) . '">Lançar o primeiro</a></div>';
    }
    ovr_g_rodape();
}

/* ------------------------------------------------------------------
   7. Lista de pedidos
------------------------------------------------------------------ */
function ovr_g_tela_pedidos() {
    $filtro = isset($_GET['situacao']) ? sanitize_key($_GET['situacao']) : '';
    $busca  = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';

    /* Filtra em PHP, não no banco. O título do pedido é "#0007" e o nome
       do cliente mora num meta, então a busca do WordPress acharia um e
       perderia o outro. Com o volume dele, carregar tudo e peneirar aqui
       é mais barato que costurar duas consultas com OR.                 */
    $todos = get_posts([
        'post_type'      => 'pedido',
        'post_status'    => ['publish', 'draft', 'private'],
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'fields'         => 'ids',
    ]);
    $ids = [];
    foreach ($todos as $pid) {
        if ($filtro && (get_post_meta($pid, '_ovr_situacao', true) ?: 'novo') !== $filtro) continue;
        if ($busca) {
            $agulha = mb_strtolower($busca);
            $palheiro = mb_strtolower(get_the_title($pid) . ' '
                      . get_post_meta($pid, '_ovr_cliente_nome', true) . ' '
                      . get_post_meta($pid, '_ovr_cliente_zap', true) . ' '
                      . get_post_meta($pid, '_ovr_cliente_email', true));
            if (mb_strpos($palheiro, $agulha) === false) continue;
        }
        $ids[] = $pid;
    }
    $achados = count($ids);
    $ids = array_slice($ids, 0, 100);

    ovr_g_cabecalho('Pedidos', 'pedidos');

    /* Aviso da lixeira com o desfazer junto. Ação destrutiva sem volta
       visível na mesma tela é o que faz a pessoa ter medo de clicar.  */
    if (isset($_GET['feito']) && $_GET['feito'] === 'lixeira' && isset($_GET['alvo'])) {
        $alvo = (int) $_GET['alvo'];
        $nome = isset($_GET['nome']) ? sanitize_text_field(rawurldecode(wp_unslash($_GET['nome']))) : 'O pedido';
        echo '<div class="g-ok g-ok--linha"><span>' . esc_html($nome) . ' foi para a lixeira.</span>';
        printf('<form method="post" action="%s" style="display:inline">', esc_url(ovr_g_url('pedidos')));
        wp_nonce_field('ovr_gestao_salvar', 'ovr_g_nonce');
        printf('<input type="hidden" name="pedido_id" value="%d"><input type="hidden" name="acao" value="restaurar">', $alvo);
        echo '<button class="g-desfazer" type="submit">Desfazer</button></form></div>';
    }

    echo '<h1 class="g-h1">Pedidos</h1>';

    echo '<form class="g-filtros" method="get" action="' . esc_url(ovr_g_url('pedidos')) . '">';
    echo '<input class="g-campo" type="search" name="q" placeholder="Buscar por número ou cliente" value="' . esc_attr($busca) . '">';
    echo '<select class="g-campo" name="situacao" onchange="this.form.submit()"><option value="">Todas as situações</option>';
    foreach (ovr_situacoes() as $id => $s) {
        printf('<option value="%s"%s>%s</option>', esc_attr($id), selected($filtro, $id, false), esc_html($s['rotulo']));
    }
    echo '</select><button class="g-btn g-btn--linha" type="submit">Filtrar</button>';
    if ($filtro || $busca) echo '<a class="g-limpar" href="' . esc_url(ovr_g_url('pedidos')) . '">limpar</a>';
    echo '</form>';

    if (!$ids) {
        /* Lista vazia por falta de pedido e lista vazia por causa do
           filtro são dois problemas diferentes, e mandar "tente outra
           situação" para quem nunca lançou nada é conselho inútil.    */
        if ($todos) {
            echo '<div class="g-vazio"><h2>Nada com esse filtro.</h2>'
               . '<p>Tente outra situação, ou limpe a busca.</p>'
               . '<a class="g-btn g-btn--linha" href="' . esc_url(ovr_g_url('pedidos')) . '">Ver todos</a></div>';
        } else {
            echo '<div class="g-vazio"><h2>Nenhum pedido ainda.</h2>'
               . '<p>Quando entrar o primeiro, pelo site ou lançado à mão, ele aparece aqui.</p>'
               . '<a class="g-btn g-btn--volt" href="' . esc_url(ovr_g_url('pedido')) . '">Lançar o primeiro</a></div>';
        }
        ovr_g_rodape();
        return;
    }

/* Selo do cupom, ao lado do nome do cliente.

   Aqui e não numa coluna própria: coluna só para isto ficaria vazia na
   esmagadora maioria das linhas, e o que pede atenção é o AVISO, não o
   código do cupom. Segue a mesma ideia do "custo em falta" ao lado da
   margem.                                                              */
function ovr_g_selo_cupom(int $id): string {
    $st = get_post_meta($id, '_ovr_cupom_st', true);
    if (!$st) return '';
    $cod = get_post_meta($id, '_ovr_cupom', true);

    if ($st === 'ok') {
        return sprintf('<br><span class="g-cupom g-cupom--ok">cupom %s</span>', esc_html($cod));
    }
    return sprintf('<br><span class="g-cupom g-cupom--alerta" title="%s">%s</span>',
        esc_attr(ovr_cupom_recado($st)),
        esc_html($st === 'repetido' ? 'já comprou antes — cupom não valeria' : 'cupom sem CPF válido'));
}

    echo '<div class="g-tabela"><table><thead><tr>'
       . '<th>Pedido</th><th>Cliente</th><th>Situação</th><th>Arte</th>'
       . '<th class="g-dir">Receita</th><th class="g-dir">A receber</th><th class="g-dir">Margem</th><th>Parado</th>'
       . '</tr></thead><tbody>';

    $artes = ovr_situacoes_arte();
    $volta = ovr_g_url('pedidos', array_filter(['situacao' => $filtro, 'q' => $busca]));
    foreach ($ids as $id) {
        $receita = (int) get_post_meta($id, '_ovr_valor_itens', true)
                 + (int) get_post_meta($id, '_ovr_valor_frete', true)
                 - (int) get_post_meta($id, '_ovr_desconto', true);
        $custo   = (int) get_post_meta($id, '_ovr_custo', true)
                 + (int) get_post_meta($id, '_ovr_frete_fornecedor', true);
        $margem  = $receita - $custo;
        $sit     = get_post_meta($id, '_ovr_situacao', true) ?: 'novo';
        $arte    = get_post_meta($id, '_ovr_arte_situacao', true) ?: 'sem';
        $prom    = get_post_meta($id, '_ovr_prometido', true);
        $atrasou = $prom && (ovr_situacoes()[$sit]['aberto'] ?? true) && strtotime($prom) < strtotime('today');
        $semCusto = ((int) get_post_meta($id, '_ovr_custo', true) <= 0 && $receita > 0);

        $falta = ovr_a_receber($id);
        $dias  = ovr_g_parado($id);

        /* Select que grava sozinho no change. Sem JS o botão Trocar
           aparece e faz o mesmo, então quem estiver com script
           bloqueado não fica sem a ação.                            */
        ob_start();
        printf('<form method="post" action="%s" class="g-troca">', esc_url(ovr_g_url('pedidos')));
        wp_nonce_field('ovr_gestao_salvar', 'ovr_g_nonce');
        printf('<input type="hidden" name="pedido_id" value="%d"><input type="hidden" name="acao" value="situacao">', $id);
        printf('<input type="hidden" name="volta" value="%s">', esc_attr($volta));
        printf('<select name="_ovr_situacao" class="g-troca__sel" style="--selo:%s" onchange="this.form.submit()">',
               esc_attr(ovr_situacoes()[$sit]['cor'] ?? '#6b6b66'));
        foreach (ovr_situacoes() as $k => $s) {
            printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($sit, $k, false), esc_html($s['rotulo']));
        }
        echo '</select><button class="g-troca__ok" type="submit">Trocar</button></form>';
        $troca = ob_get_clean();

        printf('<tr>
                  <td data-rot="Pedido"><a class="g-link" href="%s">%s</a></td>
                  <td data-rot="Cliente">%s</td>
                  <td data-rot="Situação">%s</td>
                  <td data-rot="Arte">%s</td>
                  <td data-rot="Receita" class="g-dir g-num">%s</td>
                  <td data-rot="A receber" class="g-dir g-num%s">%s</td>
                  <td data-rot="Margem" class="g-dir g-num%s">%s%s</td>
                  <td data-rot="Parado" class="%s">%s</td>
                </tr>',
            esc_url(ovr_g_url('pedido', ['id' => $id])),
            esc_html(get_the_title($id)),
            esc_html(get_post_meta($id, '_ovr_cliente_nome', true) ?: '—') . ovr_g_selo_cupom($id),
            $troca,
            esc_html($artes[$arte] ?? $arte),
            esc_html(ovr_reais($receita)),
            $falta > 0 ? ' g-num--atencao' : '',
            esc_html($falta > 0 ? ovr_reais($falta) : '—'),
            $margem <= 0 ? ' g-num--ruim' : '',
            esc_html(ovr_reais($margem)),
            $semCusto ? ' <span class="g-aviso" title="Receita lançada sem custo: a margem está inflada">custo em falta</span>' : '',
            ($atrasou || $dias >= 7) ? 'g-atraso' : '',
            esc_html($dias === 0 ? 'hoje' : ($dias === 1 ? '1 dia' : $dias . ' dias'))
        );
    }
    echo '</tbody></table></div>';
    printf('<p class="g-nota">%d pedido%s%s.</p>',
           $achados, $achados === 1 ? '' : 's',
           $achados > 100 ? ', mostrando os 100 mais recentes' : '');
    ovr_g_rodape();
}

/* ------------------------------------------------------------------
   8. Um pedido: ver, editar, lançar
------------------------------------------------------------------ */
function ovr_g_campo($chave, $def, $valor) {
    $tipo   = $def[1];
    $rotulo = $def[0];
    $dica   = (isset($def[2]) && is_string($def[2]) && !function_exists($def[2])) ? $def[2] : '';
    $id     = 'c_' . ltrim($chave, '_');

    echo '<div class="g-grupo">';
    printf('<label class="g-rot" for="%s">%s</label>', esc_attr($id), esc_html($rotulo));

    switch ($tipo) {
        case 'select':
            $lista = function_exists($def[2]) ? call_user_func($def[2]) : [];
            printf('<select class="g-campo" id="%s" name="%s">', esc_attr($id), esc_attr($chave));
            foreach ($lista as $k => $r) {
                printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($valor, $k, false), esc_html($r));
            }
            echo '</select>';
            break;
        case 'textarea':
            printf('<textarea class="g-campo" id="%s" name="%s" rows="4">%s</textarea>',
                   esc_attr($id), esc_attr($chave), esc_textarea($valor));
            break;
        case 'dinheiro':
            printf('<input class="g-campo" id="%s" name="%s" type="text" inputmode="decimal" value="%s" placeholder="0,00">',
                   esc_attr($id), esc_attr($chave), esc_attr($valor !== '' ? number_format(((int) $valor) / 100, 2, ',', '.') : ''));
            break;
        case 'anexo':
            $url = $valor ? wp_get_attachment_url((int) $valor) : '';
            echo $url
                ? '<a class="g-link" target="_blank" rel="noopener" href="' . esc_url($url) . '">abrir o arquivo enviado</a>'
                : '<span class="g-nota">Nada enviado pelo site.</span>';
            break;
        default:
            /* O campo de nome sugere quem já comprou. Escolher da lista
               em vez de redigitar é o que impede o mesmo cliente virar
               três clientes por causa de acento e maiúscula.          */
            $lista = '';
            if ($chave === '_ovr_cliente_nome') {
                $nomes = array_keys(array_column(ovr_g_clientes(), 'nome', 'nome'));
                if ($nomes) {
                    $lista = ' list="ovr-clientes"';
                    echo '<datalist id="ovr-clientes">';
                    foreach (array_slice($nomes, 0, 200) as $n) printf('<option value="%s">', esc_attr($n));
                    echo '</datalist>';
                }
            }
            printf('<input class="g-campo" id="%s" name="%s" type="%s" value="%s"%s>',
                   esc_attr($id), esc_attr($chave), esc_attr($tipo === 'number' ? 'number' : ($tipo === 'date' ? 'date' : $tipo)),
                   esc_attr($valor), $lista);
    }
    if ($dica) printf('<span class="g-dica">%s</span>', esc_html($dica));
    echo '</div>';
}

function ovr_g_tela_pedido() {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id && get_post_type($id) !== 'pedido') $id = 0;
    $novo = !$id;

    if ($id && !current_user_can('edit_post', $id)) wp_die('Sem permissão para ver este pedido.');

    $titulo = $novo ? 'Lançar pedido' : get_the_title($id);
    ovr_g_cabecalho($titulo, 'pedidos');

    if (isset($_GET['ok'])) {
        $aviso = 'Pedido salvo.';
        if (!empty($_GET['prazo'])) {
            $aviso .= ' Arte aprovada, então o prazo foi para '
                    . date_i18n('d/m/Y', strtotime(sanitize_text_field(wp_unslash($_GET['prazo']))))
                    . ', sete dias úteis a partir de hoje. Feriado não entra nessa conta: confira antes de prometer.';
        }
        printf('<p class="g-ok">%s</p>', esc_html($aviso));
    }

    echo '<div class="g-cabeca">';
    printf('<h1 class="g-h1">%s</h1>', esc_html($titulo));
    if (!$novo) {
        $sit = get_post_meta($id, '_ovr_situacao', true) ?: 'novo';
        echo '<div class="g-cabeca__lado">' . ovr_g_selo($sit)
           . '<span class="g-nota">entrou em ' . esc_html(get_the_date('d/m/Y', $id)) . '</span></div>';
    }
    echo '</div>';

    echo '<form class="g-form" method="post" action="' . esc_url(ovr_g_url('pedido', $id ? ['id' => $id] : [])) . '">';
    wp_nonce_field('ovr_gestao_salvar', 'ovr_g_nonce');
    printf('<input type="hidden" name="pedido_id" value="%d">', $id);

    $campos = ovr_campos_pedido();
    $val = function ($chave) use ($id) { return $id ? get_post_meta($id, $chave, true) : ''; };

    /* Ordem por frequência de uso, não pela ordem em que os campos
       nasceram. Situação, arte e pagamento mudam toda semana; nome e
       cidade do cliente se digitam uma vez e nunca mais. Formulário
       longo demais faz a pessoa rolar para achar o que sempre mexe. */
    echo '<div class="g-colunas">';
    echo '<div class="g-coluna">';

    /* Faixa de estado: as três decisões do dia, lado a lado. */
    echo '<section class="g-bloco g-estado"><h2 class="g-h3">Onde está</h2><div class="g-estado__grade">';
    foreach ([
        '_ovr_situacao'      => $campos['pedido'][1]['_ovr_situacao'],
        '_ovr_arte_situacao' => $campos['arte'][1]['_ovr_arte_situacao'],
        '_ovr_pagamento'     => $campos['pagamento'][1]['_ovr_pagamento'],
    ] as $chave => $def) {
        ovr_g_campo($chave, $def, $val($chave));
    }
    echo '</div></section>';

    /* O pedido em si, sem a situação que já subiu. */
    printf('<section class="g-bloco"><h2 class="g-h3">%s</h2>', esc_html($campos['pedido'][0]));
    foreach ($campos['pedido'][1] as $chave => $def) {
        if ($chave === '_ovr_situacao') continue;
        ovr_g_campo($chave, $def, $val($chave));
    }
    echo '</section>';

    printf('<section class="g-bloco"><h2 class="g-h3">%s</h2>', esc_html($campos['arte'][0]));
    foreach ($campos['arte'][1] as $chave => $def) {
        if ($chave === '_ovr_arte_situacao') continue;
        ovr_g_campo($chave, $def, $val($chave));
    }
    echo '</section>';

    printf('<section class="g-bloco"><h2 class="g-h3">%s</h2>', esc_html($campos['prazo'][0]));
    foreach ($campos['prazo'][1] as $chave => $def) ovr_g_campo($chave, $def, $val($chave));
    echo '</section>';

    /* Linha do tempo: só aparece quando existe, e vem recolhida. */
    $hist = $id ? get_post_meta($id, '_ovr_historico', true) : [];
    if (is_array($hist) && $hist) {
        printf('<details class="g-bloco g-recolhe"><summary><span class="g-h3">Histórico</span>'
             . '<span class="g-recolhe__resumo">%d mudança%s de estado</span></summary><div class="g-recolhe__corpo">',
             count($hist), count($hist) === 1 ? '' : 's');
        echo '<div class="g-tempo">';
        foreach (array_reverse($hist) as $h) {
            printf('<div class="g-tempo__item"><span class="g-tempo__quando">%s</span>'
                 . '<span class="g-tempo__oque"><strong>%s</strong>: %s → %s</span>'
                 . '<span class="g-tempo__quem">%s</span></div>',
                esc_html(date_i18n('d/m/Y H:i', strtotime($h['quando']))),
                esc_html($h['campo']),
                esc_html($h['de'] !== '' ? $h['de'] : 'vazio'),
                esc_html($h['para']),
                esc_html($h['quem']));
        }
        echo '</div></div></details>';
    }

    /* Cadastro recolhido: abre quando precisa, some quando não. */
    $temCliente = $val('_ovr_cliente_nome') || $val('_ovr_cliente_zap');
    printf('<details class="g-bloco g-recolhe"%s><summary><span class="g-h3">%s</span><span class="g-recolhe__resumo">%s</span></summary><div class="g-recolhe__corpo">',
        ($novo || !$temCliente) ? ' open' : '',
        esc_html($campos['cliente'][0]),
        esc_html($temCliente ? trim($val('_ovr_cliente_nome') . ' · ' . $val('_ovr_cliente_zap'), ' ·') : 'ninguém preenchido ainda'));
    foreach ($campos['cliente'][1] as $chave => $def) ovr_g_campo($chave, $def, $val($chave));
    echo '</div></details>';

    echo '</div><div class="g-coluna g-coluna--lado">';

    foreach (['dinheiro', 'pagamento'] as $slug) {
        $grupo = $campos[$slug];
        printf('<section class="g-bloco"><h2 class="g-h3">%s</h2>', esc_html($grupo[0]));
        foreach ($grupo[1] as $chave => $def) {
            if ($chave === '_ovr_pagamento') continue;   /* já subiu para a faixa de estado */
            ovr_g_campo($chave, $def, $val($chave));
        }
        echo '</section>';
    }

    if (!$novo) {
        $receita = (int) get_post_meta($id, '_ovr_valor_itens', true)
                 + (int) get_post_meta($id, '_ovr_valor_frete', true)
                 - (int) get_post_meta($id, '_ovr_desconto', true);
        $custo   = (int) get_post_meta($id, '_ovr_custo', true)
                 + (int) get_post_meta($id, '_ovr_frete_fornecedor', true);
        $margem  = $receita - $custo;
        $pct     = $receita > 0 ? round(($margem / $receita) * 100) : 0;
        echo '<section class="g-bloco g-bloco--resultado"><h2 class="g-h3">Resultado</h2>';
        printf('<div class="g-linha"><span>Receita</span><strong>%s</strong></div>', esc_html(ovr_reais($receita)));
        printf('<div class="g-linha"><span>Custo</span><strong>%s</strong></div>', esc_html(ovr_reais($custo)));
        printf('<div class="g-linha g-linha--total"><span>Margem</span><strong class="%s">%s <small>%d%%</small></strong></div>',
               $margem <= 0 ? 'g-num--ruim' : '', esc_html(ovr_reais($margem)), $pct);
        $falta = ovr_a_receber($id);
        printf('<div class="g-linha"><span>A receber</span><strong class="%s">%s</strong></div>',
               $falta > 0 ? 'g-num--atencao' : '', esc_html($falta > 0 ? ovr_reais($falta) : 'nada'));
        if ((int) get_post_meta($id, '_ovr_custo', true) <= 0 && $receita > 0) {
            echo '<p class="g-dica">Sem custo lançado a margem sobe sozinha. Preencha o custo dos itens.</p>';
        }
        echo '</section>';
    }

    echo '<div class="g-acoes"><button class="g-btn g-btn--volt" type="submit">'
       . ($novo ? 'Lançar pedido' : 'Salvar') . '</button>';
    if (!$novo) {
        /* Pode voltar nulo quando o papel não edita aquele post. Sem a
           guarda, o PHP 8 reclama de null dentro de esc_url().         */
        $editar = get_edit_post_link($id, '');
        if ($editar) printf('<a class="g-btn g-btn--linha" href="%s">Abrir no wp-admin</a>', esc_url($editar));
    }
    echo '<a class="g-limpar" href="' . esc_url(ovr_g_url('pedidos')) . '">voltar para a lista</a></div>';

    /* Descartar fica no fim da coluna, separado e discreto. O botão usa
       o atributo `form` para falar com um formulário que vive FORA do
       principal: formulário dentro de formulário é HTML inválido, e o
       navegador resolve isso jogando um dos dois fora.                */
    if (!$novo && current_user_can('delete_post', $id)) {
        echo '<div class="g-descartar">'
           . '<button class="g-descartar__btn" type="submit" form="ovrLixeira">Mover para a lixeira</button>'
           . '<p class="g-dica">Some da lista e das contas, e dá para desfazer. Se a venda não fechou, '
           . 'o certo é mudar a situação para Cancelado: sai da receita e o histórico continua.</p></div>';
    }

    echo '</div></div></form>';

    if (!$novo && current_user_can('delete_post', $id)) {
        printf('<form id="ovrLixeira" method="post" action="%s" onsubmit="return confirm(\'Mover %s para a lixeira?\')">',
               esc_url(ovr_g_url('pedido', ['id' => $id])), esc_attr(get_the_title($id)));
        wp_nonce_field('ovr_gestao_salvar', 'ovr_g_nonce');
        printf('<input type="hidden" name="pedido_id" value="%d"><input type="hidden" name="acao" value="lixeira">', $id);
        echo '</form>';
    }
    ovr_g_rodape();
}

/* ------------------------------------------------------------------
   8a. Clientes
------------------------------------------------------------------ */
function ovr_g_tela_clientes() {
    $mapa = ovr_g_clientes();
    ovr_g_cabecalho('Clientes', 'clientes');
    echo '<h1 class="g-h1">Clientes</h1>';
    echo '<p class="g-lead">Quem já comprou, quanto, e o que ainda deve. Não existe cadastro separado: '
       . 'a lista é montada a partir do nome digitado em cada pedido, ignorando maiúscula e acento. '
       . 'Cliente que volta vale mais que cliente novo, e até agora nada no painel mostrava isso.</p>';

    if (!$mapa) {
        echo '<div class="g-vazio"><h2>Nenhum cliente ainda.</h2>'
           . '<p>O primeiro nome preenchido num pedido aparece aqui.</p></div>';
        ovr_g_rodape();
        return;
    }

    $totalReceita = array_sum(array_column($mapa, 'receita'));
    $recorrentes = count(array_filter($mapa, function ($c) { return $c['pedidos'] > 1; }));
    echo '<div class="g-cartoes">';
    ovr_g_cartao('Clientes', (string) count($mapa), 'com pelo menos um pedido');
    ovr_g_cartao('Recorrentes', (string) $recorrentes,
                 count($mapa) ? round(($recorrentes / count($mapa)) * 100) . '% voltaram' : '',
                 $recorrentes ? 'bom' : '');
    ovr_g_cartao('Receita', ovr_reais($totalReceita), 'de todos eles');
    ovr_g_cartao('Ticket por cliente', ovr_reais(count($mapa) ? (int) round($totalReceita / count($mapa)) : 0), 'média');
    echo '</div>';

    echo '<h2 class="g-h2">Quem já comprou</h2>';
    echo '<div class="g-tabela"><table><thead><tr><th>Cliente</th><th>Contato</th>'
       . '<th class="g-dir">Pedidos</th><th class="g-dir">Receita</th><th class="g-dir">Margem</th>'
       . '<th class="g-dir">A receber</th><th>Último</th></tr></thead><tbody>';
    foreach ($mapa as $c) {
        $margem = $c['receita'] - $c['custo'];
        $grafias = count($c['grafias']) > 1
            ? ' <span class="g-aviso" title="' . esc_attr(implode(' · ', array_keys($c['grafias']))) . '">'
              . count($c['grafias']) . ' grafias</span>'
            : '';
        printf('<tr>
                  <td data-rot="Cliente"><a class="g-link" href="%s">%s</a>%s</td>
                  <td data-rot="Contato">%s</td>
                  <td data-rot="Pedidos" class="g-dir g-num">%d</td>
                  <td data-rot="Receita" class="g-dir g-num">%s</td>
                  <td data-rot="Margem" class="g-dir g-num%s">%s</td>
                  <td data-rot="A receber" class="g-dir g-num%s">%s</td>
                  <td data-rot="Último">%s</td>
                </tr>',
            esc_url(ovr_g_url('pedidos', ['q' => $c['nome']])),
            esc_html($c['nome']), $grafias,
            esc_html(trim($c['zap'] . ' · ' . $c['cidade'], ' ·') ?: '—'),
            $c['pedidos'],
            esc_html(ovr_reais($c['receita'])),
            $margem <= 0 && $c['pedidos'] ? ' g-num--ruim' : '', esc_html(ovr_reais($margem)),
            $c['aReceber'] > 0 ? ' g-num--atencao' : '',
            esc_html($c['aReceber'] > 0 ? ovr_reais($c['aReceber']) : '—'),
            esc_html($c['ultimo'] ? date_i18n('d/m/Y', strtotime($c['ultimo'])) : '—'));
    }
    echo '</tbody></table></div>';
    echo '<p class="g-nota">O aviso de grafias marca o mesmo cliente escrito de jeitos diferentes. '
       . 'O campo de nome no pedido sugere os nomes já usados: escolher em vez de digitar evita que isso cresça.</p>';
    ovr_g_rodape();
}

/* ------------------------------------------------------------------
   8b. Compras: o que buscar no fornecedor

   O campo de itens é texto livre, então aqui existe um leitor e não um
   banco de dados. Ele entende a linha que o site escreve, que é
   "Nome do produto · P ×2 · M ×4 · 6un · R$ 89,53", e junta por peça e
   tamanho. O que ele não entender aparece na tela em vez de sumir: um
   painel de compra que esconde o que não leu manda você comprar menos
   do que precisa.
------------------------------------------------------------------ */
function ovr_g_ler_itens($texto) {
    $lidos = [];
    $sobras = [];
    foreach (preg_split('/\r\n|\r|\n/', (string) $texto) as $linha) {
        $linha = trim($linha);
        if ($linha === '') continue;

        /* Tamanhos no formato "P ×2" ou "P x2", em qualquer lugar. */
        $grade = [];
        if (preg_match_all('/\b([A-Z]{1,3}|\d{1,2})\s*[×x]\s*(\d{1,4})\b/u', $linha, $m, PREG_SET_ORDER)) {
            foreach ($m as $g) $grade[$g[1]] = (int) $g[2];
        }
        /* Total em "12un". */
        $total = preg_match('/\b(\d{1,4})\s*un\b/u', $linha, $t) ? (int) $t[1] : array_sum($grade);
        /* O nome é o primeiro pedaço antes do primeiro separador. */
        $nome = trim(preg_split('/\s+·\s+/u', $linha)[0]);

        if ($nome === '' || $total <= 0) { $sobras[] = $linha; continue; }
        if (!$grade) $grade = ['—' => $total];
        foreach ($grade as $tam => $qtd) {
            $chave = $nome . '|' . $tam;
            if (!isset($lidos[$chave])) $lidos[$chave] = ['nome' => $nome, 'tam' => $tam, 'qtd' => 0];
            $lidos[$chave]['qtd'] += $qtd;
        }
    }
    return ['itens' => $lidos, 'sobras' => $sobras];
}

function ovr_g_tela_compras() {
    /* Aprovado e Em produção: os dois estados em que a peça já foi
       vendida e ainda precisa entrar em casa.                       */
    $ids = get_posts([
        'post_type' => 'pedido', 'post_status' => ['publish', 'draft', 'private'],
        'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'ASC',
    ]);
    $lista = [];
    $sobras = [];
    $pedidos = [];
    $pecasTotal = 0;
    foreach ($ids as $id) {
        $sit = get_post_meta($id, '_ovr_situacao', true) ?: 'novo';
        if (!in_array($sit, ['aprovado', 'producao'], true)) continue;
        $tipo = get_post_meta($id, '_ovr_tipo', true) ?: 'dtf';
        /* Filme e DTG saem direto do fornecedor: não há peça para comprar. */
        if (in_array($tipo, ['filme', 'arte', 'dtg'], true)) continue;
        $pedidos[] = $id;
        $pecasTotal += (int) get_post_meta($id, '_ovr_qtd_pecas', true);

        $lido = ovr_g_ler_itens(get_post_meta($id, '_ovr_itens', true));
        foreach ($lido['itens'] as $chave => $it) {
            if (!isset($lista[$chave])) {
                $lista[$chave] = ['nome' => $it['nome'], 'tam' => $it['tam'], 'qtd' => 0, 'pedidos' => []];
            }
            $lista[$chave]['qtd'] += $it['qtd'];
            $lista[$chave]['pedidos'][$id] = get_the_title($id);
        }
        foreach ($lido['sobras'] as $s) $sobras[] = ['pedido' => $id, 'linha' => $s];
    }
    uasort($lista, function ($a, $b) {
        return [$a['nome'], $a['tam']] <=> [$b['nome'], $b['tam']];
    });
    $somaLida = array_sum(array_column($lista, 'qtd'));

    ovr_g_cabecalho('Compras', 'compras');
    echo '<h1 class="g-h1">Compras</h1>';
    echo '<p class="g-lead">O que buscar no fornecedor para os pedidos aprovados e em produção. '
       . 'Comprar tudo de uma vez muda a faixa de frete: é a diferença entre pagar R$ 19,00 por três peças '
       . 'em três compras e pagar R$ 32,00 por vinte de uma vez.</p>';

    if (!$pedidos) {
        echo '<div class="g-vazio"><h2>Nada para comprar.</h2>'
           . '<p>Nenhum pedido em Aprovado ou Em produção que dependa de peça. '
           . 'Filme e impressão especial saem direto do fornecedor.</p></div>';
        ovr_g_rodape();
        return;
    }

    $frete = ovr_frete_fornecedor($somaLida ?: $pecasTotal);
    echo '<div class="g-cartoes">';
    ovr_g_cartao('Pedidos', (string) count($pedidos), 'aprovados e em produção');
    ovr_g_cartao('Peças', (string) ($somaLida ?: $pecasTotal), 'somando as grades');
    ovr_g_cartao('Frete numa compra', ovr_reais($frete), 'faixa do fornecedor');
    ovr_g_cartao('Frete por peça', ovr_reais(($somaLida ?: $pecasTotal) > 0 ? (int) round($frete / ($somaLida ?: $pecasTotal)) : 0), 'se comprar tudo junto');
    echo '</div>';

    echo '<h2 class="g-h2">Lista de compra</h2>';
    echo '<div class="g-tabela"><table><thead><tr><th>Peça</th><th>Tamanho</th>'
       . '<th class="g-dir">Quantidade</th><th>Dos pedidos</th></tr></thead><tbody>';
    foreach ($lista as $it) {
        printf('<tr><td data-rot="Peça">%s</td><td data-rot="Tamanho">%s</td>'
             . '<td data-rot="Quantidade" class="g-dir g-num">%d</td><td data-rot="Pedidos">%s</td></tr>',
            esc_html($it['nome']), esc_html($it['tam']), $it['qtd'],
            esc_html(implode(', ', $it['pedidos'])));
    }
    echo '</tbody></table></div>';

    if ($sobras) {
        echo '<h2 class="g-h2">Não consegui ler</h2>';
        echo '<p class="g-lead">Estas linhas não estão no formato que o leitor entende, então não entraram na soma acima. '
           . 'O formato que ele lê é: nome da peça, tamanhos como P ×2, e o total como 12un.</p>';
        echo '<div class="g-tabela"><table><thead><tr><th>Pedido</th><th>Linha</th></tr></thead><tbody>';
        foreach ($sobras as $s) {
            printf('<tr><td data-rot="Pedido"><a class="g-link" href="%s">%s</a></td><td data-rot="Linha">%s</td></tr>',
                esc_url(ovr_g_url('pedido', ['id' => $s['pedido']])),
                esc_html(get_the_title($s['pedido'])), esc_html($s['linha']));
        }
        echo '</tbody></table></div>';
    }
    ovr_g_rodape();
}

/* ------------------------------------------------------------------
   9. Financeiro
------------------------------------------------------------------ */
function ovr_g_barra($valor, $maximo) {
    $p = $maximo > 0 ? max(2, round(($valor / $maximo) * 100)) : 0;
    return '<span class="g-barra"><i style="width:' . (int) $p . '%"></i></span>';
}

function ovr_g_tabela_linhas($rotulo, array $t, $maximo, $link = '') {
    printf('<tr>
              <td data-rot="">%s%s</td>
              <td data-rot="Pedidos" class="g-dir">%d</td>
              <td data-rot="Receita" class="g-dir g-num">%s</td>
              <td data-rot="Custo" class="g-dir g-num">%s</td>
              <td data-rot="Margem" class="g-dir g-num%s">%s</td>
              <td data-rot="%%" class="g-dir">%d%%</td>
              <td data-rot="Ticket" class="g-dir g-num">%s</td>
              <td class="g-barra__col">%s</td>
            </tr>',
        $link ? '<a class="g-link" href="' . esc_url($link) . '">' . esc_html($rotulo) . '</a>' : esc_html($rotulo),
        '', $t['qtd'], esc_html(ovr_reais($t['receita'])), esc_html(ovr_reais($t['custo'])),
        /* Mesmo cuidado do Resumo: linha sem pedido não é prejuízo. Sem
           esta guarda, doze meses vazios saem doze vezes em vermelho e o
           mês que estiver realmente no negativo some no meio.           */
        ($t['qtd'] > 0 && $t['margem'] <= 0) ? ' g-num--ruim' : '', esc_html(ovr_reais($t['margem'])),
        $t['pct'], esc_html(ovr_reais($t['ticket'])), ovr_g_barra($t['receita'], $maximo));
}

function ovr_g_tela_financeiro() {
    $linhas = ovr_carregar_pedidos();
    $tudo   = ovr_totalizar($linhas);
    $mes    = ovr_totalizar(array_filter($linhas, function ($l) { return $l['mes'] === date('Y-m'); }));
    $ano    = ovr_totalizar(array_filter($linhas, function ($l) { return substr($l['mes'], 0, 4) === date('Y'); }));

    ovr_g_cabecalho('Financeiro', 'financeiro');
    echo '<h1 class="g-h1">Financeiro</h1>';
    echo '<p class="g-lead">Receita é item mais frete cobrado, menos desconto. Custo é a peça mais o frete do fornecedor. '
       . 'O que sobra é margem, antes de imposto e do seu tempo.</p>';

    echo '<div class="g-cartoes">';
    /* "1 pedidos" é o tipo de detalhe que faz um painel parecer inacabado. */
    $conta = function ($n, $extra = '') {
        if (!$n) return 'nenhum pedido';
        return $n . ($n === 1 ? ' pedido' : ' pedidos') . $extra;
    };
    ovr_g_cartao('Mês', ovr_reais($mes['receita']), $conta($mes['qtd'], ' · margem ' . $mes['pct'] . '%'));
    ovr_g_cartao('Ano', ovr_reais($ano['receita']), $conta($ano['qtd'], ' · margem ' . $ano['pct'] . '%'));
    ovr_g_cartao('Tudo', ovr_reais($tudo['receita']), $conta($tudo['qtd']));
    ovr_g_cartao('Ticket médio', ovr_reais($tudo['ticket']), 'por pedido');
    echo '</div>';

    /* Faturado x recebido. Um negócio quebra com a primeira coluna
       cheia e a segunda vazia, e nada no painel mostrava isso.     */
    echo '<h2 class="g-h2">Faturado e recebido</h2>';
    echo '<p class="g-lead">A coluna da esquerda é o que os pedidos valem. A da direita é o dinheiro que entrou de verdade. '
       . 'A diferença é o que você ainda tem para cobrar.</p>';
    echo '<div class="g-cartoes">';
    ovr_g_cartao('Faturado', ovr_reais($tudo['receita']), 'tudo que foi vendido');
    ovr_g_cartao('Recebido', ovr_reais($tudo['recebido']),
                 $tudo['receita'] > 0 ? round(($tudo['recebido'] / $tudo['receita']) * 100) . '% do faturado' : 'nada ainda');
    ovr_g_cartao('A receber', ovr_reais($tudo['aReceber']),
                 $tudo['aReceber'] ? 'ainda em aberto' : 'nada em aberto',
                 $tudo['aReceber'] ? 'atencao' : ($tudo['qtd'] ? 'bom' : ''));
    echo '</div>';

    $serie  = ovr_serie_mensal($linhas, 12);
    $maxMes = max(array_map(function ($t) { return $t['receita']; }, $serie)) ?: 1;
    echo '<h2 class="g-h2">Mês a mês</h2><div class="g-tabela"><table><thead><tr>'
       . '<th>Mês</th><th class="g-dir">Pedidos</th><th class="g-dir">Receita</th><th class="g-dir">Custo</th>'
       . '<th class="g-dir">Margem</th><th class="g-dir">%</th><th class="g-dir">Ticket</th><th></th>'
       . '</tr></thead><tbody>';
    foreach (array_reverse($serie, true) as $chave => $t) {
        ovr_g_tabela_linhas(date_i18n('M/Y', strtotime($chave . '-01')), $t, $maxMes);
    }
    echo '</tbody></table></div>';

    $porTipo = ovr_quebrar_por($linhas, 'tipo', ovr_tipos_pedido());
    if ($porTipo) {
        $max = max(array_map(function ($t) { return $t['receita']; }, $porTipo)) ?: 1;
        echo '<h2 class="g-h2">Por linha</h2><div class="g-tabela"><table><thead><tr>'
           . '<th>Linha</th><th class="g-dir">Pedidos</th><th class="g-dir">Receita</th><th class="g-dir">Custo</th>'
           . '<th class="g-dir">Margem</th><th class="g-dir">%</th><th class="g-dir">Ticket</th><th></th>'
           . '</tr></thead><tbody>';
        foreach ($porTipo as $rot => $t) ovr_g_tabela_linhas($rot, $t, $max);
        echo '</tbody></table></div>';
    }

    $porCanal = ovr_quebrar_por($linhas, 'canal', ovr_canais());
    if ($porCanal) {
        $max = max(array_map(function ($t) { return $t['receita']; }, $porCanal)) ?: 1;
        echo '<h2 class="g-h2">Por canal</h2><div class="g-tabela"><table><thead><tr>'
           . '<th>Canal</th><th class="g-dir">Pedidos</th><th class="g-dir">Receita</th><th class="g-dir">Custo</th>'
           . '<th class="g-dir">Margem</th><th class="g-dir">%</th><th class="g-dir">Ticket</th><th></th>'
           . '</tr></thead><tbody>';
        foreach ($porCanal as $rot => $t) ovr_g_tabela_linhas($rot, $t, $max);
        echo '</tbody></table></div>';
    }

    $faltando = array_filter($linhas, function ($l) { return $l['semCusto']; });
    if ($faltando) {
        echo '<h2 class="g-h2">Custo em falta</h2>';
        echo '<p class="g-lead">Estes têm receita lançada e nenhum custo. Enquanto ficarem assim, a margem lá em cima está maior do que é.</p>';
        echo '<div class="g-tabela"><table><thead><tr><th>Pedido</th><th class="g-dir">Receita</th><th>Situação</th></tr></thead><tbody>';
        foreach ($faltando as $l) {
            printf('<tr><td data-rot="Pedido"><a class="g-link" href="%s">%s</a></td>'
                 . '<td data-rot="Receita" class="g-dir g-num">%s</td><td data-rot="Situação">%s</td></tr>',
                esc_url(ovr_g_url('pedido', ['id' => $l['id']])), esc_html($l['titulo']),
                esc_html(ovr_reais($l['receita'])), ovr_g_selo($l['situacao']));
        }
        echo '</tbody></table></div>';
    }
    ovr_g_rodape();
}

/* ------------------------------------------------------------------
   10. Frete
   Só leitura. A edição continua no wp-admin porque é lá que mora a
   validação do token, e ter dois formulários gravando a mesma opção
   é como ter dois relógios: nunca dá a mesma hora.
------------------------------------------------------------------ */
function ovr_g_tela_frete() {
    $o = ovr_frenet_opcoes();
    ovr_g_cabecalho('Frete', 'frete');
    echo '<h1 class="g-h1">Frete</h1>';
    echo '<p class="g-lead">Duas coisas diferentes com o mesmo nome. O de cima é o que o cliente paga para receber. '
       . 'O de baixo é o que você paga para a peça chegar até você, e some da sua margem.</p>';

    echo '<h2 class="g-h2">Cotação para o cliente</h2><div class="g-cartoes">';
    ovr_g_cartao('Frenet', $o['ativo'] ? 'Ligada' : 'Desligada', $o['ativo'] ? 'o carrinho cota sozinho' : 'o carrinho mostra "a combinar"', $o['ativo'] ? 'bom' : 'atencao');
    ovr_g_cartao('CEP de origem', $o['cep_origem'] ? substr($o['cep_origem'], 0, 5) . '-' . substr($o['cep_origem'], 5) : 'não definido', 'de onde a caixa sai');
    ovr_g_cartao('Produção', $o['dias_producao'] . ' dias úteis', 'somados ao prazo da transportadora');
    ovr_g_cartao('Token', $o['token'] ? 'configurado' : 'em falta', $o['token'] ? 'guardado nas opções' : 'sem ele não cota', $o['token'] ? '' : 'ruim');
    echo '</div>';
    printf('<p class="g-nota"><a class="g-link" href="%s">Editar no wp-admin</a></p>',
           esc_url(admin_url('edit.php?post_type=pedido&page=ovr-frete')));

    echo '<h2 class="g-h2">Frete do fornecedor até você</h2>';
    echo '<p class="g-lead">Cobrado por faixa de quantidade. Cada faixa paga o teto dela, então 3 peças pagam a faixa de 5: '
       . 'o custo erra para mais, nunca para menos. Esta tabela também vive no config.js do site. Mudou aqui, muda lá.</p>';
    echo '<div class="g-tabela"><table><thead><tr><th>Até</th><th class="g-dir">Valor</th><th class="g-dir">Por peça no teto</th></tr></thead><tbody>';
    foreach (ovr_frete_fornecedor_faixas() as $f) {
        printf('<tr><td data-rot="Até">%d peça%s</td><td data-rot="Valor" class="g-dir g-num">%s</td>'
             . '<td data-rot="Por peça" class="g-dir g-num">%s</td></tr>',
            $f['ate'], $f['ate'] === 1 ? '' : 's', esc_html(ovr_reais($f['valor'])),
            esc_html(ovr_reais((int) round($f['valor'] / $f['ate']))));
    }
    printf('<tr><td data-rot="Até">acima de 100</td><td data-rot="Valor" class="g-dir g-num">%s por peça</td><td></td></tr>',
           esc_html(ovr_reais(OVR_FRETE_POR_PECA_ACIMA)));
    echo '</tbody></table></div>';
    ovr_g_rodape();
}

/* ------------------------------------------------------------------
   11. Estilo
   Mesmo vocabulário do site: Space Grotesk nos títulos, Inter no
   corpo, tinta, papel e volt. Nada de cinza de wp-admin.
------------------------------------------------------------------ */
function ovr_g_estilo() {
    return <<<CSS
*,*::before,*::after{box-sizing:border-box}
body{margin:0;background:#fbfaf6;color:#090a0c;font-family:'Inter',-apple-system,BlinkMacSystemFont,sans-serif;font-size:15px;line-height:1.6;-webkit-font-smoothing:antialiased}
a{color:inherit}
.g-envelope{max-width:1240px;margin:0 auto;padding:0 24px}
.g-topo{background:#090a0c;color:#fbfaf6;position:sticky;top:0;z-index:10}
.g-topo__interno{display:flex;align-items:center;gap:28px;min-height:68px;flex-wrap:wrap}
.g-marca{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:19px;letter-spacing:-.02em;text-decoration:none}
.g-marca span{color:#c9ff00;font-size:12px;letter-spacing:.14em;text-transform:uppercase;margin-left:6px;vertical-align:2px}
.g-nav{display:flex;gap:22px;flex:1;flex-wrap:wrap}
.g-nav a{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:12px;letter-spacing:.1em;text-transform:uppercase;text-decoration:none;color:#9a9a94;padding:4px 0;border-bottom:2px solid transparent}
.g-nav a:hover{color:#fbfaf6}
.g-nav .g-nav--ativa,.g-nav a.g-nav--ativa{color:#fbfaf6;border-bottom-color:#c9ff00}
.g-topo__acoes{display:flex;align-items:center;gap:16px}
.g-quem{font-size:12px;color:#9a9a94}
.g-quem a{color:#9a9a94}
.g-corpo{padding-block:36px 72px}
.g-h1{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:38px;letter-spacing:-.03em;line-height:1.05;margin:0 0 8px}
.g-h2{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:15px;letter-spacing:.1em;text-transform:uppercase;margin:40px 0 14px;color:#6b6b66}
.g-h3{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:13px;letter-spacing:.1em;text-transform:uppercase;margin:0 0 16px;color:#6b6b66}
.g-lead{color:#6b6b66;max-width:74ch;margin:0 0 4px}
.g-nota{font-size:12px;color:#6b6b66}
.g-ok{background:#c9ff00;padding:12px 16px;font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:12px;letter-spacing:.1em;text-transform:uppercase;margin:0 0 24px}
.g-ok--linha{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
.g-desfazer{background:none;border:0;padding:0;font:inherit;color:#090a0c;text-decoration:underline;text-underline-offset:3px;cursor:pointer}
.g-descartar{border-top:1px solid #e6e3db;margin-top:20px;padding-top:18px;display:flex;flex-direction:column;gap:8px}
.g-descartar__btn{align-self:flex-start;background:none;border:0;padding:0;font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#b00020;text-decoration:underline;text-underline-offset:4px;cursor:pointer}
.g-cartoes{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px}
.g-cartao{background:#fff;border:1px solid #e6e3db;padding:18px 20px;display:flex;flex-direction:column;gap:2px}
.g-cartao__rot{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:#6b6b66}
.g-cartao__val{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:27px;letter-spacing:-.02em;line-height:1.15}
.g-cartao__nota{font-size:12px;color:#6b6b66}
.g-cartao--bom .g-cartao__val{color:#0a7d33}
.g-cartao--ruim .g-cartao__val{color:#b00020}
.g-cartao--atencao .g-cartao__val{color:#9f5d00}
.g-funil{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px}
.g-funil__item{background:#fff;border:1px solid #e6e3db;padding:14px 16px;text-decoration:none;display:flex;flex-direction:column;gap:2px}
.g-funil__item span{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:10px;letter-spacing:.12em;text-transform:uppercase}
.g-funil__item strong{font-family:'Space Grotesk',sans-serif;font-size:24px;letter-spacing:-.02em}
.g-funil__item:hover{border-color:#090a0c}
.g-filtros{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:20px 0 22px}
.g-campo{border:1px solid #d6d3ca;background:#fff;padding:11px 13px;font:inherit;font-size:14px;color:#090a0c;width:100%}
.g-filtros .g-campo{width:auto;min-width:200px}
.g-campo:focus{outline:2px solid #090a0c;outline-offset:-2px}
.g-limpar{font-size:12px;color:#6b6b66}
.g-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:13px 22px;font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:12px;letter-spacing:.1em;text-transform:uppercase;text-decoration:none;border:1px solid transparent;cursor:pointer}
.g-btn--volt{background:#c9ff00;color:#090a0c}
.g-btn--linha{background:transparent;border-color:#d6d3ca;color:#090a0c}
.g-btn--linha:hover{border-color:#090a0c}
.g-tabela{overflow-x:auto;background:#fff;border:1px solid #e6e3db}
.g-tabela table{width:100%;border-collapse:collapse;font-size:14px}
.g-tabela th{text-align:left;font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:#6b6b66;padding:14px 16px;border-bottom:1px solid #e6e3db;white-space:nowrap}
.g-tabela td{padding:14px 16px;border-bottom:1px solid #f0eee8;vertical-align:middle}
.g-tabela tr:last-child td{border-bottom:0}
.g-dir{text-align:right}
.g-num{font-family:'Space Grotesk',sans-serif;font-weight:700;letter-spacing:-.01em;white-space:nowrap}
.g-num--ruim{color:#b00020}
.g-num--atencao{color:#9f5d00}
.g-filas{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px}
.g-fila{background:#fff;border:1px solid #e6e3db;padding:18px 20px;display:flex;flex-direction:column;gap:6px}
.g-fila__topo{display:flex;align-items:baseline;justify-content:space-between;gap:12px}
.g-fila__topo h3{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:13px;letter-spacing:.08em;text-transform:uppercase;margin:0}
.g-fila__n{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:22px;letter-spacing:-.02em}
.g-fila__lista{list-style:none;margin:8px 0 0;padding:0;display:flex;flex-direction:column}
.g-fila__lista li{display:grid;grid-template-columns:auto 1fr auto;gap:10px;align-items:baseline;padding:8px 0;border-top:1px solid #f0eee8;font-size:13px}
.g-fila__lista li span{color:#6b6b66;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.g-fila__lista li em{font-style:normal;font-size:11px;color:#6b6b66;white-space:nowrap}
.g-troca{display:inline-flex;gap:6px;align-items:center}
.g-troca__sel{border:1px solid #e6e3db;background:#fff;padding:6px 8px;font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:var(--selo);cursor:pointer;max-width:150px}
.g-troca__sel:hover{border-color:#090a0c}
.g-troca__ok{display:none;background:#090a0c;color:#fbfaf6;border:0;padding:6px 10px;font-size:10px;letter-spacing:.08em;text-transform:uppercase;cursor:pointer}
.no-js .g-troca__ok{display:inline-block}
.g-estado__grade{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px}
.g-estado__grade .g-grupo{margin-bottom:0}
.g-recolhe summary{list-style:none;cursor:pointer;display:flex;align-items:baseline;justify-content:space-between;gap:16px}
.g-recolhe summary::-webkit-details-marker{display:none}
.g-recolhe summary::after{content:'abrir';font-size:11px;color:#6b6b66;text-decoration:underline;text-underline-offset:3px}
.g-recolhe[open] summary::after{content:'fechar'}
.g-recolhe summary .g-h3{margin:0}
.g-recolhe__resumo{flex:1;font-size:13px;color:#6b6b66;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.g-recolhe__corpo{padding-top:18px;margin-top:16px;border-top:1px solid #f0eee8}
.g-vazio--curto{padding:32px 24px}
.g-tempo{display:flex;flex-direction:column}
.g-tempo__item{display:grid;grid-template-columns:130px 1fr auto;gap:14px;align-items:baseline;padding:9px 0;border-top:1px solid #f0eee8;font-size:13px}
.g-tempo__item:first-child{border-top:0}
.g-tempo__quando{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:11px;letter-spacing:.06em;color:#6b6b66;white-space:nowrap}
.g-tempo__quem{font-size:11px;color:#6b6b66;white-space:nowrap}
@media (max-width:760px){ .g-tempo__item{grid-template-columns:1fr;gap:2px} }
.g-link{font-weight:500;text-underline-offset:3px}
.g-atraso{color:#b00020;font-weight:500}
.g-aviso{display:inline-block;background:#fff3d6;color:#8a5a00;font-size:10px;letter-spacing:.08em;text-transform:uppercase;padding:2px 6px;margin-left:6px;white-space:nowrap}
/* Selo do cupom. Mesma caixinha do aviso de custo, em duas cores: verde
   quando a pessoa tinha direito, vermelho quando não tinha. Sem
   margem-left porque ele entra numa linha própria, abaixo do nome. */
.g-cupom{display:inline-block;font-size:10px;letter-spacing:.08em;text-transform:uppercase;padding:2px 6px;margin-top:3px;white-space:nowrap}
.g-cupom--ok{background:#e3f5e8;color:#0a5c28}
.g-cupom--alerta{background:#fde8e8;color:#9b1c1c}
.g-selo{display:inline-flex;align-items:center;gap:6px;font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:var(--selo)}
.g-selo::before{content:'';width:7px;height:7px;background:var(--selo);border-radius:50%}
.g-barra__col{width:120px}
.g-barra{display:block;background:#f0eee8;height:6px;width:100%}
.g-barra i{display:block;background:#2749ff;height:6px}
.g-vazio{background:#fff;border:1px solid #e6e3db;padding:48px 32px;text-align:center;margin-top:24px}
.g-vazio h2{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:22px;letter-spacing:-.02em;margin:0 0 8px}
.g-vazio p{color:#6b6b66;margin:0 auto 20px;max-width:52ch}
.g-cabeca{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;flex-wrap:wrap}
.g-cabeca__lado{display:flex;align-items:center;gap:14px}
.g-colunas{display:grid;grid-template-columns:1fr 380px;gap:24px;align-items:start;margin-top:24px}
.g-coluna{display:flex;flex-direction:column;gap:16px}
.g-coluna--lado{position:sticky;top:88px}
.g-bloco{background:#fff;border:1px solid #e6e3db;padding:22px 24px}
.g-bloco--resultado{background:#f4f2ed}
.g-grupo{display:flex;flex-direction:column;gap:6px;margin-bottom:16px}
.g-grupo:last-child{margin-bottom:0}
.g-rot{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:#6b6b66}
.g-dica{font-size:12px;color:#6b6b66}
.g-linha{display:flex;justify-content:space-between;align-items:baseline;gap:16px;padding:7px 0;font-size:14px}
.g-linha--total{border-top:1px solid #dedbd2;padding-top:13px;margin-top:6px}
.g-linha strong{font-family:'Space Grotesk',sans-serif;font-size:19px;letter-spacing:-.02em}
.g-linha small{font-size:12px;color:#6b6b66;font-weight:400}
.g-acoes{display:flex;flex-direction:column;gap:10px;align-items:stretch}
.g-acoes .g-limpar{text-align:center;padding-top:4px}
.g-rodape{border-top:1px solid #e6e3db;padding-block:22px;font-size:12px;color:#6b6b66}
@media (max-width:900px){
  .g-colunas{grid-template-columns:1fr}
  .g-coluna--lado{position:static}
}
@media (max-width:760px){
  .g-envelope{padding:0 16px}
  .g-h1{font-size:30px}
  .g-topo__interno{min-height:0;padding-block:14px}
  .g-nav{order:3;width:100%;gap:16px}
  .g-tabela{border:0;background:transparent;overflow:visible}
  .g-tabela table,.g-tabela tbody,.g-tabela tr,.g-tabela td{display:block;width:100%}
  .g-tabela thead{display:none}
  .g-tabela tr{background:#fff;border:1px solid #e6e3db;margin-bottom:10px;padding:6px 0}
  .g-tabela td{border:0;display:flex;justify-content:space-between;gap:16px;padding:7px 16px;text-align:left}
  .g-tabela td::before{content:attr(data-rot);font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:#6b6b66}
  .g-tabela td:empty,.g-barra__col{display:none}
  .g-dir{text-align:right}
}
CSS;
}
