/* Tela de escolher senha nova, aberta pelo link do e-mail. */
(() => {
  'use strict';
  const { aviso, comBotao, campos } = ContaTela;

  const form = document.querySelector('[data-form-nova]');
  const diz = aviso(document);
  const botao = comBotao(form);

  const token = new URLSearchParams(location.search).get('t') || '';

  /* Sem token não há o que fazer. Dizer isso agora é melhor que deixar
     a pessoa digitar a senha duas vezes para só então recusar. */
  if (!token) {
    form.hidden = true;
    diz('Esse link está incompleto. Peça um novo na tela de entrar.', true);
  }

  form.addEventListener('submit', async ev => {
    ev.preventDefault();
    const { senha, senha2 } = campos(form);

    if (senha.length < CONFIG.conta.senhaMinima) {
      form.querySelector('[name=senha]').focus();
      return diz(`A senha precisa de pelo menos ${CONFIG.conta.senhaMinima} caracteres.`, true);
    }
    if (senha !== senha2) {
      form.querySelector('[name=senha2]').focus();
      return diz('As duas senhas não são iguais.', true);
    }

    diz('');
    botao.travar();
    try {
      await Conta.novaSenha(token, senha);
      /* O servidor já abre a sessão, então não faz sentido pedir a
         senha que a pessoa acabou de escolher. */
      location.replace('conta.html');
    } catch (e) {
      botao.soltar();
      diz(e.message, true);
    }
  });
})();
