# Tema OVR Blog

## Instalar

1. No WordPress: **Aparência › Temas › Adicionar novo › Enviar tema**
2. Envie o arquivo `ovr-blog.zip`
3. Clique em **Ativar**

Pronto. Não precisa de plugin nenhum para o tema funcionar.

## Configurar depois de ativar

**Ajustes › Links permanentes** → escolha **Nome do post**. Sem isso as URLs
ficam `?p=123` em vez de `/como-escolher-a-malha`.

**Ajustes › Leitura** → desmarque "Sugerir aos mecanismos de busca que não
indexem". Vem marcado em site novo e derruba seu Google.

## Se o domínio mudar

Abra `functions.php` e troque a primeira linha de configuração:

```php
define('OVR_SITE', 'https://ovrcamisetas.com.br');
```

WhatsApp, Instagram e e-mail estão logo abaixo, no mesmo lugar.

## O que o tema já faz por você

**Comentário desligado.** É a maior porta de spam do WordPress e o blog não
precisa dele — a conversa acontece no WhatsApp. Para religar, apague o bloco 4
do `functions.php`.

**Endurecimento no bloco 5:** esconde a versão do WordPress, desliga o XML-RPC
(usado em ataque de força bruta em massa), fecha a listagem de usuários pela API
pública, bloqueia `?author=1`, deixa a mensagem de erro de login genérica e
proíbe editar arquivo pelo painel.

**Paleta travada na marca.** No editor só aparecem as seis cores da OVR. Post
nunca sai com uma cor que não é sua.

**Sem plugin de layout.** Cada plugin é uma porta. O tema resolve com PHP e CSS.

## Escrevendo um post

O editor mostra o texto já com a tipografia do site. A medida de leitura é curta
de propósito, 68 caracteres por linha.

Use **imagem destacada** em todo post: ela vira a capa, o card na listagem e a
prévia quando o link é compartilhado. Tamanho ideal 1200 × 675.

Bloco de citação vira o destaque grande com a barra volt na lateral. Use uma vez
por post, no máximo.
