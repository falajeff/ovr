<?php
/* Conta do cliente.
 *
 * O WordPress entra aqui como cofre de senha e banco de dados, e só.
 * O cliente NUNCA vê wp-login nem wp-admin: as telas são do site, e a
 * conversa é por REST.
 *
 * Por que WordPress e não uma tabela nossa: guardar senha direito é
 * mais difícil do que parece — algoritmo de hash, custo, atualização
 * quando o algoritmo envelhece. O WordPress faz isso há vinte anos e
 * erra menos que a gente erraria escrevendo do zero.
 *
 * O que NÃO reaproveitamos é o fluxo de "esqueci a senha": o e-mail
 * dele aponta para wp-login.php, que é exatamente a tela que o cliente
 * não pode ver. Token próprio, e-mail próprio, página no site.
 *
 * A sessão é cookie HttpOnly no domínio de segundo nível, para valer
 * nos dois subdomínios. Não é localStorage de propósito: qualquer XSS
 * lê localStorage, e nenhum lê cookie HttpOnly.                       */
if (!defined('ABSPATH')) exit;

const OVR_PAPEL_CLIENTE  = 'ovr_cliente';
const OVR_COOKIE_SESSAO  = 'ovr_sessao';
const OVR_SESSAO_DIAS    = 30;
const OVR_SENHA_MINIMA   = 8;
const OVR_TENTATIVAS_MAX = 8;      // por hora, por IP
const OVR_RESET_MINUTOS  = 30;

/* ------------------------------------------------------------------
   O papel

   Zero capacidade, nem `read`. Cliente não tem nada para fazer no
   wp-admin, e um papel sem capacidade é a forma mais curta de garantir
   isso — não depende de lembrarmos de bloquear cada tela.            */
function ovr_criar_papel_cliente() {
    if (!get_role(OVR_PAPEL_CLIENTE)) add_role(OVR_PAPEL_CLIENTE, 'Cliente OVR', []);
}
add_action('init', 'ovr_criar_papel_cliente');

function ovr_eh_cliente($user): bool {
    $u = is_numeric($user) ? get_userdata((int) $user) : $user;
    return $u instanceof WP_User && in_array(OVR_PAPEL_CLIENTE, (array) $u->roles, true);
}

/* Cinto e suspensório: se um cliente cair no wp-admin por link antigo
   ou por engano, volta para a loja em vez de ver a cara do WordPress. */
add_action('admin_init', function () {
    if (ovr_eh_cliente(wp_get_current_user()) && !wp_doing_ajax()) {
        wp_safe_redirect('https://ovrcamisetas.com.br/conta.html');
        exit;
    }
});
add_filter('show_admin_bar', fn($m) => ovr_eh_cliente(wp_get_current_user()) ? false : $m);

/* ------------------------------------------------------------------
   Sessão

   O que viaja no cookie é um token aleatório. O que fica guardado é o
   SHA-256 dele: quem ler o banco não consegue montar um cookie válido,
   do mesmo jeito que um hash de senha não devolve a senha.           */
function ovr_sessao_abrir(int $user_id): string {
    $token = bin2hex(random_bytes(32));
    set_transient('ovr_s_' . hash('sha256', $token), $user_id, OVR_SESSAO_DIAS * DAY_IN_SECONDS);
    ovr_sessao_cookie($token, time() + OVR_SESSAO_DIAS * DAY_IN_SECONDS);
    return $token;
}

function ovr_sessao_cookie(string $valor, int $expira) {
    setcookie(OVR_COOKIE_SESSAO, $valor, [
        'expires'  => $expira,
        'path'     => '/',
        /* O ponto na frente é o que faz o cookie valer na loja E no
           painel. Sem ele a sessão nasce presa ao subdomínio que a
           criou e o site nunca enxerga o login. */
        'domain'   => '.ovrcamisetas.com.br',
        'secure'   => true,
        'httponly' => true,
        /* Lax e não None: loja e painel são o mesmo site registrável,
           então o cookie viaja entre eles. None exigiria abrir para
           qualquer site de terceiro, que é como se monta CSRF. */
        'samesite' => 'Lax',
    ]);
}

