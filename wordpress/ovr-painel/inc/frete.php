<?php
/**
 * Cotação de frete pela Frenet.
 *
 * O site é estático: qualquer chave colocada lá é lida por quem abrir o
 * código-fonte da página. Então a chave mora AQUI, nas opções do
 * WordPress, e o site pergunta o frete a este endpoint. O navegador do
 * cliente nunca vê o token, só o resultado.
 *
 * Segunda razão para passar por aqui: a Frenet não devolve cabeçalho de
 * CORS para o domínio da loja. Chamada direta do navegador seria barrada
 * mesmo que a chave fosse pública.
 */

if (!defined('ABSPATH')) exit;

const OVR_FRENET_URL      = 'https://api.frenet.com.br/shipping/quote';
const OVR_FRETE_TETO_IP   = 30;    // cotações por hora, por IP
const OVR_FRETE_CACHE_MIN = 60;    // minutos guardando a mesma cotação

function ovr_frenet_opcoes() {
    return [
        'token'      => (string) get_option('ovr_frenet_token', ''),
        'cep_origem' => preg_replace('/\D/', '', (string) get_option('ovr_frenet_cep', '')),
        /* Dias que a peça leva para ficar pronta antes de postar. A
           Frenet devolve só o tempo de transporte; o cliente precisa
           enxergar o prazo cheio, senão promete o que não cumpre.     */
        'dias_producao' => (int) get_option('ovr_frenet_dias', 7),
        'ativo'      => (bool) get_option('ovr_frenet_ativo', false),
    ];
}

/* ------------------------------------------------------------------
   1. Tela de configuração
------------------------------------------------------------------ */
add_action('admin_menu', function () {
    add_submenu_page('edit.php?post_type=pedido', 'Frete', 'Frete',
                     'manage_options', 'ovr-frete', 'ovr_tela_frete');
});

