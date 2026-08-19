/* =============================================================
   OVR — carrinho

   Existe por um motivo de negócio, não de conveniência: o atacado de
   DTF soma as peças do PEDIDO, não do produto. Três regulares e sete
   oversized fecham a faixa de dez. Isso só é possível quando alguém
   guarda os itens até o cliente fechar — e esse alguém é este arquivo.

   O que fica guardado é o mínimo: id do produto, quantidade e medida
   da estampa. Preço não se guarda; ele é recalculado toda vez a partir
   do catálogo, senão um carrinho de ontem venderia pelo preço de ontem.
   ============================================================= */

const Carrinho = (() => {

  const C = CONFIG.carrinho;

  /* ------------------------ armazenamento ------------------------ */
  /* localStorage pode estar bloqueado (aba anônima, navegador travado).
     Se estiver, o carrinho vira só memória: funciona na sessão e some
     ao fechar, em vez de a página inteira quebrar.                   */
  let memoria = null;

  function ler() {
    if (memoria) return memoria;
    let cru = null;
    try { cru = localStorage.getItem(C.chave); } catch (e) { /* bloqueado */ }
    if (!cru) return (memoria = []);
    try {
      const d = JSON.parse(cru);
      const itens = Array.isArray(d?.itens) ? d.itens : [];
      const idade = (Date.now() - (d?.em || 0)) / 36e5;
      if (idade > C.validadeHoras) { apagar(); return (memoria = []); }
      return (memoria = itens);
    } catch (e) {
      apagar();
      return (memoria = []);
    }
  }

  function gravar(itens) {
    memoria = itens;
    try {
      localStorage.setItem(C.chave, JSON.stringify({ em: Date.now(), itens }));
    } catch (e) { /* segue só em memória */ }
    avisar();
  }

  function apagar() {
    memoria = [];
    try { localStorage.removeItem(C.chave); } catch (e) {}
    avisar();
  }

  /* Quem quiser saber que o carrinho mudou escuta este evento —
     é como o contador na barra se atualiza sem recarregar a página. */
  function avisar() {
    document.dispatchEvent(new CustomEvent('carrinho:mudou', { detail: { qtd: contar() } }));
  }

  const contar = () => ler().reduce((s, i) => s + (+i.qtd || 0), 0);

  /* Dois itens são o mesmo se forem o mesmo produto com a mesma
     estampa. Aí somam em vez de virar duas linhas iguais.           */
  const assinatura = i => [i.tipo, i.id ?? i.nome, JSON.stringify(i.posicoes || null)].join('|');

  function adicionar(item) {
    const itens = ler().slice();
    if (itens.length >= C.maxItens) return { ok: false, erro: 'O carrinho está cheio. Feche este pedido antes de somar mais.' };

    const chave = assinatura(item);
    const igual = itens.find(i => assinatura(i) === chave);
    if (igual) {
      igual.qtd = (+igual.qtd || 0) + (+item.qtd || 0);
      if (item.grade) {
        igual.grade = igual.grade || {};
        for (const [t, n] of Object.entries(item.grade)) igual.grade[t] = (igual.grade[t] || 0) + n;
      }
    } else {
      itens.push({ ...item, em: Date.now() });
    }
    gravar(itens);
    return { ok: true, total: contar() };
  }

  function remover(indice) {
    const itens = ler().slice();
    itens.splice(indice, 1);
    gravar(itens);
  }

  function mudarQtd(indice, qtd) {
    const itens = ler().slice();
    if (!itens[indice]) return;
    const n = Math.max(0, Math.round(+qtd || 0));
    if (!n) return remover(indice);
    /* Mexer na quantidade total desmancha a grade por tamanho: não dá
       para adivinhar de qual tamanho tirar. Some a grade e assume que
       ele acerta no WhatsApp.                                        */
    if (itens[indice].grade && n !== itens[indice].qtd) delete itens[indice].grade;
    itens[indice].qtd = n;
    gravar(itens);
  }

  /* ------------------------ preço do pedido ---------------------- */
  /* Precisa do catálogo para saber o custo de cada peça hoje. */
  let catalogo = null;
  async function comCatalogo() {
    if (catalogo) return catalogo;
    try {
      const r = await fetch('dados/catalogo.json');
      const d = await r.json();
      catalogo = Array.isArray(d) ? d : (d.produtos || d.itens || []);
    } catch (e) { catalogo = []; }
    return catalogo;
  }

  /* O cupom é do momento da compra, como o frete: fica fora do
     carrinho guardado. Se a pessoa voltar amanhã, digita de novo — e
     o servidor confere de novo, que é o que importa. */
  let cupomAtivo = null;
  const usarCupom = c => { cupomAtivo = c; };

  async function calcular() {
    const cat = await comCatalogo();
    const itens = ler().map(i => {
      if (i.tipo !== 'dtf') return i;
      const produto = cat.find(p => p.id === i.id);
      /* Peça que saiu do catálogo não pode ser vendida por um preço
         inventado: marca como indisponível e o carrinho avisa.       */
      return produto ? { ...i, produto } : { ...i, indisponivel: true };
    });
    const vendaveis = itens.filter(i => !i.indisponivel);
    const conta = await Preco.consultarCarrinho(vendaveis, cupomAtivo);
    return { ...conta, todos: itens, sumidos: itens.filter(i => i.indisponivel) };
  }

  return { ler, adicionar, remover, mudarQtd, apagar, contar, calcular, avisar, usarCupom };
})();


