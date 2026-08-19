<?php
/* Cupom: o lado que decide.
 *
 * O site mostra o desconto; aqui é onde ele vale. A divisão é essa de
 * propósito:
 *
 *   o site   confere se o CPF existe e calcula quanto sai
 *   o painel confere se a pessoa JÁ COMPROU, que é o que o site não
 *            tem como saber — pedido mora aqui
 *
 * O painel NÃO refaz a conta do desconto. Se refizesse, percentual e
 * teto passariam a existir em dois lugares e divergiriam no dia em que
 * um dos dois fosse editado. Ele confere a elegibilidade, marca o
 * pedido e deixa o número como veio, porque na OVR quem fecha a venda
 * é gente: o pedido do site é orçamento, não cobrança.
 *
 * Sinaliza em vez de recusar. Pedido de cliente que já comprou não é
 * fraude, é cliente voltando — e recusar por causa de um cupom seria
 * perder a venda para provar um ponto. */
if (!defined('ABSPATH')) exit;

/* ------------------------------------------------------------------
   Dígito verificador

   Mesma conta do site (api/documento.php). Duplicada porque site e
   painel são dois programas que sobem separados, e um require entre
   servidores seria pior que dez linhas repetidas. É seguro duplicar:
   a regra do DV do CPF não muda desde 1965.

   `ord - 48` no lugar do dígito porque desde julho de 2026 o CNPJ
   aceita letra nas doze primeiras posições. Para '0'..'9' dá no mesmo,
   então CNPJ antigo valida pelo mesmo caminho.                       */
function ovr_dv_doc(string $base, array $pesos): string {
    $soma = 0;
    for ($i = 0, $n = strlen($base); $i < $n; $i++) $soma += (ord($base[$i]) - 48) * $pesos[$i];
    $r = $soma % 11;
    return (string) ($r < 2 ? 0 : 11 - $r);
}

function ovr_documento_normalizar($bruto): ?array {
    $d = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) $bruto));

    if (strlen($d) === 11 && preg_match('/^\d{11}$/', $d) && !preg_match('/^(\d)\1{10}$/', $d)
        && $d[9]  === ovr_dv_doc(substr($d, 0, 9),  [10, 9, 8, 7, 6, 5, 4, 3, 2])
        && $d[10] === ovr_dv_doc(substr($d, 0, 10), [11, 10, 9, 8, 7, 6, 5, 4, 3, 2])) {
        return ['tipo' => 'cpf', 'numero' => $d];
    }
    if (strlen($d) === 14 && preg_match('/^[0-9A-Z]{12}\d{2}$/', $d) && !preg_match('/^(.)\1{13}$/', $d)
        && $d[12] === ovr_dv_doc(substr($d, 0, 12), [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2])
        && $d[13] === ovr_dv_doc(substr($d, 0, 13), [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2])) {
        return ['tipo' => 'cnpj', 'numero' => $d];
    }
    return null;
}

/* ------------------------------------------------------------------
   Identidade sem o número

   O CPF nunca é gravado. O que fica no banco é um HMAC dele, que serve
   para comparar dois pedidos e não serve para mais nada.

   HMAC e não SHA-256 puro: são 10^11 CPF possíveis, e a tabela inteira
   cabe num disco comum. Com a chave no meio, quem levar um export do
   banco leva bytes sem volta.

   A chave nasce sozinha na primeira vez e fica em wp_options. Isso a
   coloca no mesmo banco que ela protege — então ela não defende contra
   quem leve o banco inteiro. Defende contra o caso realista, que é um
   export de tabela circulando. E não exige que ninguém edite
   wp-config.php na mão para o cupom funcionar.

   Trocar a chave zera o histórico: todo mundo vira primeira compra de
   novo. É por isso que ela é criada uma vez e nunca mais.            */
function ovr_doc_chave(): string {
    $c = get_option('ovr_doc_chave');
    if (!$c) {
        $c = bin2hex(random_bytes(32));
        /* autoload 'no': a chave é lida só quando alguém usa cupom, e
           não precisa viajar em toda carga de página do painel. */
        add_option('ovr_doc_chave', $c, '', 'no');
    }
    return $c;
}

function ovr_doc_hash(string $numero): string {
    return hash_hmac('sha256', $numero, ovr_doc_chave());
}

