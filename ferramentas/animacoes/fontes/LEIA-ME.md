# Fontes embutidas

`fontes.json` guarda quatro arquivos woff2 em base64, subconjunto latino:

- **Space Grotesk** 500 e 700, a mesma de `--fonte-titulo` no `ovr.css`
- **Inter** 400 e 600, a mesma de `--fonte-corpo`

Estão aqui embutidas para o `gerar.py` desenhar os quadros sem depender do
Google Fonts. Se a máquina estivesse offline no meio da geração, metade dos
quadros sairia com a fonte de reserva e a diferença só apareceria no GIF
pronto.

As duas são licenciadas em **SIL Open Font License 1.1**, que permite
redistribuir desde que a licença acompanhe. É o que este arquivo faz.

- Space Grotesk: <https://github.com/floriankarsten/space-grotesk>
- Inter: <https://github.com/rsms/inter>

Para regerar do zero, apague `fontes.json` e rode o trecho de download que
está no histórico deste diretório, ou baixe os woff2 do Google Fonts e
converta em base64 com as chaves `Família-peso`.
