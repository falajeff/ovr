<?php
/* Motor de preço da OVR.
 *
 * Este arquivo é o gêmeo em PHP de assets/js/preco.js. Ele existe porque a
 * conta de preço carrega o custo da peça, o custo do filme e o markup, e
 * nada disso pode viajar para o navegador: quem lê o JavaScript da vitrine
 * lê a margem inteira.
 *
 * A regra para mexer aqui: qualquer alteração tem que passar em
 * ferramentas/conferir-motor.php, que compara este motor com o gabarito
 * congelado do motor antigo, produto por produto.
 */

if (!defined('OVR_MOTOR')) define('OVR_MOTOR', true);

function ovr_cfg() {
    static $c = null;
    if ($c === null) $c = require __DIR__ . '/preco-config.php';
    return $c;
}

/* O config inteiro, para o gerador de tabelas. O motor de preço usa
   só ovr_cfg(); isto existe para DTG e filme, que são preço fechado. */
function ovr_cfg_completa(): array { return ovr_cfg(); }

function ovr_area_util_metro(): float {
    $d = ovr_cfg()['dtf'];
    return $d['larguraCm'] * 100 * $d['aproveitamento'];
}

/* A última faixa não tem teto. Em JavaScript isso era Infinity, que o
   JSON não sabe escrever e virou null. Aqui null quer dizer "sem teto":
   sem esta leitura, 100 peças não caem na faixa de 100+.             */
function ovr_teto($f): float { return $f['max'] === null ? INF : (float) $f['max']; }

function ovr_faixa_de(int $qtd): array {
    foreach (ovr_cfg()['faixas'] as $f) {
        if ($qtd >= $f['min'] && $qtd <= ovr_teto($f)) return $f;
    }
    $fs = ovr_cfg()['faixas'];
    return $fs[count($fs) - 1];
}

/* Área pedida, em cm². Aceita medidas explícitas vindas da página. */
function ovr_area_estampa(array $posicoes): float {
    $soma = 0.0;
    $est = ovr_cfg()['estampas'];
    foreach ($posicoes as $p) {
        $e = is_string($p) ? ($est[$p] ?? null) : $p;
        if ($e && ($e['larg'] ?? 0) > 0 && ($e['alt'] ?? 0) > 0) $soma += $e['larg'] * $e['alt'];
    }
    return $soma;
}

function ovr_custo_cm2(int $qtd, float $area): float {
    $d = ovr_cfg()['dtf'];
    $util = ovr_area_util_metro();
    $metros = max(1, (int) ceil(($qtd * $area) / $util));
    $custoCompra = $metros * $d['precoMetro'] + $d['fretePorCompra'];
    return ($custoCompra / $metros) / $util;
}

function ovr_custo_estampa(int $qtd, array $posicoes): float {
    $area = ovr_area_estampa($posicoes);
    if ($area <= 0) return 0.0;
    return ovr_custo_cm2($qtd, $area) * $area;
}

function ovr_frete_pedido(int $qtd): float {
    $f = ovr_cfg()['freteFornecedor'] ?? null;
    if (!$f) return 0.0;
    foreach (($f['faixas'] ?? []) as $x) if ($qtd <= $x['ate']) return (float) $x['valor'];
    return (float) ($f['base'] ?? 0);
}

function ovr_frete_por_peca(int $qtd): float {
    if ($qtd <= 0) return 0.0;
    return ovr_frete_pedido($qtd) / $qtd;
}

/* Arredondamento comercial: sempre termina em ,90 e nunca cai abaixo do alvo. */
function ovr_arredondar(float $v): float {
    if ((ovr_cfg()['venda']['arredondar'] ?? '') === 'noventa') {
        $base = floor($v);
        return ($base + 0.90 >= $v) ? $base + 0.90 : $base + 1.90;
    }
    return round($v * 100) / 100;
}

/* A tabela do produto manda; a do config é só o retrato médio. */
function ovr_custo_da_peca(array $produto, int $qtd): float {
    $suas = $produto['faixas'] ?? null;
    if (is_array($suas) && count($suas)) {
        $aplicaveis = array_filter($suas, fn($f) => $qtd >= ($f['min'] ?? 1));
        if (count($aplicaveis)) {
            usort($aplicaveis, fn($a, $b) => ($b['min'] ?? 1) <=> ($a['min'] ?? 1));
            $a = $aplicaveis[0];
            if (isset($a['preco']) && is_numeric($a['preco'])) return (float) $a['preco'];
            return $produto['precoBase'] * (1 - ($a['desc'] ?? 0) / 100);
        }
    }
    return $produto['precoBase'] * (1 - ovr_faixa_de($qtd)['desconto']);
}

function ovr_tem_desconto_de_volume(array $produto): bool {
    $fs = ovr_cfg()['faixas'];
    $ultima = $fs[count($fs) - 1];
    return ovr_custo_da_peca($produto, 1) > ovr_custo_da_peca($produto, $ultima['ref']) + 0.001;
}

