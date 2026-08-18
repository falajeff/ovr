#!/usr/bin/env python3
"""
OVR — prepara as imagens para a web.

Converte PNG para WebP com transparência, redimensiona para o maior tamanho
que a tela realmente usa e apaga o que ficou órfão. O PNG original fica em
assets/img/_originais/ para você poder refazer com outra qualidade depois.

Uso:  python3 ferramentas/4-otimizar-imagens.py [--qualidade 82] [--seco]
"""

import argparse, os, re, shutil, sys

try:
    from PIL import Image
except ImportError:
    sys.exit('Falta o Pillow: python3 -m pip install --user Pillow')

AQUI = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ORIGINAIS = os.path.join(AQUI, 'assets', 'img', '_originais')

# maior lado que cada pasta precisa, contando tela retina
LARGURA = {
    'silhuetas': 900,   # card 316 em 2x, e a foto grande do produto em ~1,2x
    'dtg':       700,   # só aparece em card
    'pecas':     1100,  # hero e categorias, que ocupam mais espaço
}

def usados():
    """Tudo que aparece no HTML, no CSS ou no JS."""
    encontrados = set()
    for raiz, _, arquivos in os.walk(AQUI):
        if '_originais' in raiz or '/.git' in raiz:
            continue
        for a in arquivos:
            if not a.endswith(('.html', '.css', '.js', '.json')):
                continue
            texto = open(os.path.join(raiz, a), encoding='utf-8', errors='ignore').read()
            for m in re.findall(r'[\w./-]+\.(?:png|jpe?g|webp)', texto):
                encontrados.add(os.path.basename(m))
    return encontrados

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--qualidade', type=int, default=82)
    ap.add_argument('--seco', action='store_true')
    args = ap.parse_args()

    referenciados = usados()
    os.makedirs(ORIGINAIS, exist_ok=True)
    antes = depois = 0
    convertidos, orfaos = [], []

    for pasta, larg in LARGURA.items():
        dir_ = os.path.join(AQUI, 'assets', 'img', pasta)
        if not os.path.isdir(dir_):
            continue
        for nome in sorted(os.listdir(dir_)):
            if not nome.lower().endswith('.png'):
                continue
            caminho = os.path.join(dir_, nome)
            base = os.path.splitext(nome)[0]
            # o HTML pode citar o arquivo como .png ou como .webp
            if nome not in referenciados and f'{base}.webp' not in referenciados:
                orfaos.append(os.path.join(pasta, nome))
                continue

            tam = os.path.getsize(caminho)
            antes += tam
            destino = os.path.join(dir_, base + '.webp')
            if args.seco:
                print(f'  converteria {pasta}/{nome} ({tam//1024} KB)')
                continue

            im = Image.open(caminho).convert('RGBA')
            if max(im.size) > larg:
                escala = larg / max(im.size)
                im = im.resize((round(im.width * escala), round(im.height * escala)), Image.LANCZOS)
            im.save(destino, 'WEBP', quality=args.qualidade, method=6)
            novo = os.path.getsize(destino)
            depois += novo
            shutil.move(caminho, os.path.join(ORIGINAIS, nome))
            convertidos.append((f'{pasta}/{base}', tam, novo))

    if args.seco:
        print(f'\n{len(orfaos)} órfãos seriam apagados:')
        for o in orfaos: print('  ', o)
        return

    for o in orfaos:
        caminho = os.path.join(AQUI, 'assets', 'img', o)
        antes += os.path.getsize(caminho)
        shutil.move(caminho, os.path.join(ORIGINAIS, os.path.basename(o)))

    print(f'{"arquivo":<38}{"antes":>10}{"depois":>10}{"corte":>8}')
    for nome, a, d in convertidos:
        print(f'{nome:<38}{a//1024:>8} KB{d//1024:>8} KB{100-d*100//a:>7}%')
    print(f'\n{len(orfaos)} órfãos movidos para _originais/ (não eram usados em lugar nenhum)')
    print(f'total servido: {antes//1024} KB → {depois//1024} KB  ({100 - depois*100//antes}% menor)')
    print('\nOs PNG originais estão em assets/img/_originais/ caso queira refazer.')

if __name__ == '__main__':
    main()
