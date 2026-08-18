/* =============================================================
   OVR — filme DTF avulso
   O cliente pensa em arte, o fornecedor vende metro. Esta tela
   traduz uma coisa na outra e mostra o aproveitamento da folha.
   ============================================================= */

(() => {
  const raiz = document.querySelector('[data-bloco="arte"]')?.closest('.filme');
  if (!raiz) return;

  const F = CONFIG.filme;
  const $  = s => raiz.querySelector(s);
  const $$ = s => raiz.querySelectorAll(s);

  let modo = 'arte';
  const arte = { larg: 28, alt: 35, qtd: 10 };
  let metros = 5;

  /* ------------------------- desenho ---------------------------- */
  $('[data-metragens]').innerHTML = F.metragens.map(m => `
    <button class="opcao" data-metro="${m}" aria-pressed="${m === metros}">${m} m</button>`).join('');

  $$('[data-modo]').forEach(b => b.addEventListener('click', () => {
    modo = b.dataset.modo;
    $$('[data-modo]').forEach(o => o.setAttribute('aria-pressed', String(o === b)));
    raiz.querySelector('[data-bloco="arte"]').hidden  = modo !== 'arte';
    raiz.querySelector('[data-bloco="metro"]').hidden = modo !== 'metro';
    atualizar();
  }));

  $$('[data-arte]').forEach(i => {
    const mexer = () => {
      const campo = i.dataset.arte;
      const min = campo === 'qtd' ? 1 : CONFIG.estampas.minCm;
      /* a largura não pode passar da largura útil do filme: fisicamente
         não cabe, por mais que o cliente digite.                      */
      const max = campo === 'larg' ? CONFIG.estampas.limiteLargCm
                : campo === 'alt'  ? 100          // um metro de comprimento
                : 9999;
      const v = Math.min(max, Math.max(min, Math.round(+i.value || 0)));
      i.value = v; arte[campo] = v;
      atualizar();
    };
    i.addEventListener('change', mexer);
    i.addEventListener('blur', mexer);
  });

  $$('[data-metro]').forEach(b => b.addEventListener('click', () => {
    metros = +b.dataset.metro;
    $$('[data-metro]').forEach(o => o.setAttribute('aria-pressed', String(o === b)));
    atualizar();
  }));

  /* ------------------------ recálculo --------------------------- */
  function atualizar() {
    const r = modo === 'arte'
      ? Preco.filmePorArte(arte.larg, arte.alt, arte.qtd)
      : { ...Preco.precoFilmeMetro(metros), qtd: null };

    const linhas = modo === 'arte' ? [
      ['Artes', `${r.qtd} de ${arte.larg}×${arte.alt} cm`],
      ['Cabem por metro', `${r.porMetro}`],
      ['Metragem', `${r.metros} m`],
      ['Aproveitamento da folha', `${r.aproveitamento}%`],
      ['Preço por arte', reais(r.porArte)],
    ] : [
      ['Metragem', `${r.metros} m`],
      ['Preço por metro', reais(r.porMetro)],
    ];

    /* O envio não está no preço do filme: o fornecedor manda direto para
       o cliente e o valor muda com o estado. Dizer isso aqui, no total,
       evita a surpresa depois — e mostra onde ele não paga nada.       */
    const fg = CONFIG.filme.freteGratis;
    const gratis = Preco.filmeTemFreteGratis(r.metros);
    const linhaFrete = gratis
      ? `<div class="resumo__linha"><span>Frete</span><strong>Grátis para ${fg.estados.join(' e ')}</strong></div>`
      : `<div class="resumo__linha"><span>Frete</span><strong>À combinar</strong></div>`;

    $('[data-resumo]').innerHTML = linhas.map(([k, v]) => `
      <div class="resumo__linha"><span>${esc(k)}</span><strong>${esc(v)}</strong></div>`).join('')
      + linhaFrete
      + `<div class="resumo__linha resumo__total"><span>Total</span><strong>${reais(r.total)}</strong></div>`
      + `<p class="t-meta" style="margin-top:2px;text-transform:none;letter-spacing:0">`
      + (gratis
          ? `Envio por nossa conta em ${fg.estados.join(' e ')}. Outros estados, a gente cota junto com o pedido.`
          : `O envio é cobrado à parte, conforme o seu estado. A partir de ${fg.apartirDeMetros} m ele sai grátis para ${fg.estados.join(' e ')}.`)
      + `</p>`;

    /* A sobra é argumento de venda: mostra que encher a folha barateia
       a arte, em vez de esconder que ele está pagando espaço vazio.  */
    const sobra = modo === 'arte' && r.sobra > 0
      ? ` Ainda cabem ${r.sobra} arte${r.sobra > 1 ? 's' : ''} nesse mesmo metro, sem custo a mais.`
      : '';

    const botao = $('[data-fechar]');
    botao.href = linkZap(mensagem(r));
    botao.target = '_blank'; botao.rel = 'noopener';

    const nota = raiz.querySelector('[data-sobra]') || (() => {
      const p = document.createElement('p');
      p.className = 't-meta'; p.dataset.sobra = '';
      p.style.marginTop = '10px';
      $('[data-resumo]').after(p);
      return p;
    })();
    nota.textContent = sobra.trim();

    desenharEscada();
  }

  /* A escada mostra a metragem, mas o preço por metro é o MESMO em toda
     linha: sem o frete embutido não existe desconto por volume aqui.
     Anunciar "economia 0%" seria pior que não anunciar nada, então o
     que aparece é o que muda de verdade — o frete que passa a ser
     grátis. Quando o fornecedor der faixa por volume, a economia volta
     e esta função calcula de novo sozinha.                            */
  function desenharEscada() {
    const atual = modo === 'arte' ? Preco.filmePorArte(arte.larg, arte.alt, arte.qtd).metros : metros;
    const base = Preco.precoFilmeMetro(1).porMetro;
    const fg = CONFIG.filme.freteGratis;
    $('[data-escada]').innerHTML = F.metragens.map(m => {
      const p = Preco.precoFilmeMetro(m);
      const eco = Math.round((1 - p.porMetro / base) * 100);
      const selo = eco > 0 ? 'ECONOMIA ' + eco + '%'
                 : Preco.filmeTemFreteGratis(m) ? 'FRETE GRÁTIS ' + fg.estados.join('/')
                 : 'FRETE À PARTE';
      return `
        <div class="faixa ${m === atual ? 'faixa--ativa' : ''}">
          <span class="faixa__qtd">${m} METRO${m > 1 ? 'S' : ''}</span>
          <span class="faixa__eco">${m === atual ? 'SEU PEDIDO · ' : ''}${selo}</span>
          <span class="faixa__valor">${reais(p.porMetro)}<small style="display:block;font-size:11px;opacity:.6">por metro</small></span>
        </div>`;
    }).join('');
  }

  /* -------------- mensagem pronta para o WhatsApp --------------- */
  function mensagem(r) {
    const corpo = modo === 'arte'
      ? [`ARTE: ${arte.qtd}x de ${arte.larg}×${arte.alt} cm`,
         `METRAGEM: ${r.metros} m (cabem ${r.porMetro} por metro)`,
         `VALOR POR ARTE: ${reais(r.porArte)}`]
      : [`METRAGEM: ${r.metros} m`,
         `VALOR POR METRO: ${reais(r.porMetro)}`];
    return ['Oi! Quero pedir filme DTF impresso na OVR:', '', ...corpo,
            `TOTAL: ${reais(r.total)}`, '', 'Mando a arte na sequência.'].join('\n');
  }

  document.addEventListener('DOMContentLoaded', async () => {
  await Preco.carregar();
    const p = document.querySelector('[data-prazo]');
    if (p) p.textContent = `${F.prazoDias} dias úteis`;
    atualizar();
    aplicarMarca();
  });
})();
