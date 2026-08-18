# Publicar na Hostinger

## O que sobe e o que não sobe

Envie para `public_html/` **apenas isto**:

```
404.html            .htaccess           robots.txt          sitemap.xml
index.html          catalogo.html       produto.html
impressao-especial.html   guia-de-arte.html   como-funciona.html
assets/css/         assets/js/          assets/img/marca/
assets/img/silhuetas/     assets/img/dtg/     assets/img/pecas/
dados/catalogo.json
```

**Não suba:**

| Pasta | Por quê |
|---|---|
| `ferramentas/` | são os raspadores. Ninguém precisa ver como você monta o preço. |
| `assets/img/_originais/` | 19 MB de PNG que só servem para refazer os mockups. |
| `LEIA-ME.md`, `PUBLICAR.md` | documentação interna. |
| `.claude/` | configuração local. |

O `.htaccess` já bloqueia essas pastas caso subam por engano, mas o certo é não subir.

Comando para gerar a pasta pronta para envio:

```bash
cd ~/ovr && rm -rf ../ovr-publicar && mkdir -p ../ovr-publicar && \
cp -r *.html .htaccess robots.txt sitemap.xml assets dados ../ovr-publicar/ && \
rm -rf ../ovr-publicar/assets/img/_originais && \
echo "pronto em ~/ovr-publicar"
```

## Depois de subir, confira

1. **HTTPS ativo.** No hPanel, SSL grátis e "forçar HTTPS" ligado.
2. **Cabeçalhos aplicados.** Abra <https://securityheaders.com> e cole o domínio. Precisa dar A ou A+.
3. **O que não pode abrir:** `seudominio.com.br/ferramentas/` tem que dar 404.
4. **Prévia do link.** Cole o endereço no WhatsApp e veja se aparece o card preto.

## As duas coisas que só você pode fazer

Nenhuma vulnerabilidade de código derruba um site estático. O que derruba é conta.

1. **Verificação em duas etapas na Hostinger.**
2. **Verificação em duas etapas no registro.br**, onde ficam os domínios.

Isso vale mais para a sua segurança do que tudo que está no `.htaccess`.

## Se for instalar WordPress para o blog

Instale em **subdomínio separado** (`blog.ovrcamisetas.com.br`), nunca dentro do
`public_html` do site principal. Motivo: o site da loja não tem banco nem PHP, então
não tem como ser invadido. O WordPress tem. Em subdomínio, um problema no blog não
alcança as páginas de venda nem o seu catálogo.

Regras mínimas para o WordPress:
- Usuário administrador **não** pode se chamar `admin`.
- Dois fatores no login (plugin WP 2FA ou similar).
- Atualização automática de núcleo, tema e plugin ligada.
- Menos plugin possível. Cada plugin é uma porta.
- Limite de tentativas de login ligado.
