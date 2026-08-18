<?php
/**
 * Plugin Name: OVR Painel
 * Description: Gestão de pedidos da OVR. Registra pedido, arte, custo e margem, recebe pedido vindo do site e mostra o financeiro. As telas de operação ficam em /gestao.
 * Version: 0.9.2
 * Author: OVR
 * Requires PHP: 7.4
 *
 * Por que plugin e não tema: o painel precisa sobreviver a troca de tema.
 * O tema cuida da aparência; isto aqui é o negócio.
 */

if (!defined('ABSPATH')) exit;

define('OVR_PAINEL_VER', '0.9.2');
define('OVR_PAINEL_DIR', plugin_dir_path(__FILE__));

/* Dinheiro vive em CENTAVOS, sempre inteiro.
   Float em dinheiro acumula erro: 0.1 + 0.2 não dá 0.3 em nenhuma
   linguagem que use IEEE 754, e num somatório de pedidos isso aparece. */
function ovr_centavos($valor) {
    if (is_string($valor)) {
        $valor = str_replace(['R$', ' ', '.'], '', $valor);
        $valor = str_replace(',', '.', $valor);
    }
    return (int) round(((float) $valor) * 100);
}
function ovr_reais($centavos) {
    return 'R$ ' . number_format(((int) $centavos) / 100, 2, ',', '.');
}

/* Situações do pedido. A ordem aqui é a ordem do funil e o painel
   depende dela para somar "em aberto" x "fechado".                  */
function ovr_situacoes() {
    return [
        'novo'      => ['rotulo' => 'Novo',         'cor' => '#2749ff', 'aberto' => true],
        'orcado'    => ['rotulo' => 'Orçado',       'cor' => '#6b6b66', 'aberto' => true],
        'aprovado'  => ['rotulo' => 'Aprovado',     'cor' => '#0a7d33', 'aberto' => true],
        'producao'  => ['rotulo' => 'Em produção',  'cor' => '#9f5d00', 'aberto' => true],
        'enviado'   => ['rotulo' => 'Enviado',      'cor' => '#0a5c7d', 'aberto' => true],
        'entregue'  => ['rotulo' => 'Entregue',     'cor' => '#090a0c', 'aberto' => false],
        'cancelado' => ['rotulo' => 'Cancelado',    'cor' => '#b00020', 'aberto' => false],
    ];
}

/* De onde veio o pedido. Serve para ele saber qual canal converte. */
function ovr_canais() {
    return [
        'site'      => 'Site',
        'whatsapp'  => 'WhatsApp',
        'instagram' => 'Instagram',
        'indicacao' => 'Indicação',
        'manual'    => 'Lançado à mão',
    ];
}

/* O que está sendo vendido. Cada um tem custo e margem diferentes. */
function ovr_tipos_pedido() {
    return [
        'dtf'   => 'Peça com estampa DTF',
        'dtg'   => 'Impressão especial (DTG)',
        'filme' => 'Filme DTF avulso',
        'arte'  => 'Criação de arte',
        'misto' => 'Misto',
    ];
}

/* Frete do fornecedor até você, por quantidade de peças.

   ⚠️ ESTA TABELA EXISTE EM DOIS LUGARES. A outra cópia está em
   `assets/js/config.js` do site, no bloco `freteFornecedor`. Quando um
   valor mudar, mude nos dois: o site usa a dele para mostrar margem e
   o painel usa esta para fechar o resultado do pedido. Não dá para o
   PHP ler o JS, e uma tabela de seis linhas não justifica um endpoint
   só para sincronizar.

   Cada faixa cobra o teto dela, então 3 peças pagam a faixa de 5 e o
   custo erra para mais, nunca para menos. Acima de 100 não há medida:
   cobra por peça na taxa marginal entre 50 e 100, arredondada.       */
function ovr_frete_fornecedor_faixas() {
    return [
        ['ate' => 1,   'valor' => 1500],
        ['ate' => 5,   'valor' => 1900],
        ['ate' => 10,  'valor' => 2200],
        ['ate' => 25,  'valor' => 3200],
        ['ate' => 50,  'valor' => 4900],
        ['ate' => 100, 'valor' => 12100],
    ];
}
define('OVR_FRETE_POR_PECA_ACIMA', 150);   // centavos, acima de 100 peças

function ovr_frete_fornecedor($qtd) {
    $q = max(0, (int) $qtd);
    if ($q === 0) return 0;                 // sem peça não há compra
    foreach (ovr_frete_fornecedor_faixas() as $f) {
        if ($q <= $f['ate']) return $f['valor'];
    }
    return $q * OVR_FRETE_POR_PECA_ACIMA;
}

/* Preenche o frete a partir da quantidade, sem sobrescrever valor que
   alguém digitou.

   Vive aqui, e não dentro do gancho do formulário, porque os pedidos
   entram por DOIS caminhos: o formulário do wp-admin e a API que recebe
   do site. O gancho `save_post_pedido` só passa quando existe o nonce,
   então pedido vindo do site pulava o cálculo e chegava com frete zero.
   Uma função só, chamada nos dois lugares, evita a regra divergir.    */
