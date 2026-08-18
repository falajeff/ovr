#!/bin/bash
# Atualiza o catálogo inteiro a partir do site do fornecedor.
# Uso:  bash ferramentas/atualizar.sh
set -e

AQUI="$(cd "$(dirname "$0")" && pwd)"
RAIZ="$(dirname "$AQUI")"

echo "→ 1/3  lendo o site do fornecedor…"
rm -rf "$AQUI/cache"
python3 "$AQUI/1-raspar-fornecedor.py"

echo "→ 2/3  baixando as fotos que faltam…"
mkdir -p "$RAIZ/assets/img/produtos"
python3 - "$AQUI/bruto-fornecedor.json" "$RAIZ/assets/img/produtos" <<'PY' > "$AQUI/urls-faltando.txt"
import json, os, sys
bruto, destino = sys.argv[1], sys.argv[2]
dados = json.load(open(bruto, encoding="utf-8"))
vistos = set()
for p in dados["produtos"]:
    for u in p["imagens"]:
        if u in vistos:
            continue
        vistos.add(u)
        if not os.path.exists(os.path.join(destino, os.path.basename(u))):
            print(u)
PY
FALTAM=$(wc -l < "$AQUI/urls-faltando.txt" | tr -d ' ')
if [ "$FALTAM" -gt 0 ]; then
  (cd "$RAIZ/assets/img/produtos" && xargs -P 8 -n 1 curl -sfL -A "Mozilla/5.0" -O < "$AQUI/urls-faltando.txt")
  echo "   $FALTAM fotos novas."
else
  echo "   nenhuma foto nova."
fi
rm -f "$AQUI/urls-faltando.txt"

echo "→ 3/3  montando o catálogo do site…"
python3 "$AQUI/2-montar-catalogo.py"

rm -rf "$AQUI/cache"
echo
echo "Pronto. Recarregue o site no navegador."
