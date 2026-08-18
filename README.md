# OVR

Loja de camisetas personalizadas que eu toco em Marília, São Paulo, e o sistema inteiro que a faz funcionar: a vitrine, o painel que administra os pedidos, o blog e as ferramentas que montam o catálogo.

Não é exercício. O site recebe pedido de verdade e o painel é onde eles são tocados, então cada decisão aqui foi tomada com a operação rodando.

---

## As quatro superfícies

| Onde | O que é | Feito com |
|---|---|---|
| [`/`](.) | Vitrine: catálogo, página de peça, preço por volume, carrinho | HTML, CSS e JavaScript puros. Sem build, sem dependência |
| [`wordpress/ovr-painel`](wordpress/ovr-painel) | Painel de operação: pedidos, clientes, compras, financeiro, frete | Plugin WordPress em PHP, com front-end próprio fora do wp-admin |
| [`wordpress/ovr-blog`](wordpress/ovr-blog) | Blog | Tema WordPress, sem plugin de layout |
| [`wordpress/ovr-blindagem`](wordpress/ovr-blindagem) | Fechamento das portas conhecidas do WordPress | Plugin de arquivo único |

E [`ferramentas/`](ferramentas), que é como o catálogo nasce: raspa o fornecedor, monta o JSON da loja, descobre a cor real de cada malha a partir da foto e prepara as imagens.

## Rodar

A vitrine não precisa de nada instalado:

```bash
python3 -m http.server 4173
```

Abra `http://localhost:4173`. O catálogo sai de `dados/catalogo.json`, então funciona offline.

O painel e o blog são WordPress. Copie a pasta para `wp-content/plugins` ou `wp-content/themes` e ative.

## Decisões que valem a leitura

Se for olhar só quatro arquivos, olhe estes. O que interessa neles não é o código, é o comentário do lado explicando por que é assim.

**[`api/motor-preco.php:121`](api/motor-preco.php#L121)** — o markup da faixa de 1 a 9 peças é menor que o padrão, e isso quebrou em oito peças do catálogo: nelas o fornecedor cobra igual em qualquer quantidade, e o desconto no varejo fazia o preço **subir** ao passar de 9 para 10. Comprar mais saía mais caro. O comentário guarda o caso, não só a regra.

**[`wordpress/ovr-painel/inc/gestao.php:366`](wordpress/ovr-painel/inc/gestao.php#L366)** — mês sem pedido não é prejuízo, é ausência. Pintar esse zero de vermelho ensina a ignorar o vermelho, que é a cor que precisa assustar quando a margem for negativa de verdade.

**[`wordpress/ovr-painel/inc/painel.php:41`](wordpress/ovr-painel/inc/painel.php#L41)** — receita e custo saem de um lugar só. Se duas telas somarem custos diferentes, uma delas está mentindo, e não dá para saber qual.

**[`wordpress/ovr-painel/inc/frete.php:8`](wordpress/ovr-painel/inc/frete.php#L8)** — a cotação de frete passa pelo servidor porque a chave da transportadora não pode chegar ao navegador. O cliente nunca vê o token, só o resultado.

## O catálogo não é escrito à mão

`ferramentas/atualizar.sh` roda a esteira inteira:

1. `1-raspar-fornecedor.py` lê o catálogo do fornecedor: produtos, grades e faixas de preço
2. `2-montar-catalogo.py` transforma isso no JSON da loja
3. `3-cores.py` abre a foto do fornecedor e extrai o tom exato da malha. A foto não vai para o site: o que vai é a cor, aplicada sobre uma silhueta própria
4. `4-otimizar-imagens.py` converte para WebP no tamanho certo

O item 3 é o que resolve o problema de mockup: em vez de pintar uma camiseta genérica por cima, cada peça recebe a cor medida na foto real dela.

## O que não está aqui

As fotos do fornecedor, 19 MB em `assets/img/_originais`, ficam fora: são material de terceiro e servem só de entrada para o pipeline. Sem elas o site roda igual, porque o que ele usa é a silhueta mais a cor extraída.

## Licença

Sem licença aberta. O código está publicado para leitura, como portfólio, não para reuso.
