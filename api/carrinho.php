<?php
/* Fecha o preço do pedido inteiro numa chamada.
 *
 * A faixa de desconto sai do TOTAL de peças do pedido, não do item:
 * três regulares mais sete oversized fecham a faixa de dez. Por isso
 * o carrinho não pode ser somado item a item no navegador.
 * Devolve preço e subtotal. Nunca custo, nunca markup. */
define('OVR_MOTOR', true);
require __DIR__ . '/motor-preco.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$corpo = json_decode(file_get_contents('php://input'), true);
$itens = is_array($corpo['itens'] ?? null) ? $corpo['itens'] : [];
if (count($itens) > 200) { http_response_code(413); echo json_encode(['erro' => 'carrinho grande demais']); exit; }

$cfg = ovr_cfg();
$totalPecas = 0;
foreach ($itens as $i) if (($i['tipo'] ?? '') === 'dtf') $totalPecas += max(0, (int) ($i['qtd'] ?? 0));

$faixa = ovr_faixa_de(max(1, $totalPecas));
$linhas = []; $soma = 0.0;
foreach ($itens as $i) {
    $qtd = max(0, (int) ($i['qtd'] ?? 0));
    $unit = 0.0;
    if (($i['tipo'] ?? '') === 'dtf') {
        $produto = ovr_produto((int) ($i['id'] ?? 0));
        if ($produto) {
            $pos = [];
            foreach (($i['posicoes'] ?? []) as $p) {
                if (isset($p['larg'], $p['alt'])) $pos[] = ['larg' => (float) $p['larg'], 'alt' => (float) $p['alt']];
            }
            if (!$pos) $pos = [$cfg['estampas']['frente']];
            /* a quantidade que manda no preço é a do pedido, não a do item */
            $unit = ovr_unitario($produto, max(1, $totalPecas), $pos);
        }
    } else {
        /* filme e arte chegam com o preço já fechado da página deles */
        $unit = round((float) ($i['unitario'] ?? 0), 2);
    }
    $sub = round($unit * $qtd, 2);
    $linhas[] = ['unitario' => $unit, 'subtotal' => $sub];
    $soma += $sub;
}
$soma = round($soma, 2);
$minimo = $cfg['venda']['pedidoMinimo'] ?? 1;

echo json_encode([
    'linhas' => $linhas,
    'pecasDTF' => $totalPecas,
    'faixa' => $totalPecas ? ['rotulo' => $faixa['rotulo'], 'min' => $faixa['min'], 'max' => $faixa['max']] : null,
    'total' => $soma,
    'freteGratis' => false,
    'minimoAtingido' => $totalPecas === 0 || $totalPecas >= $minimo,
    'faltamPecas' => max(0, $minimo - $totalPecas),
], JSON_UNESCAPED_UNICODE);
