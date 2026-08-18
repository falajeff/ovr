<?php
/* EXEMPLO, com números INVENTADOS. Copie para preco-config.php e ponha
 * os seus. Nenhum valor daqui é o real: markup, custo de filme e custo
 * de peça foram trocados de propósito, senão o exemplo devolveria pelo
 * repositório exatamente o que a separação de preço tirou do navegador.
 *
 * O arquivo de verdade não está no repositório de propósito: ele carrega
 * custo de peça, custo de filme e markup. Quem clonar isto consegue rodar
 * o site com valores fictícios e ver a mecânica inteira funcionando.
 *
 * Gerado normalmente por ferramentas/extrair-config-preco.js, que lê o
 * config.js antes da separação. */
if (!defined('OVR_MOTOR')) { http_response_code(404); exit; }

return [
  'dtf' => [
    'precoMetro' => 40.00,      // quanto o metro de filme custa para nós
    'fretePorCompra' => 10.00,  // frete do fornecedor, uma vez por compra
    'larguraCm' => 60,
    'aproveitamento' => 0.85,   // quanto do metro vira estampa útil
  ],
  'estampas' => [
    'frente' => ['rotulo' => 'Frente', 'larg' => 28, 'alt' => 30],
    'costas' => ['rotulo' => 'Costas', 'larg' => 30, 'alt' => 30],
  ],
  'faixas' => [
    ['min' => 1,   'max' => 9,    'ref' => 5,   'rotulo' => '1–9 peças',   'desconto' => 0.00],
    ['min' => 10,  'max' => 24,   'ref' => 15,  'rotulo' => '10–24 peças', 'desconto' => 0.20],
    ['min' => 25,  'max' => 49,   'ref' => 30,  'rotulo' => '25–49 peças', 'desconto' => 0.25],
    ['min' => 50,  'max' => 99,   'ref' => 60,  'rotulo' => '50–99 peças', 'desconto' => 0.30],
    ['min' => 100, 'max' => null, 'ref' => 110, 'rotulo' => '100+ peças',  'desconto' => 0.35],
  ],
  'markup' => [
    'padrao' => 2.0,
    /* A faixa de varejo tem markup menor, e isso só se paga quando o
       fornecedor dá desconto por volume. Ver o comentário em preco.js. */
    'porFaixa' => ['1' => 1.8],
  ],
  'venda' => ['pedidoMinimo' => 1, 'arredondar' => 'exato',
              'freteGratis' => ['valor' => 1500.00, 'estados' => ['SP','PR','RJ','SC']]],
  'freteFornecedor' => ['repassarNoPreco' => true, 'base' => 121,
                        'faixas' => [['ate' => 1, 'valor' => 15], ['ate' => 10, 'valor' => 22], ['ate' => 50, 'valor' => 49]]],
  'dtg' => ['markup' => 1.9, 'etiqueta' => ['custo' => 5.00],
            'acrescimoFrente' => ['35x40' => 0], 'acrescimoCostas' => ['sem' => 0],
            'modelagens' => [['id' => 'basica', 'nome' => 'Básica', 'custo' => 50]]],
  'filme' => ['markup' => 2.0],
];
