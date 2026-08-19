<?php
/* Fecha o preço do pedido inteiro numa chamada.
 *
 * A faixa de desconto sai do TOTAL de peças do pedido, não do item:
 * três regulares mais sete oversized fecham a faixa de dez. Por isso
 * o carrinho não pode ser somado item a item no navegador.
 * Devolve preço e subtotal. Nunca custo, nunca markup. */
define('OVR_MOTOR', true);
require __DIR__ . '/motor-preco.php';
require __DIR__ . '/documento.php';
require __DIR__ . '/cupons.php';

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

/* ------------------------------------------------------------------
   Cupom

   O navegador manda o código e o documento; recebe o valor pronto.
   Nenhum percentual, nenhum teto e nenhuma regra descem para a tela —
   é a mesma linha que o preço passou a respeitar.

   O que NÃO se decide aqui é se a pessoa já comprou antes. Isso é o
   painel que sabe, porque é lá que os pedidos moram, e ele reconfere
   tudo quando o pedido chega. O número desta tela é orçamento: na OVR
   quem fecha a venda é gente, não o site.                            */
$cupom = null;
$desconto = 0.0;
if (($corpo['cupom'] ?? '') !== '') {
    $regra = ovr_cupom((string) $corpo['cupom']);
    $doc   = ovr_documento_normalizar($corpo['documento'] ?? null);

    if (!$regra) {
        $cupom = ['erro' => 'Não encontrei esse cupom. Confira o código.'];
    } elseif (!empty($regra['primeiraCompra']) && !$doc) {
        $cupom = ['erro' => trim((string) ($corpo['documento'] ?? '')) === ''
            ? 'Informe o CPF ou CNPJ para usar este cupom.'
            : 'Esse CPF ou CNPJ não existe. Confira os números.'];
    } elseif ($soma < ($regra['minimo'] ?? 0)) {
        $cupom = ['erro' => 'Este cupom vale a partir de R$ ' . number_format($regra['minimo'], 2, ',', '.') . '.'];
    } else {
        $desconto = ovr_cupom_desconto($regra, $soma);
        $cupom = [
            'codigo'     => $regra['codigo'],
            'rotulo'     => $regra['rotulo'],
            'percentual' => $regra['percentual'],
            'desconto'   => $desconto,
            /* Bate no teto: dizer isso evita a pergunta "por que não deu
               dez por cento?", que chegaria no WhatsApp de outro jeito. */
            'noTeto'     => $desconto < round($soma * $regra['percentual'] / 100, 2),
        ];
    }
}
$totalComDesconto = round($soma - $desconto, 2);

echo json_encode([
    'linhas' => $linhas,
    'pecasDTF' => $totalPecas,
    'faixa' => $totalPecas ? ['rotulo' => $faixa['rotulo'], 'min' => $faixa['min'], 'max' => $faixa['max']] : null,
    'total' => $soma,
    'cupom' => $cupom,
    'desconto' => $desconto,
    /* O frete grátis olha para este número, não para $soma: o desconto
       entra antes do frete. Um pedido de R$ 1.550 cai para R$ 1.395 e
       perde a faixa dos R$ 1.500 — o carrinho avisa quando acontece. */
    'aPagar' => $totalComDesconto,
    'freteGratis' => false,
    'minimoAtingido' => $totalPecas === 0 || $totalPecas >= $minimo,
    'faltamPecas' => max(0, $minimo - $totalPecas),
], JSON_UNESCAPED_UNICODE);
