<?php
/* Separa o catálogo em público e privado, e pré-calcula os preços.
 *
 * Depois disto o navegador recebe:
 *   dados/catalogo.json  nome, cor, grade, imagens — nenhum dinheiro
 *   dados/precos.json    preço final por faixa — nenhum custo
 * e o servidor guarda:
 *   api/catalogo-custo.json  precoBase e faixas do fornecedor
 *
 * Roda: php ferramentas/gerar-precos.php
 */
define('OVR_MOTOR', true);
require __DIR__ . '/../api/motor-preco.php';

$raiz = __DIR__ . '/..';
$fonte = json_decode(file_get_contents("$raiz/dados/catalogo.json"), true);
$produtos = $fonte['produtos'];

/* Idempotência: rodar duas vezes não pode destruir o custo. Se o catálogo
   já está separado, o custo volta do arquivo privado antes de recalcular.
   Sem isto, a segunda execução gera preço a partir de precoBase vazio.  */
$jaSeparado = !isset($produtos[0]['precoBase']);
$antigo = [];
if ($jaSeparado) {
    $antigo = (@include "$raiz/api/catalogo-custo.php") ?: [];
    $faltando = [];
    foreach ($produtos as &$p) {
        $c = $antigo[(string) $p['id']] ?? null;
        if (!$c || !isset($c['precoBase'])) { $faltando[] = $p['id']; continue; }
        $p['precoBase'] = $c['precoBase'];
        $p['faixas'] = $c['faixas'];
        if (isset($c['origem'])) { $p['origem'] = $c['origem']; }
    }
    unset($p);
    if ($faltando) {
        fwrite(STDERR, "ABORTADO: catálogo já separado e sem custo para " . count($faltando) . " produtos.\n"
            . "Restaure dados/catalogo.json com o custo antes de gerar.\n");
        exit(1);
    }
    fwrite(STDERR, "catálogo já estava separado; custo recuperado do arquivo privado\n");
}
$pos = [ovr_cfg()['estampas']['frente']];

/* Grupos que não vão para o site. Gêmeo de CONFIG.catalogo.excluirGrupos
   no config.js, que já filtrava isto no navegador. A diferença é que ali a
   peça sumia da vitrine mas continuava viajando no catalogo.json público:
   um kit de meias do fornecedor não aparecia em lugar nenhum e ainda assim
   dizia a marca dele para quem abrisse o arquivo. Peça que não entra no
   site também não precisa sair de casa. */
$foraDoSite = ['Outlet'];

/* Tipos que não são peça de vestir e não têm vitrine. Gêmeo de
   CONFIG.catalogo.tiposSemSilhueta no config.js, que já os escondia no
   navegador. Mesmo caso do Outlet: sumiam da tela e continuavam viajando
   no catalogo.json público. O Acessório é embalagem zip lock, insumo de
   expedição que o cliente nunca compra aqui. */
$tiposForaDoSite = ['Acessório'];

/* Peças que o fornecedor deixou de ter. Sair do catálogo dele não apaga a
   peça daqui sozinho: o catalogo.json só é reconstruído quando a raspagem
   roda, e até lá ela continuaria à venda. Lista explícita, com o motivo,
   porque decisão de negócio não pode viver como linha apagada em silêncio
   — apagada, ela volta na próxima raspagem se o fornecedor relistar.

   Confirmado com o Jefferson em 20/ago/2026:
     127, 128, 130  Plus Size com Elastano — não existe mais, nem masculina
                    nem feminina. A Feminina Lisa com Elastano (138) é de
                    outra categoria e continua à venda.
     55             Feminina Lisa Canelada — não existe mais. */
$idsForaDoSite = [127, 128, 130, 55];

/* O custo começa do que já existia, não do zero. Reconstruir a partir do
   catálogo público apagava em silêncio toda peça que tinha sido excluída
   numa rodada anterior: ela sai do público, some da fonte, e na execução
   seguinte o custo dela evapora. Aqui as antigas ficam e as atuais
   sobrescrevem. */
