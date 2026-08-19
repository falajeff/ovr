<?php
/* Cupons de desconto.
 *
 * Este arquivo fica no repositório de propósito. Ele não guarda custo
 * nem markup: código, percentual e teto são coisas que o cliente lê na
 * própria tela quando aplica o cupom. O que é secreto — a chave que
 * anonimiza o CPF — mora em preco-config.php, que é privado.
 *
 * A conta do desconto acontece aqui e só aqui. O navegador manda o
 * código e recebe o valor pronto, pelo mesmo motivo que ele não calcula
 * mais preço: regra de desconto no cliente é regra que se edita com o
 * inspetor aberto. */
if (!defined('OVR_MOTOR')) { http_response_code(403); exit('acesso direto não permitido'); }

function ovr_cupons(): array {
    return [
        'PRIMEIRA10' => [
            'rotulo'         => 'Primeira compra',
            'percentual'     => 10,
            /* Teto em reais. Sem ele um pedido de cem peças leva R$ 757
               de desconto, e não é pedido grande que precisa de empurrão
               para fechar — é o primeiro pedido pequeno. O teto deixa o
               cupom inteiro para quem está começando e apara a ponta. */
            'teto'           => 150.00,
            'primeiraCompra' => true,
            'minimo'         => 0.0,
            'ate'            => null,      // 'AAAA-MM-DD' para expirar
            'ativo'          => true,
        ],
    ];
}

function ovr_cupom(string $codigo): ?array {
    /* Maiúscula e sem espaço: quem digita cupom digita de qualquer jeito. */
    $c = strtoupper(preg_replace('/\s+/', '', $codigo));
    $todos = ovr_cupons();
    if (!isset($todos[$c])) return null;

    $cupom = $todos[$c] + ['codigo' => $c];
    if (empty($cupom['ativo'])) return null;
    if (!empty($cupom['ate']) && date('Y-m-d') > $cupom['ate']) return null;
    return $cupom;
}

/* Quanto sai do total. Nunca mais que o teto, nunca mais que o próprio
   total — desconto maior que a compra viraria crédito, e a loja não tem
   isso. Arredonda para baixo no centavo para o total nunca sobrar. */
function ovr_cupom_desconto(array $cupom, float $total): float {
    if ($total < ($cupom['minimo'] ?? 0)) return 0.0;
    $bruto = $total * ($cupom['percentual'] / 100);
    $teto  = $cupom['teto'] ?? INF;
    return round(min($bruto, $teto, $total), 2);
}