/* Já existe pedido com esta identidade? Exclui o pedido que está sendo
   criado agora, senão ele se acharia repetido de si mesmo. */
function ovr_ja_comprou(string $hash, int $exceto = 0): bool {
    $achados = get_posts([
        'post_type'      => 'pedido',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'exclude'        => $exceto ? [$exceto] : [],
        'meta_key'       => '_ovr_doc_hash',
        'meta_value'     => $hash,
        'no_found_rows'  => true,
    ]);
    return !empty($achados);
}

/* ------------------------------------------------------------------
   O que gravar no pedido

   Devolve a meta pronta. Três estados possíveis para o cupom:

     ''          não usou cupom
     'ok'        usou e tem direito
     'repetido'  usou, mas já tem pedido anterior — o painel avisa
     'sem-doc'   usou e o documento não confere                       */
function ovr_meta_do_cupom(array $dados, int $post_id): array {
    $codigo = strtoupper(sanitize_text_field($dados['cupom'] ?? ''));
    $doc    = ovr_documento_normalizar(($dados['cliente']['documento'] ?? ''));

    $meta = [
        /* O hash entra em TODO pedido, com cupom ou sem. É ele que faz o
           próximo pedido desta pessoa ser reconhecido como repetido.

           Enquanto o CPF só era pedido junto do cupom, havia um furo:
           quem comprasse sem cupom não deixava rastro, e voltava depois
           como primeira compra. Com o documento obrigatório no
           formulário, o furo fecha sozinho. */
        '_ovr_doc_hash'  => $doc ? ovr_doc_hash($doc['numero']) : '',
        '_ovr_doc_tipo'  => $doc ? $doc['tipo'] : '',
        '_ovr_cupom'     => '',
        '_ovr_cupom_st'  => '',
        '_ovr_desconto'  => 0,
    ];
    if ($codigo === '') return $meta;

    $meta['_ovr_cupom']    = $codigo;
    $meta['_ovr_desconto'] = max(0, (int) ($dados['desconto'] ?? 0));

    if (!$doc) {
        /* Não deveria chegar aqui: o receber.php recusa o pedido sem
           documento válido antes de gravar. Fica como rede, para o dia
           em que alguém mexer na ordem das checagens. */
        $meta['_ovr_cupom_st'] = 'sem-doc';
        $meta['_ovr_desconto'] = 0;
    } elseif (ovr_ja_comprou($meta['_ovr_doc_hash'], $post_id)) {
        /* O desconto fica gravado para você ver o que foi prometido na
           tela. Quem decide honrar ou não é você, no WhatsApp. */
        $meta['_ovr_cupom_st'] = 'repetido';
    } else {
        $meta['_ovr_cupom_st'] = 'ok';
    }
    return $meta;
}

/* Frase curta para a lista e a ficha do pedido. */
function ovr_cupom_recado(string $st): string {
    return [
        'ok'       => 'primeira compra confirmada',
        'repetido' => 'ATENÇÃO: este CPF/CNPJ já tem pedido — o cupom de primeira compra não valeria',
        'sem-doc'  => 'ATENÇÃO: cupom usado sem CPF/CNPJ válido',
    ][$st] ?? '';
}

/* ==================================================================
   O painel passa a ser o DONO dos cupons

   Antes as regras moravam em api/cupons.php, no site, e mexer num
   percentual exigia subir arquivo. Agora moram aqui, editáveis em
   /gestao, e o site pergunta quando alguém digita um código.

   A pergunta só acontece nesse momento, que é raro, então a chamada
   entre servidores não pesa na vitrine.

   O que o site recebe é o VALOR do desconto, nunca a regra: percentual
   e teto não saem daqui. Um teto que vaza vira alvo — a pessoa monta o
   pedido exatamente no ponto em que o desconto rende mais.
   ================================================================== */

/* Dinheiro em centavos, como o resto do painel. Float de dinheiro é
   como o sistema descobre tarde que R$ 0,01 sumiu. */
function ovr_cupons_padrao(): array {
    return [[
        'codigo'         => 'PRIMEIRA10',
        'rotulo'         => 'Primeira compra',
        'percentual'     => 10,
        'teto'           => 15000,
        'minimo'         => 0,
        'primeiraCompra' => true,
        'ate'            => '',
        'ativo'          => true,
    ]];
}

