# Publicar na Hostinger

O site fica em `ovrcamisetas.com.br`, o blog e o painel em subdomínios
próprios. Este documento cobre a vitrine.

## O que sobe

Envie para `public_html/`:

```
index.html          catalogo.html       produto.html        carrinho.html
como-funciona.html  impressao-especial.html                 filme-dtf.html
criacao-de-arte.html                    guia-de-arte.html   404.html

.htaccess           robots.txt          sitemap.xml

assets/css/         assets/js/
assets/img/marca/   assets/img/pecas/   assets/img/silhuetas/
assets/img/dtg/     assets/img/mockups/

dados/catalogo.json dados/precos.json   dados/mockups.json  dados/medidas.json

api/preco.php       api/carrinho.php    api/motor-preco.php api/.htaccess
```

## O que nunca sobe

| Pasta ou arquivo | Por quê |
|---|---|
| `ferramentas/` | esteira que monta o catálogo. Roda na sua máquina, não no servidor. |
| `assets/img/_originais/`, `assets/img/_novas/` | material de entrada dos mockups, dezenas de MB que o site não usa. |
| `README.md`, `LEIA-ME.md`, `PUBLICAR.md` | documentação. |
| `wordpress/` | o painel e o blog têm cada um o seu destino, fora daqui. |
| `.claude/`, `*.zip` | configuração local e restos de empacotamento. |

O `.htaccess` devolve 404 para `ferramentas/`, para os originais e para
qualquer `.py`, `.sh`, `.md`, `.log`, `.bak`, `.sql` ou `.zip`, caso algo
suba por engano. Mas a rede é a segunda tranca, não a primeira: o certo é
não subir.

## Os três arquivos que existem só no servidor

Estes **não estão no repositório** e precisam existir na Hostinger para o
preço funcionar. Se você trocar de servidor, copie-os à mão.

| Arquivo | O que guarda |
|---|---|
| `api/preco-config.php` | markup, custo do filme, custo de frete do fornecedor |
| `api/catalogo-custo.php` | custo de cada peça e a URL dela no fornecedor |
| `ferramentas/fornecedor.json` | endereço do fornecedor (só na sua máquina) |

Cada um tem um `.exemplo` ao lado, com números e endereços inventados, que
serve para rodar o projeto sem saber nada da margem real.

`api/catalogo-custo.php` é gerado por `ferramentas/gerar-precos.php`, junto
com `dados/precos.json`. Rode a esteira antes de publicar quando o catálogo
mudar.

## Por que existe PHP num site estático

Até julho de 2026 a vitrine era HTML, CSS e JavaScript puros, sem servidor
nenhum. O preço era calculado no navegador, e para isso o `catalogo.json`
público carregava `precoBase` e as faixas do fornecedor. Com o preço de
venda impresso na tela ao lado, a margem saía por subtração.

A conta foi para o servidor. `api/motor-preco.php` é gêmeo do antigo
`preco.js`, e o navegador passou a receber preço pronto. `dados/precos.json`
traz a escada pré-calculada na medida padrão, que é o caminho de quase todo
o tráfego e não toca a rede; `api/preco.php` só entra quando o cliente muda
o tamanho da estampa ou a quantidade, e `api/carrinho.php` fecha o pedido
inteiro, porque a faixa sai do total de peças e não dá para somar item a
item.

Ou seja: o site tem PHP hoje, e tem por um motivo. Isso muda a superfície de
ataque, então `api/.htaccess` libera apenas `preco.php` e `carrinho.php`.
Tudo o mais na pasta responde 403, e os dois arquivos de custo se recusam a
rodar fora do motor.

## Depois de subir, confira

1. **HTTPS ativo.** No hPanel, SSL grátis e "forçar HTTPS" ligado.
2. **Cabeçalhos aplicados.** Cole o domínio em <https://securityheaders.com>.
   Precisa dar A ou A+.
3. **O que não pode abrir:** `/ferramentas/` e `/api/motor-preco.php` têm que
   dar 404 e 403. `/api/preco.php?id=2&qtd=1&posicoes=28x30` tem que
   devolver JSON.
4. **O catálogo público não pode ter custo.** Abra `/dados/catalogo.json` e
   procure por `precoBase`, `faixas` e `origem`. Os três têm que estar
   ausentes.
5. **Versão dos assets.** Se mexeu em CSS ou JavaScript, o `?v=` nos HTML e o
   `versaoAssets` no `config.js` precisam ter subido. Sem isso o navegador
   serve o arquivo velho por até sete dias.
6. **Prévia do link.** Cole o endereço no WhatsApp e veja se aparece o card.

## As duas coisas que só você pode fazer

Nenhuma vulnerabilidade de código derruba um site pequeno. O que derruba é
conta.

1. **Verificação em duas etapas na Hostinger.**
2. **Verificação em duas etapas no registro.br**, onde ficam os domínios.

Isso vale mais para a sua segurança do que tudo que está no `.htaccess`.

## Blog e painel

Os dois moram em subdomínio separado, `blog.ovrcamisetas.com.br` e
`painel.ovrcamisetas.com.br`, e nunca dentro do `public_html` da loja. O
motivo é isolamento: WordPress tem banco e área de login, a vitrine não. Em
subdomínio, um problema no blog não alcança as páginas de venda.

Regras que valem para os dois:

- Usuário administrador **não** pode se chamar `admin`.
- Dois fatores no login.
- Atualização automática de núcleo, tema e plugin ligada.
- Menos plugin possível. Cada plugin é uma porta.
- Limite de tentativas de login ligado.

O plugin `ovr-blindagem` fecha as portas conhecidas do WordPress e fica em
`wordpress/ovr-blindagem`.