/* ==============================================================
   Contador e prévia na barra
   ============================================================== */
(() => {
  function pintar() {
    const n = Carrinho.contar();
    document.querySelectorAll('[data-carrinho-n]').forEach(e => {
      e.textContent = n;
      e.hidden = !n;
    });
  }
  document.addEventListener('carrinho:mudou', pintar);
  document.addEventListener('DOMContentLoaded', pintar);

  /* ---------------- prévia do carrinho -------------------------
     Serve para o cliente conferir e corrigir sem sair da página em
     que está comprando. Abre no clique, não no hover: no celular
     hover não existe, e no computador um painel que aparece de
     passagem atrapalha quem só ia para outro link.               */
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c =>
    ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));

  let painel = null, aberto = false;

  function fechar() {
    if (!painel) return;
    painel.hidden = true; aberto = false;
    document.querySelectorAll('.nav__cesta').forEach(c => c.setAttribute('aria-expanded', 'false'));
  }

  async function desenhar() {
    const c = await Carrinho.calcular();
    if (!c.todos.length) {
      painel.innerHTML = `<div class="previa__vazio">
        <p>Seu carrinho está vazio.</p>
        <a class="btn btn--volt btn--cheio" href="catalogo.html">Ver o catálogo</a></div>`;
      return;
    }
    const porChave = new Map(c.linhas.map(l => [l.em, l]));
    const itens = c.todos.map(i => porChave.get(i.em) || i);

    painel.innerHTML = `
      <div class="previa__topo">
        <strong>Seu pedido</strong>
        <button data-previa-fechar aria-label="Fechar">Fechar</button>
      </div>
      <div class="previa__itens">
        ${itens.map((l, i) => `
          <div class="previa__item">
            <div>
              <strong>${esc(l.nome)}</strong>
              <span class="t-meta">${l.qtd} ${l.qtd === 1 ? 'peça' : 'peças'}${l.estampa ? ' · ' + esc(l.estampa) : ''}</span>
              ${l.indisponivel ? '<span class="cart__alerta">Saiu do catálogo</span>' : ''}
            </div>
            <div class="previa__valor">
              ${l.indisponivel ? '–' : `<strong>${reais(l.subtotal)}</strong>`}
              <button data-previa-tirar="${i}" aria-label="Remover ${esc(l.nome)}">Remover</button>
            </div>
          </div>`).join('')}
      </div>
      <div class="previa__fim">
        ${c.faixa ? `<div class="resumo__linha"><span>Faixa</span><strong>${esc(c.faixa.rotulo)}</strong></div>` : ''}
        <div class="resumo__linha resumo__total"><span>Total</span><strong>${reais(c.total)}</strong></div>
        ${!c.minimoAtingido ? `<p class="cart__alerta">${c.faltamPecas === 1 ? 'Falta 1 peça' : `Faltam ${c.faltamPecas} peças`} para o mínimo de ${CONFIG.venda.pedidoMinimo}.</p>` : ''}
        <a class="btn btn--volt btn--cheio" href="carrinho.html">Fechar pedido</a>
      </div>`;

    painel.querySelector('[data-previa-fechar]')?.addEventListener('click', fechar);
    painel.querySelectorAll('[data-previa-tirar]').forEach(b =>
      b.addEventListener('click', () => { Carrinho.remover(+b.dataset.previaTirar); desenhar(); }));
  }

  function montar() {
    const cesta = document.querySelector('.nav__cesta');
    /* na própria página do carrinho a prévia seria redundante */
    if (!cesta || document.querySelector('[data-pagina-carrinho]')) return;

    painel = document.createElement('div');
    painel.className = 'previa';
    painel.hidden = true;
    painel.setAttribute('role', 'dialog');
    painel.setAttribute('aria-label', 'Prévia do pedido');
    cesta.parentElement.appendChild(painel);

    cesta.setAttribute('aria-expanded', 'false');
    cesta.setAttribute('aria-haspopup', 'dialog');
    cesta.addEventListener('click', async ev => {
      ev.preventDefault();
      if (aberto) return fechar();
      await desenhar();
      painel.hidden = false; aberto = true;
      cesta.setAttribute('aria-expanded', 'true');
    });

    document.addEventListener('click', ev => {
      if (aberto && !painel.contains(ev.target) && !ev.target.closest('.nav__cesta')) fechar();
    });
    document.addEventListener('keydown', ev => { if (ev.key === 'Escape') fechar(); });
    document.addEventListener('carrinho:mudou', () => { if (aberto) desenhar(); });
  }
  document.addEventListener('DOMContentLoaded', montar);
})();


/* ==============================================================
   A página do carrinho
   ============================================================== */