function ovr_cupons_salvos(): array {
    $c = get_option('ovr_cupons', null);
    if ($c === null) {
        /* Primeira vez: nasce com o cupom que já estava no site, para a
           troca não desligar a promoção que está no ar. */
        $c = ovr_cupons_padrao();
        add_option('ovr_cupons', $c, '', 'no');
    }
    return is_array($c) ? $c : [];
}

function ovr_cupons_gravar(array $lista): void {
    update_option('ovr_cupons', array_values($lista), 'no');
}

function ovr_cupom_por_codigo(string $codigo): ?array {
    $c = strtoupper(preg_replace('/\s+/', '', $codigo));
    foreach (ovr_cupons_salvos() as $cupom) {
        if (($cupom['codigo'] ?? '') === $c) return $cupom;
    }
    return null;
}

/* Um cupom pode existir e mesmo assim não valer agora. */
function ovr_cupom_vigente(array $c): bool {
    if (empty($c['ativo'])) return false;
    if (!empty($c['ate']) && current_time('Y-m-d') > $c['ate']) return false;
    return true;
}

/* Quanto sai do total, em centavos. Nunca mais que o teto, nunca mais
   que o próprio total — desconto maior que a compra viraria crédito, e
   a loja não tem isso. */
function ovr_cupom_valor(array $c, int $totalCentavos): int {
    if ($totalCentavos < (int) ($c['minimo'] ?? 0)) return 0;
    $bruto = (int) floor($totalCentavos * ((int) $c['percentual']) / 100);
    $teto  = (int) ($c['teto'] ?? 0);
    if ($teto > 0) $bruto = min($bruto, $teto);
    return max(0, min($bruto, $totalCentavos));
}

/* ------------------------------------------------------------------
   A chave que o site usa para perguntar

   Nasce sozinha e é mostrada na tela de Cupons para você colar no
   arquivo privado do site. Não é segredo de vida ou morte — o endpoint
   só devolve o desconto de um código que o cliente digitou — mas sem
   ela qualquer um enumeraria códigos e descobriria o teto por
   tentativa.                                                          */
function ovr_cupom_chave(): string {
    $k = get_option('ovr_cupom_chave');
    if (!$k) { $k = bin2hex(random_bytes(24)); add_option('ovr_cupom_chave', $k, '', 'no'); }
    return $k;
}

add_action('rest_api_init', function () {
    register_rest_route('ovr/v1', '/cupom', [
        'methods'             => 'POST',
        'callback'            => 'ovr_rest_cupom',
        'permission_callback' => '__return_true',   // a chave é conferida dentro
    ]);
});

function ovr_rest_cupom(WP_REST_Request $req) {
    /* Rota de valor: nunca pode ser cacheada, pelo mesmo motivo das
       rotas de conta. */
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('X-LiteSpeed-Cache-Control: no-cache, no-store');
    if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);

    $d = $req->get_json_params() ?: [];

    /* hash_equals e não ===: comparação de string vaza o tamanho do
       prefixo certo pelo tempo que leva. */
    if (!hash_equals(ovr_cupom_chave(), (string) ($d['chave'] ?? ''))) {
        return ovr_erro('ovr_chave', 'Não autorizado.', 403);
    }

    $total = max(0, (int) ($d['total'] ?? 0));
    $cupom = ovr_cupom_por_codigo((string) ($d['codigo'] ?? ''));

    if (!$cupom || !ovr_cupom_vigente($cupom)) {
        return ['ok' => false, 'erro' => 'Não encontrei esse cupom. Confira o código.'];
    }
    if ($total < (int) ($cupom['minimo'] ?? 0)) {
        return ['ok' => false, 'erro' => 'Este cupom vale a partir de R$ '
            . number_format(((int) $cupom['minimo']) / 100, 2, ',', '.') . '.'];
    }

    /* A checagem de primeira compra é do site, que tem o documento na
       mão. Aqui só dizemos que o cupom exige. */
    $desconto = ovr_cupom_valor($cupom, $total);
    $cheio    = (int) floor($total * ((int) $cupom['percentual']) / 100);

    return [
        'ok'             => true,
        'codigo'         => $cupom['codigo'],
        'rotulo'         => $cupom['rotulo'],
        'percentual'     => (int) $cupom['percentual'],
        'primeiraCompra' => !empty($cupom['primeiraCompra']),
        'desconto'       => $desconto,
        'noTeto'         => $desconto < $cheio,
    ];
}
