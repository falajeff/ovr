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
