/* =============================================================
   OVR — configuração
   Único arquivo que você mexe no dia a dia. Tudo no site lê daqui.
   ============================================================= */

const CONFIG = {

  /* Suba este número quando trocar um mockup ou uma silhueta.
     É o que faz o navegador do cliente buscar a imagem nova.      */
  versaoAssets: '56',

  /* ---------- 1. MARCA -------------------------------------- */
  marca: {
    nome: 'OVR',
    assinatura: 'Custom goods para marcas, pessoas e ideias em movimento.',
    whatsapp: '5514996548259',
    email: 'ovrcamisetas@gmail.com',
    instagram: 'ovrcamisetas',
    cidade: 'Marília / SP',
  },

  /* ---------- 2. FILME DTF ---------------------------------- */
  /* Você compra o filme por metro e paga UM frete por compra.
     Quanto maior o pedido, mais metros na mesma compra, e o frete dilui.
     O site calcula isso sozinho a partir da quantidade real do pedido.  */
  /* ⚠️ Este preço NÃO é só da página de filme: ele é o custo da estampa
     dentro do preço de TODA camiseta. Mexer aqui move o catálogo inteiro. */
  /* dtf: movido para api/preco-config.php, que não vai para o navegador */

  /* Tamanhos de estampa oferecidos, em centímetros */
  estampas: {
    /* Medida padrão de cada posição. É a que entra inclusa no preço
       de vitrine quando o cliente não mexe em nada.

       A altura tem que caber no teto da regra dos 10cm: camiseta com P
       na grade tem altura útil 40, logo o teto é 30. Se a padrão passar
       disso ela é aparada na página do produto, a vitrine calcula por
       uma área que o cliente não consegue pedir, e o seletor abre em
       "Outro". Por isso 30 e não 35.                                  */
    frente: { rotulo: 'Frente', larg: 28, alt: 30 },
    costas: { rotulo: 'Costas', larg: 30, alt: 30 },

    /* Sugestões prontas. O cliente pode ignorar e digitar a medida.
       Todas cabem em 30 de altura; a folga que sobra é de largura,
       que vai até 51. Por isso "Grande" cresce para o lado.          */
    presets: [
      { id: 'bolso',  nome: 'Bolso',  larg: 10, alt: 10 },
      { id: 'media',  nome: 'Média',  larg: 20, alt: 25 },
      { id: 'padrao', nome: 'Padrão', larg: 28, alt: 30 },
      { id: 'grande', nome: 'Grande', larg: 40, alt: 30 },
    ],

    /* Largura máxima: sai do próprio filme. 60cm com 85% de aproveitamento
       dá 51. Isso é fato do insumo, não é estimativa.                  */
    limiteLargCm: Math.floor(60 * 0.85),

    /* Altura máxima: NÃO é fixa, depende da peça. A regra do Jefferson é
       "sempre 10cm a menos que a altura da peça". Como a estampa precisa
       caber em TODAS as peças do pedido, o limite sai do MENOR tamanho
       da grade — se tem P e GG, quem manda é o P.

       ⚠️ TABELA PROVISÓRIA. O catálogo dos 155 produtos não traz medida
       nenhuma, então montei esta escala ancorada no número que ele deu
       (peça de 40 → estampa de 30). É altura ÚTIL do painel de estampa,
       não o comprimento total da peça. PRECISA da tabela real dele.    */
    margemAlturaCm: 10,
    alturaUtilPorTamanho: {
      PP: 38, P: 40, M: 42, G: 44, GG: 46, XG: 48, XGG: 50, EXG: 50,
      G1: 46, G2: 48, G3: 50, G4: 52,
      /* infantil */
      '2': 26, '4': 29, '6': 32, '8': 35, '10': 37, '12': 39, '14': 41, '16': 43,
      'ÚNICO': 40, U: 40,
    },
    alturaUtilPadrao: 40,   // usado quando o tamanho não está na tabela
    minCm: 5,
  },

  /* ---------- 3. MARKUP ------------------------------------- */
  /* Peça avulsa tem markup menor porque o fornecedor não dá desconto
     na unidade e o preço precisa caber no mercado.                    */
  /* markup: movido para api/preco-config.php, que não vai para o navegador */

  /* Faixas de desconto do fornecedor e a quantidade que
     representa cada faixa na vitrine.                                 */
  faixas: [
    { min: 1,   max: 9, ref: 5,   rotulo: '1–9 peças' },
    { min: 10,  max: 24, ref: 17,  rotulo: '10–24 peças' },
    { min: 25,  max: 49, ref: 37,  rotulo: '25–49 peças' },
    { min: 50,  max: 99, ref: 75,  rotulo: '50–99 peças' },
    { min: 100, max: Infinity, ref: 110, rotulo: '100+ peças' },
  ],

  /* ---------- 4. IMPRESSÃO ESPECIAL (DTG) -------------------- */
  /* Terceirizada, peça pronta e enviada direto ao cliente.
     Custo é o preço da peça pronta, então o markup é menor: você
     não encosta na peça, não embala e não paga frete.                 */
  dtg: {
    /* Não é mais limite de quantidade: agora o DTF faz de 1 peça em
       diante e a escolha entre as duas técnicas é pela arte, não pelo
       volume. Fica só como referência de quando avisar o cliente que
       em cor chapada o DTF sai mais barato. Nada no site lê isto.    */
    limitePecas: 9,
    prazoDias: 7,
    /* Este bloco já teve um campo de custo, que ninguém lia. Ao lado do
       preço de venda em dados/precos.json ele entregava o markup por
       divisão. Foi para ferramentas/notas-privadas.md — e o número não
       volta para cá nem dentro de comentário. */
    etiqueta: { rotulo: 'Etiqueta termocolante com a sua marca' },
    /* custo da peça pronta com estampa frente até 35x40 */
    modelagens: [
      { id: 'oversized-classica', nome: 'Oversized Clássica', cores: 7,  img: 'oversized-classica.webp' },
      { id: 'oversized-heavy',    nome: 'Oversized Heavy', cores: 2,  img: 'oversized-heavy.webp' },
      { id: 'boxy',               nome: 'Boxy', cores: 6,  img: 'boxy.webp' },
      { id: 'boxy-linha',         nome: 'Boxy com linha', cores: 6,  img: 'boxy-linha.webp', obs: 'linha contrastante' },
      { id: 'basica',             nome: 'Básica', cores: 2,  img: 'basica.webp' },
      { id: 'baby-tee',           nome: 'Baby Tee', cores: 5,  img: 'baby-tee.webp' },
      { id: 'regata-boxy',        nome: 'Regata Boxy', cores: 6,  img: 'regata-boxy.webp' },
    ],
    /* ⚠️ A tabela de EXG diverge da do fornecedor, e a página de
       impressão especial não pergunta o tamanho da peça. Os números e
       o que a divergência custa por venda ficam em api/preco-config.php:
       é conta de margem, e este arquivo o navegador baixa inteiro.     */
    /* acréscimo do fornecedor por tamanho de estampa */
    acrescimoFrente: { '12x12': 0, '15x20': 10, '25x30': 20, '35x40': 30, '40x50': 37 },
    acrescimoCostas: { 'sem': 0, '25x30': 28, '35x40': 38, '40x50': 45 },
  },

  /* ---------- 4b. FILME DTF AVULSO -------------------------- */
  /* Revenda do insumo para quem tem prensa e só quer o filme impresso.
     Você compra no fornecedor e ele envia direto ao cliente, então o
     custo é o mesmo do bloco `dtf`: metro + um frete por compra.
     Markup menor que o da peça porque aqui você não encosta no produto. */
  filme: {
    metragens: [1, 2, 3, 5, 10],

    /* ⚠️ Diferente da camiseta: aqui o frete NÃO entra no preço. O filme
       sai do fornecedor direto para o cliente, e o envio é cobrado à
       parte conforme o estado. Na camiseta o filme vem para a sua casa
       antes de virar peça, então lá o frete continua embutido no custo.

       Em SP e PR você absorve o envio. Começa em 2 metros porque em 1
       a margem depois do frete é de R$ 5 — brinde que come a venda.   */
    freteGratis: { estados: ['SP', 'PR'], apartirDeMetros: 2 },
    /* Abaixo disso não compensa abrir pedido: some o tempo de conferir a
       arte, faturar e acompanhar. Vale como piso do carrinho.          */
    minimoPedido: 90.00,
    prazoDias: 5,
  },

  /* ---------- 4c. CRIAÇÃO DE ARTE --------------------------- */
  /* Pesquisa de mercado (ago/2026): hora de design no Brasil fica entre
     R$ 50 e R$ 200; estampa de designer iniciante ~R$ 200, estabelecido
     R$ 500–1.000; uso comercial encarece (arte de 400 vira 600–800);
     ilustração página simples pela tabela SIB/SIAP, R$ 300–600.

     Posicionamento escolhido: faixa acessível. Aqui a arte é isca para
     a impressão, não o produto principal — o objetivo é não perder o
     cliente que não tem arte, e não competir com estúdio de ilustração. */
  arte_servico: {
    /* Você faz: preço fechado, sai do seu tempo. */
    niveis: [
      { id: 'ajuste',   nome: 'Ajuste de arquivo',
        desc: 'A arte existe mas o arquivo não serve: fundo branco, resolução baixa, cor em RGB, texto não convertido.',
        de: 60,  ate: 90,  prazo: '1 dia útil', quemFaz: 'casa' },
      { id: 'tipo',     nome: 'Composição tipográfica',
        desc: 'Estampa feita de texto: frase, lettering, nome de time ou de turma, numeração.',
        de: 150, ate: 250, prazo: '2 dias úteis', quemFaz: 'casa' },
      { id: 'autoral',  nome: 'Estampa autoral',
        desc: 'Composição com elementos gráficos, textura e tipografia. O preço varia com o tamanho e o número de elementos.',
        de: 250, ate: 450, prazo: '3 a 5 dias úteis', quemFaz: 'casa' },
      { id: 'ilustra',  nome: 'Ilustração',
        desc: 'Desenho original, personagem ou cena. Feita por ilustrador parceiro e orçada caso a caso.',
        de: 600, ate: null, prazo: 'a combinar', quemFaz: 'parceiro' },
    ],
      /* markupIlustrador: movido para api/preco-config.php. Era o
         multiplicador sobre o orçamento do ilustrador, e estava aqui sem
         nenhum uso — bastava dividir o preço por ele para saber quanto
         o parceiro cobra.                                              */
    /* Conferir a arte do cliente continua de graça — é o que puxa a
       conversa. Cobrar só quando ela precisa ser CONSERTADA.           */
    conferenciaGratis: true,
    /* Acima desta quantidade de peças a arte de composição entra sem
       custo: em pedido grande ela se paga na margem da impressão.
       Deixe null para nunca dar de graça.                              */
    cortesiaAcimaDe: 50,
  },

  /* ---------- 4b. FRETE DO FORNECEDOR ------------------------
     A peça crua vindo do fornecedor até você. NÃO é o frete do filme, que
     já está no bloco `dtf` e entra no custo da estampa.

     Isto faltava no motor inteiro. Todo pedido pagava esse frete no
     mundo real e nenhum pagava no cálculo, então a margem que o site e
     o painel mostravam vinha inflada. Em 10 peças o erro é de R$ 1,50
     por peça e some; em 1 peça é R$ 14 e come 40% do resultado.

     Tabela medida no simulador do fornecedor para Marília. Cada faixa é o
     valor do TETO dela, então uma quantidade no meio paga o teto e o
     custo erra para mais, nunca para menos.

     O salto de 50 para 100 é mais que o dobro (49 para 121) porque aí
     o pedido passa de um volume para dois: a maior caixa fecha em 60
     peças, como está em `embalagem.porVolume`.                        */
  /* freteFornecedor: movido para api/preco-config.php, que não vai para o navegador */

  /* ---------- 5. VENDA -------------------------------------- */
  venda: {
    /* Uma peça dá lucro: R$ 47 na Básica de R$ 37,29, contra R$ 49 por
       peça em duas. Diferença de 3%, não os 30% que eu supunha quando
       o frete de 1 peça ainda estava chutado em R$ 25.

       O texto antigo aqui dizia que uma peça dava prejuízo de R$ 37.
       Nunca bateu com o motor: a folha de filme rende 6 estampas e o
       que sobra fica em estoque para o próximo pedido, então uma peça
       carrega 1/6 da folha, não a folha inteira.                      */
    pedidoMinimo: 1,
    freteGratis: { valor: 1500.00, estados: ['SP', 'PR', 'RJ', 'SC'] },
    prazoProducao: '7 dias úteis após aprovação da arte',
    arredondar: 'exato',   // 'exato' | 'noventa'
  },

  /* ---------- 5b. CARRINHO E PAINEL -------------------------- */
  carrinho: {
    /* Onde o pedido é entregue. O painel é outro WordPress, em outro
       domínio, então isto é uma chamada entre origens — o endpoint só
       aceita pedido vindo de ovrcamisetas.com.br.                     */
    endpoint: 'https://painel.ovrcamisetas.com.br/wp-json/ovr/v1/pedido',
    /* Cotação de frete. Passa pelo painel, e não direto na Frenet, por
       duas razões: a chave da Frenet ficaria visível no código-fonte
       desta página, e a Frenet não devolve CORS para o nosso domínio. */
    endpointFrete: 'https://painel.ovrcamisetas.com.br/wp-json/ovr/v1/frete',
    /* Chave no navegador do cliente. Mudar isto esvazia o carrinho de
       todo mundo, então só mexa se o formato dos itens mudar.         */
    chave: 'ovr.carrinho.v1',
    /* Carrinho velho não vale nada: preço muda, estoque muda. */
    validadeHoras: 72,
    maxItens: 40,
  },

  /* ---------- 5c. CONTA DO CLIENTE --------------------------- */
  conta: {
    /* Mora no painel porque a identidade mora no painel: senha, cadastro
       e histórico de pedido são de lá. Aqui só existe a tela.          */
    endpoint: 'https://painel.ovrcamisetas.com.br/wp-json/ovr/v1/conta',
    /* Mínimo que o servidor também exige. Repetido aqui só para o aviso
       aparecer antes de a pessoa apertar o botão. */
    senhaMinima: 8,
  },

  /* ---------- 5c. EMBALAGEM ---------------------------------- */
  /* Serve para dizer ao cliente (e a você) em que caixa o pedido cabe,
     e para estimar o peso do frete.

     ⚠️ TABELA PROVISÓRIA. Montei com medida de camiseta dobrada
     (30 × 22 × 3 cm, ~180 g) e caixas comuns de transportadora. Troque
     pelas caixas que você realmente compra — é o que vai no cálculo
     de frete depois.                                                  */
  embalagem: {
    pesoPecaG: 180,
    caixas: [
      /* `cm` é [comprimento, largura, altura], em número, porque a
         transportadora cota por medida e não por texto. O saco tem 3 cm
         de altura estimada com a peça dobrada.                         */
      { id: 'saco', nome: 'Saco plástico',  ate: 2,  dims: '35 × 25 cm',       cm: [35, 25, 3],  taraG: 30 },
      { id: 'p',    nome: 'Caixa P',        ate: 6,  dims: '32 × 24 × 12 cm',  cm: [32, 24, 12], taraG: 200 },
      { id: 'm',    nome: 'Caixa M',        ate: 15, dims: '35 × 27 × 20 cm',  cm: [35, 27, 20], taraG: 320 },
      { id: 'g',    nome: 'Caixa G',        ate: 30, dims: '40 × 30 × 30 cm',  cm: [40, 30, 30], taraG: 480 },
      { id: 'gg',   nome: 'Caixa GG',       ate: 60, dims: '50 × 40 × 35 cm',  cm: [50, 40, 35], taraG: 700 },
    ],
    /* Acima da maior caixa o pedido vai em mais de um volume. */
    porVolume: 60,
  },

  /* ---------- 6. REGRAS DE ARTE ----------------------------- */
  arte: {
    formatos: ['PNG', 'TIFF'],
    dpi: 300,
    fonteMinimaPt: 48,
    tracoMinimoPt: 3,
    tamanhoMaxMB: 25,
  },

  /* ---------- 7. CATÁLOGO ----------------------------------- */
  catalogo: {
    /* Peça que está no outlet do fornecedor não entra no site:
       é ponta de estoque, sem reposição garantida.                    */
    excluirGrupos: ['Outlet'],
    /* Silhueta base por tipo de peça: mockup branco recortado, tingido
       em tempo real pela cor do produto. Nenhuma foto de fornecedor.   */
    silhuetaPadrao: 'basica.webp',
    silhuetas: {
      'Básica':        'basica.webp',
      'Com Elastano':  'basica.webp',
      'Algodão Pima':  'basica.webp',
      'Modal':         'basica.webp',
      'Proteção UV':   'basica.webp',
      'Canelada':      'basica.webp',
      'Cropped':       'basica.webp',
      'Regata':        'basica.webp',
      'Oversized':     'oversized.webp',
      'Streetwear':    'oversized.webp',
      'Gola Polo':     'polo.webp',
      'Moletom':       'moletom.webp',
      'Bermuda':       'bermuda.webp',
      'Corta-vento':   'corta-vento.webp',
    },
    /* Tipos que ainda não têm silhueta própria e ficam fora da vitrine */
    /* Acessório é kit de embalagem e meia: não é peça de estampa. */
    tiposSemSilhueta: ['Acessório'],
  },
};

/* Congela para ninguém sobrescrever sem querer */
Object.freeze(CONFIG);
