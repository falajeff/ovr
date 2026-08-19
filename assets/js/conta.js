/* ==============================================================
   Conta do cliente — o lado do navegador

   Aqui não existe regra de negócio nenhuma. Toda decisão é do
   painel: se a senha confere, se o e-mail já existe, se o link de
   recuperação ainda vale. Esta camada leva o que a pessoa digitou e
   mostra o que o servidor respondeu.

   É a mesma linha do preço e do cupom. Regra que mora no navegador é
   regra que se edita com o inspetor aberto.
   ============================================================== */
(() => {
  'use strict';

  const API = CONFIG.conta.endpoint;

  /* --------------------------------------------------------------
     Toda leitura leva um carimbo único na URL.

     Não é paranoia: o LiteSpeed da Hostinger já foi flagrado
     devolvendo `x-litespeed-cache: hit` para /conta/eu, que responde
     uma coisa diferente para cada pessoa. O servidor agora manda
     no-store, mas isso depende de a hospedagem respeitar o cabeçalho
     — e de continuar respeitando daqui a um ano, depois de alguém
     mexer numa configuração de CDN. O carimbo não depende de
     ninguém.                                                       */
  const semCache = url => url + (url.includes('?') ? '&' : '?') + '_=' + Date.now();

  async function chamar(caminho, corpo = null, metodo = null) {
    const m = metodo || (corpo ? 'POST' : 'GET');
    const opcoes = {
      method: m,
      /* Sem isto o navegador manda a chamada e DESCARTA o cookie de
         sessão, porque loja e painel são origens diferentes. */
      credentials: 'include',
      headers: corpo ? { 'Content-Type': 'application/json' } : {},
    };
    if (corpo) opcoes.body = JSON.stringify(corpo);

    let r, d;
    try {
      r = await fetch(m === 'GET' ? semCache(API + caminho) : API + caminho, opcoes);
      d = await r.json().catch(() => ({}));
    } catch (e) {
      throw new Error('Não consegui falar com o servidor. Confira a internet e tente de novo.');
    }
    if (!r.ok) throw new Error(d?.message || 'Não deu certo agora. Tente de novo.');
    return d;
  }

  /* --------------------------------------------------------------
     Quem está logado

     Guardado em memória para a página não perguntar duas vezes. Não
     vai para localStorage: se fosse, um logout em outra aba deixaria
     esta aba achando que ainda está logada, e o servidor é a única
     fonte de verdade sobre isso.                                    */
  let atual;              // undefined = ainda não perguntei; null = deslogado
  let perguntando = null;

  async function eu(forcar = false) {
    if (!forcar && atual !== undefined) return atual;
    /* Se duas partes da página perguntarem ao mesmo tempo, faz uma
       chamada só. Sem isto o cabeçalho e o miolo perguntam junto. */
    if (!perguntando) {
      perguntando = chamar('/eu')
        .then(d => { atual = d.conta || null; return atual; })
        .catch(() => { atual = null; return null; })
        .finally(() => { perguntando = null; });
    }
    return perguntando;
  }

  const guardar = d => { atual = d?.conta || null; return atual; };

  /* --------------------------------------------------------------
     As ações                                                       */
  const Conta = {
    eu,
    atual: () => atual,

    criar: dados => chamar('/criar', dados).then(d => {
      /* O servidor responde igual quando o e-mail já existe, e nesse
         caso não vem conta nenhuma: vem `confira_email`. Quem decide
         o que a tela diz é essa diferença, não uma checagem daqui. */
      if (d.confira_email) return { confiraEmail: true };
      return { conta: guardar(d) };
    }),

    entrar: (email, senha) => chamar('/entrar', { email, senha }).then(guardar),
    sair:   () => chamar('/sair', {}).then(() => { atual = null; }),

    salvarDados:    dados => chamar('/dados', dados).then(guardar),
    salvarEndereco: e     => chamar('/endereco', e),
    pedidos:        ()    => chamar('/pedidos').then(d => d.pedidos || []),

    esqueci:    email          => chamar('/esqueci', { email }),
    novaSenha:  (token, senha) => chamar('/nova-senha', { token, senha }).then(guardar),
  };

  /* --------------------------------------------------------------
     O link no cabeçalho

     Nasce como "Entrar" no HTML e vira "Minha conta" quando há
     sessão. Começar por "Entrar" e não vazio evita o pulo de layout
     que aconteceria enquanto a resposta não chega.                  */
  async function pintarCabecalho() {
    const alvos = document.querySelectorAll('[data-conta-link]');
    if (!alvos.length) return;
    const c = await eu();
    alvos.forEach(a => {
      a.hidden = false;
      if (c) {
        a.href = 'conta.html';
        a.textContent = 'Minha conta';
        a.setAttribute('aria-label', 'Minha conta, ' + c.nome);
      } else {
        a.href = 'entrar.html';
        a.textContent = 'Entrar';
      }
    });
  }

  window.Conta = Conta;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', pintarCabecalho);
  } else {
    pintarCabecalho();
  }
})();
