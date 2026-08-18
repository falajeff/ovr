<?php
/**
 * Recepção de pedido vindo do site.
 *
 * Este é o único ponto do painel que aceita escrita de quem não fez login.
 * Tudo aqui é escrito partindo do princípio de que o que chega é hostil até
 * provar o contrário: origem confere, cadência confere, arquivo confere.
 */

if (!defined('ABSPATH')) exit;

/* Quem pode falar com este endpoint. Sem curinga: é a loja e mais nada. */
function ovr_origens_permitidas() {
    return [
        'https://ovrcamisetas.com.br',
        'https://www.ovrcamisetas.com.br',
    ];
}

/* Teto de arquivo e o que aceitamos. SVG fica de fora de propósito:
   é XML, executa script, e não serve para impressão mesmo.            */
function ovr_arte_aceita() {
    return [
        'png'          => 'image/png',
        'jpg|jpeg'     => 'image/jpeg',
        'tif|tiff'     => 'image/tiff',
        'pdf'          => 'application/pdf',
    ];
}
const OVR_ARTE_MAX_BYTES = 26214400;   // 25 MB, o mesmo do guia de arte
const OVR_TETO_POR_IP    = 5;          // pedidos por hora, por IP
const OVR_SEGUNDOS_MINIMOS = 4;        // ninguém preenche um pedido em 4s

/* ------------------------------------------------------------------
   1. Pasta das artes — fora do alcance de quem sabe a URL
------------------------------------------------------------------ */
/* Arte de cliente é propriedade dele. O diretório padrão de uploads é
   público: quem descobre o caminho baixa. Então a arte vai para uma
   pasta com o acesso direto negado, e sai só pelo download autenticado
   mais abaixo.                                                        */
function ovr_pasta_artes() {
    $up   = wp_upload_dir();
    $dir  = trailingslashit($up['basedir']) . 'ovr-artes';
    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
    }
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) {
        /* Apache 2.2 e 2.4 falam dialetos diferentes; as duas formas
           juntas cobrem os dois sem quebrar nenhum.                   */
        file_put_contents($ht, "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
                             . "<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n");
    }
    if (!file_exists($dir . '/index.html')) file_put_contents($dir . '/index.html', '');
    return $dir;
}

/* ------------------------------------------------------------------
   2. O endpoint
------------------------------------------------------------------ */
add_action('rest_api_init', function () {
    register_rest_route('ovr/v1', '/pedido', [
        'methods'             => 'POST',
        'callback'            => 'ovr_receber_pedido',
        'permission_callback' => '__return_true',   // público de propósito; a porta é checada dentro
    ]);
});

/* O navegador manda um OPTIONS antes do POST com arquivo. Sem resposta
   a esse preflight o pedido nem sai da loja.                          */
add_action('rest_api_init', function () {
    remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
    add_filter('rest_pre_serve_request', function ($servido) {
        $origem = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (in_array($origem, ovr_origens_permitidas(), true)) {
            header('Access-Control-Allow-Origin: ' . $origem);
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Access-Control-Max-Age: 600');
        }
        header('Vary: Origin');
        return $servido;
    });
}, 15);

function ovr_erro($codigo, $mensagem, $status = 400) {
    return new WP_Error($codigo, $mensagem, ['status' => $status]);
}