function ovr_aplicar_frete_automatico($post_id) {
    $qtd = (int) get_post_meta($post_id, '_ovr_qtd_pecas', true);
    if ($qtd <= 0) return 0;
    /* Campo em branco chega como zero, então zero significa calcula. */
    if ((int) get_post_meta($post_id, '_ovr_frete_fornecedor', true) > 0) return 0;
    $valor = ovr_frete_fornecedor($qtd);
    update_post_meta($post_id, '_ovr_frete_fornecedor', $valor);
    return $valor;
}

/* Situação da arte — é o gargalo real da operação, então tem campo
   próprio em vez de virar observação solta.                          */
function ovr_situacoes_arte() {
    return [
        'sem'       => 'Ainda não enviou',
        'recebida'  => 'Recebida, a conferir',
        'problema'  => 'Com problema, avisado',
        'aprovada'  => 'Aprovada para produção',
        'nossa'     => 'Vamos criar (serviço de arte)',
    ];
}

require_once OVR_PAINEL_DIR . 'inc/pedidos.php';
require_once OVR_PAINEL_DIR . 'inc/painel.php';
require_once OVR_PAINEL_DIR . 'inc/financeiro.php';
require_once OVR_PAINEL_DIR . 'inc/receber.php';
/* Depois de receber.php: frete.php usa ovr_origens_permitidas(),
   ovr_erro() e ovr_ip_do_visitante(), que nascem lá.                 */
require_once OVR_PAINEL_DIR . 'inc/frete.php';
/* Por último: gestao.php desenha as telas de /gestao reaproveitando as
   funções de todos os outros. Carregar antes deixaria chamada no ar.

   O file_exists é válvula de escape: se alguma coisa der errado nas
   telas novas, apagar esse único arquivo devolve o painel ao que era,
   sem mexer em mais nada e sem derrubar o WordPress junto.            */
if (file_exists(OVR_PAINEL_DIR . 'inc/gestao.php')) {
    require_once OVR_PAINEL_DIR . 'inc/gestao.php';
}

/* Permissões do tipo "pedido".
   ⚠️ O WordPress NÃO deduz as variantes: conceder `delete_pedidos` não
   dá o direito de mandar para a lixeira. Sem `delete_published_pedidos`
   e `delete_others_pedidos` nem o administrador consegue apagar, e o
   link nem aparece na linha. A lista abaixo precisa ser completa.    */
function ovr_capacidades() {
    return [
        'edit_pedidos', 'edit_others_pedidos', 'edit_private_pedidos', 'edit_published_pedidos',
        'publish_pedidos', 'read_private_pedidos', 'create_pedidos',
        'delete_pedidos', 'delete_others_pedidos', 'delete_private_pedidos', 'delete_published_pedidos',
    ];
}

function ovr_conceder_capacidades() {
    add_role('ovr_operacao', 'Operação OVR', ['read' => true, 'upload_files' => true]);
    foreach (['administrator', 'ovr_operacao'] as $papel) {
        $r = get_role($papel);
        if (!$r) continue;
        foreach (ovr_capacidades() as $cap) $r->add_cap($cap);
    }
}

register_activation_hook(__FILE__, function () {
    ovr_conceder_capacidades();
    update_option('ovr_caps_ver', OVR_PAINEL_VER);
    ovr_registrar_pedido();
    flush_rewrite_rules();
});

/* Autoconserto: o gancho de ativação só roda ao ativar, então uma
   permissão que faltou numa versão antiga ficaria faltando para sempre.
   Aqui ela é reaplicada sozinha quando a versão do plugin muda.

   O flush das regras de reescrita anda junto: /gestao nasceu na 0.6.0 e
   sem isso ele responderia 404 até alguém salvar os links permanentes
   na mão, que é o tipo de instrução que ninguém lembra de seguir.     */
add_action('admin_init', function () {
    if (get_option('ovr_caps_ver') === OVR_PAINEL_VER) return;
    ovr_conceder_capacidades();
    flush_rewrite_rules();
    update_option('ovr_caps_ver', OVR_PAINEL_VER);
});

/* Atalho no topo do wp-admin: quem entra pelo caminho velho acha o novo.

   Aponta para /gestao/resumo/ e não para /gestao/ de propósito. O
   endereço curto ficou com um 404 guardado no cache do servidor de
   antes da rota existir, e ele só sai de lá quando o cache expirar.
   Mandar direto para a tela nomeada contorna isso sem depender de
   ninguém limpar nada.                                               */
add_action('admin_bar_menu', function ($barra) {
    if (!current_user_can('edit_pedidos')) return;
    $barra->add_node([
        'id'    => 'ovr-gestao',
        'title' => 'Gestão OVR',
        'href'  => home_url('/gestao/resumo/'),
    ]);
}, 80);

register_deactivation_hook(__FILE__, 'flush_rewrite_rules');
