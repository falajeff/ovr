/* Minha conta: dados, endereço salvo e histórico de pedidos.

   Tudo numa página só, em três blocos. Separar em abas ou telas custaria
   mais cliques para quem entra aqui justamente para conferir uma coisa
   e sair. */
(() => {
  'use strict';
  const { aviso, comBotao, campos, reais, esc, ligarMascaras } = ContaTela;

  const carregando = document.querySelector('[data-carregando]');
  const painel     = document.querySelector('[data-painel]');

  iniciar();

  async function iniciar() {
    const c = await Conta.eu();
    /* Sem sessão não há conta para mostrar. Leva o destino junto para
       a pessoa voltar aqui depois de entrar, em vez de cair na home. */
    if (!c) { location.replace('entrar.html?volta=conta.html'); return; }

    const novo = new URLSearchParams(location.search).has('novo');
    painel.innerHTML = desenhar(c, novo);
    carregando.hidden = true;
    painel.hidden = false;

    ligarMascaras(painel);
    ligarDados(c);
    ligarEndereco();
    ligarSair();
    carregarPedidos();
  }

  const desenhar = (c, novo) => `
    <div class="conta__topo">
      <div>
        <h1 class="t-titulo conta__titulo">Olá, ${esc(primeiroNome(c.nome))}</h1>
        <p class="t-corpo conta__apoio">${esc(c.email)}</p>
      </div>
      <button class="btn btn--linha" data-sair type="button">Sair</button>
    </div>

    ${novo ? `<p class="conta__nota">Conta criada. Seu cupom de boas-vindas chegou por e-mail, e vale na primeira compra.</p>` : ''}

    <div class="conta__grade">
      <section class="conta__bloco">
        <h2 class="conta__h2">Seus dados</h2>
        <form class="conta__form" data-form-dados novalidate>
          <label class="campo"><span>Nome ou empresa</span>
            <input name="nome" value="${esc(c.nome)}" autocomplete="name"></label>
          <label class="campo"><span>WhatsApp</span>
            <input name="zap" value="${esc(c.zap)}" inputmode="tel" autocomplete="tel"></label>
          <label class="campo"><span>CPF ou CNPJ</span>
            <input name="documento" value="${esc(formatarDoc(c.documento))}" inputmode="numeric"></label>
          <p class="conta__estado" data-estado hidden></p>
          <button class="btn btn--volt" type="submit">Salvar</button>
        </form>
      </section>

      <section class="conta__bloco">
        <h2 class="conta__h2">Endereço de entrega</h2>
        <form class="conta__form" data-form-endereco novalidate>
          <label class="campo"><span>CEP</span>
            <input name="cep" value="${esc(formatarCep(c.endereco.cep))}" inputmode="numeric" autocomplete="postal-code" placeholder="00000-000"></label>
          <label class="campo"><span>Rua</span>
            <input name="rua" value="${esc(c.endereco.rua)}" autocomplete="address-line1"></label>
          <div class="conta__dupla">
            <label class="campo"><span>Número</span>
              <input name="numero" value="${esc(c.endereco.numero)}"></label>
            <label class="campo"><span>Complemento</span>
              <input name="complemento" value="${esc(c.endereco.complemento)}"></label>
          </div>
          <label class="campo"><span>Bairro</span>
            <input name="bairro" value="${esc(c.endereco.bairro)}"></label>
          <div class="conta__dupla">
            <label class="campo"><span>Cidade</span>
              <input name="cidade" value="${esc(c.endereco.cidade)}" autocomplete="address-level2"></label>
            <label class="campo"><span>UF</span>
              <input name="uf" value="${esc(c.endereco.uf)}" maxlength="2" autocomplete="address-level1"></label>
          </div>
          <p class="conta__estado" data-estado hidden></p>
          <button class="btn btn--volt" type="submit">Salvar endereço</button>
        </form>
      </section>
    </div>

    <section class="conta__bloco conta__bloco--largo">
      <h2 class="conta__h2">Seus pedidos</h2>
      <div data-pedidos><p class="t-corpo">Buscando…</p></div>
    </section>`;

  const primeiroNome = n => String(n || '').trim().split(/\s+/)[0] || 'tudo bem';

  const formatarDoc = d => !d ? '' : (d.length === 11
    ? d.replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.$2.$3-$4')
    : d.replace(/^(.{2})(.{3})(.{3})(.{4})(.{2})$/, '$1.$2.$3/$4-$5'));

  const formatarCep = c => !c ? '' : c.replace(/^(\d{5})(\d{3})$/, '$1-$2');

  /* ---------------------------------------------------------------- */
  function ligarDados(c) {
    const form = painel.querySelector('[data-form-dados]');
    const diz  = aviso(form.parentElement);
    const botao = comBotao(form);

    form.addEventListener('submit', async ev => {
      ev.preventDefault();
      diz('');
      botao.travar();
      try {
        await Conta.salvarDados(campos(form));
        diz('Salvo.');
      } catch (e) { diz(e.message, true); }
      botao.soltar();
    });
  }

  function ligarEndereco() {
    const form = painel.querySelector('[data-form-endereco]');
    const diz  = aviso(form.parentElement);
    const botao = comBotao(form);

    /* Preenche pelo CEP. O ViaCEP é público e não pede chave; se ele
       estiver fora do ar a pessoa digita à mão, então a falha é
       silenciosa de propósito. */
    const cep = form.querySelector('[name=cep]');
    cep.addEventListener('blur', async () => {
      const n = cep.value.replace(/\D/g, '');
      if (n.length !== 8) return;
      try {
        const r = await fetch(`https://viacep.com.br/ws/${n}/json/`);
        const d = await r.json();
        if (d.erro) return;
        const por = (nome, valor) => {
          const el = form.querySelector(`[name="${nome}"]`);
          if (el && !el.value) el.value = valor || '';
        };
        por('rua', d.logradouro); por('bairro', d.bairro);
        por('cidade', d.localidade); por('uf', d.uf);
        form.querySelector('[name=numero]')?.focus();
      } catch (e) { /* digita à mão */ }
    });

    form.addEventListener('submit', async ev => {
      ev.preventDefault();
      diz('');
      botao.travar();
      try {
        await Conta.salvarEndereco(campos(form));
        diz('Endereço salvo.');
      } catch (e) { diz(e.message, true); }
      botao.soltar();
    });
  }

  function ligarSair() {
    painel.querySelector('[data-sair]')?.addEventListener('click', async () => {
      await Conta.sair().catch(() => {});
      location.replace('index.html');
    });
  }

  async function carregarPedidos() {
    const alvo = painel.querySelector('[data-pedidos]');
    let lista;
    try { lista = await Conta.pedidos(); }
    catch (e) { alvo.innerHTML = `<p class="t-corpo">${esc(e.message)}</p>`; return; }

    if (!lista.length) {
      alvo.innerHTML = `<p class="t-corpo">Nenhum pedido ainda.
        <a href="catalogo.html" style="text-decoration:underline">Ver o catálogo</a>.</p>`;
      return;
    }
    alvo.innerHTML = `<div class="conta__pedidos">${lista.map(p => `
      <div class="conta__pedido">
        <div>
          <strong>${esc(p.numero)}</strong>
          <span class="t-meta">${esc(p.data)}</span>
        </div>
        <p class="t-corpo conta__itens">${esc(p.itens) || '—'}</p>
        <div class="conta__pedido__fim">
          <span class="conta__situacao">${esc(p.situacao)}</span>
          <strong>${reais(p.total)}</strong>
        </div>
      </div>`).join('')}</div>`;
  }
})();
