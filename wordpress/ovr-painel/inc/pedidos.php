<?php
/**
 * O pedido: tipo de conteúdo, campos, lista e filtros.
 */

if (!defined('ABSPATH')) exit;

/* ------------------------------------------------------------------
   1. O tipo de conteúdo
------------------------------------------------------------------ */
function ovr_registrar_pedido() {
    register_post_type('pedido', [
        'labels' => [
            'name'               => 'Pedidos',
            'singular_name'      => 'Pedido',
            'add_new'            => 'Lançar pedido',
            'add_new_item'       => 'Lançar pedido',
            'edit_item'          => 'Pedido',
            'search_items'       => 'Buscar pedidos',
            'not_found'          => 'Nenhum pedido ainda.',
            'not_found_in_trash' => 'Nenhum pedido na lixeira.',
            'menu_name'          => 'Pedidos',
        ],
        'public'              => false,   // pedido NÃO tem página pública
        'show_ui'             => true,
        'show_in_menu'        => true,
        'menu_icon'           => 'dashicons-clipboard',
        'menu_position'       => 3,
        'supports'            => ['title'],
        'capability_type'     => ['pedido', 'pedidos'],
        'map_meta_cap'        => true,
        'exclude_from_search' => true,
        'publicly_queryable'  => false,
        'has_archive'         => false,
    ]);
}
add_action('init', 'ovr_registrar_pedido');

/* Título do pedido é gerado, não digitado: número sequencial + cliente.
   Assim a lista fica legível sem o Jefferson ter que inventar nome.   */
add_filter('wp_insert_post_data', function ($dados, $bruto) {
    if ($dados['post_type'] !== 'pedido') return $dados;
    /* O WordPress cria um rascunho automático só de você ABRIR a tela de
       lançar pedido. Sem esta linha, cada tela aberta e abandonada queima
       um número e a numeração vira um queijo suíço.                     */
    if ($dados['post_status'] === 'auto-draft') return $dados;
    if (!empty($dados['post_title']) && $dados['post_title'] !== 'Rascunho automático') return $dados;

    $numero = (int) get_option('ovr_proximo_pedido', 1);
    update_option('ovr_proximo_pedido', $numero + 1);
    $dados['post_title'] = sprintf('#%04d', $numero);
    return $dados;
}, 10, 2);

