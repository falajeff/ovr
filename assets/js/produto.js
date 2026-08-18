/* =============================================================
   OVR — página de produto
   Monta o pedido tamanho a tamanho, recalcula o preço a cada
   mudança e fecha no WhatsApp com a mensagem pronta.
   ============================================================= */

(() => {
  const raiz = document.querySelector('[data-produto]');
  const E = CONFIG.estampas;
  let produto = null;
  let grade = [];
  let limiteAltAnterior = null;
  /* Cada posição carrega a própria medida. Antes era só o id da posição
     e o tamanho vinha fixo do config.                                  */
  const medidaPadrao = id => ({ id, rotulo: E[id].rotulo, larg: E[id].larg, alt: E[id].alt });
  let posicoes = [medidaPadrao('frente')];
  const quantidades = {};          // { P: 5, M: 10, ... }

  /* Guarda contra teto ausente: Math.min(undefined, x) devolve NaN, e um
     NaN aqui vira estampa de área zero — ou seja, impressão de graça.
     Sem teto definido é melhor não limitar do que limitar para NaN.     */
  const limitar = (v, min, max) =>
    Math.min(Number.isFinite(max) ? max : Infinity, Math.max(min, v));

  /* "Frente 28×30 cm" ou "Frente 28×30 e costas 30×30 cm" */
  const descreverEstampa = () => posicoes
    .map(p => `${p.rotulo.toLowerCase()} ${p.larg}×${p.alt} cm`)
    .join(' e ')
    .replace(/^./, c => c.toUpperCase());


  /* A estampa tem que caber em TODAS as peças do pedido, então quem manda
     é o menor tamanho. Enquanto a grade está vazia, considera a grade
     inteira da peça — é o cenário mais restritivo e o mais honesto.     */
  function tamanhoLimitante() {
    const pedidos = Object.entries(quantidades).filter(([, n]) => n > 0).map(([t]) => t);
    const alvo = pedidos.length ? pedidos : grade.map(g => g.tam);
    const altura = t => E.alturaUtilPorTamanho[String(t).toUpperCase()] ?? E.alturaUtilPadrao;
    return alvo.reduce((menor, t) => (altura(t) < altura(menor) ? t : menor), alvo[0] || 'M');
  }
  const alturaMaxima = () => {
    const t = tamanhoLimitante();
    const util = E.alturaUtilPorTamanho[String(t).toUpperCase()] ?? E.alturaUtilPadrao;
    return Math.max(E.minCm, util - E.margemAlturaCm);
  };

  const totalPecas = () => Object.values(quantidades).reduce((s, n) => s + n, 0);

  /* O raspador devolve a grade na ordem do fornecedor, que vem embaralhada.
     Aqui ela volta para a ordem que o cliente espera ler.                  */
  const ORDEM_TAM = ['PP','P','M','G','GG','XG','XGG','EXG','G1','G2','G3','G4',
                     '2','4','6','8','10','12','14','16','ÚNICO','U'];
  const ordenarGrade = grade => [...grade].sort((a, b) => {
    const ia = ORDEM_TAM.indexOf(String(a.tam).toUpperCase());
    const ib = ORDEM_TAM.indexOf(String(b.tam).toUpperCase());
    return (ia < 0 ? 99 : ia) - (ib < 0 ? 99 : ib);
  });

  /* ------------------------- desenho ---------------------------- */
  function montar() {
    const irmas = CATALOGO.filter(p => p.tipo === produto.tipo && p.id !== produto.id).slice(0, 3);
    grade = ordenarGrade(produto.grade?.length ? produto.grade
                                                     : [{ tam: 'P' }, { tam: 'M' }, { tam: 'G' }, { tam: 'GG' }]);

    raiz.innerHTML = `
      <nav class="envelope" style="padding-block:20px" aria-label="Você está em">
        <p class="t-meta"><a href="catalogo.html">Catálogo</a> / <a href="catalogo.html?tipo=${encodeURIComponent(produto.tipo)}">${esc(produto.tipo)}</a> / ${esc(produto.nome)}</p>
      </nav>

      <section class="envelope produto" style="padding-bottom:64px">
        <div>
          <div class="poco poco--retrato">${pecaHTML(produto, null, true)}</div>
          ${irmas.length ? `<div class="miniaturas">${irmas.map(p => `
            <a class="poco poco--quadrado" href="produto.html?id=${+p.id}" title="${esc(p.nome)}">${pecaHTML(p, p.cor)}</a>`).join('')}</div>` : ''}
        </div>

        <div class="produto__painel">
          <p class="t-meta">${esc(produto.tipo)} · ${esc(produto.cor)} · Ref. ${+produto.id}</p>
          <h1 class="t-h2">${esc(produto.nome)}</h1>
          <p class="produto__preco" data-unitario></p>
          <p class="t-corpo" style="font-size:14px" data-explica></p>

          <div class="campo">
            <label>Onde vai a estampa</label>
            <div class="opcoes">
              <button class="opcao" data-pos="frente" aria-pressed="true">Frente</button>
              <button class="opcao" data-pos="costas" aria-pressed="false">Costas</button>
              <button class="opcao" data-pos="frente-costas" aria-pressed="false">Frente e costas</button>
            </div>
          </div>

          <div class="campo">
            <label>Tamanho da estampa</label>
            <div data-medidas></div>
            <p class="t-meta" data-limite></p>
          </div>

          <div class="campo">
            <label>Tamanhos e quantidade</label>
            <div class="grade-tamanhos">
              ${grade.map(g => `
                <div class="tamanho" data-cel="${esc(g.tam)}">
                  <span>${esc(g.tam)}</span>
                  <input type="number" min="0" step="1" value="0" inputmode="numeric" data-tam="${esc(g.tam)}" aria-label="Quantidade ${esc(g.tam)}">
                </div>`).join('')}
            </div>
            <p class="t-meta" data-dica>Preencha a grade. O preço cai sozinho conforme o total.</p>
          </div>

          <div class="resumo">
            <div class="resumo__linha"><span>Peças</span><strong data-qtd>0</strong></div>
            <div class="resumo__linha"><span>Faixa</span><strong data-faixa>–</strong></div>
            <div class="resumo__linha"><span>Valor por peça</span><strong data-valor>–</strong></div>
            <div class="resumo__linha resumo__total"><span>Total</span><strong data-total>–</strong></div>
          </div>

          <button class="btn btn--volt btn--cheio" type="button" data-adicionar>Adicionar ao pedido
            <svg class="btn__seta" viewBox="0 0 14 14" fill="none" aria-hidden="true"><path d="M4 10L10 4M10 4H4.8M10 4V9.2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </button>
          <p class="cart__estado" data-add-estado role="status" aria-live="polite"></p>
          <p class="t-corpo" style="font-size:12px">Junte quantas peças quiser: o desconto olha o pedido inteiro, e pode misturar modelo e cor.</p>

          <a class="btn btn--linha btn--cheio" data-fechar><span data-fechar-texto>Fechar pedido no WhatsApp</span>
            <svg class="btn__seta" viewBox="0 0 14 14" fill="none" aria-hidden="true"><path d="M4 10L10 4M10 4H4.8M10 4V9.2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </a>

          <div class="aviso-arte">
            <strong style="font-family:var(--fonte-titulo); font-size:15px; letter-spacing:.02em">Antes de enviar a arte</strong>
            <p class="t-corpo" style="font-size:13px">PNG ou TIFF com fundo transparente, ${CONFIG.arte.dpi} DPI no tamanho final, cor em CMYK.
              <a href="guia-de-arte.html" style="text-decoration:underline">Ver o guia completo</a>.</p>
            <p class="t-corpo" style="font-size:13px; margin-top:8px">Não tem arte ainda? <a href="criacao-de-arte.html" style="text-decoration:underline">A gente cria para você</a>.</p>
          </div>

          <div data-medidas-tabela></div>

          <div class="ficha">
            <h3>Ficha técnica</h3>
            <div class="ficha__linha"><span>Tipo</span><span>${esc(produto.tipo)}</span></div>
            <div class="ficha__linha"><span>Cor</span><span>${esc(produto.cor)}</span></div>
            <div class="ficha__linha"><span>Grade</span><span>${grade.map(g => esc(g.tam)).join(' · ')}</span></div>
            <div class="ficha__linha"><span>Impressão</span><span>DTF, até ${E.limiteLargCm} × ${alturaMaxima()} cm no tamanho ${tamanhoLimitante()}</span></div>
            <div class="ficha__linha"><span>Produção</span><span>${CONFIG.venda.prazoProducao}</span></div>
            <div class="ficha__linha"><span>Frete</span><span>Grátis acima de ${reais(CONFIG.venda.freteGratis.valor)} em ${CONFIG.venda.freteGratis.estados.join(', ')}</span></div>
          </div>
        </div>
      </section>

      <section class="secao inverso">
        <div class="envelope">
          <div class="cabecalho-secao">
            <h2 class="t-h2">O preço cai com o volume.</h2>
            <p class="t-corpo">O cálculo inclui a base, a estampa em DTF e a operação. O desconto do fornecedor e a diluição do frete do filme entram sozinhos conforme a quantidade.</p>
          </div>
          <div class="escada" data-escada></div>
        </div>
      </section>`;

    raiz.querySelectorAll('[data-pos]').forEach(b => b.addEventListener('click', () => {
      const querem = b.dataset.pos === 'frente-costas' ? ['frente', 'costas'] : [b.dataset.pos];
      /* preserva a medida que o cliente já ajustou nas posições que ficam */
      posicoes = querem.map(id => posicoes.find(p => p.id === id) || medidaPadrao(id));
      raiz.querySelectorAll('[data-pos]').forEach(o => o.setAttribute('aria-pressed', String(o === b)));
      desenharMedidas();
      atualizar();
    }));

    desenharMedidas();

    raiz.querySelectorAll('[data-tam]').forEach(i => {
      const mexer = () => {
        const n = Math.max(0, parseInt(i.value || '0', 10) || 0);
        i.value = n;
        quantidades[i.dataset.tam] = n;
        i.closest('.tamanho').classList.toggle('tamanho--ativo', n > 0);
        atualizar();
      };
      i.addEventListener('input', mexer);
      i.addEventListener('change', mexer);
    });

    const somar = raiz.querySelector('[data-adicionar]');
    if (somar) somar.addEventListener('click', adicionarAoPedido);

    atualizar();
  }

  /* --------------- manda a peça para o carrinho ------------------
     Guarda só o que identifica o item: id, grade e medida. O preço
     é refeito no carrinho a partir do catálogo, porque a faixa
     depende do pedido inteiro e não desta página.                  */
  function adicionarAoPedido() {
    const estado = raiz.querySelector('[data-add-estado]');
    const diz = (t, erro = false) => {
      if (!estado) return;
      estado.textContent = t;
      estado.className = 'cart__estado' + (erro ? ' cart__estado--erro' : '');
    };

    const qtd = totalPecas();
    if (!qtd) return diz('Preencha a grade primeiro: quantas peças de cada tamanho.', true);

    const grade = Object.fromEntries(Object.entries(quantidades).filter(([, n]) => n > 0));
    const r = Carrinho.adicionar({
      tipo: 'dtf',
      id: produto.id,
      nome: produto.nome,
      cor: produto.cor,
      qtd,
      grade,
      posicoes: posicoes.map(p => ({ id: p.id, rotulo: p.rotulo, larg: p.larg, alt: p.alt })),
      estampa: descreverEstampa(),
    });
    if (!r.ok) return diz(r.erro, true);

    /* Zera a grade para ele poder montar a próxima peça sem apagar
       número por número — é assim que o pedido misturado se monta.  */
    Object.keys(quantidades).forEach(t => { quantidades[t] = 0; });
    raiz.querySelectorAll('[data-tam]').forEach(i => {
      i.value = 0;
      i.closest('.tamanho')?.classList.remove('tamanho--ativo');
    });
    atualizar();

    diz(`${qtd} ${qtd === 1 ? 'peça somada' : 'peças somadas'} ao pedido, ${r.total} no total. `
      + 'Escolha outra peça ou abra o carrinho para fechar.');
  }

  /* ---------------- tamanho da estampa por posição --------------- */
  function desenharMedidas() {
    const caixa = raiz.querySelector('[data-medidas]');
    if (!caixa) return;
    const maxAlt = alturaMaxima();
    limiteAltAnterior = maxAlt;

    caixa.innerHTML = posicoes.map(p => {
      const casa = E.presets.find(x => x.larg === p.larg && x.alt === p.alt);
      return `
        <div class="medida" data-medida="${p.id}">
          ${posicoes.length > 1 ? `<span class="medida__onde">${esc(p.rotulo)}</span>` : ''}
          <div class="opcoes">
            ${E.presets.map(x => `
              <button class="opcao opcao--min" data-preset="${x.id}"
                      ${x.alt > maxAlt ? 'disabled title="Não cabe no menor tamanho do pedido"' : ''}
                      aria-pressed="${casa && casa.id === x.id}">${esc(x.nome)}
                <small>${x.larg}×${x.alt}</small>
              </button>`).join('')}
            <button class="opcao opcao--min" data-preset="livre" aria-pressed="${!casa}">Outro</button>
          </div>
          <div class="medida__cm" ${casa ? 'hidden' : ''}>
            <input type="number" data-cm="larg" value="${p.larg}" min="${E.minCm}" max="${E.limiteLargCm}" inputmode="numeric" aria-label="Largura da estampa ${esc(p.rotulo)} em cm">
            <span>×</span>
            <input type="number" data-cm="alt" value="${p.alt}" min="${E.minCm}" max="${maxAlt}" inputmode="numeric" aria-label="Altura da estampa ${esc(p.rotulo)} em cm">
            <span>cm</span>
          </div>
        </div>`;
    }).join('');

    caixa.querySelectorAll('[data-medida]').forEach(bloco => {
      const p = posicoes.find(x => x.id === bloco.dataset.medida);
      const cm = bloco.querySelector('.medida__cm');

      bloco.querySelectorAll('[data-preset]').forEach(b => b.addEventListener('click', () => {
        const escolha = b.dataset.preset;
        if (escolha === 'livre') {
          cm.hidden = false;
        } else {
          const x = E.presets.find(v => v.id === escolha);
          p.larg = x.larg; p.alt = x.alt;
          cm.hidden = true;
          cm.querySelector('[data-cm="larg"]').value = x.larg;
          cm.querySelector('[data-cm="alt"]').value = x.alt;
        }
        bloco.querySelectorAll('[data-preset]').forEach(o => o.setAttribute('aria-pressed', String(o === b)));
        atualizar();
      }));

      cm.querySelectorAll('[data-cm]').forEach(i => {
        const mexer = () => {
          const eixo = i.dataset.cm;
          /* A altura não tem teto fixo: ele sai da peça, pela regra dos
             10cm. Por isso vem de alturaMaxima() e não de uma constante. */
          const teto = eixo === 'larg' ? E.limiteLargCm : alturaMaxima();
          const v = limitar(Math.round(+i.value || 0), E.minCm, teto);
          i.value = v;
          p[eixo] = v;
          atualizar();
        };
        i.addEventListener('change', mexer);
        i.addEventListener('blur', mexer);
      });
    });
  }

  /* ------------------------ recálculo --------------------------- */
  /* Uma consulta por atualização, com sequência para descartar resposta
     atrasada: o cliente digita rápido na grade e a rede não devolve na
     ordem em que foi chamada.                                          */
  let consultaAtual = 0;

  async function atualizar() {
    const qtd = totalPecas();

    /* O limite muda quando o cliente inclui um tamanho menor na grade.
       Se a estampa já estava mais alta que o novo teto, ela desce.     */
    const maxAlt = alturaMaxima();
    let precisaRedesenhar = maxAlt !== limiteAltAnterior;
    posicoes.forEach(p => { if (p.alt > maxAlt) { p.alt = maxAlt; precisaRedesenhar = true; } });
    if (precisaRedesenhar) { limiteAltAnterior = maxAlt; desenharMedidas(); }

    const aviso = raiz.querySelector('[data-limite]');
    if (aviso) aviso.textContent =
      `O preço acompanha a área impressa. Máximo ${E.limiteLargCm} × ${maxAlt} cm, ` +
      `limitado pelo tamanho ${tamanhoLimitante()} da sua grade.`;

    const efetiva = Math.max(1, qtd);
    const $ = s => raiz.querySelector(s);

    const meu = ++consultaAtual;
    let d, avulso;
    try {
      d = await Preco.consultar(produto, efetiva, posicoes);
      avulso = qtd ? null : await Preco.consultar(produto, 1, posicoes);
    } catch (e) {
      /* Sem preço não se inventa número: a página diz que não conseguiu. */
      $('[data-unitario]').textContent = '—';
      $('[data-explica]').textContent = 'Não consegui calcular o preço agora. Recarregue a página ou fale no WhatsApp.';
      return;
    }
    if (meu !== consultaAtual) return;   // chegou tarde, já tem consulta mais nova

    $('[data-unitario]').textContent = qtd ? reais(d.unitario) : reais(avulso.unitario);
    $('[data-explica]').textContent = qtd
      ? `Valor por peça na faixa de ${d.faixa.rotulo}. Estampa em ${descreverEstampa().toLowerCase()}.`
      : `Preço da peça avulsa. Monte a grade abaixo e ele cai conforme a quantidade.`;
    $('[data-qtd]').textContent = qtd;
    $('[data-faixa]').textContent = qtd ? d.faixa.rotulo : '–';
    $('[data-valor]').textContent = qtd ? reais(d.unitario) : '–';
    $('[data-total]').textContent = qtd ? reais(d.total) : '–';

    /* Pedido mínimo: o filme vem por metro e um metro não se paga numa
       peça só. Não escondemos o preço nem travamos o botão — mandamos
       para a conversa, onde ele pode juntar com outro pedido.          */
    const minimo  = Math.max(1, CONFIG.venda.pedidoMinimo || 1);
    const atingiu = qtd >= minimo;
    const faltam  = minimo - qtd;

    $('[data-dica]').textContent = !qtd
      ? (minimo > 1
          ? `Preencha a grade. O preço cai sozinho conforme o total. Mínimo de ${minimo} peças.`
          : 'Preencha a grade. Faz uma peça só, e o preço cai sozinho conforme o total.')
      : atingiu
        ? 'O preço cai sozinho conforme o total.'
        : `${faltam === 1 ? 'Falta' : 'Faltam'} ${faltam} ${faltam === 1 ? 'peça' : 'peças'} para o mínimo de ${minimo}. `
          + 'Abaixo disso a folha de filme não se paga. Chama no WhatsApp que a gente vê se dá para juntar com outro pedido.';

    const botao = $('[data-fechar]');
    botao.href = linkZap(mensagem(qtd, d, atingiu));
    botao.target = '_blank'; botao.rel = 'noopener';
    botao.style.opacity = atingiu ? '1' : '.55';
    botao.querySelector('[data-fechar-texto]').textContent =
        !qtd    ? 'Tirar dúvida no WhatsApp'
      : atingiu ? 'Fechar pedido no WhatsApp'
                : `Falar sobre ${qtd} ${qtd === 1 ? 'peça' : 'peças'} no WhatsApp`;

    const linhas = d.escada;
    $('[data-escada]').innerHTML = linhas.map((l, i) => `
      <div class="faixa ${i === linhas.length - 1 ? 'faixa--ativa' : ''} ${l.ativa ? 'faixa--atual' : ''}">
        <span class="faixa__qtd">${esc(l.rotulo.toUpperCase())}</span>
        <span class="faixa__eco">${l.ativa ? 'FAIXA ATUAL · ' : ''}${l.economia ? 'ECONOMIA ' + l.economia + '%' : 'PREÇO BASE'}</span>
        <span class="faixa__valor">${reais(l.valor)}</span>
      </div>`).join('');
  }

  /* -------------- mensagem pronta para o WhatsApp --------------- */
  function mensagem(qtd, d, atingiu = true) {
    if (!qtd) {
      return `Oi! Estou vendo a ${produto.nome} no site da OVR (ref. ${produto.id}) e queria tirar uma dúvida antes de fechar.`;
    }
    const grade = Object.entries(quantidades)
      .filter(([, n]) => n > 0)
      .map(([t, n]) => `${t} x${n}`)
      .join(', ');
    return [
      atingiu
        ? 'Oi! Quero fechar este pedido na OVR:'
        : `Oi! Sei que o mínimo do site é ${CONFIG.venda.pedidoMinimo} peças, mas queria só ${qtd}. Dá para fazer?`,
      '',
      `PEÇA: ${produto.nome} (${produto.tipo} · ${produto.cor})`,
      `ESTAMPA: ${descreverEstampa()} em DTF`,
      `TAMANHOS: ${grade}`,
      `QUANTIDADE: ${qtd} ${qtd === 1 ? 'peça' : 'peças'} (faixa ${d.faixa.rotulo})`,
      `VALOR: ${reais(d.unitario)} por peça`,
      `TOTAL: ${reais(d.total)}`,
      '',
      `Ref. do site: ${produto.id}`,
      'Mando a arte na sequência.',
    ].join('\n');
  }

  /* ----------------------- peça para busca ----------------------- */
  /* Sem isto, as 151 páginas de peça chegam ao Google com a mesma
     descrição e nenhum dado de produto. São elas que respondem busca
     de cauda longa, do tipo "camiseta oversized preta atacado".
     Canonical e og também moram aqui: a página é uma só e o que muda
     é o id, então tudo que identifica a peça sai do mesmo lugar.   */
  function descreverParaBusca(produto) {
    const preco = Preco.aPartirDe(produto);
    const url = `https://ovrcamisetas.com.br/produto.html?id=${produto.id}`;
    const cor = produto.cor ? `${produto.cor}. ` : '';
    const texto = `${produto.nome} para personalizar em DTF. ${cor}`
                + `Estampa frente inclusa, a partir de ${reais(preco)} por peça. `
                + `O preço cai conforme a quantidade do pedido.`;

    const meta = (seletor, valor, criar) => {
      let n = document.head.querySelector(seletor);
      if (!n && criar) { n = criar(); document.head.appendChild(n); }
      if (n) n.setAttribute(n.tagName === 'LINK' ? 'href' : 'content', valor);
    };
    meta('meta[name="description"]', texto, () => {
      const m = document.createElement('meta'); m.setAttribute('name', 'description'); return m;
    });
    meta('meta[property="og:title"]', `${produto.nome} · OVR`);
    meta('meta[property="og:description"]', texto);
    meta('link[rel="canonical"]', url, () => {
      const l = document.createElement('link'); l.setAttribute('rel', 'canonical'); return l;
    });
    meta('meta[property="og:url"]', url);

    const dados = {
      '@context': 'https://schema.org',
      '@type': 'Product',
      name: produto.nome,
      description: texto,
      sku: String(produto.id),
      color: produto.cor || undefined,
      brand: { '@type': 'Brand', name: 'OVR' },
      offers: {
        '@type': 'Offer',
        url,
        priceCurrency: 'BRL',
        price: preco.toFixed(2),
        availability: 'https://schema.org/InStock',
        seller: { '@type': 'Organization', name: 'OVR' },
      },
    };
    const trilha = {
      '@context': 'https://schema.org',
      '@type': 'BreadcrumbList',
      itemListElement: [
        { '@type': 'ListItem', position: 1, name: 'Catálogo', item: 'https://ovrcamisetas.com.br/catalogo.html' },
        { '@type': 'ListItem', position: 2, name: produto.tipo || 'Peça', item: 'https://ovrcamisetas.com.br/catalogo.html' },
        { '@type': 'ListItem', position: 3, name: produto.nome, item: url },
      ],
    };
    let script = document.getElementById('ovr-dados-produto');
    if (!script) {
      script = document.createElement('script');
      script.type = 'application/ld+json';
      script.id = 'ovr-dados-produto';
      document.head.appendChild(script);
    }
    script.textContent = JSON.stringify([dados, trilha]);
  }

  /* ------------------------- arranque --------------------------- */
  document.addEventListener('DOMContentLoaded', async () => {
    const itens = await carregarCatalogo();
    const id = +new URLSearchParams(location.search).get('id');
    produto = itens.find(p => p.id === id) || itens[0];
    if (!produto) {
      raiz.innerHTML = `<p class="envelope t-corpo" style="padding-block:80px">Peça não encontrada. <a href="catalogo.html" style="text-decoration:underline">Voltar ao catálogo</a>.</p>`;
      return;
    }
    document.title = `${produto.nome} · OVR`;
    descreverParaBusca(produto);
    montar();
    aplicarMarca();

    /* A tabela entra depois porque vem de outro arquivo. Não segura o
       desenho da página: se as medidas demorarem ou faltarem, o resto
       já está de pé e o espaço simplesmente não aparece.             */
    const caixa = raiz.querySelector('[data-medidas-tabela]');
    if (caixa) {
      const dados = await carregarMedidas();
      const tabela = tabelaDeMedidas(dados, produto.tipo, produto.grade || [], produto);
      caixa.innerHTML = medidasHTML(tabela);
    }
  });
})();
