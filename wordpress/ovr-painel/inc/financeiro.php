<?php
/**
 * Financeiro: o que o Resumo não responde.
 *
 * O Resumo mostra o mês corrente e o que precisa de ação hoje. Aqui é a
 * outra pergunta: está crescendo, qual linha paga a conta e qual canal
 * traz cliente que dá margem. Tudo sai dos campos que já existem no
 * pedido — nenhum lançamento novo para ele fazer.
 */

if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_submenu_page('edit.php?post_type=pedido', 'Financeiro', 'Financeiro',
                     'edit_pedidos', 'ovr-financeiro', 'ovr_tela_financeiro');
});

/* Uma consulta só para a tela inteira.
   O Resumo chama ovr_somar() duas vezes e cada chamada varre tudo. Aqui
   são 12 meses mais dois recortes: repetir isso seria varrer 15 vezes o
   mesmo conjunto. Carrega uma vez, agrupa em PHP.                      */
function ovr_carregar_pedidos() {
    static $cache = null;
    if ($cache !== null) return $cache;

    $ids = get_posts([
        'post_type'      => 'pedido',
        'post_status'    => ['publish', 'draft', 'private'],
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ]);

    $situacoes = ovr_situacoes();
    $linhas = [];

    foreach ($ids as $id) {
        $sit = get_post_meta($id, '_ovr_situacao', true) ?: 'novo';
        if ($sit === 'cancelado') continue;          // cancelado não é receita

        $itens    = (int) get_post_meta($id, '_ovr_valor_itens', true);
        $frete    = (int) get_post_meta($id, '_ovr_valor_frete', true);
        $desconto = (int) get_post_meta($id, '_ovr_desconto', true);
        /* Custo é item MAIS frete do fornecedor. Os dois vivem em campos
           separados para o preenchimento automático não somar duas
           vezes, mas para a margem eles são a mesma coisa: dinheiro que
           saiu antes da peça existir.                                  */
        $itensCusto = (int) get_post_meta($id, '_ovr_custo', true);
        $freteForn  = (int) get_post_meta($id, '_ovr_frete_fornecedor', true);
        $custo      = $itensCusto + $freteForn;

        $linhas[] = [
            'id'       => $id,
            'titulo'   => get_the_title($id),
            'mes'      => get_post_time('Y-m', false, $id),
            'situacao' => $sit,
            'fechado'  => !($situacoes[$sit]['aberto'] ?? true),
            'tipo'     => get_post_meta($id, '_ovr_tipo', true) ?: 'misto',
            'canal'    => get_post_meta($id, '_ovr_canal', true) ?: 'manual',
            'receita'  => $itens + $frete - $desconto,
            'custo'    => $custo,
            'freteForn' => $freteForn,
            /* Faturado é o que o pedido vale; recebido é o que entrou.
               Marcar Pago sem digitar o valor quita o pedido inteiro,
               senão o "a receber" nunca zera. Mesma regra de
               ovr_a_receber(), para as duas contas não brigarem.     */
            'recebido' => ((get_post_meta($id, '_ovr_pagamento', true) ?: 'aguardando') === 'pago')
                          ? ($itens + $frete - $desconto)
                          : (int) get_post_meta($id, '_ovr_valor_pago', true),
            /* Deixar o campo em branco no formulário grava ZERO, não vazio:
               ovr_centavos('') devolve 0 e o meta vira '0'. Então procurar
               string vazia aqui nunca acharia nada, e o aviso de margem
               inflada jamais apareceria.

               O que importa de verdade é o pedido que tem receita e não tem
               custo: é ele que aparece com 100% de margem e mente na conta.
               Custo zero legítimo não existe nessa operação, toda peça é
               comprada de alguém.

               Olha o custo TOTAL: um pedido que só tem o frete lançado
               continua com a peça faltando e continua mentindo.        */
            'semCusto' => ($itensCusto <= 0 && ($itens + $frete - $desconto) > 0),
        ];
    }
    return $cache = $linhas;
}

/* Soma um conjunto de linhas já carregadas */
function ovr_totalizar(array $linhas) {
    $t = ['qtd' => 0, 'receita' => 0, 'custo' => 0, 'margem' => 0, 'semCusto' => 0, 'recebido' => 0];
    foreach ($linhas as $l) {
        $t['qtd']++;
        $t['receita'] += $l['receita'];
        $t['custo']   += $l['custo'];
        $t['recebido'] += isset($l['recebido']) ? $l['recebido'] : 0;
        if ($l['semCusto']) $t['semCusto']++;
    }
    $t['aReceber'] = max(0, $t['receita'] - $t['recebido']);
    $t['margem'] = $t['receita'] - $t['custo'];
    $t['pct']    = $t['receita'] > 0 ? round(($t['margem'] / $t['receita']) * 100) : 0;
    $t['ticket'] = $t['qtd'] > 0 ? (int) round($t['receita'] / $t['qtd']) : 0;
    return $t;
}