$publico = []; $custo = $antigo; $precos = [];
foreach ($produtos as $p) {
    $custo[(string) $p['id']] = [
        'precoBase' => $p['precoBase'],
        'faixas' => $p['faixas'] ?? [],
        /* A URL da peça no site do fornecedor. Ninguém no site lê este
           campo, e ele entregava de quem a OVR compra: nome, id do produto
           lá dentro e, por tabela, o preço de balcão deles. Fica do lado
           privado junto com o custo, que é onde já mora esse tipo de
           informação. Se o catálogo já vier separado, o valor volta daqui
           na recuperação logo acima, então reexecutar não perde nada. */
        'origem' => $p['origem'] ?? ($antigo[(string) $p['id']]['origem'] ?? null),
    ];
    $limpo = $p;
    unset($limpo['precoBase'], $limpo['faixas'], $limpo['origem']);   // dinheiro e fornecedor saem do público
    $fora = array_intersect($p['grupos'] ?? [], $foraDoSite)
         || in_array($p['tipo'] ?? '', $tiposForaDoSite, true)
         || in_array((int) $p['id'], $idsForaDoSite, true);
    if (!$fora) {
        $publico[] = $limpo;
    }

    $enxugar = fn(array $escada) => array_map(fn($l) => [
        'rotulo' => $l['rotulo'], 'min' => $l['min'], 'max' => $l['max'],
        'valor' => $l['valor'], 'economia' => $l['economia'],
    ], $escada);

    if ($fora) continue;   // sem vitrine, sem preço para calcular

    $precos[(string) $p['id']] = [
        'aPartirDe' => ovr_a_partir_de($p, $pos),
        'escada' => $enxugar(ovr_escada($p, $pos)),
        /* A mesma peça sem estampa. Lista de posições vazia é o que diz ao
           motor que é peça lisa, e lá ele troca o markup. Vem pré-calculado
           pelo mesmo motivo do estampado: a vitrine mostra preço sem tocar
           a rede, e a conta continua sendo só do servidor. */
        'liso' => [
            'aPartirDe' => ovr_a_partir_de($p, []),
            'escada' => $enxugar(ovr_escada($p, [])),
        ],
    ];
}

$fonte['produtos'] = $publico;
file_put_contents("$raiz/dados/catalogo.json", json_encode($fonte, JSON_UNESCAPED_UNICODE));
/* Guardado como PHP e não como JSON: assim nem uma configuração errada
   de servidor consegue entregar o custo, porque o arquivo se recusa a
   rodar fora do motor. */
file_put_contents("$raiz/api/catalogo-custo.php",
    "<?php\n"
  . "if (!defined('OVR_MOTOR')) { http_response_code(404); exit; }\n"
  . "return json_decode(<<<'JSON'\n" . json_encode($custo, JSON_UNESCAPED_UNICODE) . "\nJSON, true);\n");
@unlink("$raiz/api/catalogo-custo.json");
/* DTG e filme também saem prontos: o cliente recebe preço, não a conta. */
$dtgTabela = [];
$dtg = ovr_cfg_completa()['dtg'] ?? null;
if ($dtg) {
    foreach ($dtg['modelagens'] as $m) {
        $cDtg = $m['custo'];
        $preco = $cDtg * $dtg['markup'];
        $base = floor($preco);
        $preco = ($base + 0.90 >= $preco) ? $base + 0.90 : $base + 1.90;
        $acF = []; foreach ($dtg['acrescimoFrente'] as $k => $v) {
            $c2 = $cDtg - ($dtg['acrescimoFrente']['35x40'] ?? 0) + $v;
            $p2 = $c2 * $dtg['markup']; $b2 = floor($p2);
            $acF[$k] = round(($b2 + 0.90 >= $p2) ? $b2 + 0.90 : $b2 + 1.90, 2);
        }
        $acC = []; foreach ($dtg['acrescimoCostas'] as $k => $v) {
            $c2 = $cDtg + $v;
            $p2 = $c2 * $dtg['markup']; $b2 = floor($p2);
            $acC[$k] = round(($b2 + 0.90 >= $p2) ? $b2 + 0.90 : $b2 + 1.90, 2);
        }
        $dtgTabela[$m['id']] = ['preco' => round($preco, 2), 'porFrente' => $acF, 'porCostas' => $acC];
    }
    $et = $dtg['etiqueta']['custo'] * $dtg['markup'];
    $dtgTabela['_etiqueta'] = round($et, 2);
}

$filme = ovr_cfg_completa()['filme'] ?? [];
$d = ovr_cfg()['dtf'];
$filmeTabela = [
    'precoMetro' => round($d['precoMetro'] * ($filme['markup'] ?? 1), 2),
    'larguraCm' => $d['larguraCm'],
    'aproveitamento' => $d['aproveitamento'],
];

file_put_contents("$raiz/dados/precos.json", json_encode([
    'medidaPadrao' => ovr_cfg()['estampas']['frente'],
    'faixas' => array_map(fn($f) => ['rotulo'=>$f['rotulo'],'min'=>$f['min'],'max'=>$f['max'],'ref'=>$f['ref']], ovr_cfg()['faixas']),
    'produtos' => $precos,
    'dtg' => $dtgTabela,
    'filme' => $filmeTabela,
], JSON_UNESCAPED_UNICODE));

printf("público:  %d produtos, sem precoBase, faixas nem origem (%d fora por grupo)\n",
    count($publico), count($produtos) - count($publico));
printf("custo:    %d produtos, só no servidor\n", count($custo));
printf("preços:   %d produtos pré-calculados\n", count($precos));
