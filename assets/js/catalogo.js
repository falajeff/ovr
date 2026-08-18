/* =============================================================
   OVR — catálogo
   Filtra por tipo e grupo, ordena e desenha a grade.
   O filtro vive na URL, então o link é compartilhável.
   ============================================================= */

(() => {
  let todos = [];
  let filtroTipo = null;
  let filtroGrupo = null;

  const grade   = document.querySelector('[data-grade]');
  const barra   = document.querySelector('[data-filtros]');
  const contag  = document.querySelector('[data-contagem]');
  const vazio   = document.querySelector('[data-vazio]');
  const ordenar = document.querySelector('[data-ordenar]');

  const cartao = p => `
    <a class="card" href="produto.html?id=${+p.id}">
      <div class="poco">${pecaHTML(p)}</div>
      <span class="card__nome">${esc(p.nome)}</span>
      <span class="t-meta">${esc(p.tipo)} · ${esc(p.cor)}</span>
      <span class="card__preco">A partir de ${reais(Preco.aPartirDe(p))}<svg viewBox="0 0 14 14" fill="none" aria-hidden="true"><path d="M4 10L10 4M10 4H4.8M10 4V9.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
    </a>`;

  function filtrados() {
    let l = todos;
    if (filtroTipo)  l = l.filter(p => p.tipo === filtroTipo);
    if (filtroGrupo) l = l.filter(p => p.grupos.includes(filtroGrupo));
    const modo = ordenar?.value || 'preco-asc';
    const chave = p => Preco.aPartirDe(p);
    if (modo === 'preco-asc')  l = [...l].sort((a, b) => chave(a) - chave(b));
    if (modo === 'preco-desc') l = [...l].sort((a, b) => chave(b) - chave(a));
    if (modo === 'nome')       l = [...l].sort((a, b) => a.nome.localeCompare(b.nome, 'pt-BR'));
    return l;
  }

  /* Com "Todas" ligado são 151 peças de 14 tipos numa grade só, e o
     cliente rola sem saber onde está: uma UV, uma infantil, um moletom.
     Aí a grade quebra em blocos por tipo, cada um com sua divisória.
     Com um filtro ativo não faz sentido — todas as peças são do mesmo
     tipo e a divisória seria uma faixa só, repetindo o chip aceso.     */
  function desenharAgrupado(l) {
    const ordemTipos = [...new Set(l.map(p => p.tipo))]
      .map(t => ({ t, n: l.filter(p => p.tipo === t).length }))
      .sort((a, b) => b.n - a.n || a.t.localeCompare(b.t, 'pt-BR'));

    grade.innerHTML = ordemTipos.map(({ t, n }) => `
      <div class="grupo__titulo">
        <h2>${esc(t)}</h2>
        <span class="t-meta">${n} ${n === 1 ? 'peça' : 'peças'}</span>
      </div>
      <div class="grade-produtos grupo__grade">
        ${l.filter(p => p.tipo === t).map(cartao).join('')}
      </div>`).join('');
  }

  function desenhar() {
    const l = filtrados();
    const agrupar = !filtroTipo && !filtroGrupo && l.length > 0;
    grade.classList.toggle('grade-produtos', !agrupar);
    grade.classList.toggle('grade-produtos--agrupada', agrupar);
    if (agrupar) desenharAgrupado(l);
    else grade.innerHTML = l.map(cartao).join('');
    contag.textContent = `${l.length} ${l.length === 1 ? 'peça' : 'peças'}`;
    vazio.hidden = l.length > 0;
    grade.hidden = l.length === 0;
    barra.querySelectorAll('[data-chip]').forEach(c => {
      const ativo = (c.dataset.tipo || null) === filtroTipo && (c.dataset.grupo || null) === filtroGrupo;
      c.classList.toggle('chip--ativo', ativo);
    });
    const u = new URL(location.href);
    filtroTipo ? u.searchParams.set('tipo', filtroTipo) : u.searchParams.delete('tipo');
    filtroGrupo ? u.searchParams.set('grupo', filtroGrupo) : u.searchParams.delete('grupo');
    history.replaceState(null, '', u);
  }

  function montarFiltros() {
    const tipos = [...new Set(todos.map(p => p.tipo))]
      .map(t => ({ t, n: todos.filter(p => p.tipo === t).length }))
      .sort((a, b) => b.n - a.n)
      .filter(x => x.n >= 3);
    const grupos = ['Feminino', 'Plus Size', 'Infantil'].filter(g => todos.some(p => p.grupos.includes(g)));

    const chip = (rot, attrs) => `<button class="chip" data-chip ${attrs}>${rot}</button>`;
    barra.innerHTML =
      chip('Todas', '') +
      tipos.map(x => chip(esc(x.t), `data-tipo="${esc(x.t)}"`)).join('') +
      grupos.map(g => chip(esc(g), `data-grupo="${esc(g)}"`)).join('');

    barra.addEventListener('click', e => {
      const c = e.target.closest('[data-chip]');
      if (!c) return;
      filtroTipo = c.dataset.tipo || null;
      filtroGrupo = c.dataset.grupo || null;
      desenhar();
    });
  }

  /* A fila de chips é mais larga que a tela. No celular o dedo já rolava,
     mas no mouse não havia como andar: clicar e arrastar não fazia nada,
     e a roda só rola na vertical. Aqui entram os três caminhos que o
     mouse precisa — arrastar, roda e a sombra que avisa que continua. */
  function iniciarRolagemLateral() {
    const caixa = barra.parentElement;
    let arrastando = false, partiuEm = 0, rolagemInicial = 0, andou = 0;

    const esq = caixa.querySelector('[data-sombra="esq"]');
    const dir = caixa.querySelector('[data-sombra="dir"]');

    const sombras = () => {
      const sobra = barra.scrollWidth - barra.clientWidth;
      if (esq) esq.style.opacity = barra.scrollLeft > 1 ? '1' : '0';
      if (dir) dir.style.opacity = barra.scrollLeft < sobra - 1 ? '1' : '0';
    };

    const mover = e => {
      const d = e.clientX - partiuEm;
      andou = Math.max(andou, Math.abs(d));
      barra.scrollLeft = rolagemInicial - d;
      sombras();
    };
    const soltar = () => {
      arrastando = false;
      barra.classList.remove('arrastando');
      removeEventListener('pointermove', mover);
      removeEventListener('pointerup', soltar);
      /* O clique do arrasto nasce logo depois deste pointerup, na mesma
         rodada — então o selo tem que sobreviver a ele e sumir no tique
         seguinte. Se ficasse ligado, o próximo Enter num chip com foco
         seria engolido, e teclado não arrasta nada.                    */
      if (andou > 5) setTimeout(() => { andou = 0; }, 0);
      else andou = 0;
    };

    /* Só no mouse: no toque o navegador já rola sozinho, com inércia, e
       entrar no meio disso só piora.                                    */
    barra.addEventListener('pointerdown', e => {
      if (e.pointerType !== 'mouse' || e.button !== 0) return;
      arrastando = true; andou = 0;
      partiuEm = e.clientX; rolagemInicial = barra.scrollLeft;
      barra.classList.add('arrastando');
      addEventListener('pointermove', mover);
      addEventListener('pointerup', soltar);
    });

    /* Todo arrasto termina num clique. Sem isto, largar em cima de um chip
       trocaria o filtro sem querer. Na captura, para o clique nem chegar
       ao chip.                                                           */
    barra.addEventListener('click', e => {
      if (andou > 5) { e.stopPropagation(); e.preventDefault(); }
    }, true);

    /* Roda vertical vira rolagem lateral: mouse comum não tem eixo X.
       Se já veio no eixo X (trackpad), deixa o navegador cuidar.        */
    barra.addEventListener('wheel', e => {
      if (Math.abs(e.deltaY) <= Math.abs(e.deltaX)) return;
      if (barra.scrollWidth - barra.clientWidth < 1) return;
      e.preventDefault();
      barra.scrollLeft += e.deltaY;
      sombras();
    }, { passive: false });

    /* O evento de scroll cobre o dedo e o teclado; quem move a barra por
       código chama `sombras()` na mão, porque rolagem programática nem
       sempre dispara o evento.                                          */
    barra.addEventListener('scroll', sombras, { passive: true });
    addEventListener('resize', sombras);
    sombras();
  }

  document.addEventListener('DOMContentLoaded', async () => {
    todos = await carregarCatalogo();
    document.querySelectorAll('[data-total-bases]').forEach(e => { e.textContent = todos.length; });

    const q = new URLSearchParams(location.search);
    filtroTipo = q.get('tipo');
    filtroGrupo = q.get('grupo');

    montarFiltros();
    iniciarRolagemLateral();
    desenhar();
    ordenar?.addEventListener('change', desenhar);
    document.querySelector('[data-limpar]')?.addEventListener('click', () => {
      filtroTipo = filtroGrupo = null; desenhar();
    });
  });
})();
