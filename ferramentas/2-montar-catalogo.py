#!/usr/bin/env python3
"""Transforma o catalogo raspado no JSON final da loja: caminhos locais, tipo, cor, publico."""
import json, os, re, unicodedata

AQUI = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(AQUI, "bruto-fornecedor.json")
_forn = json.load(open(os.path.join(AQUI, "fornecedor.json"), encoding="utf-8"))
BASE = _forn["base"]
BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

def sa(s):  # sem acento, maiusculo
    return "".join(c for c in unicodedata.normalize("NFD", s) if unicodedata.category(c) != "Mn").upper()

# ---- tipo de peca. Primeira regra que casar vence. ----
TIPOS = [
    (r"BERMUDA", "Bermuda"),
    (r"CORTA[- ]?VENTO", "Corta-vento"),
    (r"MOLETOM|CASACO", "Moletom"),
    (r"MEIA|EMBALAGEM|ZIP ?LOCK", "Acessório"),
    (r"CROPPED", "Cropped"),
    (r"REGATA", "Regata"),
    (r"POLO", "Gola Polo"),
    (r"OVERSIZED", "Oversized"),
    (r"STREET ?WEAR", "Streetwear"),
    (r"\bUV\b|PROTECAO", "Proteção UV"),
    (r"PIMA", "Algodão Pima"),
    (r"MODAL", "Modal"),
    (r"CANELAD", "Canelada"),
    (r"ELASTANO|MALHA FRIA", "Com Elastano"),
    (r"", "Básica"),
]

# ---- cor. Ordem importa: o mais especifico vem antes. ----
CORES = [
    ("OFF WHITE|OFF WHTE", "Off White", "#f2efe6"),
    ("AZUL MARINHO|MARINHO|NAVY", "Marinho", "#1c2b45"),
    ("AZUL PETROLEO|PETROLEO", "Petróleo", "#1f4e5a"),
    ("AZUL TURQUESA|TURQUESA", "Turquesa", "#1fb6c1"),
    ("AZUL CLARO", "Azul Claro", "#7fb3e8"),
    ("ROYAL", "Royal", "#0062b8"),
    ("AZUL|BLUE", "Azul", "#2b6cb0"),
    ("VERDE MILITAR|MILITAR", "Verde Militar", "#4a5232"),
    ("VERDE|GREEN", "Verde", "#0f7a3d"),
    ("CINZA[- ]?MESCLA|MESCLA", "Mescla", "#9a9a9a"),
    ("GRAFITE|CHUMBO", "Grafite", "#40434a"),
    ("CINZA|GREY|GRAY", "Cinza", "#8d8d8d"),
    ("PRETO|PRETA|BLACK", "Preto", "#111111"),
    ("BRANCA|BRANCO|WHITE", "Branco", "#ffffff"),
    ("VERMELH|RED", "Vermelho", "#c8102e"),
    ("BORDO|VINHO", "Bordô", "#6b1b2b"),
    ("SALMAO", "Salmão", "#f4a08a"),
    ("ROSA|PINK", "Rosa", "#e8548c"),
    ("LILAS|LILAZ", "Lilás", "#b18adf"),
    ("ROXO|PURPLE", "Roxo", "#6b2fa0"),
    ("AMARELO|CANARIO", "Amarelo", "#ffd52f"),
    ("LARANJA|ORANGE", "Laranja", "#f2600c"),
    ("MARROM|CAFE|BROWN", "Marrom", "#5b3a26"),
    ("CAQUI|KHAKI", "Caqui", "#8a7a52"),
    ("AREIA|BEGE|NUDE", "Areia", "#d9c7a7"),
    ("STONE", "Stone", "#7d7466"),
]

PUBLICO = {"MASCULINO": "Masculino", "FEMININO": "Feminino", "UNISSEX": "Unissex",
           "INFANTIL": "Infantil", "PLUS-SIZE": "Plus Size", "OUTLET": "Outlet",
           "ACESSORIOS--OUTROS": "Acessórios"}

# ---- acertos de escrita: o fornecedor cadastra tudo em caixa alta e sem acento ----
ACENTOS = {
    "Basica": "Básica", "Basicas": "Básicas", "Algodao": "Algodão", "Malhao": "Malhão",
    "Selecao": "Seleção", "Poliester": "Poliéster", "Canario": "Canário", "Salmao": "Salmão",
    "Petroleo": "Petróleo", "Lilas": "Lilás", "Bordo": "Bordô", "Ceu": "Céu",
    "Whte": "White", "Fem.": "Feminina", "Coz": "cordão", "Moraceo": "Musgo",
    "Marmorizado": "Marmorizado", "Ziplock": "Zip Lock",
}
PEQUENAS = {"de", "com", "e", "da", "do", "em", "c/", "a"}

def titulo(s):
    saida = []
    for i, w in enumerate(s.split()):
        b = w.lower()
        if b in PEQUENAS and i > 0:
            saida.append(b)
        elif re.match(r"^\d", w) or w.upper() in ("UV", "PP", "GG", "DTF", "DTG"):
            saida.append(w.upper() if w.isalpha() else w)
        else:
            saida.append(w.capitalize())
    texto = " ".join(saida)
    texto = re.sub(r"\bFem\.?(?=\s)", "Feminina", texto, flags=re.I)
    for errado, certo in ACENTOS.items():
        if errado.endswith("."):
            continue
        texto = re.sub(rf"\b{re.escape(errado)}\b", certo, texto, flags=re.I)
    return re.sub(r"\s+", " ", texto).strip()