function ovr_receber_pedido(WP_REST_Request $req) {

    /* --- porta 1: origem ------------------------------------------ */
    $origem = $req->get_header('origin') ?: '';
    if (!in_array($origem, ovr_origens_permitidas(), true)) {
        return ovr_erro('ovr_origem', 'Origem não autorizada.', 403);
    }

    /* --- porta 2: cadência ---------------------------------------- */
    $ip    = ovr_ip_do_visitante();
    $chave = 'ovr_ritmo_' . md5($ip);
    $conta = (int) get_transient($chave);
    if ($conta >= OVR_TETO_POR_IP) {
        return ovr_erro('ovr_ritmo', 'Muitos pedidos seguidos. Tente de novo daqui a pouco.', 429);
    }

    /* --- porta 3: o corpo ----------------------------------------- */
    $cru = $req->get_param('pedido');
    $dados = json_decode(is_string($cru) ? $cru : '', true);
    if (!is_array($dados)) {
        return ovr_erro('ovr_corpo', 'Pedido ilegível.');
    }

    /* Isca: campo escondido que só robô preenche. */
    if (!empty($dados['empresa'])) {
        return ovr_erro('ovr_isca', 'Pedido recusado.', 422);
    }

    /* Tempo de preenchimento. `t` sai do site quando a página abre. */
    $t = (int) ($dados['t'] ?? 0);
    $agora = time();
    if ($t <= 0 || ($agora - $t) < OVR_SEGUNDOS_MINIMOS || ($agora - $t) > 7200) {
        return ovr_erro('ovr_tempo', 'Pedido expirado. Recarregue a página e envie de novo.', 422);
    }

    /* --- porta 4: conteúdo mínimo --------------------------------- */
    $cliente = is_array($dados['cliente'] ?? null) ? $dados['cliente'] : [];
    $nome    = sanitize_text_field($cliente['nome'] ?? '');
    $zap     = sanitize_text_field($cliente['zap'] ?? '');
    $email   = sanitize_email($cliente['email'] ?? '');
    $itens   = is_array($dados['itens'] ?? null) ? $dados['itens'] : [];

    if ($nome === '')                 return ovr_erro('ovr_nome', 'Falta o nome.');
    if ($zap === '' && $email === '') return ovr_erro('ovr_contato', 'Falta WhatsApp ou e-mail.');
    if (!$itens)                      return ovr_erro('ovr_itens', 'O carrinho está vazio.');
    if (count($itens) > 60)           return ovr_erro('ovr_itens', 'Pedido grande demais para o site. Fale com a gente.');

    /* --- cria o pedido -------------------------------------------- */
    $post_id = wp_insert_post([
        'post_type'   => 'pedido',
        'post_status' => 'publish',
        'post_title'  => '',        // o filtro do pedidos.php numera
    ], true);

    if (is_wp_error($post_id)) {
        return ovr_erro('ovr_gravar', 'Não consegui registrar o pedido.', 500);
    }

    /* Só conta no teto depois de dar certo: pedido recusado por validação
       não deve queimar a cota de quem está só errando o formulário.    */
    set_transient($chave, $conta + 1, HOUR_IN_SECONDS);

    $meta = [
        '_ovr_cliente_nome'   => $nome,
        '_ovr_cliente_zap'    => $zap,
        '_ovr_cliente_email'  => $email,
        '_ovr_cliente_cidade' => sanitize_text_field($cliente['cidade'] ?? ''),
        '_ovr_canal'          => 'site',
        '_ovr_situacao'       => 'novo',
        '_ovr_tipo'           => ovr_tipo_valido($dados['tipo'] ?? ''),
        '_ovr_itens'          => ovr_itens_em_texto($itens),
        '_ovr_itens_json'     => wp_json_encode($itens, JSON_UNESCAPED_UNICODE),
        '_ovr_estampa'        => sanitize_text_field($dados['estampa'] ?? ''),
        /* Só peça que sai do fornecedor de malha gera frete até você. Filme e DTG vão
           do fornecedor direto ao cliente e não passam pela sua casa. */
        '_ovr_qtd_pecas'      => ovr_contar_pecas($itens),
        '_ovr_embalagem'      => sanitize_text_field($dados['embalagem'] ?? ''),
        '_ovr_obs'            => sanitize_textarea_field($dados['obs'] ?? ''),
        '_ovr_valor_itens'    => max(0, (int) ($dados['total'] ?? 0)),
        /* Frete que o CLIENTE escolheu e vai pagar. É receita, e não se
           confunde com `_ovr_frete_fornecedor`, que é o que você paga
           para lá. Dois fretes, sentidos opostos.               */
        '_ovr_valor_frete'    => max(0, (int) ($dados['frete']['valor'] ?? 0)),
        '_ovr_origem_site'    => 1,     // marca que o valor veio calculado de fora
        '_ovr_ip'             => $ip,
    ];
    foreach ($meta as $k => $v) update_post_meta($post_id, $k, $v);

    /* Frete do fornecedor pela quantidade. Precisa vir DEPOIS do laço, que é
       quem grava a quantidade. Sem esta chamada o pedido do site chegava
       com frete zero e a margem do Financeiro nascia inflada: o gancho
       do formulário não roda aqui, porque a API não manda nonce.      */
    ovr_aplicar_frete_automatico($post_id);

    /* --- a arte --------------------------------------------------- */
    $arte = ovr_guardar_arte($post_id);
    if (is_wp_error($arte)) {
        /* O pedido já vale mesmo sem a arte: melhor registrar e avisar
           do que perder o cliente por causa de um arquivo.            */
        update_post_meta($post_id, '_ovr_arte_situacao', 'sem');
        update_post_meta($post_id, '_ovr_arte_nota', 'O envio do arquivo falhou: ' . $arte->get_error_message());
    } elseif ($arte) {
        update_post_meta($post_id, '_ovr_arte_situacao', 'recebida');
        update_post_meta($post_id, '_ovr_arte_anexo', $arte);
    } else {
        /* Sem arquivo: ou ele manda depois, ou contratou a criação. */
        $quer_arte = !empty($dados['arte_servico']);
        update_post_meta($post_id, '_ovr_arte_situacao', $quer_arte ? 'nossa' : 'sem');
    }

    ovr_avisar_pedido_novo($post_id);

    return new WP_REST_Response([
        'ok'     => true,
        'numero' => get_the_title($post_id),
        'arte'   => is_wp_error($arte) ? 'falhou' : ($arte ? 'recebida' : 'sem'),
    ], 201);
}