/* ------------------------------------------------------------------
   2. Os campos
   Um metabox só, agrupado pelo que a pessoa faz: quem é, o que é,
   quanto entra, quanto sai.
------------------------------------------------------------------ */
function ovr_campos_pedido() {
    return [
        'cliente' => ['Cliente', [
            '_ovr_cliente_nome'   => ['Nome', 'text'],
            '_ovr_cliente_zap'    => ['WhatsApp', 'text'],
            '_ovr_cliente_email'  => ['E-mail', 'email'],
            '_ovr_cliente_cidade' => ['Cidade / UF', 'text'],
        ]],
        'pedido' => ['O pedido', [
            '_ovr_canal'      => ['Canal', 'select', 'ovr_canais'],
            '_ovr_tipo'       => ['Tipo', 'select', 'ovr_tipos_pedido'],
            '_ovr_situacao'   => ['Situação', 'select', 'ovr_situacoes_lista'],
            '_ovr_itens'      => ['Itens', 'textarea',
                                  'Uma linha por item. Ex.: Camiseta Básica Preta · GG · 10un · 60,27'],
            '_ovr_estampa'    => ['Estampa', 'text', 'Ex.: Frente 28×30 cm'],
            /* Pedido vindo do site já chega preenchido. Lançado à mão,
               é este número que faz o frete do fornecedor ser calculado. */
            '_ovr_qtd_pecas'  => ['Quantidade de peças', 'number',
                                  'Só as peças que você compra em malha crua. Filme e DTG não contam: saem prontos do fornecedor de cada um.'],
            '_ovr_embalagem'  => ['Embalagem sugerida', 'text', 'Calculada pela quantidade do pedido'],
        ]],
        'arte' => ['Arte', [
            '_ovr_arte_situacao' => ['Situação da arte', 'select', 'ovr_situacoes_arte'],
            '_ovr_arte_anexo'    => ['Arquivo enviado', 'anexo'],
            '_ovr_arte_link'     => ['Link do arquivo', 'url', 'Se ele mandou por WeTransfer, Drive etc.'],
            '_ovr_arte_nota'     => ['O que precisa ajustar', 'textarea'],
        ]],
        'dinheiro' => ['Dinheiro', [
            '_ovr_valor_itens' => ['Valor dos itens', 'dinheiro'],
            '_ovr_valor_frete' => ['Frete cobrado', 'dinheiro'],
            '_ovr_desconto'    => ['Desconto', 'dinheiro'],
            /* Os dois campos abaixo são disjuntos de propósito. Se o
               frete estivesse dentro do custo, o cálculo automático
               somaria de novo e o pedido apareceria com o dobro.     */
            '_ovr_custo'       => ['Custo dos itens (peça, filme, terceiro)', 'dinheiro'],
            '_ovr_frete_fornecedor' => ['Frete do fornecedor', 'dinheiro',
                                        'Preenche sozinho pela quantidade quando você deixa em branco. Digitou um valor, ele manda.'],
        ]],
        /* Faturado e recebido são coisas diferentes, e misturar as duas
           é como um negócio descobre tarde que vendeu bem e não tem
           dinheiro em caixa. O bloco de cima diz quanto o pedido vale;
           este diz quanto entrou de verdade.                          */
        'pagamento' => ['Pagamento', [
            '_ovr_pagamento'       => ['Situação do pagamento', 'select', 'ovr_situacoes_pagamento'],
            '_ovr_valor_pago'      => ['Valor recebido', 'dinheiro'],
            '_ovr_pagamento_forma' => ['Forma', 'select', 'ovr_formas_pagamento'],
            '_ovr_pagamento_data'  => ['Recebido em', 'date'],
        ]],
        'prazo' => ['Prazo e observação', [
            '_ovr_prometido' => ['Prometido para', 'date'],
            '_ovr_rastreio'  => ['Código de rastreio', 'text', 'Some no WhatsApp se não ficar guardado aqui.'],
            '_ovr_obs'       => ['Observação', 'textarea'],
        ]],
    ];
}

/* Data prometida a partir da aprovação da arte.

   O site promete 7 dias úteis DEPOIS que a arte é aprovada, e não da
   entrada do pedido: enquanto a arte não fecha, o relógio nem começou.
   Feriado não entra na conta, então a data é um palpite bom e não uma
   promessa: por isso ela só preenche o campo vazio e continua editável.  */
function ovr_dias_uteis($dias, $de = null) {
    $t = $de ? strtotime($de) : time();
    $andou = 0;
    while ($andou < $dias) {
        $t = strtotime('+1 day', $t);
        $semana = (int) date('N', $t);
        if ($semana < 6) $andou++;
    }
    return date('Y-m-d', $t);
}

/* Chamada nos dois caminhos de gravação, como o frete: quando a arte
   passa para aprovada e ninguém digitou prazo, propõe um.             */
function ovr_aplicar_prazo_automatico($post_id) {
    if (get_post_meta($post_id, '_ovr_arte_situacao', true) !== 'aprovada') return '';
    if (get_post_meta($post_id, '_ovr_prometido', true)) return '';
    $data = ovr_dias_uteis(7);
    update_post_meta($post_id, '_ovr_prometido', $data);
    return $data;
}

/* Linha do tempo do pedido. Guarda só o que muda de estado, que é o
   que vira discussão com cliente: "você falou que ia sair dia tal".
   Um array em meta basta; criar tabela para isso seria pesar o banco
   por um recurso que se lê uma vez por pedido.                        */
function ovr_registrar_historico($post_id, $campo, $de, $para) {
    if ($de === $para) return;
    $h = get_post_meta($post_id, '_ovr_historico', true);
    if (!is_array($h)) $h = [];
    $h[] = [
        'quando' => current_time('mysql'),
        'quem'   => wp_get_current_user()->display_name ?: 'sistema',
        'campo'  => $campo,
        'de'     => (string) $de,
        'para'   => (string) $para,
    ];
    if (count($h) > 60) $h = array_slice($h, -60);   // não vira log infinito
    update_post_meta($post_id, '_ovr_historico', $h);
}

