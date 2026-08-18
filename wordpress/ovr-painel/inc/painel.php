<?php
/**
 * Tela de resumo: o que está aberto, o que atrasou, quanto entrou.
 * Só leitura — decisão, não digitação.
 */

if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_submenu_page('edit.php?post_type=pedido', 'Resumo', 'Resumo',
                     'edit_pedidos', 'ovr-resumo', 'ovr_tela_resumo');
});

/* Soma os pedidos de um período. Uma consulta só, somando em PHP:
   com o volume dele isso é mais barato e mais legível que SQL cru.  */
function ovr_somar($inicio = null, $fim = null, $apenasFechados = false) {
    $args = [
        'post_type'      => 'pedido',
        'post_status'    => ['publish', 'draft', 'private'],
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ];
    if ($inicio) {
        $args['date_query'] = [['after' => $inicio, 'before' => $fim ?: 'now', 'inclusive' => true]];
    }
    $ids = get_posts($args);

    $r = ['qtd' => 0, 'receita' => 0, 'custo' => 0, 'margem' => 0,
          'abertos' => 0, 'atrasados' => 0, 'arte_pendente' => 0, 'por_situacao' => []];

    foreach ($ids as $id) {
        $sit = get_post_meta($id, '_ovr_situacao', true) ?: 'novo';
        if ($sit === 'cancelado') continue;                 // cancelado não é receita
        $fechado = !(ovr_situacoes()[$sit]['aberto'] ?? true);
        if ($apenasFechados && !$fechado) continue;

        $receita = (int) get_post_meta($id, '_ovr_valor_itens', true)
                 + (int) get_post_meta($id, '_ovr_valor_frete', true)
                 - (int) get_post_meta($id, '_ovr_desconto', true);
        /* Item mais frete do fornecedor, igual ao Financeiro. Se as duas
           telas somarem custos diferentes, uma delas está mentindo.   */
        $custo = (int) get_post_meta($id, '_ovr_custo', true)
               + (int) get_post_meta($id, '_ovr_frete_fornecedor', true);

        $r['qtd']++;
        $r['receita'] += $receita;
        $r['custo']   += $custo;
        $r['por_situacao'][$sit] = ($r['por_situacao'][$sit] ?? 0) + 1;
        if (!$fechado) $r['abertos']++;

        $prom = get_post_meta($id, '_ovr_prometido', true);
        if ($prom && !$fechado && strtotime($prom) < strtotime('today')) $r['atrasados']++;

        if (in_array(get_post_meta($id, '_ovr_arte_situacao', true), ['sem', 'problema'], true)) {
            $r['arte_pendente']++;
        }
    }
    $r['margem'] = $r['receita'] - $r['custo'];
    return $r;
}

function ovr_cartao($rotulo, $valor, $nota = '', $cor = '#090a0c') {
    printf(
        '<div style="background:#fff;border:1px solid #dcdcde;padding:16px 18px;min-width:180px;flex:1">
           <div style="font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#666">%s</div>
           <div style="font-size:26px;font-weight:700;margin-top:4px;color:%s">%s</div>
           <div style="font-size:12px;color:#666;margin-top:2px">%s</div>
         </div>',
        esc_html($rotulo), esc_attr($cor), esc_html($valor), esc_html($nota)
    );
}

function ovr_tela_resumo() {
    $mes  = ovr_somar(date('Y-m-01'));
    $tudo = ovr_somar();
    $pct  = $mes['receita'] > 0 ? round(($mes['margem'] / $mes['receita']) * 100) : 0;

    echo '<div class="wrap"><h1>Resumo</h1>';
    echo '<p style="color:#666;max-width:70ch">Os números saem do que está lançado em Pedidos. '
       . 'Pedido cancelado não entra. Pedido sem custo preenchido infla a margem — a lista marca quais são.</p>';

    echo '<h2 style="margin-top:24px">Este mês</h2>';
    echo '<div style="display:flex;gap:12px;flex-wrap:wrap">';
    ovr_cartao('Pedidos', (string) $mes['qtd']);
    ovr_cartao('Receita', ovr_reais($mes['receita']));
    ovr_cartao('Custo', ovr_reais($mes['custo']));
    ovr_cartao('Margem', ovr_reais($mes['margem']), $pct . '% da receita',
               $mes['margem'] <= 0 ? '#b00020' : '#0a7d33');
    echo '</div>';

    echo '<h2 style="margin-top:28px">Precisa de você</h2>';
    echo '<div style="display:flex;gap:12px;flex-wrap:wrap">';
    ovr_cartao('Em aberto', (string) $tudo['abertos'], 'ainda não entregues');
    ovr_cartao('Atrasados', (string) $tudo['atrasados'], 'passaram do prometido',
               $tudo['atrasados'] ? '#b00020' : '#090a0c');
    ovr_cartao('Arte pendente', (string) $tudo['arte_pendente'], 'sem arte ou com problema',
               $tudo['arte_pendente'] ? '#9f5d00' : '#090a0c');
    echo '</div>';

    echo '<h2 style="margin-top:28px">Funil</h2><div style="display:flex;gap:8px;flex-wrap:wrap">';
    foreach (ovr_situacoes() as $id => $s) {
        if ($id === 'cancelado') continue;
        $n = $tudo['por_situacao'][$id] ?? 0;
        printf('<a href="%s" style="text-decoration:none;background:#fff;border:1px solid #dcdcde;padding:12px 16px;min-width:120px">
                  <div style="font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:%s">%s</div>
                  <div style="font-size:22px;font-weight:700;color:#090a0c">%d</div></a>',
            esc_url(admin_url('edit.php?post_type=pedido&ovr_situacao=' . $id)),
            esc_attr($s['cor']), esc_html($s['rotulo']), $n);
    }
    echo '</div>';

    if ($tudo['qtd'] === 0) {
        echo '<p style="margin-top:28px"><a class="button button-primary" href="'
           . esc_url(admin_url('post-new.php?post_type=pedido')) . '">Lançar o primeiro pedido</a></p>';
    }
    echo '</div>';
}
