#!/usr/bin/env python3
"""
OVR — extrai a cor real de cada peça a partir da foto do fornecedor.

A foto NÃO vai para o site. Ela serve só para descobrir o tom exato da malha,
que depois tinge a silhueta branca da OVR.

Como funciona: pega os tons dominantes da faixa do tronco, descarta fundo e
pele, e escolhe o candidato mais próximo da família da cor pelo nome. Se
nenhum candidato bater, cai na referência do nome. Assim uma foto mal
enquadrada nunca pinta a peça de cor errada.

Uso:  python3 ferramentas/3-cores.py [--fotos CAMINHO] [--seco]
"""

import argparse, collections, json, os, sys

try:
    from PIL import Image
except ImportError:
    sys.exit('Falta o Pillow: python3 -m pip install --user Pillow')

AQUI = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CATALOGO = os.path.join(AQUI, 'dados', 'catalogo.json')
FOTOS_PADRAO = os.path.expanduser('~/loja-camisetas/assets/img/produtos')

# Referência por nome de cor. É a âncora que impede a foto de errar a família.
REFERENCIA = {
    'amarelo': '#f5c518', 'areia': '#d9c4a3', 'azul': '#2b5fa8', 'azul turquesa': '#2fb6c4',
    'bordô': '#6e1220', 'branco': '#ffffff', 'caqui': '#a08b5b', 'cinza': '#9a9a9a',
    'cinza mescla': '#c9c7c4', 'grafite': '#4a4a4c', 'laranja': '#e8703a', 'lilás': '#9b7fc7',
    'marinho': '#24304a', 'marrom': '#5c3a2e', 'mescla': '#c9c7c4', 'off white': '#efeae0',
    'petróleo': '#1f4e4a', 'pink': '#e2568d', 'preto': '#1c1c1e', 'rosa': '#e2568d',
    'rosa claro': '#f3b6c8', 'roxo': '#5b2a83', 'royal': '#1257a6', 'salmão': '#f0a183',
    'turquesa': '#2fb6c4', 'verde': '#1f7a3d', 'verde claro': '#7fbf6a',
    'verde militar': '#4a5340', 'verde moraceo': '#2f6b4f', 'vermelho': '#c8202c',
}

def hex_para_rgb(h):
    h = h.lstrip('#')
    return tuple(int(h[i:i+2], 16) for i in (0, 2, 4))

def rgb_para_hex(r, g, b):
    lim = lambda v: max(0, min(255, int(v)))
    return '#%02x%02x%02x' % (lim(r), lim(g), lim(b))

def parece_pele(r, g, b):
    """Braço do modelo aparece muito na foto e engana a amostragem."""
    return 118 < r < 246 and r > g > b and 18 < (r - b) < 95 and (g - b) < 60

def candidatos(caminho, quantos=8):
    """Tons dominantes da faixa do tronco, sem fundo e sem pele."""
    im = Image.open(caminho).convert('RGB')
    W, H = im.size
    recorte = im.crop((int(W * .30), int(H * .40), int(W * .70), int(H * .74)))
    cont = collections.Counter()
    for r, g, b in recorte.getdata():
        if r > 244 and g > 244 and b > 244: continue        # fundo claro
        if r < 14 and g < 14 and b < 14: continue           # fundo escuro
        if parece_pele(r, g, b): continue
        cont[(r // 12, g // 12, b // 12)] += 1
    return [((r * 12 + 6, g * 12 + 6, b * 12 + 6), n) for (r, g, b), n in cont.most_common(quantos)]

def distancia(a, b):
    """Distância ponderada para o olho: verde pesa mais que azul."""
    return (2 * (a[0] - b[0]) ** 2 + 4 * (a[1] - b[1]) ** 2 + 3 * (a[2] - b[2]) ** 2) ** .5

def cor_da_peca(caminho, nome_cor):
    ref_hex = REFERENCIA.get(nome_cor.strip().lower())
    ref = hex_para_rgb(ref_hex) if ref_hex else None
    try:
        cands = candidatos(caminho)
    except Exception:
        return ref_hex, 'falha ao ler a foto'
    if not cands:
        return ref_hex, 'foto sem área útil'
    if ref is None:
        return rgb_para_hex(*cands[0][0]), 'sem referência de nome'

    # entre os dominantes, o mais próximo da família da cor pelo nome
    melhor, dist = min(((c, distancia(c, ref)) for c, _ in cands), key=lambda x: x[1])
    if dist > 210:
        return ref_hex, f'foto longe do nome ({int(dist)})'
    return rgb_para_hex(*melhor), f'foto (d={int(dist)})'

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--fotos', default=FOTOS_PADRAO)
    ap.add_argument('--seco', action='store_true', help='só mostra, não grava')
    args = ap.parse_args()

    dados = json.load(open(CATALOGO, encoding='utf-8'))
    produtos = dados['produtos']
    trocados = fallback = sem_foto = 0

    for p in produtos:
        foto = None
        for nome_arq in p.get('imagens') or []:
            caminho = os.path.join(args.fotos, nome_arq)
            if os.path.exists(caminho):
                foto = caminho
                break
        if not foto:
            sem_foto += 1
            novo = REFERENCIA.get(p['cor'].strip().lower())
            origem = 'sem foto, usou o nome'
        else:
            novo, origem = cor_da_peca(foto, p['cor'])
        if not novo:
            continue
        if 'nome' in origem or 'sem foto' in origem:
            fallback += 1
        if novo.lower() != p.get('hex', '').lower():
            trocados += 1
        p['hex'] = novo

    print(f'{len(produtos)} peças · {trocados} cores corrigidas · {fallback} pela referência de nome · {sem_foto} sem foto')
    if args.seco:
        for p in produtos[:20]:
            print(f"  {p['nome'][:40]:<42}{p['cor']:<14}{p['hex']}")
        return
    json.dump(dados, open(CATALOGO, 'w', encoding='utf-8'), ensure_ascii=False, indent=2)
    print(f'gravado em {CATALOGO}')

if __name__ == '__main__':
    main()
