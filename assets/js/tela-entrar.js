/* Tela de entrar, com o "esqueci a senha" no mesmo lugar.

   Os dois blocos vivem na mesma página e um esconde o outro. Mandar a
   pessoa para outra URL só para pedir o e-mail dela quebra o fluxo de
   quem só errou a senha. */
(() => {
  'use strict';
  const { aviso, comBotao, campos } = ContaTela;
  const q = s => document.querySelector(s);

  /* Quem já está logado não tem o que fazer aqui. */
  Conta.eu().then(c => { if (c) location.replace('conta.html'); });

  const mostrar = nome => {
    document.querySelectorAll('[data-bloco]').forEach(b => { b.hidden = b.dataset.bloco !== nome; });
    document.querySelector(`[data-bloco="${nome}"] input`)?.focus();
  };
  document.querySelectorAll('[data-ir]').forEach(b =>
    b.addEventListener('click', () => mostrar(b.dataset.ir)));

  /* --- entrar --- */
  const fEntrar = q('[data-form-entrar]');
  const dizEntrar = aviso(fEntrar.closest('[data-bloco]'));
  const botaoEntrar = comBotao(fEntrar);

  fEntrar.addEventListener('submit', async ev => {
    ev.preventDefault();
    const { email, senha } = campos(fEntrar);
    if (!email || !senha) return dizEntrar('Preencha o e-mail e a senha.', true);

    dizEntrar('');
    botaoEntrar.travar();
    try {
      await Conta.entrar(email, senha);
      /* `replace` e não `href`: voltar para a tela de entrar depois de
         entrar não faz sentido e confunde. */
      const volta = new URLSearchParams(location.search).get('volta');
      location.replace(volta && volta.startsWith('/') === false && !volta.includes('//') ? volta : 'conta.html');
    } catch (e) {
      botaoEntrar.soltar();
      dizEntrar(e.message, true);
    }
  });

  /* --- esqueci --- */
  const fEsqueci = q('[data-form-esqueci]');
  const dizEsqueci = aviso(fEsqueci.closest('[data-bloco]'));
  const botaoEsqueci = comBotao(fEsqueci);

  fEsqueci.addEventListener('submit', async ev => {
    ev.preventDefault();
    const { email } = campos(fEsqueci);
    if (!email) return dizEsqueci('Informe o e-mail da conta.', true);

    dizEsqueci('');
    botaoEsqueci.travar();
    try {
      await Conta.esqueci(email);
      /* A resposta é a mesma exista o e-mail ou não, e o texto aqui
         precisa combinar com isso. Dizer "enviamos" quando o e-mail
         não existe seria mentira; dizer "se existir" conta a verdade
         sem entregar quem é cliente. */
      fEsqueci.hidden = true;
      dizEsqueci('Se esse e-mail tiver conta aqui, o link já está a caminho. Ele vale por 30 minutos.');
    } catch (e) {
      botaoEsqueci.soltar();
      dizEsqueci(e.message, true);
    }
  });
})();