add_action('admin_init', function () {
    register_setting('ovr_frete', 'ovr_frenet_token', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('ovr_frete', 'ovr_frenet_cep',   ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('ovr_frete', 'ovr_frenet_dias',  ['sanitize_callback' => 'absint']);
    register_setting('ovr_frete', 'ovr_frenet_ativo', ['sanitize_callback' => 'absint']);
});

function ovr_tela_frete() {
    if (!current_user_can('manage_options')) wp_die('Sem permissão.');
    $o = ovr_frenet_opcoes();

    echo '<div class="wrap"><h1>Frete</h1>';
    echo '<p style="max-width:70ch;color:#666">A chave fica guardada aqui, no servidor. O site pergunta o frete '
       . 'a este painel e nunca vê a chave. Enquanto o frete estiver desligado, o carrinho continua dizendo '
       . 'que o envio é combinado no WhatsApp, como é hoje.</p>';

    /* --- teste de conexão, disparado pelo botão abaixo -------------- */
    if (isset($_GET['ovr_testar']) && check_admin_referer('ovr_testar_frete')) {
        $r = ovr_frenet_cotar('01310100', 0.5, 20, 30, 3, 100.00);
        if (is_wp_error($r)) {
            printf('<div class="notice notice-error"><p><strong>A Frenet respondeu com erro:</strong> %s</p></div>',
                esc_html($r->get_error_message()));
        } elseif (empty($r)) {
            echo '<div class="notice notice-warning"><p>A Frenet respondeu, mas não devolveu nenhum serviço '
               . 'para esse teste. Confira se o CEP de origem está certo e se a conta tem transportadora ativa.</p></div>';
        } else {
            echo '<div class="notice notice-success"><p><strong>Conexão certa.</strong> Cotação de teste para São Paulo, '
               . 'pacote de 0,5 kg:</p><ul style="margin-left:18px;list-style:disc">';
            foreach ($r as $s) {
                printf('<li>%s: %s, %d dias</li>', esc_html($s['nome']),
                    esc_html(ovr_reais($s['preco'])), (int) $s['prazo']);
            }
            echo '</ul></div>';
        }
    }

    echo '<form method="post" action="options.php">';
    settings_fields('ovr_frete');
    echo '<table class="form-table"><tbody>';

    printf('<tr><th scope="row"><label for="ovr_frenet_token">Chave da Frenet</label></th><td>
              <input type="password" class="regular-text" id="ovr_frenet_token" name="ovr_frenet_token" value="%s" autocomplete="off">
              <p class="description">Está no painel da Frenet, em Configurações. Fica só aqui: o site não recebe ela.</p></td></tr>',
        esc_attr($o['token']));

    printf('<tr><th scope="row"><label for="ovr_frenet_cep">CEP de origem</label></th><td>
              <input type="text" id="ovr_frenet_cep" name="ovr_frenet_cep" value="%s" placeholder="00000000" maxlength="9">
              <p class="description">De onde a caixa sai. É o seu endereço em Marília.</p></td></tr>',
        esc_attr($o['cep_origem']));

    printf('<tr><th scope="row"><label for="ovr_frenet_dias">Dias de produção</label></th><td>
              <input type="number" id="ovr_frenet_dias" name="ovr_frenet_dias" value="%d" min="0" max="60" style="width:80px">
              <p class="description">Somados ao prazo da transportadora. A Frenet devolve só o tempo de estrada;
              o cliente precisa ver o prazo cheio, senão o site promete o que a produção não cumpre.</p></td></tr>',
        $o['dias_producao']);

    printf('<tr><th scope="row">Mostrar frete no site</th><td>
              <label><input type="checkbox" name="ovr_frenet_ativo" value="1" %s> Ligado</label>
              <p class="description">Desligado, o carrinho segue como está hoje, dizendo que o envio é combinado no WhatsApp.
              Só ligue depois que o teste abaixo passar.</p></td></tr>',
        checked($o['ativo'], true, false));

    echo '</tbody></table>';
    submit_button('Salvar');
    echo '</form>';

    if ($o['token'] && $o['cep_origem']) {
        printf('<p><a class="button" href="%s">Testar conexão</a> '
             . '<span style="color:#666">Cota um pacote de 0,5 kg para São Paulo e mostra o que a Frenet responder.</span></p>',
            esc_url(wp_nonce_url(add_query_arg('ovr_testar', 1), 'ovr_testar_frete')));
    } else {
        echo '<p style="color:#666">Preencha a chave e o CEP de origem para liberar o teste de conexão.</p>';
    }
    echo '</div>';
}

/* ------------------------------------------------------------------
   2. A chamada
------------------------------------------------------------------ */
/* Devolve lista de ['nome','preco'(centavos),'prazo'(dias)] ou WP_Error.

   ⚠️ O formato da resposta da Frenet foi escrito a partir da
   documentação deles, sem uma conta para testar contra. O botão "Testar
   conexão" existe justamente para provar isto contra a API real. Se o
   nome dos campos mudou, é aqui que conserta.                        */
function ovr_frenet_cotar($cep_destino, $peso_kg, $altura, $largura, $comprimento, $valor) {
    $o = ovr_frenet_opcoes();
    if (!$o['token'])      return new WP_Error('ovr_sem_chave', 'Chave da Frenet não configurada.');
    if (!$o['cep_origem']) return new WP_Error('ovr_sem_cep', 'CEP de origem não configurado.');

    $destino = preg_replace('/\D/', '', (string) $cep_destino);
    if (strlen($destino) !== 8) return new WP_Error('ovr_cep', 'CEP inválido.');

    /* Piso nas medidas só para não mandar zero. Os mínimos de cada
       transportadora quem aplica é ela: testado no simulador da Frenet
       com 0,21 kg e 35 × 25 × 3, e os Correios cotaram normal. Piso de
       0,3 kg como eu tinha antes só inflava o peso à toa.             */
    $corpo = [
        'SellerCEP'             => $o['cep_origem'],
        'RecipientCEP'          => $destino,
        'ShipmentInvoiceValue'  => round(max(1, (float) $valor), 2),
        'ShippingServiceCode'   => null,
        'RecipientCountry'      => 'BR',
        'ShippingItemArray'     => [[
            'Height'   => max(1,   (float) $altura),
            'Length'   => max(11,  (float) $comprimento),
            'Width'    => max(11,  (float) $largura),
            'Weight'   => max(0.1, (float) $peso_kg),
            'Quantity' => 1,
        ]],
    ];

    $resp = wp_remote_post(OVR_FRENET_URL, [
        'timeout' => 12,
        'headers' => ['Content-Type' => 'application/json', 'token' => $o['token']],
        'body'    => wp_json_encode($corpo),
    ]);

    if (is_wp_error($resp)) return $resp;

    $codigo = wp_remote_retrieve_response_code($resp);
    if ($codigo !== 200) {
        return new WP_Error('ovr_frenet_http', 'A Frenet respondeu HTTP ' . $codigo . '.');
    }

    $json = json_decode(wp_remote_retrieve_body($resp), true);
    /* A chave vem escrita assim mesmo na API deles, com o "r" faltando. */
    $lista = $json['ShippingSevicesArray'] ?? $json['ShippingServicesArray'] ?? null;
    if (!is_array($lista)) {
        return new WP_Error('ovr_frenet_corpo', 'Resposta da Frenet em formato inesperado.');
    }

    $fora = [];
    foreach ($lista as $s) {
        /* Serviço com erro vem na mesma lista, marcado. Ignora em vez de
           mostrar "R$ 0,00" para o cliente.                           */
        if (!empty($s['Error']) && filter_var($s['Error'], FILTER_VALIDATE_BOOLEAN)) continue;
        $preco = (float) str_replace(',', '.', (string) ($s['ShippingPrice'] ?? 0));
        if ($preco <= 0) continue;
        $fora[] = [
            'nome'  => sanitize_text_field($s['ServiceDescription'] ?? $s['Carrier'] ?? 'Envio'),
            'preco' => (int) round($preco * 100),
            'prazo' => (int) ($s['DeliveryTime'] ?? 0) + $o['dias_producao'],
        ];
    }
    usort($fora, function ($a, $b) { return $a['preco'] <=> $b['preco']; });
    return $fora;
}

/* ------------------------------------------------------------------
   3. O endpoint que o site chama
------------------------------------------------------------------ */
add_action('rest_api_init', function () {
    register_rest_route('ovr/v1', '/frete', [
        'methods'             => 'POST',
        'callback'            => 'ovr_responder_frete',
        'permission_callback' => '__return_true',   // a porta é checada dentro
    ]);
});

function ovr_responder_frete(WP_REST_Request $req) {
    /* Mesmas portas do endpoint de pedido: origem e cadência. Cotação é
       barata, mas é uma chamada paga na conta dele a cada consulta.   */
    $origem = $req->get_header('origin') ?: '';
    if (!in_array($origem, ovr_origens_permitidas(), true)) {
        return ovr_erro('ovr_origem', 'Origem não autorizada.', 403);
    }

    $o = ovr_frenet_opcoes();
    if (!$o['ativo']) {
        /* Não é erro: é o site perguntando e o painel dizendo que hoje
           o frete ainda é combinado na conversa.                      */
        return rest_ensure_response(['ativo' => false, 'opcoes' => []]);
    }

    $ip    = ovr_ip_do_visitante();
    $chave = 'ovr_frete_ritmo_' . md5($ip);
    if ((int) get_transient($chave) >= OVR_FRETE_TETO_IP) {
        return ovr_erro('ovr_ritmo', 'Muitas consultas seguidas.', 429);
    }
    set_transient($chave, ((int) get_transient($chave)) + 1, HOUR_IN_SECONDS);

    $cep   = preg_replace('/\D/', '', (string) $req->get_param('cep'));
    $peso  = (float) $req->get_param('peso');
    $alt   = (float) $req->get_param('altura');
    $larg  = (float) $req->get_param('largura');
    $comp  = (float) $req->get_param('comprimento');
    $valor = (float) $req->get_param('valor');

    if (strlen($cep) !== 8) return ovr_erro('ovr_cep', 'Informe um CEP com 8 dígitos.');

    /* Teto nas medidas: o corpo vem de fora e ninguém cota um caixote de
       duas toneladas por engano nem de propósito.                     */
    $peso = min($peso, 60);  $alt = min($alt, 100);
    $larg = min($larg, 100); $comp = min($comp, 100);
    $valor = min($valor, 50000);

    /* Mesma pergunta, mesma resposta por uma hora. Cotação de CEP não
       muda de minuto em minuto e cada chamada custa.                  */
    $cacheKey = 'ovr_frete_' . md5(implode('|', [$cep, $peso, $alt, $larg, $comp, round($valor)]));
    $guardado = get_transient($cacheKey);
    if (is_array($guardado)) {
        return rest_ensure_response(['ativo' => true, 'opcoes' => $guardado, 'cache' => true]);
    }

    $r = ovr_frenet_cotar($cep, $peso, $alt, $larg, $comp, $valor);
    if (is_wp_error($r)) {
        /* O cliente não precisa saber que a integração caiu; precisa
           saber que dá para seguir. Quem precisa do detalhe é ele, e
           esse fica no log do servidor.                               */
        error_log('[OVR frete] ' . $r->get_error_message());
        return rest_ensure_response(['ativo' => true, 'opcoes' => [], 'falhou' => true]);
    }

    set_transient($cacheKey, $r, OVR_FRETE_CACHE_MIN * MINUTE_IN_SECONDS);
    return rest_ensure_response(['ativo' => true, 'opcoes' => $r]);
}
