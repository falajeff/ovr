<?php
/* Cupom: o site pergunta, o painel responde.
 *
 * A tabela de cupons morava aqui e mudar um percentual exigia subir
 * arquivo. Agora ela vive no painel, editável em /gestao, e este arquivo
 * só faz a pergunta.
 *
 * O que volta é o VALOR do desconto, nunca a regra. Percentual e teto
 * não descem para cá e muito menos para o navegador: teto que vaza vira
 * alvo, porque a pessoa monta o pedido no ponto exato em que o desconto
 * rende mais.
 *
 * A pergunta só acontece quando alguém digita um código, que é raro.
 * Uma chamada entre servidores nesse momento não pesa na vitrine — o
 * preço das peças continua sendo calculado aqui, sem sair da máquina. */
if (!defined('OVR_MOTOR')) { http_response_code(403); exit('acesso direto não permitido'); }

const OVR_CUPOM_URL     = 'https://painel.ovrcamisetas.com.br/wp-json/ovr/v1/cupom';
const OVR_CUPOM_TIMEOUT = 6;   // segundos

function ovr_cupom_chave_painel(): string {
    $f = __DIR__ . '/painel-chave.php';
    if (!is_file($f)) return '';
    $c = require $f;
    return is_string($c) ? $c : (string) ($c['cupom'] ?? '');
}

/* Devolve o mesmo formato que o carrinho já esperava:
     ['erro' => '...']                          não deu
     ['codigo'=>…, 'rotulo'=>…, 'percentual'=>…, 'desconto'=>…, 'noTeto'=>…, 'primeiraCompra'=>…]
   O desconto sai em reais, que é a unidade do motor de preço daqui. */
function ovr_cupom_consultar(string $codigo, float $totalReais): array {
    $chave = ovr_cupom_chave_painel();
    if ($chave === '') {
        /* Sem chave não dá para perguntar. Falha explícita: melhor a
           pessoa saber que o cupom não está funcionando do que achar que
           o código dela é inválido. */
        return ['erro' => 'O cupom está indisponível agora. Fale com a gente pelo WhatsApp.'];
    }

    $corpo = json_encode([
        'chave'  => $chave,
        'codigo' => $codigo,
        'total'  => (int) round($totalReais * 100),
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init(OVR_CUPOM_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $corpo,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => OVR_CUPOM_TIMEOUT,
        /* Conectar tem prazo próprio e mais curto: painel fora do ar não
           pode segurar o carrinho de quem está comprando. */
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $resposta = curl_exec($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($resposta === false || $status >= 500 || $status === 0) {
        return ['erro' => 'Não consegui conferir o cupom agora. Tente de novo em um minuto.'];
    }
    $d = json_decode($resposta, true);
    if (!is_array($d)) {
        return ['erro' => 'Não consegui conferir o cupom agora. Tente de novo em um minuto.'];
    }
    if ($status === 403) {
        /* Chave errada é problema de configuração, não do cliente. */
        return ['erro' => 'O cupom está indisponível agora. Fale com a gente pelo WhatsApp.'];
    }
    if (empty($d['ok'])) {
        return ['erro' => (string) ($d['erro'] ?? 'Não encontrei esse cupom. Confira o código.')];
    }

    return [
        'codigo'         => (string) $d['codigo'],
        'rotulo'         => (string) $d['rotulo'],
        'percentual'     => (int) $d['percentual'],
        'primeiraCompra' => !empty($d['primeiraCompra']),
        'desconto'       => round(((int) $d['desconto']) / 100, 2),
        'noTeto'         => !empty($d['noTeto']),
    ];
}