/* Últimos N meses, incluindo os vazios: mês sem venda é informação. */
function ovr_serie_mensal(array $linhas, $meses = 12) {
    $serie = [];
    for ($i = $meses - 1; $i >= 0; $i--) {
        $chave = date('Y-m', strtotime("-$i months", strtotime(date('Y-m-01'))));
        $serie[$chave] = ovr_totalizar(array_filter($linhas, function ($l) use ($chave) {
            return $l['mes'] === $chave;
        }));
    }
    return $serie;
}

function ovr_quebrar_por(array $linhas, $campo, array $rotulos) {
    $out = [];
    foreach ($rotulos as $id => $rotulo) {
        $sub = array_filter($linhas, function ($l) use ($campo, $id) { return $l[$campo] === $id; });
        if (!$sub) continue;
        $out[$rotulo] = ovr_totalizar($sub);
    }
    return $out;
}

/* Barra proporcional em CSS puro. Sem biblioteca de gráfico: são doze
   barras num painel interno, e uma dependência a mais é uma dependência
   a mais para quebrar numa atualização do WordPress.                   */
function ovr_barra($valor, $maximo, $cor = '#2749ff') {
    $pct = $maximo > 0 ? max(1, round(($valor / $maximo) * 100)) : 0;
    printf('<div style="background:#f0f0f1;height:8px;width:100%%;border-radius:2px">
              <div style="background:%s;height:8px;width:%d%%;border-radius:2px"></div></div>',
        esc_attr($cor), $pct);
}

function ovr_linha_tabela($rotulo, array $t, $maximo, $link = '') {
    echo '<tr>';
    printf('<td style="font-weight:600">%s</td>',
        $link ? '<a href="' . esc_url($link) . '">' . esc_html($rotulo) . '</a>' : esc_html($rotulo));
    printf('<td style="text-align:right">%d</td>', $t['qtd']);
    printf('<td style="text-align:right">%s</td>', esc_html(ovr_reais($t['receita'])));
    printf('<td style="text-align:right">%s</td>', esc_html(ovr_reais($t['custo'])));
    printf('<td style="text-align:right;color:%s;font-weight:600">%s</td>',
        $t['margem'] <= 0 && $t['qtd'] ? '#b00020' : '#0a7d33', esc_html(ovr_reais($t['margem'])));
    printf('<td style="text-align:right">%s</td>', $t['qtd'] ? $t['pct'] . '%' : '—');
    printf('<td style="text-align:right">%s</td>', $t['qtd'] ? esc_html(ovr_reais($t['ticket'])) : '—');
    echo '<td style="width:140px">';
    ovr_barra($t['receita'], $maximo);
    echo '</td></tr>';
}