function ovr_sessao_fechar() {
    $t = $_COOKIE[OVR_COOKIE_SESSAO] ?? '';
    if ($t) delete_transient('ovr_s_' . hash('sha256', $t));
    ovr_sessao_cookie('', time() - 3600);
}

/* Devolve o WP_User da sessão, ou null. */
function ovr_sessao_usuario(): ?WP_User {
    $t = $_COOKIE[OVR_COOKIE_SESSAO] ?? '';
    if (!$t) return null;
    $id = get_transient('ovr_s_' . hash('sha256', $t));
    if (!$id) return null;
    $u = get_userdata((int) $id);
    return ovr_eh_cliente($u) ? $u : null;
}

/* ------------------------------------------------------------------
   Portas comuns a toda chamada de conta                              */
function ovr_conta_porta(WP_REST_Request $req) {
    /* NADA daqui pode ser cacheado. Toda rota de conta responde uma
       coisa diferente para cada pessoa, e um cache na frente delas
       entrega o nome, o e-mail, o CPF e o endereço de um cliente para o
       próximo visitante.

       Isto foi encontrado em produção: o LiteSpeed da Hostinger estava
       devolvendo `x-litespeed-cache: hit` para /conta/eu. O que estava
       guardado era a resposta deslogada, inofensiva por sorte, mas a
       mesma regra guardaria uma resposta logada.

       Três cabeçalhos porque são três camadas: o padrão HTTP, o do
       LiteSpeed, que ignora o primeiro em algumas configurações, e o do
       WordPress, que também marca a resposta como privada. */
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('X-LiteSpeed-Cache-Control: no-cache, no-store');
    header('Pragma: no-cache');
    if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);

    $origem = $req->get_header('origin') ?: '';
    if (!in_array($origem, ovr_origens_permitidas(), true)) {
        return ovr_erro('ovr_origem', 'Origem não autorizada.', 403);
    }
    return true;
}

/* Cadência por IP. Vale para entrar, criar e recuperar: as três são
   porta de entrada e as três são alvo de tentativa em massa.         */
function ovr_conta_ritmo(string $balde): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0';
    $k  = 'ovr_r_' . $balde . '_' . md5($ip);
    $n  = (int) get_transient($k);
    if ($n >= OVR_TENTATIVAS_MAX) return false;
    set_transient($k, $n + 1, HOUR_IN_SECONDS);
    return true;
}

/* O retrato que o site recebe. Nunca inclui hash de senha nem papel:
   o navegador não precisa e não deve saber.                          */
function ovr_conta_retrato(WP_User $u): array {
    return [
        'id'        => $u->ID,
        'nome'      => $u->display_name,
        'email'     => $u->user_email,
        'zap'       => (string) get_user_meta($u->ID, '_ovr_zap', true),
        'documento' => (string) get_user_meta($u->ID, '_ovr_documento', true),
        'endereco'  => ovr_conta_endereco($u->ID),
    ];
}

function ovr_conta_endereco(int $id): array {
    $e = get_user_meta($id, '_ovr_endereco', true);
    $vazio = ['cep' => '', 'rua' => '', 'numero' => '', 'complemento' => '',
              'bairro' => '', 'cidade' => '', 'uf' => ''];
    return is_array($e) ? array_merge($vazio, $e) : $vazio;
}

/* ------------------------------------------------------------------
   As rotas                                                           */
add_action('rest_api_init', function () {
    $rota = function ($caminho, $callback, $metodo = 'POST') {
        register_rest_route('ovr/v1', $caminho, [
            'methods'             => $metodo,
            'callback'            => $callback,
            'permission_callback' => 'ovr_conta_porta',
        ]);
    };
    $rota('/conta/criar',       'ovr_conta_criar');
    $rota('/conta/entrar',      'ovr_conta_entrar');
    $rota('/conta/sair',        'ovr_conta_sair');
    $rota('/conta/eu',          'ovr_conta_eu', 'GET');
    $rota('/conta/dados',       'ovr_conta_dados');
    $rota('/conta/endereco',    'ovr_conta_salvar_endereco');
    $rota('/conta/pedidos',     'ovr_conta_pedidos', 'GET');
    $rota('/conta/esqueci',     'ovr_conta_esqueci');
    $rota('/conta/nova-senha',  'ovr_conta_nova_senha');
});