/* ------------------------------------------------------------------
   3. Peças de apoio
------------------------------------------------------------------ */

/* Atrás de proxy o REMOTE_ADDR é o proxy. Só confiamos no cabeçalho
   encaminhado se ele existir; e pegamos o primeiro salto, que é o
   único que o cliente não escolhe sozinho na infra da Hostinger.      */
function ovr_ip_do_visitante() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $c) {
        if (empty($_SERVER[$c])) continue;
        $ip = trim(explode(',', $_SERVER[$c])[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return '0.0.0.0';
}

function ovr_tipo_valido($t) {
    return array_key_exists($t, ovr_tipos_pedido()) ? $t : 'misto';
}

/* Quantas peças o pedido tem, contando só o que vem do fornecedor de malha.
   Item sem `tipo` conta como peça: o carrinho só manda tipo explícito
   em filme e arte, então a ausência significa camiseta.               */
function ovr_contar_pecas($itens) {
    $total = 0;
    foreach ((array) $itens as $i) {
        if (!is_array($i)) continue;
        $tipo = strtolower((string) ($i['tipo'] ?? 'dtf'));
        if (in_array($tipo, ['filme', 'arte', 'dtg'], true)) continue;
        $total += max(0, (int) ($i['qtd'] ?? 0));
    }
    return $total;
}

/* Vira as linhas que ele lê na tela. O JSON fica guardado à parte para
   quando o painel precisar somar; isto aqui é para o olho humano.     */
function ovr_itens_em_texto($itens) {
    $linhas = [];
    foreach ($itens as $i) {
        if (!is_array($i)) continue;
        $partes = array_filter([
            sanitize_text_field($i['produto'] ?? $i['nome'] ?? 'Item'),
            sanitize_text_field($i['grade']   ?? ''),
            isset($i['qtd']) ? ((int) $i['qtd']) . 'un' : '',
            isset($i['unitario']) ? ovr_reais((int) $i['unitario']) : '',
        ]);
        $linhas[] = implode(' · ', $partes);
    }
    return implode("\n", $linhas);
}

/* Guarda o arquivo na pasta protegida e cria o anexo.
   Devolve id do anexo, 0 se não veio arquivo, WP_Error se deu ruim.   */
function ovr_guardar_arte($post_id) {
    if (empty($_FILES['arte']) || ($_FILES['arte']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return 0;
    }
    $f = $_FILES['arte'];
    if ($f['error'] !== UPLOAD_ERR_OK)          return new WP_Error('upload', 'erro ' . $f['error']);
    if ($f['size'] > OVR_ARTE_MAX_BYTES)        return new WP_Error('grande', 'passa de 25 MB');
    if (!is_uploaded_file($f['tmp_name']))      return new WP_Error('suspeito', 'arquivo inválido');

    /* Confere o que o arquivo É, não o que ele diz ser: wp_check_filetype_and_ext
       lê os bytes e compara com a extensão. Nome mentiroso morre aqui. */
    $checa = wp_check_filetype_and_ext($f['tmp_name'], $f['name'], ovr_arte_aceita());
    if (empty($checa['ext']) || empty($checa['type'])) {
        return new WP_Error('formato', 'formato não aceito — mande PNG, JPG, TIFF ou PDF');
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $dir  = ovr_pasta_artes();
    $base = sanitize_file_name(pathinfo($checa['proper_filename'] ?: $f['name'], PATHINFO_FILENAME));
    $nome = wp_unique_filename($dir, ($base ?: 'arte') . '.' . $checa['ext']);
    $alvo = trailingslashit($dir) . $nome;

    if (!@move_uploaded_file($f['tmp_name'], $alvo)) {
        return new WP_Error('mover', 'não consegui salvar o arquivo');
    }
    @chmod($alvo, 0644);

    $anexo = wp_insert_attachment([
        'post_mime_type' => $checa['type'],
        'post_title'     => $base ?: 'arte',
        'post_status'    => 'inherit',
        'post_parent'    => $post_id,
    ], $alvo, $post_id, true);

    if (is_wp_error($anexo)) { @unlink($alvo); return $anexo; }

    /* Sem gerar miniaturas: a pasta é fechada, ninguém vai ver thumb,
       e TIFF grande derruba a memória do PHP tentando redimensionar.  */
    update_post_meta($anexo, '_wp_attached_file', 'ovr-artes/' . $nome);
    return $anexo;
}

/* ------------------------------------------------------------------
   4. Download da arte — só para quem tem acesso ao painel
------------------------------------------------------------------ */
add_action('admin_post_ovr_arte', function () {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id || !current_user_can('edit_pedidos')) wp_die('Sem permissão.', '', ['response' => 403]);
    /* Nonce além da capacidade: impede que um link plantado em outro site
       faça o navegador logado dele baixar arquivo sem querer.          */
    check_admin_referer('ovr_arte_' . $id);

    $anexo = get_post($id);
    if (!$anexo || $anexo->post_type !== 'attachment') wp_die('Arquivo não encontrado.', '', ['response' => 404]);

    $caminho = get_attached_file($id);
    if (!$caminho || !file_exists($caminho)) wp_die('Arquivo não encontrado.', '', ['response' => 404]);

    /* Trava de caminho: só serve o que está dentro da pasta de artes.
       Sem isto, um id torto viraria leitura de arquivo arbitrário.    */
    $raiz = realpath(ovr_pasta_artes());
    $real = realpath($caminho);
    if (!$raiz || !$real || strpos($real, $raiz) !== 0) wp_die('Caminho inválido.', '', ['response' => 400]);

    nocache_headers();
    header('Content-Type: ' . $anexo->post_mime_type);
    header('Content-Length: ' . filesize($real));
    header('Content-Disposition: attachment; filename="' . basename($real) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($real);
    exit;
});

/* ------------------------------------------------------------------
   5. Aviso por e-mail — pedido do site não pode passar despercebido
------------------------------------------------------------------ */
function ovr_avisar_pedido_novo($post_id) {
    $m = fn($k) => get_post_meta($post_id, $k, true);
    $numero = get_the_title($post_id);
    $corpo  = [
        'Pedido ' . $numero . ' entrou pelo site.',
        '',
        'Cliente: ' . $m('_ovr_cliente_nome'),
        'WhatsApp: ' . ($m('_ovr_cliente_zap') ?: '—'),
        'E-mail: ' . ($m('_ovr_cliente_email') ?: '—'),
        'Cidade: ' . ($m('_ovr_cliente_cidade') ?: '—'),
        '',
        'Itens:',
        $m('_ovr_itens'),
        '',
        'Estampa: ' . ($m('_ovr_estampa') ?: '—'),
        'Valor calculado no site: ' . ovr_reais((int) $m('_ovr_valor_itens')),
        'Arte: ' . (ovr_situacoes_arte()[$m('_ovr_arte_situacao')] ?? '—'),
        '',
        'Abrir: ' . admin_url('post.php?post=' . $post_id . '&action=edit'),
    ];
    wp_mail(get_option('admin_email'), 'OVR · pedido ' . $numero . ' pelo site',
            implode("\n", $corpo));
}