def hex_para_rgb(h):
    h = h.lstrip("#")
    if len(h) == 3:
        h = "".join(c * 2 for c in h)
    return tuple(int(h[i:i + 2], 16) for i in (0, 2, 4)) if len(h) == 6 else (200, 200, 200)

def cor_mais_proxima(h):
    """Quando o nome nao diz a cor, deduz pela amostra que o fornecedor publica."""
    r, g, b = hex_para_rgb(h)
    melhor, dist = "Outra", 1e9
    for _, nome, ref in CORES:
        rr, gg, bb = hex_para_rgb(ref)
        d = (r - rr) ** 2 + (g - gg) ** 2 + (b - bb) ** 2
        if d < dist:
            dist, melhor = d, nome
    return melhor

def tem_banner(arquivo):
    """O fornecedor queima arte de marketing em algumas fotos (tarja preta com texto).
       Detecta pelo canto superior esquerdo escuro para nao usar como foto principal."""
    caminho = f"{BASE}/assets/img/produtos/{arquivo}"
    if not os.path.exists(caminho):
        return False
    try:
        from PIL import Image
        import numpy as np
        a = np.asarray(Image.open(caminho).convert("L").resize((100, 100)), dtype=float)
        return bool(a[0:34, 0:34].mean() < 95)
    except ImportError:
        return False

src = json.load(open(SRC, encoding="utf-8"))
cats = {c["id"]: c for c in src["categorias"]}
banners = []

produtos = []
for p in src["produtos"]:
    nome = titulo(p["nome"])
    up = sa(nome)
    tipo = next(r for pat, r in TIPOS if re.search(pat, up))

    # "C/ cordao Branco" descreve o cadarco, nao a peca — sai antes de achar a cor
    up_cor = re.split(r"\bC/\s*CORDAO\b|\bC/\s*COZ\b", up)[0]
    cor = next((nome_cor for pat, nome_cor, _ in CORES if re.search(pat, up_cor)), None)

    hexes = [g["cor"] for g in p["grade"] if g["cor"]]
    amostra = hexes[0] if hexes else "#cccccc"
    if not cor:
        cor = cor_mais_proxima(amostra)

    publicos = []
    for cid in p["categorias"]:
        g = PUBLICO.get(cats[cid]["grupo"].replace(" ", "-").upper()) or titulo(cats[cid]["grupo"])
        if g not in publicos:
            publicos.append(g)
    publicos = [g for g in publicos if g != "Outlet"] or publicos
    # o nome as vezes carrega o publico que a categoria nao tem
    for marca, rotulo in (("INFANTIL", "Infantil"), ("PLUS SIZE", "Plus Size"), ("FEMININA|FEM\\.", "Feminino")):
        if re.search(marca, up) and rotulo not in publicos:
            publicos.append(rotulo)

    grade, vistos = [], set()
    for g in p["grade"]:
        if g["tam"] not in vistos:
            vistos.add(g["tam"])
            grade.append({"tam": g["tam"], "estoque": g["estoque"]})

    # foto limpa na frente; se todas tiverem tarja, anota para revisao manual
    imgs = [os.path.basename(u) for u in p["imagens"]]
    limpas = [f for f in imgs if not tem_banner(f)]
    if not limpas:
        banners.append(nome)
    else:
        imgs = limpas + [f for f in imgs if f not in limpas]

    produtos.append({
        "id": int(p["id"]), "nome": nome, "tipo": tipo, "cor": cor, "hex": amostra,
        "grupos": publicos, "precoBase": p["precoBase"], "faixas": p["faixas"],
        "grade": grade, "estoque": sum(g["estoque"] for g in grade),
        "imagens": imgs,
        "origem": BASE + p["href"],
    })

ORDEM = ["Básica", "Oversized", "Streetwear", "Com Elastano", "Gola Polo", "Algodão Pima",
         "Modal", "Proteção UV", "Canelada", "Regata", "Cropped", "Moletom", "Corta-vento",
         "Bermuda", "Acessório"]
produtos.sort(key=lambda x: (ORDEM.index(x["tipo"]) if x["tipo"] in ORDEM else 99, x["nome"]))

dados = {"produtos": produtos}
os.makedirs(f"{BASE}/dados", exist_ok=True)
json.dump(dados, open(f"{BASE}/dados/catalogo.json", "w", encoding="utf-8"), ensure_ascii=False, indent=1)
with open(f"{BASE}/dados/catalogo.js", "w", encoding="utf-8") as f:
    f.write("/* Gerado a partir do catálogo do fornecedor.\n"
            "   Para atualizar: python3 ferramentas/atualizar-catalogo.py */\nconst CATALOGO = ")
    json.dump(dados, f, ensure_ascii=False, separators=(",", ":"))
    f.write(";\n")

from collections import Counter
print(f"{len(produtos)} produtos")
print("tipos: ", dict(Counter(p["tipo"] for p in produtos)))
print("cores: ", dict(Counter(p["cor"] for p in produtos)))
print("público:", dict(Counter(g for p in produtos for g in p["grupos"])))

if banners:
    print(f"\n⚠ {len(banners)} peças só têm foto com tarja de marketing do fornecedor "
          f"(vale refotografar ou recortar):")
    for n in banners:
        print(f"   · {n}")
