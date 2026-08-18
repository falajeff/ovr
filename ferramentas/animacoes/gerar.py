#!/usr/bin/env python3
"""
Monta os GIFs animados do case a partir de anim.html.

Cada quadro é um carregamento separado da página com ?anim=&f=, fotografado
pelo Chrome em modo headless. Desenhar no navegador em vez de no PIL é o que
deixa a tipografia idêntica à do site: Space Grotesk e Inter, as mesmas do
`--fonte-titulo` e `--fonte-corpo` do ovr.css.

    python3 ferramentas/animacoes/gerar.py

⚠ Behance só estica a imagem até a largura da coluna se ela tiver 1400 px ou
mais. Por isso 1600×900, e não os 960×540 da primeira versão.
"""

import base64, json, pathlib, shutil, subprocess, sys, tempfile
from PIL import Image

AQUI = pathlib.Path(__file__).resolve().parent
DESTINO = pathlib.Path.home() / "Downloads/OVR/08 — Case e Behance/Animações"
CHROME = "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
LARG, ALT = 1600, 900

# Mesma cadência da primeira versão: entrada, respiro a cada elo, pausa longa
# no fim para quem lê. Os 60 ms são os quadros de transição.
ROTEIRO = {
    "a": dict(
        arquivo="OVR-anim-A-cadeia-de-tokens.gif",
        # 1 abertura + 4 elos × 4 passos + 3 da legenda = 20
        duracoes=[700] + ([60, 60, 60, 380] * 4) + [60, 60, 1900],
    ),
    "b": dict(
        arquivo="OVR-anim-B-escada-de-preco.gif",
        # 1 abertura + 4 trechos × 4 passos = 17; o último segura mais
        duracoes=[800] + ([60, 60, 60, 420] * 3) + [60, 60, 60, 2000],
    ),
    "c": dict(
        arquivo="OVR-anim-C-densidade.gif",
        # 1 vitrine + 8 da transformação + 4 das linhas + 3 da legenda = 16
        duracoes=[900] + ([60] * 7 + [420]) + ([60] * 3 + [420]) + [60, 60, 2000],
    ),
}


def html_com_fontes() -> str:
    """Injeta as fontes como data URI para o Chrome não depender de rede."""
    fontes = json.loads((AQUI / "fontes/fontes.json").read_text())
    faces = []
    for nome, b64 in fontes.items():
        familia, peso = nome.rsplit("-", 1)
        faces.append(
            f"@font-face{{font-family:'{familia}';font-weight:{peso};font-style:normal;"
            f"font-display:block;src:url(data:font/woff2;base64,{b64}) format('woff2');}}"
        )
    html = (AQUI / "anim.html").read_text()
    return html.replace("<style>", "<style>\n" + "\n".join(faces), 1)


def fotografar(pagina: pathlib.Path, anim: str, i: int, saida: pathlib.Path) -> None:
    subprocess.run(
        [CHROME, "--headless", "--disable-gpu", "--hide-scrollbars",
         "--force-device-scale-factor=1", f"--window-size={LARG},{ALT}",
         "--virtual-time-budget=1200", f"--screenshot={saida}",
         f"file://{pagina}?anim={anim}&f={i}"],
        check=True, capture_output=True,
    )


def montar(anim: str, pagina: pathlib.Path, tmp: pathlib.Path) -> pathlib.Path:
    duracoes = ROTEIRO[anim]["duracoes"]
    quadros = []
    for i, _ in enumerate(duracoes):
        png = tmp / f"{anim}-{i:03d}.png"
        fotografar(pagina, anim, i, png)
        im = Image.open(png).convert("RGB")
        if im.size != (LARG, ALT):
            im = im.crop((0, 0, LARG, ALT))
        quadros.append(im)
        print(f"  {anim.upper()} quadro {i+1:>2}/{len(duracoes)}", end="\r", flush=True)

    destino = DESTINO / ROTEIRO[anim]["arquivo"]
    destino.parent.mkdir(parents=True, exist_ok=True)
    # save_all com duration por quadro: a pausa vira duração, não repetição.
    # Repetir o mesmo quadro não funciona — o PIL descarta quadros idênticos
    # consecutivos e a pausa some.
    quadros[0].save(
        destino, save_all=True, append_images=quadros[1:],
        duration=duracoes, loop=0, optimize=True, disposal=2,
    )

    # Trava: se dois quadros seguidos saírem iguais, o PIL grava um só e as
    # durações escorregam — o respiro vai parar no meio de uma transição e
    # nada avisa. Já aconteceu, então aqui estoura.
    conferido = Image.open(destino)
    gravados = 0
    try:
        while True:
            gravados += 1
            conferido.seek(conferido.tell() + 1)
    except EOFError:
        pass
    if gravados != len(duracoes):
        raise SystemExit(
            f"{destino.name}: mandei {len(duracoes)} quadros e o arquivo tem "
            f"{gravados}. Algum par consecutivo está idêntico — o PIL colapsou."
        )

    print(f"  {anim.upper()} → {destino.name}  "
          f"{destino.stat().st_size/1024:.0f} KB, {gravados} quadros, "
          f"{sum(duracoes)/1000:.1f}s")
    return destino


def main() -> int:
    if not pathlib.Path(CHROME).exists():
        print(f"Chrome não encontrado em {CHROME}", file=sys.stderr)
        return 1

    tmp = pathlib.Path(tempfile.mkdtemp(prefix="ovr-anim-"))
    try:
        pagina = tmp / "quadro.html"
        pagina.write_text(html_com_fontes())
        for anim in ROTEIRO:
            montar(anim, pagina, tmp)
    finally:
        shutil.rmtree(tmp, ignore_errors=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
