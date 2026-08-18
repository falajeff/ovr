/* =============================================================
   OVR — home
   Escolhe as peças em destaque e desenha a escada de preço.
   Fica em arquivo separado para o site poder rodar com uma
   política de conteúdo que proíbe script embutido.
   ============================================================= */

/* ---------- destaques da home ---------- */
const DESTAQUES = [289, 262, 286];  // ids substituídos abaixo pelos mais vendáveis
document.addEventListener('DOMContentLoaded', async () => {
  const alvo = document.querySelector('[data-destaques]');
  const escadaAlvo = document.querySelector('[data-escada-vitrine]');
  const itens = await carregarCatalogo();

  /* escolhe quatro peças de tipos diferentes, com estoque */
  const querer = ['Oversized', 'Gola Polo', 'Moletom', 'Básica'];
  const destaques = querer
    .map(t => itens.filter(p => p.tipo === t && p.estoque > 20).sort((a, b) => b.estoque - a.estoque)[0])
    .filter(Boolean);

  alvo.innerHTML = destaques.map(p => `
    <a class="card" href="produto.html?id=${+p.id}">
      <div class="poco">${pecaHTML(p)}</div>
      <span class="card__nome">${esc(p.nome)}</span>
      <span class="t-meta">${esc(p.tipo)} · ${esc(p.cor)}</span>
      <span class="card__preco">A partir de ${reais(Preco.aPartirDe(p))}<svg viewBox="0 0 14 14" fill="none" aria-hidden="true"><path d="M4 10L10 4M10 4H4.8M10 4V9.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
    </a>`).join('');

  /* Escada da vitrine sobre a básica representativa: o preço que mais
     se repete na categoria, não a ponta mais barata do catálogo. */
  /* Escolhia pelo precoBase, que era o custo do fornecedor e saiu do
     catálogo público. Agora escolhe pelo preço de venda, que é o número
     que o visitante enxerga e serve igual de critério de "peça típica". */
  const basicas = itens.filter(p => p.tipo === 'Básica');
  const contagem = {};
  basicas.forEach(p => { const v = Preco.aPartirDe(p); if (v > 0) contagem[v] = (contagem[v] || 0) + 1; });
  const ranking = Object.entries(contagem).sort((a, b) => b[1] - a[1]);
  const precoComum = ranking.length ? +ranking[0][0] : 0;
  const referencia = basicas.find(p => Preco.aPartirDe(p) === precoComum);

  if (referencia && escadaAlvo) {
    const linhas = Preco.escada(referencia);
    escadaAlvo.innerHTML = linhas.map((l, i) => `
      <div class="faixa ${i === linhas.length - 1 ? 'faixa--ativa' : ''}">
        <span class="faixa__qtd">${esc(l.rotulo.toUpperCase())}</span>
        <span class="faixa__eco">${l.economia ? 'ECONOMIA ' + l.economia + '%' : 'PREÇO BASE'}</span>
        <span class="faixa__valor">${reais(l.valor)}</span>
      </div>`).join('');
  }
});
