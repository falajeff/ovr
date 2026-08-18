/* =============================================================
   OVR — criação de arte
   Desenha os níveis de serviço a partir do config, para o preço
   viver num lugar só e não espalhado pelo HTML.
   ============================================================= */

(() => {
  const alvo = document.querySelector('[data-niveis]');
  if (!alvo) return;

  const S = CONFIG.arte_servico;

  /* "R$ 250 a R$ 450" ou "a partir de R$ 600" quando não tem teto —
     ilustração depende do orçamento do parceiro.                    */
  const faixa = n => n.ate ? `${reaisCurto(n.de)} a ${reaisCurto(n.ate)}` : `a partir de ${reaisCurto(n.de)}`;

  alvo.innerHTML = S.niveis.map(n => `
    <a class="card" data-zap="Oi! Quero um orcamento de ${esc(n.nome)} na OVR.">
      <span class="card__nome">${esc(n.nome)}</span>
      <span class="t-corpo" style="font-size:13px; min-height:5.4em">${esc(n.desc)}</span>
      <span class="t-meta">${esc(n.prazo)}${n.quemFaz === 'parceiro' ? ' · ilustrador parceiro' : ''}</span>
      <span class="card__preco">${faixa(n)}
        <svg viewBox="0 0 14 14" fill="none" aria-hidden="true"><path d="M4 10L10 4M10 4H4.8M10 4V9.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </span>
    </a>`).join('');

  const nota = document.querySelector('[data-cortesia]');
  if (nota) {
    nota.textContent = S.cortesiaAcimaDe
      ? `Em pedidos acima de ${S.cortesiaAcimaDe} peças, a composição tipográfica e a estampa autoral entram sem custo. Nesse volume a arte se paga na própria produção. Ilustração e ajuste de arquivo seguem cobrados à parte.`
      : '';
  }

  document.addEventListener('DOMContentLoaded', aplicarMarca);
})();
