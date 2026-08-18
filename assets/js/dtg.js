/* =============================================================
   OVR — impressão especial (DTG)
   Desenha as modelagens com o preço já no markup configurado.
   ============================================================= */
document.addEventListener('DOMContentLoaded', async () => {
  await Preco.carregar();
  const alvo = document.querySelector('[data-modelagens]');
  if (!alvo) return;
  const d = CONFIG.dtg;

  alvo.innerHTML = d.modelagens.map(m => {
    const p = Preco.precoDTG(m);
    return `
      <a class="card" data-zap="Oi! Quero um orcamento de impressao especial (DTG) na modelagem ${esc(m.nome)}.">
        <div class="poco poco--contem poco--branco"><img src="assets/img/dtg/${esc(m.img)}?v=${CONFIG.versaoAssets}" alt="${esc(m.nome)}" loading="lazy"></div>
        <span class="card__nome">${esc(m.nome)}</span>
        <span class="t-meta">${esc(m.cores)} cores${m.obs ? ' · ' + esc(m.obs) : ''}</span>
        <span class="card__preco">${reais(p.preco)}<svg viewBox="0 0 14 14" fill="none" aria-hidden="true"><path d="M4 10L10 4M10 4H4.8M10 4V9.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
      </a>`;
  }).join('');

  document.querySelectorAll('[data-etiqueta]').forEach(e => {
    e.textContent = reais(Preco.precoEtiqueta());
  });
  aplicarMarca();   // religa os links de WhatsApp criados agora

  /* As medidas do DTG vêm de outro fornecedor e têm uma coluna a mais
     (ombro). Uma tabela por modelagem seria repetição: várias dividem a
     mesma. Então mostramos as tabelas ÚNICAS, dizendo quais modelagens
     cada uma cobre.                                                    */
  const caixa = document.querySelector('[data-medidas-dtg]');
  if (!caixa) return;
  carregarMedidas().then(dados => {
    const rota = dados._dtgPorModelagem || {};
    const porTabela = {};
    for (const m of d.modelagens) {
      const chave = rota[m.id];
      if (!chave) continue;
      (porTabela[chave] = porTabela[chave] || []).push(m.nome);
    }
    const blocos = Object.entries(porTabela).map(([chave, nomes]) => {
      const t = dados.tabelas?.[chave];
      if (!t) return '';
      return `<div class="medidas-dtg__item">
                <h3>${esc(nomes.join(' · '))}</h3>
                ${medidasHTML(t, { titulo: false })}
              </div>`;
    }).filter(Boolean);

    /* Modelagem sem tabela publicada pelo fornecedor: dizer isso é melhor
       que deixar o cliente procurar uma informação que não existe.     */
    const semTabela = d.modelagens.filter(m => !rota[m.id]).map(m => m.nome);
    caixa.innerHTML = blocos.join('')
      + (semTabela.length
          ? `<p class="t-meta medidas-dtg__falta">${esc(semTabela.join(' e '))}: medidas sob consulta, porque o fornecedor não publica a tabela dessas.</p>`
          : '');
  });
});
