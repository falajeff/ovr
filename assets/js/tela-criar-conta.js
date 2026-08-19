/* Tela de criar conta. */
(() => {
  'use strict';
  const { aviso, comBotao, campos, ligarMascaras } = ContaTela;

  Conta.eu().then(c => { if (c) location.replace('conta.html'); });
  ligarMascaras();

  const form = document.querySelector('[data-form-criar]');
  const diz = aviso(form.closest('[data-bloco]'));
  const botao = comBotao(form);

  /* Mesma conta do servidor, repetida aqui só para o erro aparecer
     antes de a pessoa apertar o botão. Quem decide continua sendo o
     servidor: se este trecho fosse burlado, o cadastro seria recusado
     lá do mesmo jeito. */
  const dv = (base, pesos) => {
    let s = 0;
    for (let i = 0; i < base.length; i++) s += (base.charCodeAt(i) - 48) * pesos[i];
    const r = s % 11;
    return String(r < 2 ? 0 : 11 - r);
  };
  function docValido(bruto) {
    const d = String(bruto || '').replace(/[^0-9A-Za-z]/g, '').toUpperCase();
    if (d.length === 11) {
      if (!/^\d{11}$/.test(d) || /^(\d)\1{10}$/.test(d)) return false;
      return d[9]  === dv(d.slice(0, 9),  [10, 9, 8, 7, 6, 5, 4, 3, 2])
          && d[10] === dv(d.slice(0, 10), [11, 10, 9, 8, 7, 6, 5, 4, 3, 2]);
    }
    if (d.length === 14) {
      if (!/^[0-9A-Z]{12}\d{2}$/.test(d) || /^(.)\1{13}$/.test(d)) return false;
      return d[12] === dv(d.slice(0, 12), [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2])
          && d[13] === dv(d.slice(0, 13), [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
    }
    return false;
  }

  form.addEventListener('submit', async ev => {
    ev.preventDefault();
    const d = campos(form);
    const foco = nome => form.querySelector(`[name="${nome}"]`)?.focus();

    if (!d.nome)                    { foco('nome');      return diz('Informe seu nome ou o da empresa.', true); }
    if (!d.email.includes('@'))     { foco('email');     return diz('Confira o e-mail.', true); }
    if (!docValido(d.documento))    { foco('documento'); return diz('Esse CPF ou CNPJ não existe. Confira os números.', true); }
    if (d.senha.length < CONFIG.conta.senhaMinima) {
      foco('senha');
      return diz(`A senha precisa de pelo menos ${CONFIG.conta.senhaMinima} caracteres.`, true);
    }

    diz('');
    botao.travar();
    try {
      const r = await Conta.criar(d);
      /* Quando o e-mail já existe, o servidor responde a mesma coisa e
         manda um e-mail explicando. A tela precisa combinar com isso:
         se dissesse "já existe", o cadastro viraria um verificador de
         quem é cliente da loja. */
      if (r.confiraEmail) {
        document.querySelectorAll('[data-bloco]').forEach(b => { b.hidden = b.dataset.bloco !== 'confira'; });
        return;
      }
      location.replace('conta.html?novo=1');
    } catch (e) {
      botao.soltar();
      diz(e.message, true);
    }
  });
})();
