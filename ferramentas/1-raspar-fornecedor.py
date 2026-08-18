#!/usr/bin/env python3
"""Raspa o catalogo completo do fornecedor: produtos, imagens, tamanhos e faixas de preco.

O endereco do fornecedor mora em fornecedor.json, que fica fora do
repositorio. Nao e segredo tecnico: e que o codigo publicado nao precisa
dizer de quem a OVR compra. Copie fornecedor.exemplo.json e preencha.
"""
import json, re, subprocess, sys, os, html

OUT = os.path.dirname(os.path.abspath(__file__))

_cfg_path = os.path.join(OUT, "fornecedor.json")
if not os.path.exists(_cfg_path):
    sys.exit("falta ferramentas/fornecedor.json — copie de fornecedor.exemplo.json")
_cfg = json.load(open(_cfg_path, encoding="utf-8"))
BASE, ASSETS = _cfg["base"], _cfg["assets"]

def fetch(url, cache_name):
    path = os.path.join(OUT, "cache", cache_name)
    os.makedirs(os.path.dirname(path), exist_ok=True)
    if os.path.exists(path) and os.path.getsize(path) > 5000:
        return open(path, encoding="utf-8", errors="ignore").read()
    subprocess.run(["curl", "-sL", "-A", "Mozilla/5.0", url, "-o", path], check=True)
    return open(path, encoding="utf-8", errors="ignore").read()

def clean(s):
    return html.unescape(re.sub(r"\s+", " ", re.sub(r"<[^>]+>", "", s))).strip()

# 1. categorias
home = fetch(BASE + "/", "home.html")
cats = {}
_re_cat = r'href="(?:%s)?(/CategoriaId_(\d+)/([^"]*?)\.html)"' % re.escape(BASE)
for m in re.finditer(_re_cat, home):
    href, cid, slug = m.group(1), m.group(2), m.group(3)
    parts = [p.replace("-", " ") for p in slug.split("/")]
    cats[cid] = {"id": cid, "href": href, "grupo": parts[0], "nome": parts[-1] if len(parts) > 1 else parts[0]}
print(f"{len(cats)} categorias", file=sys.stderr)

produtos = {}
for cid, cat in sorted(cats.items(), key=lambda x: int(x[0])):
    try:
        page = fetch(BASE + cat["href"], f"cat_{cid}.html")
    except Exception as e:
        print(f"  falhou cat {cid}: {e}", file=sys.stderr); continue

    blocks = page.split('<div class="prod-item-grade">')[1:]
    n = 0
    for b in blocks:
        hm = re.search(r'href="(/ProdutoId_(\d+),(\d+)/[^"]+)"', b)
        if not hm:
            continue
        href, pid, bcid = hm.group(1), hm.group(2), hm.group(3)
        nm = re.search(r"<h1><a[^>]*>(.*?)</a>", b, re.S)
        nome = clean(nm.group(1)) if nm else ""
        if not nome:
            continue

        imgs = []
        pim = re.search(r'<div class="prod-img">(.*?)</div>\s*<ul', b, re.S)
        for im in re.finditer(r'src="(%s[^"]+)"' % re.escape(ASSETS), pim.group(1) if pim else b):
            u = im.group(1)
            if u not in imgs:
                imgs.append(u)

        pm = re.search(r'id="valprodpri" value="([\d.]+)"', b)
        if not pm:
            continue
        preco = float(pm.group(1))

        faixas = []
        tm = re.search(r'<table class="table" style="font-size: 12px;">(.*?)</table>', b, re.S)
        if tm:
            for row in re.findall(r"<tr>(.*?)</tr>", tm.group(1), re.S):
                tds = [clean(t) for t in re.findall(r"<td[^>]*>(.*?)</td>", row, re.S)]
                if len(tds) != 3 or "Quantidade" in tds[0]:
                    continue
                q = 1 if "abaixo" in tds[0].lower() else int(re.sub(r"\D", "", tds[0]) or 1)
                desc = float(re.sub(r"[^\d]", "", tds[1]) or 0)
                val = float(tds[2].replace("R$", "").replace(".", "").replace(",", ".").strip())
                faixas.append({"min": q, "desc": desc, "preco": val})
        faixas.sort(key=lambda f: f["min"])
        if not faixas:
            faixas = [{"min": 1, "desc": 0, "preco": preco}]

        grade = []
        gm = re.search(r'<div class="caixagrade">(.*?)</table>', b, re.S)
        if gm:
            for row in re.findall(r"<tr>(.*?)</tr>", gm.group(1), re.S):
                sm = re.search(r"<strong>(.*?)</strong>", row, re.S)
                em = re.search(r"\((\d+)\)", row)
                cm = re.search(r"background:\s*(#[0-9a-fA-F]{3,6})", row)
                if sm:
                    grade.append({"tam": clean(sm.group(1)), "estoque": int(em.group(1)) if em else 0,
                                  "cor": (cm.group(1) if cm else "").lower()})

        if pid in produtos:
            produtos[pid]["categorias"] = sorted(set(produtos[pid]["categorias"] + [cid]))
            continue
        produtos[pid] = {
            "id": pid, "nome": nome, "href": href, "imagens": imgs, "precoBase": preco,
            "faixas": faixas, "grade": grade, "categorias": [cid], "categoriaPrincipal": bcid,
        }
        n += 1
    print(f"  cat {cid} {cat['nome'][:34]:36s} +{n}", file=sys.stderr)

data = {"categorias": list(cats.values()), "produtos": list(produtos.values())}
with open(os.path.join(OUT, "bruto-fornecedor.json"), "w", encoding="utf-8") as f:
    json.dump(data, f, ensure_ascii=False, indent=1)
print(f"\nTOTAL: {len(produtos)} produtos unicos", file=sys.stderr)