function ovr_campos_com_historico() {
    return [
        '_ovr_situacao'      => ['Situação', 'ovr_situacoes_lista'],
        '_ovr_arte_situacao' => ['Arte', 'ovr_situacoes_arte'],
        '_ovr_pagamento'     => ['Pagamento', 'ovr_situacoes_pagamento'],
    ];
}

/* Nome do cliente normalizado, para "Padaria do Zé", "padaria do ze" e
   "PADARIA DO ZE" virarem a mesma pessoa nos relatórios.              */
function ovr_chave_cliente($nome) {
    $n = trim((string) $nome);
    if ($n === '') return '';
    $n = remove_accents($n);
    $n = strtolower(preg_replace('/\s+/', ' ', $n));
    return $n;
}

function ovr_situacoes_pagamento() {
    return [
        'aguardando' => 'Aguardando',
        'parcial'    => 'Recebido em parte',
        'pago'       => 'Pago',
    ];
}

function ovr_formas_pagamento() {
    return [
        ''              => 'Não informado',
        'pix'           => 'Pix',
        'dinheiro'      => 'Dinheiro',
        'cartao'        => 'Cartão',
        'transferencia' => 'Transferência',
        'outro'         => 'Outro',
    ];
}

/* Quanto falta entrar de um pedido. Pedido cancelado não deve nada.

   Regra do "pago": quando a situação é `pago` o saldo é zero mesmo que
   o valor recebido esteja em branco. Sem isso, marcar Pago e esquecer
   de digitar o valor deixaria o pedido eternamente na conta do que
   falta receber, e o número que ele olha para cobrar viraria mentira. */
function ovr_a_receber($post_id) {
    $sit = get_post_meta($post_id, '_ovr_situacao', true) ?: 'novo';
    if ($sit === 'cancelado') return 0;
    $devido = (int) get_post_meta($post_id, '_ovr_valor_itens', true)
            + (int) get_post_meta($post_id, '_ovr_valor_frete', true)
            - (int) get_post_meta($post_id, '_ovr_desconto', true);
    if ($devido <= 0) return 0;
    if ((get_post_meta($post_id, '_ovr_pagamento', true) ?: 'aguardando') === 'pago') return 0;
    return max(0, $devido - (int) get_post_meta($post_id, '_ovr_valor_pago', true));
}

/* wrapper porque ovr_situacoes() devolve array com cor, e o select
   só quer id => rótulo.                                              */
function ovr_situacoes_lista() {
    $out = [];
    foreach (ovr_situacoes() as $id => $s) $out[$id] = $s['rotulo'];
    return $out;
}

add_action('add_meta_boxes', function () {
    add_meta_box('ovr_pedido', 'Dados do pedido', 'ovr_desenhar_metabox', 'pedido', 'normal', 'high');
    add_meta_box('ovr_resumo', 'Resultado', 'ovr_desenhar_resumo', 'pedido', 'side', 'high');
});