function ovr_cabecalho_tabela($primeira) {
    printf('<thead><tr>
              <th style="text-align:left">%s</th>
              <th style="text-align:right">Pedidos</th>
              <th style="text-align:right">Receita</th>
              <th style="text-align:right">Custo</th>
              <th style="text-align:right">Margem</th>
              <th style="text-align:right">%%</th>
              <th style="text-align:right">Ticket</th>
              <th></th>
            </tr></thead>', esc_html($primeira));
}

function ovr_tela_financeiro() {
    if (!current_user_can('edit_pedidos')) {
        wp_die(esc_html__('Você não tem permissão para ver o financeiro.', 'ovr'));
    }

    $linhas = ovr_carregar_pedidos();
    $tudo   = ovr_totalizar($linhas);
    $mes    = ovr_totalizar(array_filter($linhas, function ($l) { return $l['mes'] === date('Y-m'); }));
    $ano    = ovr_totalizar(array_filter($linhas, function ($l) {
        return strpos($l['mes'], date('Y') . '-') === 0;
    }));

    echo '<div class="wrap"><h1>Financeiro</h1>';

    if (!$linhas) {
        echo '<p>Ainda não há pedido lançado. Assim que o primeiro entrar, os números aparecem aqui.</p></div>';
        return;
    }

    /* O aviso vem antes dos números, não depois: quem lê a margem
       primeiro e a ressalva depois já decidiu com o número errado. */
    if ($tudo['semCusto'] > 0) {
        printf('<div class="notice notice-warning" style="margin:12px 0"><p>
                  <strong>%d de %d pedidos estão com custo zerado.</strong>
                  Eles entram na receita mas não no custo, então a margem abaixo está mais alta que a real.
                  A lista deles está no fim da página.</p></div>',
            $tudo['semCusto'], $tudo['qtd']);
    }

    echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:16px">';
    ovr_cartao('Receita no mês', ovr_reais($mes['receita']), $mes['qtd'] . ' pedidos');
    ovr_cartao('Receita no ano', ovr_reais($ano['receita']), $ano['qtd'] . ' pedidos');
    ovr_cartao('Margem no ano', ovr_reais($ano['margem']), $ano['pct'] . '% da receita',
               $ano['margem'] <= 0 ? '#b00020' : '#0a7d33');
    ovr_cartao('Ticket médio', ovr_reais($ano['ticket']), 'no ano');
    echo '</div>';

    $serie  = ovr_serie_mensal($linhas, 12);
    $maxMes = max(array_map(function ($t) { return $t['receita']; }, $serie)) ?: 1;

    echo '<h2 style="margin-top:32px">Mês a mês</h2>';
    echo '<table class="widefat striped" style="max-width:1000px">';
    ovr_cabecalho_tabela('Mês');
    echo '<tbody>';
    foreach ($serie as $chave => $t) {
        $rotulo = date_i18n('M/y', strtotime($chave . '-01'));
        ovr_linha_tabela($rotulo, $t, $maxMes);
    }
    echo '</tbody></table>';

    $porTipo = ovr_quebrar_por($linhas, 'tipo', ovr_tipos_pedido());
    if ($porTipo) {
        $maxTipo = max(array_map(function ($t) { return $t['receita']; }, $porTipo)) ?: 1;
        echo '<h2 style="margin-top:32px">Por linha</h2>';
        echo '<p style="color:#666;max-width:70ch">Qual produto paga a conta. Margem baixa aqui é sinal '
           . 'de preço errado ou de custo que subiu e não foi repassado.</p>';
        echo '<table class="widefat striped" style="max-width:1000px">';
        ovr_cabecalho_tabela('Linha');
        echo '<tbody>';
        foreach ($porTipo as $rotulo => $t) ovr_linha_tabela($rotulo, $t, $maxTipo);
        echo '</tbody></table>';
    }

    $porCanal = ovr_quebrar_por($linhas, 'canal', ovr_canais());
    if ($porCanal) {
        $maxCanal = max(array_map(function ($t) { return $t['receita']; }, $porCanal)) ?: 1;
        echo '<h2 style="margin-top:32px">Por canal</h2>';
        echo '<p style="color:#666;max-width:70ch">De onde vem o dinheiro, não só o contato. '
           . 'Canal com muito pedido e ticket baixo custa mais atendimento do que entrega.</p>';
        echo '<table class="widefat striped" style="max-width:1000px">';
        ovr_cabecalho_tabela('Canal');
        echo '<tbody>';
        foreach ($porCanal as $rotulo => $t) ovr_linha_tabela($rotulo, $t, $maxCanal);
        echo '</tbody></table>';
    }

    $faltando = array_filter($linhas, function ($l) { return $l['semCusto']; });
    if ($faltando) {
        echo '<h2 style="margin-top:32px">Pedidos com custo zerado</h2>';
        echo '<p style="color:#666;max-width:70ch">Enquanto estiverem assim, a margem das tabelas acima está inflada. '
           . 'Cada link abre o pedido direto no campo de custo.</p>';
        echo '<table class="widefat striped" style="max-width:700px"><thead><tr>'
           . '<th style="text-align:left">Pedido</th><th style="text-align:left">Mês</th>'
           . '<th style="text-align:right">Receita</th></tr></thead><tbody>';
        foreach ($faltando as $l) {
            printf('<tr><td><a href="%s">%s</a></td><td>%s</td><td style="text-align:right">%s</td></tr>',
                esc_url(get_edit_post_link($l['id'])),
                esc_html($l['titulo'] ?: '#' . $l['id']),
                esc_html(date_i18n('M/y', strtotime($l['mes'] . '-01'))),
                esc_html(ovr_reais($l['receita'])));
        }
        echo '</tbody></table>';
    }

    echo '</div>';
}
