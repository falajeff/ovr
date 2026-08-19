<?php
/* CPF e CNPJ: existe este número?

 * Uma responsabilidade só. O site confere o dígito verificador e para
 * por aí: quem guarda a identidade de quem já comprou é o painel, que
 * é onde o pedido mora.

 * Aqui não há chave secreta nem lista de CPF. Se este servidor vazar
 * amanhã, não leva cadastro de cliente junto — é o mesmo raciocínio
 * que tirou custo e markup do navegador. */
if (!defined('OVR_MOTOR')) { http_response_code(403); exit('acesso direto não permitido'); }

/* ------------------------------------------------------------------
   Dígito verificador, o mesmo cálculo para CPF e CNPJ

   Soma cada caractere pelo seu peso, tira o resto por 11 e inverte.
   Resto 0 ou 1 vira dígito 0; qualquer outro vira 11 menos o resto.

   O valor de cada caractere é `ord - 48`, e não o dígito, porque desde
   julho de 2026 o CNPJ aceita letra nas doze primeiras posições. Para
   '0'..'9' as duas contas dão no mesmo, então um CNPJ antigo continua
   validando pelo mesmo caminho — não há dois algoritmos aqui.       */
function ovr_dv(string $base, array $pesos): string {
    $soma = 0;
    for ($i = 0, $n = strlen($base); $i < $n; $i++) {
        $soma += (ord($base[$i]) - 48) * $pesos[$i];
    }
    $resto = $soma % 11;
    return (string) ($resto < 2 ? 0 : 11 - $resto);
}

function ovr_cpf_valido(string $d): bool {
    if (!preg_match('/^\d{11}$/', $d)) return false;

    /* 111.111.111-11 e os outros dez repetidos passam no dígito
       verificador. São inválidos por regra, não por cálculo, então
       precisam ser barrados na mão. */
    if (preg_match('/^(\d)\1{10}$/', $d)) return false;

    $p1 = ovr_dv(substr($d, 0, 9),  [10, 9, 8, 7, 6, 5, 4, 3, 2]);
    $p2 = ovr_dv(substr($d, 0, 10), [11, 10, 9, 8, 7, 6, 5, 4, 3, 2]);
    return $d[9] === $p1 && $d[10] === $p2;
}

function ovr_cnpj_valido(string $d): bool {
    /* Doze posições alfanuméricas mais dois dígitos. A regra nova é
       mais larga que a antiga: todo CNPJ numérico continua casando. */
    if (!preg_match('/^[0-9A-Z]{12}\d{2}$/', $d)) return false;
    if (preg_match('/^(.)\1{13}$/', $d)) return false;

    $p1 = ovr_dv(substr($d, 0, 12), [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
    $p2 = ovr_dv(substr($d, 0, 13), [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
    return $d[12] === $p1 && $d[13] === $p2;
}

/* Devolve ['tipo' => 'cpf'|'cnpj', 'numero' => '...'] ou null.
   Aceita com ou sem máscara: quem digita ponto e traço é a maioria. */
function ovr_documento_normalizar(?string $bruto): ?array {
    $d = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) $bruto));
    if ($d === '') return null;
    if (strlen($d) === 11 && ovr_cpf_valido($d))  return ['tipo' => 'cpf',  'numero' => $d];
    if (strlen($d) === 14 && ovr_cnpj_valido($d)) return ['tipo' => 'cnpj', 'numero' => $d];
    return null;
}