/* O markup da faixa de 1 a 9 é menor que o padrão: na unidade o fornecedor
   não dá desconto e o preço ainda precisa caber no mercado.

   Isso quebrou em oito peças do catálogo. Nelas o custo não caía ao subir de
   9 para 10 peças, mas o markup subia de volta para o padrão. Resultado: o
   preço AUMENTAVA ao passar de 9 para 10. Comprar mais saía mais caro.

   Daí a guarda: peça sem desconto de volume usa sempre o markup padrão, em
   qualquer quantidade, e a escada fica plana em vez de invertida.

   Em 20/ago/2026 a CAUSA apareceu, e era outra: aquelas oito estavam
   gravadas com um preço promocional avulso, sem a tabela por quantidade da
   categoria delas. Não era o fornecedor cobrando igual em qualquer
   quantidade — era o custo errado. Os dados foram corrigidos contra os
   banners de categoria e hoje nenhuma peça cai nesta guarda.

   Ela fica assim mesmo. É barata, e no dia em que uma peça nova entrar com
   preço avulso ela evita que o catálogo volte a cobrar mais de quem compra
   mais. Guarda que nunca dispara não é código morto: é código esperando. */
function ovr_markup_de(array $faixa, ?array $produto): float {
    $m = ovr_cfg()['markup'];
    if ($produto && !ovr_tem_desconto_de_volume($produto)) return (float) $m['padrao'];
    return (float) ($m['porFaixa'][(string) $faixa['min']] ?? $m['padrao']);
}

/* Markup da peça lisa. Não tem escada por faixa: o que justifica o markup
   menor da faixa de varejo na peça estampada é o desconto do fornecedor, e
   aqui o markup já nasce baixo. Um degrau a mais só inverteria a escada,
   que é o bug que a guarda acima existe para evitar. */
function ovr_markup_liso(): float {
    $m = ovr_cfg()['markup'];
    return (float) ($m['liso'] ?? $m['padrao']);
}

/* Sem área de estampa, a peça é lisa — e peça lisa tem markup próprio.
   A condição é a área e não uma bandeira à parte porque é a mesma coisa
   dita duas vezes: se não há o que imprimir, não há impressão para cobrar.

   Isto não muda preço nenhum por acidente. Os três chamadores (preco.php,
   carrinho.php e gerar-precos.php) já trocam lista vazia pela medida
   padrão da frente antes de chegar aqui, então lista vazia só acontece
   quando alguém pede peça lisa de propósito. */
function ovr_unitario(array $produto, int $qtd, array $posicoes): float {
    $custoPeca = ovr_custo_da_peca($produto, $qtd);
    $estampa = ovr_custo_estampa($qtd, $posicoes);
    $frete = (ovr_cfg()['freteFornecedor']['repassarNoPreco'] ?? false) ? ovr_frete_por_peca($qtd) : 0.0;
    $markup = $estampa > 0
        ? ovr_markup_de(ovr_faixa_de($qtd), $produto)
        : ovr_markup_liso();
    return ovr_arredondar(($custoPeca + $estampa + $frete) * $markup);
}

function ovr_escada(array $produto, array $posicoes, ?int $qtdAtual = null): array {
    $linhas = [];
    foreach (ovr_cfg()['faixas'] as $f) {
        $linhas[] = [
            'rotulo' => $f['rotulo'],
            'min' => $f['min'], 'max' => $f['max'],
            'valor' => ovr_unitario($produto, $f['ref'], $posicoes),
            'ativa' => $qtdAtual !== null && $qtdAtual >= $f['min'] && $qtdAtual <= ovr_teto($f),
        ];
    }
    $base = $linhas[0]['valor'];
    foreach ($linhas as &$l) $l['economia'] = $base > 0 ? (int) round((1 - $l['valor'] / $base) * 100) : 0;
    return $linhas;
}

/* Junta a ficha pública com o custo privado. O navegador nunca vê a
   segunda metade: ela só existe dentro do servidor.                 */
function ovr_produto(int $id): ?array {
    static $pub = null, $cus = null;
    if ($pub === null) {
        $pub = [];
        $j = json_decode(@file_get_contents(__DIR__ . '/../dados/catalogo.json'), true);
        foreach (($j['produtos'] ?? []) as $p) $pub[(string) $p['id']] = $p;
        $cus = (@include __DIR__ . '/catalogo-custo.php') ?: [];
    }
    $k = (string) $id;
    if (!isset($pub[$k]) || !isset($cus[$k])) return null;
    return $pub[$k] + $cus[$k];
}

function ovr_a_partir_de(array $produto, array $posicoes): float {
    $fs = ovr_cfg()['faixas'];
    $ultima = $fs[count($fs) - 1];
    return ovr_unitario($produto, $ultima['ref'], $posicoes);
}