/* --- criar --------------------------------------------------------- */
function ovr_conta_criar(WP_REST_Request $req) {
    if (!ovr_conta_ritmo('criar')) return ovr_erro('ovr_ritmo', 'Muitas tentativas. Tente daqui a pouco.', 429);

    $d     = $req->get_json_params() ?: [];
    $nome  = sanitize_text_field($d['nome'] ?? '');
    $email = sanitize_email($d['email'] ?? '');
    $senha = (string) ($d['senha'] ?? '');
    $doc   = ovr_documento_normalizar($d['documento'] ?? '');

    if ($nome === '')                    return ovr_erro('ovr_nome', 'Informe seu nome.');
    if (!is_email($email))               return ovr_erro('ovr_email', 'E-mail inválido.');
    if (strlen($senha) < OVR_SENHA_MINIMA) return ovr_erro('ovr_senha', 'A senha precisa de pelo menos ' . OVR_SENHA_MINIMA . ' caracteres.');
    if (!$doc)                           return ovr_erro('ovr_documento', 'Informe um CPF ou CNPJ válido.');

    /* E-mail já cadastrado NÃO devolve erro diferente. Um "esse e-mail
       já existe" transforma o cadastro em consulta: dá para descobrir
       quem é cliente da loja testando endereços. Respondemos o mesmo
       dos dois lados e mandamos um e-mail contando o que houve. */
    if (email_exists($email)) {
        ovr_conta_avisar_duplicado($email);
        return ['ok' => true, 'confira_email' => true];
    }

    $id = wp_insert_user([
        'user_login'   => $email,
        'user_email'   => $email,
        'user_pass'    => $senha,
        'display_name' => $nome,
        'first_name'   => $nome,
        'role'         => OVR_PAPEL_CLIENTE,
    ]);
    if (is_wp_error($id)) return ovr_erro('ovr_criar', 'Não consegui criar a conta agora.', 500);

    update_user_meta($id, '_ovr_documento', $doc['numero']);
    update_user_meta($id, '_ovr_doc_hash', ovr_doc_hash($doc['numero']));
    update_user_meta($id, '_ovr_zap', sanitize_text_field($d['zap'] ?? ''));

    ovr_conta_email_bemvindo(get_userdata($id));
    ovr_sessao_abrir($id);
    return ['ok' => true, 'conta' => ovr_conta_retrato(get_userdata($id))];
}

/* --- entrar -------------------------------------------------------- */
function ovr_conta_entrar(WP_REST_Request $req) {
    if (!ovr_conta_ritmo('entrar')) return ovr_erro('ovr_ritmo', 'Muitas tentativas. Tente daqui a pouco.', 429);

    $d     = $req->get_json_params() ?: [];
    $email = sanitize_email($d['email'] ?? '');
    $senha = (string) ($d['senha'] ?? '');

    $u = get_user_by('email', $email);

    /* Mensagem única para e-mail que não existe e para senha errada.
       Duas mensagens diferentes contam ao atacante quando ele acertou
       metade do par. */
    $recusa = ovr_erro('ovr_login', 'E-mail ou senha não conferem.', 401);
    if (!$u || !ovr_eh_cliente($u))            return $recusa;
    if (!wp_check_password($senha, $u->user_pass, $u->ID)) return $recusa;

    ovr_sessao_abrir($u->ID);
    return ['ok' => true, 'conta' => ovr_conta_retrato($u)];
}

/* --- sair ---------------------------------------------------------- */
function ovr_conta_sair(WP_REST_Request $req) {
    ovr_sessao_fechar();
    return ['ok' => true];
}

/* --- quem sou eu --------------------------------------------------- */
function ovr_conta_eu(WP_REST_Request $req) {
    $u = ovr_sessao_usuario();
    return $u ? ['ok' => true, 'conta' => ovr_conta_retrato($u)] : ['ok' => true, 'conta' => null];
}

