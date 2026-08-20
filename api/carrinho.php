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

/* Teto de segurança por tipo de linha, em reais por unidade. Não é a
   tabela de preço: é o limite do que faz sentido, para um valor forjado
   não passar. Os números saem do que cada página realmente cobra, com
   folga larga para não barrar venda legítima. */
const OVR_QTD_MAX_ITEM = 10000;

function ovr_teto_por_tipo(string $tipo): float {
    return [
        'filme' => 20000.0,   // rolo grande de filme
        'arte'  => 5000.0,    // ilustração autoral cara
        'dtg'   => 1000.0,    // peça DTG mais cara com estampa grande
    ][$tipo] ?? 2000.0;
}

$cfg = ovr_cfg();
/* Peça estampada e peça lisa saem do mesmo pedido ao fornecedor, então o
   desconto por volume dele olha as duas somadas. Quem leva 5 estampadas e
   5 lisas comprou 10 peças, e é isso que decide a faixa. Contar separado
   inventaria uma barreira que não existe do outro lado. */
const OVR_TIPOS_DA_CASA = ['dtf', 'peca'];
$totalPecas = 0;
foreach ($itens as $i) {
    if (in_array($i['tipo'] ?? '', OVR_TIPOS_DA_CASA, true)) $totalPecas += max(0, (int) ($i['qtd'] ?? 0));
}

$faixa = ovr_faixa_de(max(1, $totalPecas));
$linhas = []; $soma = 0.0;
foreach ($itens as $i) {
    /* Teto de quantidade. Sem ele, 99.999.999 peças fechavam um pedido de
       sete bilhões de reais — número que não é venda, é lixo no painel. */
    $qtd = min(max(0, (int) ($i['qtd'] ?? 0)), OVR_QTD_MAX_ITEM);
    $unit = 0.0;
    $tipo = $i['tipo'] ?? '';
    if (in_array($tipo, OVR_TIPOS_DA_CASA, true)) {
        $produto = ovr_produto((int) ($i['id'] ?? 0));
        if ($produto) {
            /* Peça lisa: lista de posições vazia, e o motor troca o markup.
               Aqui a lista é montada pelo TIPO e não pelo que o navegador
               mandou, senão bastaria omitir as posições numa linha `dtf`
               para pagar preço de peça lisa e receber peça estampada. */
            $pos = [];
            if ($tipo === 'dtf') {
                foreach (($i['posicoes'] ?? []) as $p) {
                    if (isset($p['larg'], $p['alt'])) $pos[] = ['larg' => (float) $p['larg'], 'alt' => (float) $p['alt']];
                }
                if (!$pos) $pos = [$cfg['estampas']['frente']];
            }
            /* a quantidade que manda no preço é a do pedido, não a do item */
            $unit = ovr_unitario($produto, max(1, $totalPecas), $pos);
        }
    } else {
        /* Filme, arte e DTG chegam com o preço já fechado da página deles,
           porque cada uma tem uma conta própria que não é a da peça.

           Mas o navegador NÃO pode ser autoridade sobre o número. Mandando
           `unitario` negativo dava para abater o total de um pedido real:
           dez camisetas mais uma linha de filme a -700 fechava perto de
           zero. O pedido é orçamento e quem fecha é gente, mas o valor que
           chega no painel é o que você usa para cotar.

           Duas travas, e nenhuma delas tenta refazer a conta da página:
           preço nunca é negativo, e nunca passa do teto por tipo.        */
        $unit = max(0.0, round((float) ($i['unitario'] ?? 0), 2));
        $unit = min($unit, ovr_teto_por_tipo($i['tipo'] ?? ''));
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
    $doc = ovr_documento_normalizar($corpo['documento'] ?? null);
    $r   = ovr_cupom_consultar((string) $corpo['cupom'], $soma);

    if (isset($r['erro'])) {
        $cupom = ['erro' => $r['erro']];
    } elseif (!empty($r['primeiraCompra']) && !$doc) {
        /* O documento é conferido AQUI e não no painel: é este servidor
           que recebe o que a pessoa digitou. O painel só diz que o cupom
           exige primeira compra. */
        $cupom = ['erro' => trim((string) ($corpo['documento'] ?? '')) === ''
            ? 'Informe o CPF ou CNPJ para usar este cupom.'
            : 'Esse CPF ou CNPJ não existe. Confira os números.'];
    } elseif ($r['desconto'] <= 0) {
        $cupom = ['erro' => 'Este cupom não vale para este pedido.'];
    } else {
        $desconto = $r['desconto'];
        $cupom = [
            'codigo'     => $r['codigo'],
            'rotulo'     => $r['rotulo'],
            'percentual' => $r['percentual'],
            'desconto'   => $desconto,
            /* Bate no teto: dizer isso evita a pergunta "por que não deu
               dez por cento?", que chegaria no WhatsApp de outro jeito. */
            'noTeto'     => $r['noTeto'],
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