function ovr_desenhar_metabox($post) {
    wp_nonce_field('ovr_salvar_pedido', 'ovr_nonce');
    echo '<style>
      .ovr-grupo{margin:0 0 22px}
      .ovr-grupo h4{margin:0 0 10px;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#666}
      .ovr-linha{display:grid;grid-template-columns:200px 1fr;gap:12px;align-items:start;margin-bottom:8px}
      .ovr-linha label{padding-top:6px;font-weight:600}
      .ovr-linha input[type=text],.ovr-linha input[type=email],.ovr-linha input[type=url],
      .ovr-linha input[type=date],.ovr-linha select,.ovr-linha textarea{width:100%;max-width:520px}
      .ovr-linha textarea{min-height:70px}
      .ovr-dica{display:block;color:#666;font-size:12px;margin-top:3px}
    </style>';

    foreach (ovr_campos_pedido() as $grupo) {
        [$titulo, $campos] = $grupo;
        echo '<div class="ovr-grupo"><h4>' . esc_html($titulo) . '</h4>';
        foreach ($campos as $chave => $def) {
            $rotulo = $def[0];
            $tipo   = $def[1];
            $extra  = $def[2] ?? null;
            $valor  = get_post_meta($post->ID, $chave, true);
            echo '<div class="ovr-linha"><label for="' . esc_attr($chave) . '">' . esc_html($rotulo) . '</label><div>';

            if ($tipo === 'select') {
                $opcoes = is_callable($extra) ? call_user_func($extra) : [];
                echo '<select name="' . esc_attr($chave) . '" id="' . esc_attr($chave) . '">';
                foreach ($opcoes as $id => $texto) {
                    echo '<option value="' . esc_attr($id) . '"' . selected($valor, $id, false) . '>'
                       . esc_html($texto) . '</option>';
                }
                echo '</select>';
            } elseif ($tipo === 'textarea') {
                echo '<textarea name="' . esc_attr($chave) . '" id="' . esc_attr($chave) . '">'
                   . esc_textarea($valor) . '</textarea>';
                if ($extra) echo '<span class="ovr-dica">' . esc_html($extra) . '</span>';
            } elseif ($tipo === 'anexo') {
                /* Arte do cliente não fica em pasta pública: sai por uma
                   rota que confere permissão. Aqui só mostramos o link. */
                if ($valor && get_post((int) $valor)) {
                    $url = wp_nonce_url(
                        admin_url('admin-post.php?action=ovr_arte&id=' . (int) $valor),
                        'ovr_arte_' . (int) $valor
                    );
                    printf('<a class="button" href="%s">Baixar %s</a> <span class="ovr-dica">%s</span>',
                        esc_url($url),
                        esc_html(get_the_title((int) $valor)),
                        esc_html(size_format((int) @filesize(get_attached_file((int) $valor))))
                    );
                } else {
                    echo '<span class="ovr-dica">Nenhum arquivo enviado pelo site.</span>';
                }
            } elseif ($tipo === 'dinheiro') {
                /* guarda centavos, mostra reais */
                $mostra = $valor === '' ? '' : number_format(((int) $valor) / 100, 2, ',', '.');
                echo '<input type="text" inputmode="decimal" name="' . esc_attr($chave) . '" id="'
                   . esc_attr($chave) . '" value="' . esc_attr($mostra) . '" placeholder="0,00">';
                if ($extra) echo '<span class="ovr-dica">' . esc_html($extra) . '</span>';
                /* No frete, mostra o que a tabela diz para a quantidade
                   já salva. Ele confere sem abrir o simulador.        */
                if ($chave === '_ovr_frete_fornecedor') {
                    $q = (int) get_post_meta($post->ID, '_ovr_qtd_pecas', true);
                    if ($q > 0) {
                        printf('<span class="ovr-dica">Tabela para %d %s: <strong>%s</strong>.</span>',
                            $q, $q === 1 ? 'peça' : 'peças', esc_html(ovr_reais(ovr_frete_fornecedor($q))));
                    }
                }
            } else {
                echo '<input type="' . esc_attr($tipo) . '" name="' . esc_attr($chave) . '" id="'
                   . esc_attr($chave) . '" value="' . esc_attr($valor) . '">';
                if ($extra) echo '<span class="ovr-dica">' . esc_html($extra) . '</span>';
            }
            echo '</div></div>';
        }
        echo '</div>';
    }
}

/* Caixa lateral: o número que importa. Mostra margem em tempo real
   do que já foi salvo, para ele ver se o pedido vale a pena.         */
function ovr_desenhar_resumo($post) {
    $itens = (int) get_post_meta($post->ID, '_ovr_valor_itens', true);
    $frete = (int) get_post_meta($post->ID, '_ovr_valor_frete', true);
    $desc  = (int) get_post_meta($post->ID, '_ovr_desconto', true);
    $custo = (int) get_post_meta($post->ID, '_ovr_custo', true);
    /* O frete do fornecedor é custo tanto quanto a peça. Ficou em campo
       separado só para o cálculo automático não somar duas vezes.     */
    $freteForn = (int) get_post_meta($post->ID, '_ovr_frete_fornecedor', true);

    $receita = $itens + $frete - $desc;
    $custoTotal = $custo + $freteForn;
    $margem  = $receita - $custoTotal;
    $pct     = $receita > 0 ? round(($margem / $receita) * 100) : 0;

    $cor = $margem <= 0 ? '#b00020' : ($pct < 25 ? '#9f5d00' : '#0a7d33');
    echo '<p style="margin:0 0 6px"><strong>Receita</strong><br>' . esc_html(ovr_reais($receita)) . '</p>';
    echo '<p style="margin:0 0 6px"><strong>Custo</strong><br>' . esc_html(ovr_reais($custoTotal));
    if ($freteForn > 0) {
        echo '<br><span style="color:#666;font-size:12px">itens ' . esc_html(ovr_reais($custo))
           . ' + frete ' . esc_html(ovr_reais($freteForn)) . '</span>';
    }
    echo '</p>';
    echo '<p style="margin:12px 0 0;padding-top:12px;border-top:1px solid #ddd">'
       . '<strong>Margem</strong><br><span style="font-size:22px;color:' . esc_attr($cor) . '">'
       . esc_html(ovr_reais($margem)) . '</span><br>'
       . '<span style="color:#666">' . esc_html($pct) . '% da receita</span></p>';
    if ($custo <= 0 && $receita > 0) {
        echo '<p style="color:#9f5d00;margin-top:12px">Custo dos itens em branco: a margem acima está inflada.</p>';
    }
    /* Valor que veio do site é proposta, não acordo: quem digitou foi o
       navegador do cliente. Serve para orçar, não para fechar sem olhar. */
    if (get_post_meta($post->ID, '_ovr_origem_site', true)) {
        echo '<p style="margin-top:12px;padding:10px;background:#fff4e0;border-left:3px solid #9f5d00">'
           . 'Este valor foi calculado no site, no navegador do cliente. '
           . 'Confira antes de fechar.</p>';
    }
}

/* ------------------------------------------------------------------
   3. Salvar
------------------------------------------------------------------ */
add_action('save_post_pedido', function ($post_id) {
    if (!isset($_POST['ovr_nonce']) || !wp_verify_nonce($_POST['ovr_nonce'], 'ovr_salvar_pedido')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    /* Guarda o antes para a linha do tempo saber o que mudou. */
    $antes = [];
    foreach (array_keys(ovr_campos_com_historico()) as $k) $antes[$k] = get_post_meta($post_id, $k, true);

    foreach (ovr_campos_pedido() as $grupo) {
        foreach ($grupo[1] as $chave => $def) {
            if ($def[1] === 'anexo') continue;      // só leitura, não vem do formulário
            if (!isset($_POST[$chave])) continue;
            $bruto = wp_unslash($_POST[$chave]);
            switch ($def[1]) {
                case 'dinheiro': $valor = ovr_centavos($bruto); break;
                case 'email':    $valor = sanitize_email($bruto); break;
                case 'url':      $valor = esc_url_raw($bruto); break;
                case 'textarea': $valor = sanitize_textarea_field($bruto); break;
                default:         $valor = sanitize_text_field($bruto);
            }
            update_post_meta($post_id, $chave, $valor);
        }
    }

    /* Frete do fornecedor: preenche sozinho quando ele deixou em branco.
       A regra mora em ovr_aplicar_frete_automatico() porque o pedido
       vindo do site entra por outro caminho e precisa da mesma regra. */
    ovr_aplicar_frete_automatico($post_id);
    ovr_aplicar_prazo_automatico($post_id);

    foreach (ovr_campos_com_historico() as $k => $def) {
        $lista = function_exists($def[1]) ? call_user_func($def[1]) : [];
        $de   = $antes[$k] ?? '';
        $para = get_post_meta($post_id, $k, true);
        ovr_registrar_historico($post_id, $def[0], $lista[$de] ?? $de, $lista[$para] ?? $para);
    }
}, 10, 1);

/* ------------------------------------------------------------------
   4. A lista: colunas que respondem "o que precisa da minha atenção"
------------------------------------------------------------------ */
add_filter('manage_pedido_posts_columns', function ($cols) {
    return [
        'cb'        => $cols['cb'],
        'title'     => 'Pedido',
        'cliente'   => 'Cliente',
        'tipo'      => 'Tipo',
        'situacao'  => 'Situação',
        'arte'      => 'Arte',
        'receita'   => 'Receita',
        'margem'    => 'Margem',
        'prometido' => 'Prometido',
    ];
});

add_action('manage_pedido_posts_custom_column', function ($col, $post_id) {
    $m = fn($k) => get_post_meta($post_id, $k, true);
    switch ($col) {
        case 'cliente':
            echo esc_html($m('_ovr_cliente_nome') ?: '—');
            if ($m('_ovr_cliente_zap')) echo '<br><span style="color:#666">' . esc_html($m('_ovr_cliente_zap')) . '</span>';
            break;
        case 'tipo':
            echo esc_html(ovr_tipos_pedido()[$m('_ovr_tipo')] ?? '—');
            break;
        case 'situacao':
            $s = ovr_situacoes()[$m('_ovr_situacao')] ?? null;
            if (!$s) { echo '—'; break; }
            printf('<span style="display:inline-block;padding:2px 8px;border-radius:3px;color:#fff;background:%s;font-size:11px">%s</span>',
                esc_attr($s['cor']), esc_html($s['rotulo']));
            break;
        case 'arte':
            $a = ovr_situacoes_arte()[$m('_ovr_arte_situacao')] ?? '—';
            $alerta = in_array($m('_ovr_arte_situacao'), ['sem', 'problema'], true);
            printf('<span style="color:%s">%s</span>', $alerta ? '#b00020' : '#444', esc_html($a));
            break;
        case 'receita':
            echo esc_html(ovr_reais((int)$m('_ovr_valor_itens') + (int)$m('_ovr_valor_frete') - (int)$m('_ovr_desconto')));
            break;
        case 'margem':
            /* Custo é item MAIS frete do fornecedor, igual ao Resumo, ao
               Financeiro e à caixa lateral do pedido. Esta coluna somava
               só o item e mostrava margem maior que as outras telas.    */
            $r = (int)$m('_ovr_valor_itens') + (int)$m('_ovr_valor_frete') - (int)$m('_ovr_desconto');
            $custo = (int)$m('_ovr_custo') + (int)$m('_ovr_frete_fornecedor');
            $g = $r - $custo;
            printf('<span style="color:%s">%s</span>', $g <= 0 ? '#b00020' : '#0a7d33', esc_html(ovr_reais($g)));
            /* Receita sem custo de item não é margem cheia, é dado que
               falta. Sem esta marca a lista premia o pedido mal lançado.

               Olha o custo do ITEM, não o total: o frete preenche sozinho
               e mascararia a peça faltando. Mesmo teste do Financeiro e
               da caixa lateral, senão as três telas discordam.          */
            if ((int)$m('_ovr_custo') <= 0 && $r > 0) {
                echo '<br><span style="color:#9f5d00;font-size:11px">custo em branco</span>';
            }
            break;
        case 'prometido':
            $d = $m('_ovr_prometido');
            if (!$d) { echo '—'; break; }
            $atrasado = strtotime($d) < strtotime('today')
                        && !in_array($m('_ovr_situacao'), ['entregue', 'cancelado'], true);
            printf('<span style="color:%s">%s</span>', $atrasado ? '#b00020' : '#444',
                esc_html(date_i18n('d/m/Y', strtotime($d))) . ($atrasado ? ' · atrasado' : ''));
            break;
    }
}, 10, 2);

/* Filtro por situação, para ele abrir só o que está em produção */
add_action('restrict_manage_posts', function ($tipo) {
    if ($tipo !== 'pedido') return;
    $atual = $_GET['ovr_situacao'] ?? '';
    echo '<select name="ovr_situacao"><option value="">Todas as situações</option>';
    foreach (ovr_situacoes() as $id => $s) {
        echo '<option value="' . esc_attr($id) . '"' . selected($atual, $id, false) . '>'
           . esc_html($s['rotulo']) . '</option>';
    }
    echo '</select>';
});

add_action('pre_get_posts', function ($q) {
    if (!is_admin() || !$q->is_main_query()) return;
    if ($q->get('post_type') !== 'pedido') return;
    if (!empty($_GET['ovr_situacao'])) {
        $q->set('meta_key', '_ovr_situacao');
        $q->set('meta_value', sanitize_text_field($_GET['ovr_situacao']));
    }
});