/* --- dados e endereço ---------------------------------------------- */
function ovr_conta_dados(WP_REST_Request $req) {
    $u = ovr_sessao_usuario();
    if (!$u) return ovr_erro('ovr_sessao', 'Entre na sua conta.', 401);

    $d = $req->get_json_params() ?: [];
    if (isset($d['nome']) && trim($d['nome']) !== '') {
        wp_update_user(['ID' => $u->ID, 'display_name' => sanitize_text_field($d['nome'])]);
    }
    if (isset($d['zap'])) update_user_meta($u->ID, '_ovr_zap', sanitize_text_field($d['zap']));
    if (isset($d['documento'])) {
        $doc = ovr_documento_normalizar($d['documento']);
        if (!$doc) return ovr_erro('ovr_documento', 'Esse CPF ou CNPJ não existe.');
        update_user_meta($u->ID, '_ovr_documento', $doc['numero']);
        update_user_meta($u->ID, '_ovr_doc_hash', ovr_doc_hash($doc['numero']));
    }
    return ['ok' => true, 'conta' => ovr_conta_retrato(get_userdata($u->ID))];
}

function ovr_conta_salvar_endereco(WP_REST_Request $req) {
    $u = ovr_sessao_usuario();
    if (!$u) return ovr_erro('ovr_sessao', 'Entre na sua conta.', 401);

    $d = $req->get_json_params() ?: [];
    $e = [];
    foreach (['cep','rua','numero','complemento','bairro','cidade','uf'] as $c) {
        $e[$c] = sanitize_text_field($d[$c] ?? '');
    }
    $e['cep'] = preg_replace('/\D/', '', $e['cep']);
    $e['uf']  = strtoupper(substr($e['uf'], 0, 2));

    if (strlen($e['cep']) !== 8) return ovr_erro('ovr_cep', 'CEP inválido.');
    if ($e['rua'] === '' || $e['numero'] === '' || $e['cidade'] === '' || $e['uf'] === '') {
        return ovr_erro('ovr_endereco', 'Faltou rua, número, cidade ou UF.');
    }
    update_user_meta($u->ID, '_ovr_endereco', $e);
    return ['ok' => true, 'endereco' => $e];
}

/* --- os pedidos dele ----------------------------------------------- */
function ovr_conta_pedidos(WP_REST_Request $req) {
    $u = ovr_sessao_usuario();
    if (!$u) return ovr_erro('ovr_sessao', 'Entre na sua conta.', 401);

    /* Casa pelo hash do documento, não pelo e-mail: e-mail se troca e o
       histórico se perderia junto. Também pega o pedido que a pessoa
       fez antes de criar conta, o que é justamente o que ela espera. */
    $hash = (string) get_user_meta($u->ID, '_ovr_doc_hash', true);
    if (!$hash) return ['ok' => true, 'pedidos' => []];

    $ids = get_posts([
        'post_type' => 'pedido', 'post_status' => 'any', 'posts_per_page' => 50,
        'fields' => 'ids', 'meta_key' => '_ovr_doc_hash', 'meta_value' => $hash,
        'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true,
    ]);

    $situacoes = function_exists('ovr_situacoes') ? ovr_situacoes() : [];
    $pedidos = [];
    foreach ($ids as $id) {
        $m = fn($k) => get_post_meta($id, $k, true);
        $sit = $m('_ovr_situacao');
        $pedidos[] = [
            'numero'   => get_the_title($id),
            'data'     => get_the_date('d/m/Y', $id),
            'situacao' => $situacoes[$sit]['rotulo'] ?? 'Em andamento',
            'itens'    => (string) $m('_ovr_itens'),
            /* Só o que ele pagou. Custo, markup e frete do fornecedor
               ficam de fora — é a mesma linha do resto do sistema. */
            'total'    => (int) $m('_ovr_valor_itens') + (int) $m('_ovr_valor_frete') - (int) $m('_ovr_desconto'),
        ];
    }
    return ['ok' => true, 'pedidos' => $pedidos];
}

/* ------------------------------------------------------------------
   Esqueci a senha

   Escrito à mão porque o do WordPress manda o cliente para
   wp-login.php, que é a tela que ele não pode ver. O que se reaproveita
   é o que importa: wp_set_password, que faz o hash direito.

   Três cuidados:

   1. A resposta é SEMPRE a mesma, exista o e-mail ou não. Senão o
      formulário de recuperação vira um verificador de cadastro.
   2. O token fica guardado em hash, como o da sessão.
   3. Usar o token o apaga. Link de redefinir senha que funciona duas
      vezes é link que continua valendo na caixa de entrada de quem
      perdeu o acesso ao e-mail.                                       */
