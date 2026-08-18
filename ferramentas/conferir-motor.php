<?php
/* Compara o motor PHP com o gabarito congelado do motor JavaScript.
   Roda: php ferramentas/conferir-motor.php /tmp/precos-antes.json */
define('OVR_MOTOR', true);
require __DIR__ . '/../api/motor-preco.php';
$gab = json_decode(file_get_contents($argv[1] ?? '/tmp/precos-antes.json'), true);
$porId = [];
foreach ((include __DIR__ . '/../api/catalogo-custo.php') as $id => $_) {
    $porId[(int) $id] = ovr_produto((int) $id);
}
$pos = [ovr_cfg()['estampas']['frente']];

$ok = 0; $erros = [];
foreach ($gab['produtos'] as $id => $g) {
    $p = $porId[$id] ?? null;
    if (!$p) { $erros[] = "produto $id sumiu do catálogo"; continue; }
    $a = ovr_a_partir_de($p, $pos);
    if (abs($a - $g['aPartirDe']) > 0.001) $erros[] = "#$id aPartirDe: js {$g['aPartirDe']} vs php $a";
    else $ok++;
    foreach ($g['escada'] as $i => $l) {
        $php = ovr_escada($p, $pos)[$i];
        if (abs($php['valor'] - $l['valor']) > 0.001) $erros[] = "#$id escada {$l['rotulo']}: js {$l['valor']} vs php {$php['valor']}";
        elseif ($php['economia'] !== $l['economia']) $erros[] = "#$id economia {$l['rotulo']}: js {$l['economia']} vs php {$php['economia']}";
        else $ok++;
    }
    foreach ($g['unitarios'] as [$q, $v]) {
        $php = ovr_unitario($p, $q, $pos);
        if (abs($php - $v) > 0.001) $erros[] = "#$id unitario q=$q: js $v vs php $php";
        else $ok++;
    }
}
foreach ($gab['frete'] as [$q, $ped, $peca]) {
    if (abs(ovr_frete_pedido($q) - $ped) > 0.001) $erros[] = "frete pedido q=$q";
    elseif (abs(ovr_frete_por_peca($q) - $peca) > 0.001) $erros[] = "frete peça q=$q";
    else $ok++;
}
printf("pontos conferidos: %d\nbateram: %d\ndivergiram: %d\n", $ok + count($erros), $ok, count($erros));
foreach (array_slice($erros, 0, 12) as $e) echo "  ✗ $e\n";
exit(count($erros) ? 1 : 0);
