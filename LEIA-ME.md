# OVR — site

HTML, CSS e JavaScript puros. Sem build, sem instalação, sem servidor de aplicação.

## Abrir

```bash
cd ~/ovr && python3 -m http.server 8791
```

Depois abra <http://localhost:8791>. Precisa de servidor porque o catálogo é
carregado por `fetch`; abrir o arquivo direto pelo Finder não funciona.

## O único arquivo que você mexe

`assets/js/config.js`. Tudo no site lê de lá: preço, markup, frete, contato,
regras de arte e a tabela de DTG.

| O que trocar | Onde |
|---|---|
| Preço do metro de filme e frete | `dtf.precoMetro`, `dtf.fretePorCompra` |
| Markup por faixa | `markup.padrao`, `markup.porFaixa` |
| Faixas de desconto do fornecedor | `faixas[].desconto` |
| Frete grátis | `venda.freteGratis` |
| Tabela do DTG | `dtg.modelagens[].custo` |
| WhatsApp, Instagram, e-mail | `marca` |
| Trocou uma imagem e ela não atualiza | suba `versaoAssets` |

## Como o preço é calculado

```
preço = (custo da peça na faixa + custo da estampa) × markup da faixa
```

O custo da estampa não é fixo. O filme é comprado por metro e o frete é cobrado
uma vez por compra, então pedido maior compra mais metros na mesma remessa, o
frete dilui e o centímetro fica mais barato. O site calcula os metros a partir
da quantidade real do pedido.

Markup é 2,2 na faixa de 1 a 9 peças e 2,5 nas demais. A peça avulsa tem markup
menor porque o fornecedor não dá desconto na unidade e o preço precisa caber no
mercado.

## Como o cliente fecha o pedido

Não tem carrinho nem pagamento no site. Na página de produto o cliente monta a
grade de tamanhos, vê o preço cair conforme o total e clica em **Fechar pedido
no WhatsApp**. O link já vai com a mensagem escrita: peça, cor, estampa, grade,
quantidade, faixa, valor por peça e total. Ele só envia.

## Imagens das peças

Nenhuma foto de fornecedor. As peças do catálogo são um mockup branco recortado
(`assets/img/silhuetas/`) tingido em tempo real pela cor do produto, via máscara
CSS. Para acrescentar um tipo novo de peça, gere um mockup branco em fundo
transparente, salve na mesma pasta e registre em `catalogo.silhuetas`.

Tipos sem silhueta própria ficam fora da vitrine (`catalogo.tiposSemSilhueta`).
Hoje são bermuda, acessório e corta-vento.

## Regras que o site já respeita

- Peça do grupo **Outlet** do fornecedor nunca aparece. É ponta de estoque.
- Preço sempre com a estampa frente inclusa.
- Frete por conta do cliente, grátis acima de R$ 1.500 para SP, PR, RJ e SC.

## Atualizar o catálogo

```bash
cd ~/ovr/ferramentas && ./atualizar.sh
```

Raspa o fornecedor de novo e regrava `dados/catalogo.json`. Os preços do site se
recalculam sozinhos a partir dos novos `precoBase`.

## Páginas

| Arquivo | O que é |
|---|---|
| `index.html` | Home |
| `catalogo.html` | Catálogo em DTF, com filtro e ordenação |
| `produto.html` | Peça, montagem do pedido e fechamento no WhatsApp |
| `impressao-especial.html` | DTG terceirizado, até 9 peças |
| `guia-de-arte.html` | Como preparar o arquivo |
| `como-funciona.html` | Processo, prazos e DTF × DTG |