(() => {
  const raiz = document.querySelector('[data-pagina-carrinho]');
  if (!raiz) return;

  const esc = s => String(s ?? '').replace(/[&<>"']/g, c =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  /* Carimbo de quando a página abriu. O painel recusa pedido enviado
     rápido demais — é o jeito barato de barrar robô sem CAPTCHA.     */
  const abriuEm = Math.floor(Date.now() / 1000);

  /* ---------------------- o que a pessoa já digitou ----------------
     desenhar() reescreve o innerHTML inteiro, e com ele o formulário.
     Sem isto, mudar a quantidade de um item apagava o nome e o e-mail
     já preenchidos — e agora apagaria o CPF junto, bem no meio do
     caminho de quem está tentando fechar.

     O arquivo de arte é a exceção: por segurança o navegador não deixa
     repor o valor de um <input type=file>, então quem escolher a arte e
     depois mexer na quantidade precisa escolher de novo. Já era assim. */
  let formGuardado = {};

  function guardarForm() {
    const f = raiz.querySelector('[data-form]');
    if (!f) return;
    f.querySelectorAll('input[name], textarea[name]').forEach(el => {
      if (el.type === 'file') return;
      formGuardado[el.name] = el.value;
    });
  }

  function restaurarForm() {
    const f = raiz.querySelector('[data-form]');
    if (!f) return;
    Object.entries(formGuardado).forEach(([nome, valor]) => {
      if (!valor) return;
      const el = f.querySelector(`[name="${nome}"]`);
      if (el && el.type !== 'file' && !el.value) el.value = valor;
    });
  }

  /* ---------------------- conta, quando existe ---------------------
     Preencher o formulário com o que já está salvo é o que faz a conta
     valer a pena. Sem isto, criar conta seria só mais um cadastro.

     Só preenche campo VAZIO: se a pessoa digitou outro nome para este
     pedido, o que ela escreveu manda. E nada disso é obrigatório — o
     pedido continua funcionando para quem nunca criou conta.          */
  async function preencherPelaConta() {
    if (typeof Conta === 'undefined') return;
    const c = await Conta.eu().catch(() => null);
    if (!c) return;

    const f = raiz.querySelector('[data-form]');
    if (!f) return;

    const doc = c.documento || '';
    const e = c.endereco || {};
    const por = (nome, valor) => {
      const el = f.querySelector(`[name="${nome}"]`);
      if (el && !el.value && valor) el.value = valor;
    };

    por('nome', c.nome);
    por('email', c.email);
    por('zap', c.zap);
    por('documento', doc ? mascaraDoc(doc) : '');
    /* Cidade e UF juntas, que é como o campo do pedido pede. */
    por('cidade', e.cidade ? (e.uf ? `${e.cidade} / ${e.uf}` : e.cidade) : '');

    /* O CEP salvo já cota o frete sozinho. É o passo que mais some do
       carrinho de quem já comprou uma vez. */
    const cep = raiz.querySelector('[data-cep]');
    if (cep && !cep.value && e.cep) cep.value = e.cep.replace(/^(\d{5})(\d{3})$/, '$1-$2');

    /* Guarda também, para o próximo redesenho não perder o que veio da
       conta — o restaurarForm() só conhece o que foi digitado. */
    guardarForm();
  }

  async function desenhar() {
    guardarForm();
    const c = await Carrinho.calcular();

    if (!c.todos.length) {
      raiz.innerHTML = `
        <div class="envelope" style="padding-block:80px 100px;text-align:center">
          <h1 class="t-titulo" style="font-size:clamp(28px,4vw,44px)">Seu carrinho está vazio</h1>
          <p class="t-corpo" style="margin:14px auto 28px;max-width:44ch">
            Monte a grade na página da peça e volte aqui. O desconto por quantidade
            soma o pedido inteiro, então pode misturar modelos e cores.</p>
          <a class="btn btn--volt" href="catalogo.html">Ver o catálogo</a>
        </div>`;
      return;
    }

    /* ---------------------- cupom ---------------------------------
       O documento NÃO fica aqui: ele é campo obrigatório do formulário,
       ao lado do e-mail. Duplicar o campo faria a pessoa digitar o
       mesmo CPF duas vezes na mesma tela.

       Nada de percentual ou teto neste arquivo: a tela só mostra o que
       o servidor respondeu. */
    const blocoCupom = c => {
      const r = c.cupom || {};
      const aberto = !!cupomAberto || !!r.codigo || !!r.erro;
      return `
        <div class="cupom" data-cupom>
          ${aberto ? '' : `<button type="button" class="cupom__abrir" data-cupom-abrir>Tenho um cupom</button>`}
          <div class="cupom__campos"${aberto ? '' : ' hidden'}>
            <label class="campo"><span>Cupom</span>
              <span class="cupom__linha">
                <input type="text" data-cupom-codigo placeholder="Código" autocomplete="off"
                       spellcheck="false" value="${esc(cupomDigitado.codigo)}">
                <button type="button" class="btn btn--linha" data-cupom-aplicar>Aplicar</button>
              </span></label>
            ${r.erro ? `<p class="cupom__aviso cupom__aviso--erro">${esc(r.erro)}</p>` : ''}
            ${r.codigo ? `<p class="cupom__aviso cupom__aviso--ok">Cupom ${esc(r.codigo)} aplicado${r.noTeto ? `, no teto de ${reais(r.desconto)}` : ''}. <button type="button" class="cupom__tirar" data-cupom-tirar>Tirar</button></p>` : ''}
          </div>
        </div>`;
    };

    const linha = (l, i) => `
      <div class="cart__item">
        <div class="cart__desc">
          <strong>${esc(l.nome)}</strong>
          ${l.cor ? `<span class="t-meta">${esc(l.cor)}</span>` : ''}
          ${l.estampa ? `<span class="t-meta">Estampa: ${esc(l.estampa)}</span>` : ''}
          ${l.grade ? `<span class="t-meta">${Object.entries(l.grade).filter(([, n]) => n > 0).map(([t, n]) => `${esc(t)} ×${n}`).join(' · ')}</span>` : ''}
          ${l.indisponivel ? `<span class="cart__alerta">Esta peça saiu do catálogo. Remova para fechar o pedido.</span>` : ''}
        </div>
        <div class="cart__qtd">
          <label class="t-meta" for="q${i}">Qtd</label>
          <input id="q${i}" type="number" min="0" step="1" inputmode="numeric" value="${+l.qtd || 0}" data-qtd="${i}">
        </div>
        <div class="cart__valor">
          ${l.indisponivel ? '–' : `<strong>${reais(l.subtotal)}</strong><span class="t-meta">${reais(l.unitario)} por unidade</span>`}
        </div>
        <button class="cart__tirar" data-remover="${i}" aria-label="Remover ${esc(l.nome)}">Remover</button>
      </div>`;

    /* As linhas calculadas não incluem as indisponíveis, então as
       remonto na ordem original para o cliente ver o que sumiu.      */
    const porChave = new Map(c.linhas.map(l => [l.em, l]));
    const linhas = c.todos.map(i => porChave.get(i.em) || i);

    raiz.innerHTML = `
      <div class="envelope cart">
        <h1 class="t-titulo" style="font-size:clamp(28px,4vw,44px);margin-bottom:6px">Seu pedido</h1>
        <p class="t-corpo" style="margin-bottom:26px">
          ${c.pecasDTF ? `${c.pecasDTF} ${c.pecasDTF === 1 ? 'peça' : 'peças'} em DTF. O desconto olha o pedido inteiro, não cada modelo.` : 'Confira os itens antes de enviar.'}
        </p>

        <div class="cart__lista">${linhas.map(linha).join('')}</div>

        <div class="cart__fecho">
          <div class="cart__resumo">
            ${c.faixa ? `<div class="resumo__linha"><span>Faixa</span><strong>${esc(c.faixa.rotulo)}</strong></div>` : ''}
            ${c.embalagem ? `<div class="resumo__linha"><span>Embalagem</span><strong>${esc(c.embalagem.rotulo)}</strong></div>` : ''}
            ${c.desconto > 0 ? `
              <div class="resumo__linha"><span>Subtotal</span><strong>${reais(c.total)}</strong></div>
              <div class="resumo__linha resumo__desconto">
                <span>${esc(c.cupom.rotulo)}${c.cupom.noTeto ? '' : ` (${c.cupom.percentual}%)`}</span>
                <strong>− ${reais(c.desconto)}</strong>
              </div>` : ''}
            <div class="resumo__linha"><span>Frete</span><strong data-frete-valor>${c.freteGratis ? 'Grátis' : 'A combinar'}</strong></div>
            <div class="resumo__linha resumo__total"><span>Total</span><strong data-total-geral>${reais(c.aPagar)}</strong></div>
            ${!c.freteGratis ? `<p class="t-meta">Frete grátis a partir de ${reais(CONFIG.venda.freteGratis.valor)} para ${CONFIG.venda.freteGratis.estados.join(', ')}.</p>` : ''}

            ${blocoCupom(c)}

            <!-- O cliente que não sabe quanto custa o envio desiste no
                 carrinho. Enquanto a cotação estiver desligada no painel
                 este bloco nem aparece, e o texto acima continua valendo. -->
            <div class="cart__cep" data-cep-bloco hidden>
              <label class="campo"><span>Calcular o frete</span>
                <span class="cep__linha">
                  <input type="text" inputmode="numeric" maxlength="9" data-cep placeholder="Seu CEP" autocomplete="postal-code">
                  <button type="button" class="btn btn--linha" data-cep-calcular>Calcular</button>
                </span>
              </label>
              <div data-frete-opcoes></div>
            </div>
            ${!c.minimoAtingido ? `<p class="cart__alerta">${c.faltamPecas === 1 ? 'Falta 1 peça' : `Faltam ${c.faltamPecas} peças`} para o mínimo de ${CONFIG.venda.pedidoMinimo}. Abaixo disso a folha de filme não se paga.</p>` : ''}
            ${c.sumidos.length ? `<p class="cart__alerta">Remova as peças indisponíveis para enviar.</p>` : ''}
          </div>

          <form class="cart__form" data-form novalidate>
            <h2 class="t-titulo" style="font-size:20px;margin-bottom:4px">Para onde mandamos o orçamento</h2>
            <p class="t-corpo" style="font-size:13px;margin-bottom:16px">
              O pedido entra como solicitação. A gente confere a arte e fecha com você no WhatsApp. Não se paga nada agora.</p>

            <label class="campo"><span>Nome ou empresa</span>
              <input name="nome" required autocomplete="name"></label>
            <label class="campo"><span>WhatsApp</span>
              <input name="zap" inputmode="tel" autocomplete="tel" placeholder="(14) 99999-0000"></label>
            <label class="campo"><span>E-mail</span>
              <input name="email" type="email" required autocomplete="email"></label>
            <p class="t-meta" style="margin-top:-8px">A confirmação do pedido e o botão para agilizar pelo WhatsApp chegam neste e-mail.</p>
            <label class="campo"><span>CPF ou CNPJ</span>
              <input name="documento" inputmode="numeric" autocomplete="off" placeholder="000.000.000-00" required>
              <em class="t-meta">Vai na nota fiscal. É também o que identifica a primeira compra.</em></label>
            <label class="campo"><span>Cidade / UF</span>
              <input name="cidade" autocomplete="address-level2"></label>

            <label class="campo"><span>Sua arte <em class="t-meta">(opcional, pode mandar depois)</em></span>
              <input name="arte" type="file" accept=".png,.jpg,.jpeg,.tif,.tiff,.pdf">
              <em class="t-meta">PNG, JPG, TIFF ou PDF, até ${CONFIG.arte.tamanhoMaxMB} MB.
                <a href="guia-de-arte.html" style="text-decoration:underline">Ver o guia</a>.</em></label>

            <!-- O carrinho é onde o cliente descobre que não tem arte. Antes
                 este caminho só existia no rodapé, e ninguém achava.        -->
            <p class="cart__semarte">Não tem arte ainda?
              <a href="criacao-de-arte.html">A gente cria para você</a>,
              a partir de ${reaisCurto(CONFIG.arte_servico.niveis[0].de)}, e conferir a sua é sempre de graça.</p>

            <label class="campo"><span>Observação</span>
              <textarea name="obs" rows="3" placeholder="Prazo, cor da estampa, qualquer detalhe."></textarea></label>

            <!-- isca: fica escondida e só robô preenche -->
            <div class="isca" aria-hidden="true">
              <label>Empresa <input name="empresa" tabindex="-1" autocomplete="off"></label>
            </div>

            <button class="btn btn--volt btn--cheio" type="submit" data-enviar
              ${!c.minimoAtingido || c.sumidos.length ? 'disabled' : ''}>Enviar pedido</button>
            <p class="cart__estado" data-estado role="status" aria-live="polite"></p>
            <p class="t-meta">Prefere conversar? <a data-zap="Oi! Montei um pedido no site da OVR e queria falar antes de fechar." style="text-decoration:underline">Chamar no WhatsApp</a>.</p>
          </form>
        </div>
      </div>`;

    restaurarForm();
    ligar(c);
    preencherPelaConta();
  }

  function ligar(c) {
    raiz.querySelectorAll('[data-remover]').forEach(b =>
      b.addEventListener('click', () => { Carrinho.remover(+b.dataset.remover); desenhar(); }));

    raiz.querySelectorAll('[data-qtd]').forEach(i =>
      i.addEventListener('change', () => { Carrinho.mudarQtd(+i.dataset.qtd, i.value); desenhar(); }));

    const form = raiz.querySelector('[data-form]');
    if (form) form.addEventListener('submit', ev => enviar(ev, c));

    ligarFrete(c);
    ligarCupom();

    /* o link do WhatsApp é montado pelo app.js em toda página */
    if (typeof aplicarMarca === 'function') aplicarMarca();
  }

  /* ---------------------- cotação de frete -------------------------
     A escolha vive aqui e não no Carrinho porque ela é do momento da
     compra, não do carrinho guardado: se o cliente voltar amanhã, o
     frete é cotado de novo. Guardar valor de frete velho é a receita
     para cobrar um preço que a transportadora não pratica mais.     */
  let freteEscolhido = null;

  /* ---------------------- cupom, do lado da tela -------------------
     desenhar() repinta o bloco inteiro. Sem guardar o que foi digitado
     aqui fora, aplicar o cupom apagaria os dois campos na hora de
     mostrar o resultado. */
  let cupomAberto = false;
  let cupomDigitado = { codigo: '' };

  /* Máscara só de exibição. Quem valida de verdade é o servidor, e ele
     aceita com ou sem pontuação — isto existe para o campo ficar
     legível enquanto se digita, não para filtrar. */
  function mascaraDoc(v) {
    const d = v.replace(/[^0-9A-Za-z]/g, '').toUpperCase().slice(0, 14);
    if (d.length <= 11 && /^\d*$/.test(d)) {
      return d.replace(/^(\d{3})(\d)/, '$1.$2')
              .replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3')
              .replace(/\.(\d{3})(\d{1,2})$/, '.$1-$2');
    }
    return d.replace(/^(.{2})(.)/, '$1.$2')
            .replace(/^(.{2})\.(.{3})(.)/, '$1.$2.$3')
            .replace(/^(.{2})\.(.{3})\.(.{3})(.)/, '$1.$2.$3/$4')
            .replace(/(.{4})(\d{1,2})$/, '$1-$2');
  }

  /* ---------------------- documento, do lado da tela ---------------
     A mesma conta do servidor (api/documento.php). Aqui ela NÃO é
     controle: é retorno imediato, para a pessoa descobrir o dígito
     errado enquanto digita e não depois de apertar enviar. Quem decide
     continua sendo o servidor — se este trecho fosse burlado, o pedido
     seria recusado lá.

     `ord - 48` no lugar do dígito porque o CNPJ aceita letra nas doze
     primeiras posições desde julho de 2026. Para '0'..'9' dá no mesmo. */
  function dvDoc(base, pesos) {
    let soma = 0;
    for (let i = 0; i < base.length; i++) soma += (base.charCodeAt(i) - 48) * pesos[i];
    const r = soma % 11;
    return String(r < 2 ? 0 : 11 - r);
  }

  function docValido(bruto) {
    const d = String(bruto || '').replace(/[^0-9A-Za-z]/g, '').toUpperCase();
    if (d.length === 11) {
      /* Onze dígitos iguais passam na conta e são inválidos por regra. */
      if (!/^\d{11}$/.test(d) || /^(\d)\1{10}$/.test(d)) return false;
      return d[9]  === dvDoc(d.slice(0, 9),  [10, 9, 8, 7, 6, 5, 4, 3, 2])
          && d[10] === dvDoc(d.slice(0, 10), [11, 10, 9, 8, 7, 6, 5, 4, 3, 2]);
    }
    if (d.length === 14) {
      if (!/^[0-9A-Z]{12}\d{2}$/.test(d) || /^(.)\1{13}$/.test(d)) return false;
      return d[12] === dvDoc(d.slice(0, 12), [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2])
          && d[13] === dvDoc(d.slice(0, 13), [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
    }
    return false;
  }

  const campoDoc = () => raiz.querySelector('[name="documento"]');

  function ligarCupom() {
    /* A máscara vive aqui e não em ligar(): o campo é do formulário,
       mas quem precisa dele legível é o cupom. */
    const doc = campoDoc();
    doc?.addEventListener('input', () => { doc.value = mascaraDoc(doc.value); });

    /* Trocou o CPF com cupom aplicado? Confere de novo. Sem isto a
       prévia continuaria mostrando o resultado do documento anterior. */
    let revalida;
    doc?.addEventListener('input', () => {
      if (!cupomDigitado.codigo) return;
      clearTimeout(revalida);
      revalida = setTimeout(() => {
        Carrinho.usarCupom({ codigo: cupomDigitado.codigo, documento: doc.value.trim() });
        desenhar();
      }, 600);
    });

    const bloco = raiz.querySelector('[data-cupom]');
    if (!bloco) return;

    const campos = bloco.querySelector('.cupom__campos');
    const cod    = bloco.querySelector('[data-cupom-codigo]');

    bloco.querySelector('[data-cupom-abrir]')?.addEventListener('click', ev => {
      cupomAberto = true;
      campos.hidden = false;
      ev.target.remove();
      cod?.focus();
    });

    cod?.addEventListener('input', () => { cupomDigitado.codigo = cod.value; });

    const aplicar = async () => {
      cupomDigitado.codigo = cod.value.trim();
      if (!cupomDigitado.codigo) { cod.focus(); return; }

      /* O CPF está do outro lado da tela. Se faltar, o aviso aparece no
         cupom E o foco vai para o campo, senão a pessoa lê "informe o
         CPF" sem saber onde ele está. */
      const numero = (doc?.value || '').trim();
      if (!docValido(numero)) {
        doc?.focus();
        doc?.scrollIntoView({ block: 'center', behavior: 'smooth' });
      }
      Carrinho.usarCupom({ codigo: cupomDigitado.codigo, documento: numero });
      await desenhar();
    };

    bloco.querySelector('[data-cupom-aplicar]')?.addEventListener('click', aplicar);
    [cod].forEach(i => i?.addEventListener('keydown', ev => {
      if (ev.key === 'Enter') { ev.preventDefault(); aplicar(); }
    }));

    bloco.querySelector('[data-cupom-tirar]')?.addEventListener('click', async () => {
      cupomDigitado.codigo = '';
      Carrinho.usarCupom(null);
      await desenhar();
    });
  }


  function ligarFrete(c) {
    const bloco = raiz.querySelector('[data-cep-bloco]');
    if (!bloco || !c.linhas.length) return;

    const campo   = bloco.querySelector('[data-cep]');
    const botao   = bloco.querySelector('[data-cep-calcular]');
    const alvo    = bloco.querySelector('[data-frete-opcoes]');
    const linha   = raiz.querySelector('[data-frete-valor]');
    const totalEl = raiz.querySelector('[data-total-geral]');

    /* Máscara simples: só dígito e o traço no lugar certo. */
    campo.addEventListener('input', () => {
      const n = campo.value.replace(/\D/g, '').slice(0, 8);
      campo.value = n.length > 5 ? n.slice(0, 5) + '-' + n.slice(5) : n;
    });
    campo.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); botao.click(); } });

    const pintar = () => {
      const gratis = freteEscolhido && freteEscolhido.preco === 0;
      linha.textContent = !freteEscolhido ? (c.freteGratis ? 'Grátis' : 'A combinar')
                        : gratis ? 'Grátis' : reais(freteEscolhido.preco / 100);
      totalEl.textContent = reais(c.aPagar + (freteEscolhido ? freteEscolhido.preco / 100 : 0));
    };

    botao.addEventListener('click', async () => {
      const cep = campo.value.replace(/\D/g, '');
      if (cep.length !== 8) { alvo.innerHTML = '<p class="cart__alerta">CEP precisa ter 8 dígitos.</p>'; return; }

      /* Frete grátis é promessa nossa e não depende de cotação: se o
         pedido bate o valor e o estado, resolve aqui e não gasta
         consulta na conta.                                           */
      const uf = Preco.ufDoCep(cep);
      /* Olha `total`, não `aPagar`: o frete grátis é decidido ANTES do
         desconto. Com `aPagar` havia uma faixa perversa — entre R$ 1.500
         e R$ 1.649 o cupom derrubava o pedido para fora da promoção, e o
         cliente economizava R$ 150 para voltar a pagar frete. */
      if (uf && Preco.temFreteGratis(c.total, uf)) {
        freteEscolhido = { nome: 'Frete grátis', preco: 0, prazo: null };
        alvo.innerHTML = `<p class="cart__frete-gratis">Frete grátis para ${esc(uf)}: o seu pedido passou de ${reais(CONFIG.venda.freteGratis.valor)}.</p>`;
        pintar();
        return;
      }

      const emb = c.embalagem;
      botao.disabled = true;
      alvo.innerHTML = '<p class="t-meta">Consultando as transportadoras…</p>';
      try {
        const r = await fetch(CONFIG.carrinho.endpointFrete, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            cep,
            peso: emb?.pesoKg || 0.3,
            comprimento: emb?.cm?.[0] || 30,
            largura: emb?.cm?.[1] || 20,
            altura: emb?.cm?.[2] || 10,
            valor: c.aPagar,
          }),
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok || !j.ativo || !j.opcoes?.length) {
          /* Sem cotação o pedido continua válido: o envio volta a ser
             combinado na conversa, que é como funciona hoje.          */
          alvo.innerHTML = '<p class="t-meta">Não consegui cotar agora. O envio a gente combina no WhatsApp, junto com o pedido.</p>';
          freteEscolhido = null; pintar();
          return;
        }
        alvo.innerHTML = `<div class="frete__opcoes">${j.opcoes.map((o, i) => `
          <label class="frete__opcao">
            <input type="radio" name="frete" value="${i}" ${i === 0 ? 'checked' : ''}>
            <span class="frete__nome">${esc(o.nome)}</span>
            <span class="frete__prazo">${+o.prazo} dias úteis</span>
            <span class="frete__preco">${reais(o.preco / 100)}</span>
          </label>`).join('')}</div>`;
        freteEscolhido = j.opcoes[0];
        alvo.querySelectorAll('input[name="frete"]').forEach(r2 =>
          r2.addEventListener('change', () => { freteEscolhido = j.opcoes[+r2.value]; pintar(); }));
        pintar();
      } catch (e) {
        alvo.innerHTML = '<p class="t-meta">Não consegui cotar agora. O envio a gente combina no WhatsApp.</p>';
        freteEscolhido = null; pintar();
      } finally {
        botao.disabled = false;
      }
    });

    /* Pergunta ao painel se a cotação está ligada. Enquanto não estiver,
       o bloco fica escondido e o carrinho segue como está hoje.       */
    fetch(CONFIG.carrinho.endpointFrete, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ cep: '00000000' }),
    }).then(r => r.json()).then(j => { if (j && j.ativo) bloco.hidden = false; }).catch(() => {});
  }

  async function enviar(ev, c) {
    ev.preventDefault();
    const form = ev.target;
    const estado = raiz.querySelector('[data-estado]');
    const botao = raiz.querySelector('[data-enviar]');
    const diz = (t, erro = false) => {
      estado.textContent = t;
      estado.className = 'cart__estado' + (erro ? ' cart__estado--erro' : '');
    };

    const d = new FormData(form);
    const nome = (d.get('nome') || '').toString().trim();
    const zap = (d.get('zap') || '').toString().trim();
    const email = (d.get('email') || '').toString().trim();
    if (!nome)  return diz('Falta o seu nome.', true);
    if (!email) return diz('Informe o e-mail que vai receber a confirmação do pedido.', true);

    /* O documento é obrigatório: ele vai na nota e é o que identifica a
       primeira compra. A recusa aqui é só conveniência — o servidor
       confere de novo e recusa igual. */
    const documento = (d.get('documento') || '').toString().trim();
    if (!documento) { campoDoc()?.focus(); return diz('Informe o CPF ou CNPJ. Ele vai na nota fiscal.', true); }
    if (!docValido(documento)) { campoDoc()?.focus(); return diz('Esse CPF ou CNPJ não existe. Confira os números.', true); }

    const arquivo = form.querySelector('input[name="arte"]')?.files?.[0] || null;
    if (arquivo && arquivo.size > CONFIG.arte.tamanhoMaxMB * 1024 * 1024) {
      return diz(`A arte passa de ${CONFIG.arte.tamanhoMaxMB} MB. Mande por WhatsApp depois.`, true);
    }

    const pedido = {
      cliente: {
        nome, zap, email,
        cidade: (d.get('cidade') || '').toString().trim(),
        /* Vai cru e o painel decide o que guardar: lá ele vira hash e o
           número é descartado. O carrinho não persiste documento. */
        documento: (d.get('documento') || '').toString().trim(),
      },
      tipo: tipoDoPedido(c.linhas),
      itens: c.linhas.map(l => ({
        tipo: l.tipo, id: l.id ?? null, produto: l.nome, cor: l.cor || '',
        grade: l.grade ? Object.entries(l.grade).filter(([, n]) => n > 0).map(([t, n]) => `${t} ×${n}`).join(', ') : '',
        qtd: l.qtd, estampa: l.estampa || '',
        unitario: Math.round(l.unitario * 100), subtotal: Math.round(l.subtotal * 100),
      })),
      estampa: [...new Set(c.linhas.map(l => l.estampa).filter(Boolean))].join(' / '),
      embalagem: c.embalagem?.rotulo || '',
      total: Math.round(c.total * 100),
      /* `total` continua sendo a soma das peças, sem desconto, porque é
         dela que sai a margem no painel. O cupom viaja ao lado, e o
         painel refaz a conta: o que a tela mostrou é orçamento. */
      cupom: c.cupom?.codigo || '',
      desconto: Math.round((c.desconto || 0) * 100),
      /* `total` continua sendo só as peças. O frete viaja separado
         porque no painel ele é outro campo e outra conta: item é custo,
         frete cobrado é receita.                                      */
      frete: freteEscolhido
        ? { nome: freteEscolhido.nome, valor: freteEscolhido.preco, prazo: freteEscolhido.prazo }
        : null,
      obs: (d.get('obs') || '').toString().trim(),
      arte_servico: c.linhas.some(l => l.tipo === 'arte'),
      empresa: (d.get('empresa') || '').toString(),   // isca
      t: abriuEm,
    };

    const corpo = new FormData();
    corpo.append('pedido', JSON.stringify(pedido));
    if (arquivo) corpo.append('arte', arquivo, arquivo.name);

    botao.disabled = true;
    diz('Enviando…');
    try {
      const r = await fetch(CONFIG.carrinho.endpoint, { method: 'POST', body: corpo });
      const j = await r.json().catch(() => ({}));
      if (!r.ok) throw new Error(j?.message || 'Não consegui enviar agora.');
      Carrinho.apagar();
      sucesso(j, pedido);
    } catch (e) {
      botao.disabled = false;
      diz(e.message + ' Se insistir, chama no WhatsApp que a gente resolve por lá.', true);
    }
  }

  const tipoDoPedido = linhas => {
    const t = new Set(linhas.map(l => l.tipo));
    return t.size === 1 ? [...t][0] : 'misto';
  };

  function sucesso(resposta, pedido) {
    const numero = resposta?.numero || '';
    const emailEnviado = !!resposta?.email_enviado;
    const avisoEmail = emailEnviado
      ? `Enviamos a confirmação para ${esc(pedido.cliente.email)}.`
      : `O pedido chegou, mas o e-mail para ${esc(pedido.cliente.email)} não saiu. A gente resolve pelo WhatsApp.`;
    const avisoArte = resposta?.arte === 'recebida'
      ? 'Sua arte chegou junto. Vamos conferir o arquivo de graça e avisar se algo precisar de ajuste.'
      : 'Envie agora no WhatsApp e ganhe tempo na conferência. Se ainda não tiver, tudo bem.';
    raiz.innerHTML = `
      <section class="pedido-ok" aria-labelledby="pedido-ok-titulo">
        <div class="envelope">
          <div class="pedido-ok__grade">
            <div class="pedido-ok__recibo">
              <div class="pedido-ok__selo">
                <span class="pedido-ok__check" aria-hidden="true">
                  <svg viewBox="0 0 24 24" fill="none"><path d="m6 12.5 3.7 3.7L18.5 7.5" stroke="currentColor" stroke-width="2.2" stroke-linecap="square" stroke-linejoin="miter"/></svg>
                </span>
                <span>Pedido recebido</span>
              </div>

              <p class="pedido-ok__rotulo">Número do pedido</p>
              <p class="pedido-ok__numero">${numero ? esc(numero) : 'Recebido'}</p>
              <h1 class="pedido-ok__titulo" id="pedido-ok-titulo">Agora é com a gente.</h1>
              <p class="pedido-ok__intro">Sua solicitação entrou na OVR. Não houve cobrança e o pedido só fecha depois da nossa conferência.</p>

              <div class="pedido-ok__acoes">
                <a class="btn btn--volt" data-zap="Oi! Acabei de enviar o pedido ${esc(numero)} pelo site e quero agilizar a finalização.">Agilizar no WhatsApp ↗</a>
                <a class="pedido-ok__voltar" href="catalogo.html">Voltar ao catálogo</a>
              </div>
              <p class="pedido-ok__seguro">Nenhum pagamento foi feito agora.</p>
            </div>

            <div class="pedido-ok__proximos">
              <p class="t-etiqueta">O que acontece agora</p>
              <h2>Próximos passos</h2>
              <ol class="pedido-ok__passos">
                <li>
                  <span>01</span>
                  <div><strong>Confirmação registrada</strong><p class="${emailEnviado ? '' : 'pedido-ok__aviso'}">${avisoEmail}</p></div>
                </li>
                <li>
                  <span>02</span>
                  <div><strong>Conferência OVR</strong><p>Vamos verificar estoque, arte, frete e prazo.</p></div>
                </li>
                <li>
                  <span>03</span>
                  <div><strong>Fechamento no WhatsApp</strong><p>A gente fala com você para confirmar tudo antes de produzir.</p></div>
                </li>
              </ol>

              <div class="pedido-ok__arte">
                <div><strong>${resposta?.arte === 'recebida' ? 'Arte recebida' : 'Já tem a arte?'}</strong><p>${avisoArte}</p></div>
                <a data-zap="Oi! Acabei de enviar o pedido ${esc(numero)} e quero mandar a arte para conferência.">${resposta?.arte === 'recebida' ? 'Falar sobre a arte ↗' : 'Mandar minha arte ↗'}</a>
              </div>
            </div>
          </div>
        </div>
      </section>`;
    if (typeof aplicarMarca === 'function') aplicarMarca();
  }

  document.addEventListener('DOMContentLoaded', desenhar);
})();
