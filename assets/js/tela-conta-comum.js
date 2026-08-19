/* Pedaços que as quatro telas de conta usam. Vive à parte para o
   comportamento de cada uma ficar curto e legível. */
window.ContaTela = (() => {
  'use strict';

  /* Mesma máscara do carrinho. Só exibição: quem valida é o servidor. */
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

  const mascaraCep = v => v.replace(/\D/g, '').slice(0, 8).replace(/^(\d{5})(\d)/, '$1-$2');

  /* Aviso na própria tela, e não alert(): alert trava a página e some
     no toque seguinte, então a pessoa perde o que errou. */
  function aviso(escopo) {
    const el = escopo.querySelector('[data-estado]');
    return (texto, erro = false) => {
      if (!el) return;
      el.hidden = !texto;
      el.textContent = texto || '';
      el.className = 'conta__estado' + (erro ? ' conta__estado--erro' : '');
    };
  }

  /* Botão que não deixa mandar duas vezes. Sem isto, dois cliques
     rápidos criam duas contas ou dois pedidos de recuperação. */
  function comBotao(form) {
    const b = form.querySelector('button[type=submit]');
    const rotulo = b ? b.textContent : '';
    return {
      travar:  () => { if (b) { b.disabled = true;  b.textContent = 'Um instante…'; } },
      soltar:  () => { if (b) { b.disabled = false; b.textContent = rotulo; } },
    };
  }

  const campos = form => Object.fromEntries(
    [...new FormData(form).entries()].map(([k, v]) => [k, String(v).trim()]));

  const reais = c => (c / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  const esc = s => String(s ?? '').replace(/[&<>"']/g, m =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));

  /* Liga a máscara em qualquer campo de documento ou CEP da página. */
  function ligarMascaras(escopo = document) {
    escopo.querySelectorAll('[name=documento]').forEach(i =>
      i.addEventListener('input', () => { i.value = mascaraDoc(i.value); }));
    escopo.querySelectorAll('[name=cep]').forEach(i =>
      i.addEventListener('input', () => { i.value = mascaraCep(i.value); }));
  }

  return { mascaraDoc, mascaraCep, aviso, comBotao, campos, reais, esc, ligarMascaras };
})();
