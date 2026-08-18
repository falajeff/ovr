<?php
/* Endpoint de preço. Devolve só preço: nunca custo, nunca markup.
 *
 * A página da peça chama aqui quando o cliente mexe na quantidade ou no
 * tamanho da estampa. O que sai daqui é o que ele já veria na tela de
 * qualquer jeito, e o que fica aqui dentro é a conta que produz isso. */
define('OVR_MOTOR', true);
require __DIR__ . '/motor-preco.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

$id  = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$qtd = isset($_GET['qtd']) ? max(1, (int) $_GET['qtd']) : 1;

$produto = ovr_produto($id);
if (!$produto) { http_response_code(404); echo json_encode(['erro' => 'peça não encontrada']); exit; }

/* posicoes=30x40,25x30 — largura por altura em cm, uma por posição.
   Sem parâmetro, usa a medida padrão da frente, que é a da vitrine. */
$posicoes = [];
if (!empty($_GET['posicoes'])) {
    foreach (explode(',', $_GET['posicoes']) as $m) {
        if (preg_match('/^(\d+(?:\.\d+)?)x(\d+(?:\.\d+)?)$/', trim($m), $x)) {
            $posicoes[] = ['larg' => (float) $x[1], 'alt' => (float) $x[2]];
        }
    }
}
if (!$posicoes) $posicoes = [ovr_cfg()['estampas']['frente']];

$faixa = ovr_faixa_de($qtd);
$unit  = ovr_unitario($produto, $qtd, $posicoes);

echo json_encode([
    'id' => $id,
    'qtd' => $qtd,
    'unitario' => $unit,
    'total' => round($unit * $qtd, 2),
    'faixa' => ['rotulo' => $faixa['rotulo'], 'min' => $faixa['min'], 'max' => $faixa['max']],
    'escada' => ovr_escada($produto, $posicoes, $qtd),
    'aPartirDe' => ovr_a_partir_de($produto, $posicoes),
], JSON_UNESCAPED_UNICODE);