function ovr_conta_esqueci(WP_REST_Request $req) {
    if (!ovr_conta_ritmo('esqueci')) return ovr_erro('ovr_ritmo', 'Muitas tentativas. Tente daqui a pouco.', 429);

    $email = sanitize_email(($req->get_json_params() ?: [])['email'] ?? '');
    $u = $email ? get_user_by('email', $email) : null;

    if ($u && ovr_eh_cliente($u)) {
        $token = bin2hex(random_bytes(32));
        set_transient('ovr_rs_' . hash('sha256', $token), $u->ID, OVR_RESET_MINUTOS * MINUTE_IN_SECONDS);

        $link = 'https://ovrcamisetas.com.br/nova-senha.html?t=' . $token;
        wp_mail($u->user_email, 'Redefinir sua senha na OVR', ovr_conta_email_senha($u, $link), [
            'Content-Type: text/html; charset=UTF-8',
        ]);
    }
    /* Mesma resposta dos dois lados. */
    return ['ok' => true];
}

function ovr_conta_nova_senha(WP_REST_Request $req) {
    if (!ovr_conta_ritmo('nova-senha')) return ovr_erro('ovr_ritmo', 'Muitas tentativas. Tente daqui a pouco.', 429);

    $d     = $req->get_json_params() ?: [];
    $token = preg_replace('/[^a-f0-9]/', '', (string) ($d['token'] ?? ''));
    $senha = (string) ($d['senha'] ?? '');

    if (strlen($senha) < OVR_SENHA_MINIMA) {
        return ovr_erro('ovr_senha', 'A senha precisa de pelo menos ' . OVR_SENHA_MINIMA . ' caracteres.');
    }
    $chave = 'ovr_rs_' . hash('sha256', $token);
    $id = $token ? get_transient($chave) : false;
    if (!$id) return ovr_erro('ovr_token', 'Esse link expirou ou já foi usado. Peça outro.', 400);

    delete_transient($chave);            // uma vez só
    wp_set_password($senha, (int) $id);

    $u = get_userdata((int) $id);
    ovr_sessao_abrir((int) $id);         // já entra, sem pedir a senha de novo
    return ['ok' => true, 'conta' => ovr_conta_retrato($u)];
}

/* ------------------------------------------------------------------
   Os e-mails da conta

   O molde é o de inc/emails.php, o mesmo do "pedido recebido". Aqui
   ficam só o texto e o destino de cada um.                            */

function ovr_conta_email_bemvindo(WP_User $u) {
    $cupom = function_exists('ovr_cupom') ? ovr_cupom('PRIMEIRA10') : null;
    /* Se o cupom estiver desligado no site, o e-mail sai sem a caixa em
       vez de prometer um desconto que o carrinho vai recusar. */
    $caixa = $cupom ? ovr_email_caixa(
        'Seu cupom de boas-vindas',
        sprintf("%d%% de desconto na primeira compra, até %s.\nÉ só digitar no carrinho, antes de enviar.",
                $cupom['percentual'], 'R$ ' . number_format($cupom['teto'], 2, ',', '.')),
        $cupom['codigo']
    ) : '';

    $miolo = ovr_email_kicker('Conta criada')
           . ovr_email_titulo('Sua conta está pronta.')
           . ovr_email_paragrafo('Oi, ' . $u->display_name . '! Agora seus dados e seu endereço ficam salvos, '
                               . 'e o próximo pedido sai em bem menos cliques.')
           . $caixa
           . ($cupom ? ovr_email_paragrafo('O cupom soma com o desconto por quantidade e não tira o frete grátis. '
                                         . 'Vale uma vez por CPF ou CNPJ.', 14) : '')
           . ovr_email_botao('Ver o catálogo', 'https://ovrcamisetas.com.br/catalogo.html')
           . ovr_email_nota('Se o botão não abrir, acesse ovrcamisetas.com.br/catalogo');

    ovr_email_enviar($u->user_email,
        $cupom ? 'Sua conta está pronta, e tem ' . $cupom['percentual'] . '% esperando' : 'Sua conta na OVR está pronta',
        ovr_email_moldura($miolo,
            'OVR Camisetas · Você recebeu este e-mail porque criou uma conta em ovrcamisetas.com.br.'));
}

function ovr_conta_email_senha(WP_User $u, string $link) {
    $miolo = ovr_email_kicker('Redefinir senha')
           . ovr_email_titulo('Vamos escolher outra.')
           . ovr_email_paragrafo('Oi, ' . $u->display_name . '! Clique no botão para criar uma senha nova. '
                               . 'O link vale por ' . OVR_RESET_MINUTOS . ' minutos e só funciona uma vez.')
           . ovr_email_caixa('Se não foi você',
                "Pode ignorar este e-mail.\nSua senha continua a mesma, e ninguém entrou na sua conta.")
           . ovr_email_paragrafo('Depois de escolher a senha nova, você já entra direto. '
                               . 'Não precisa digitar de novo.', 14)
           . ovr_email_botao('Escolher senha nova', $link)
           . ovr_email_nota('Se o botão não abrir, copie o endereço que aparece nele e cole no navegador.');

    ovr_email_enviar($u->user_email, 'Redefinir sua senha na OVR',
        ovr_email_moldura($miolo,
            'OVR Camisetas · Você recebeu este e-mail porque alguém pediu para redefinir a senha desta conta.'));
}

/* Aviso de cadastro repetido. Existe para o "criar conta" poder
   responder igual nos dois casos sem deixar a pessoa no escuro: quem
   tentou cadastrar de novo recebe o caminho de volta por e-mail. */
function ovr_conta_avisar_duplicado(string $email) {
    $u = get_user_by('email', $email);
    if (!$u) return;

    /* E-mail que existe como usuário do painel, e não como cliente. Foi
       o que aconteceu no primeiro teste: o endereço do dono da loja já
       era o admin do WordPress, o cadastro foi recusado e ninguém
       recebeu nada.

       Manda mesmo assim, com outro texto. Avisar não vaza: quem recebe
       é o próprio dono do endereço, nunca quem tentou o cadastro. O que
       não se pode é dar o papel de cliente para um e-mail existente,
       porque aí qualquer um tomaria a conta do admin "se cadastrando"
       com o e-mail dele. */
    if (!ovr_eh_cliente($u)) {
        $miolo = ovr_email_kicker('Cadastro recusado')
               . ovr_email_titulo('Este endereço já está em uso.')
               . ovr_email_paragrafo('Alguém tentou criar uma conta de cliente com este e-mail, mas ele já '
                                   . 'pertence a um acesso interno da OVR. Por segurança, o cadastro não foi feito.')
               . ovr_email_paragrafo('Se foi você e quer comprar pelo site, use outro endereço de e-mail, '
                                   . 'ou fale com a gente pelo WhatsApp que a gente resolve.', 14)
               . ovr_email_botao('Falar no WhatsApp', 'https://wa.me/5514996548259')
               . ovr_email_nota('Se não foi você, pode ignorar. Nada mudou no seu acesso.');
        ovr_email_enviar($u->user_email, 'Não consegui criar a conta com este e-mail',
            ovr_email_moldura($miolo,
                'OVR Camisetas · Você recebeu este e-mail porque tentaram criar uma conta com o seu endereço.'));
        return;
    }

    $miolo = ovr_email_kicker('Cadastro repetido')
           . ovr_email_titulo('Este e-mail já é seu.')
           . ovr_email_paragrafo('Oi, ' . $u->display_name . '! Alguém tentou criar uma conta com este e-mail, '
                               . 'e ele já está cadastrado aqui. Se foi você, é só entrar.')
           . ovr_email_caixa('Esqueceu a senha?',
                "Na mesma tela de entrar tem o atalho para pedir uma nova.\nChega por e-mail em alguns segundos.")
           . ovr_email_paragrafo('Se não foi você que tentou, pode ignorar. Nada mudou na sua conta '
                               . 'e ninguém entrou nela.', 14)
           . ovr_email_botao('Entrar na minha conta', 'https://ovrcamisetas.com.br/entrar.html')
           . ovr_email_nota('Se o botão não abrir, acesse ovrcamisetas.com.br/entrar');

    ovr_email_enviar($u->user_email, 'Você já tem conta na OVR',
        ovr_email_moldura($miolo,
            'OVR Camisetas · Você recebeu este e-mail porque tentaram criar uma conta com o seu endereço.'));
}
