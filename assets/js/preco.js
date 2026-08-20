/* =============================================================
   OVR — preço no navegador

   Aqui NÃO existe conta de preço. A fórmula, o custo da peça, o custo
   do filme e o markup moram no servidor, em api/motor-preco.php, e o
   que chega aqui é preço pronto:

     dados/precos.json   escada e "a partir de" na medida padrão
     api/preco.php       quando o cliente muda tamanho ou quantidade
     api/carrinho.php    o pedido inteiro, que depende da soma das peças

   O que continua sendo calculado aqui é o que o cliente já vê de
   qualquer jeito e não revela custo: frete grátis pelo CEP, caixa e
   peso do pedido, e a leitura da faixa em que a quantidade cai.
   ============================================================= */

const Preco = (() => {

  const { estampas, venda } = CONFIG;
  const base = () => (CONFIG.versaoAssets ? `?v=${CONFIG.versaoAssets}` : '');

  let TABELA = null;

  /* --------- tabela de preços, carregada uma vez ------------------- */
  async function carregar() {
    if (TABELA) return TABELA;
    const r = await fetch(`dados/precos.json${base()}`);
    TABELA = await r.json();
    return TABELA;
  }
  const pronto = () => TABELA !== null;
  /* Lista de posições vazia quer dizer PEÇA LISA, aqui e no servidor. É a
     mesma convenção nos dois lados: sem o que imprimir, não há impressão
     para cobrar, e o markup é outro. Quem simplesmente não passa posições
     continua caindo na medida padrão da frente, como sempre. */
  const ehLiso = posicoes => Array.isArray(posicoes) && posicoes.length === 0;

  function doProduto(produto, liso = false) {
    const t = (TABELA?.produtos || {})[String(produto?.id)] || null;
    if (!t) return null;
    return liso ? (t.liso || null) : t;
  }

  /* --------- faixa em que uma quantidade cai ----------------------- */
  /* max null é "sem teto": a última faixa era Infinity em JavaScript e o
     JSON não sabe escrever isso. Ler null como fim aberto é o que faz
     100 peças caírem na faixa de 100+.                                */
  const teto = f => (f.max == null ? Infinity : f.max);

  function faixaDe(qtd) {
    const fs = TABELA?.faixas || [];
    return fs.find(f => qtd >= f.min && qtd <= teto(f)) || fs[fs.length - 1] || null;
  }

  /* --------- área pedida, em cm² ----------------------------------- */
  function areaEstampa(posicoes = ['frente']) {
    return posicoes.reduce((soma, p) => {
      const e = typeof p === 'string' ? estampas[p] : p;
      return (e && e.larg > 0 && e.alt > 0) ? soma + e.larg * e.alt : soma;
    }, 0);
  }

  /* A medida escolhida é a padrão? Se for, o preço sai da tabela e não
     precisa de rede. É o caso da vitrine e da primeira abertura da
     página de peça, que juntas são quase todo o tráfego.              */
  function naMedidaPadrao(posicoes) {
    const p = TABELA?.medidaPadrao;
    if (!p || !Array.isArray(posicoes) || posicoes.length !== 1) return false;
    const e = typeof posicoes[0] === 'string' ? estampas[posicoes[0]] : posicoes[0];
    return !!e && Math.abs(e.larg - p.larg) < 0.01 && Math.abs(e.alt - p.alt) < 0.01;
  }

  /* --------- consultas síncronas, na medida padrão ----------------- */
  function aPartirDe(produto, liso = false) {
    return doProduto(produto, liso)?.aPartirDe ?? 0;
  }

  function escada(produto, posicoes = ['frente'], qtdAtual = null) {
    const t = doProduto(produto, ehLiso(posicoes));
    if (!t) return [];
    return t.escada.map(l => ({
      rotulo: l.rotulo, min: l.min, max: l.max,
      faixa: { rotulo: l.rotulo, min: l.min, max: l.max },
      valor: l.valor, economia: l.economia,
      ativa: qtdAtual !== null && qtdAtual >= l.min && qtdAtual <= teto(l),
    }));
  }

  function unitario(produto, qtd = 1, posicoes = ['frente']) {
    const linhas = escada(produto, posicoes);
    if (!linhas.length) return 0;
    const l = linhas.find(x => qtd >= x.min && qtd <= teto(x)) || linhas[linhas.length - 1];
    return l.valor;
  }

  /* Só o que a página mostra: faixa, unitário e total. Custo, markup e
     margem não existem mais deste lado.                               */
  function detalhe(produto, qtd = 1, posicoes = ['frente']) {
    const unit = unitario(produto, qtd, posicoes);
    return { faixa: faixaDe(Math.max(1, qtd)), qtd, unitario: unit, total: +(unit * qtd).toFixed(2) };
  }

  /* --------- consulta ao servidor, para medida fora do padrão ------ */
  async function consultar(produto, qtd = 1, posicoes = ['frente']) {
    /* Peça lisa não tem medida para variar, então nunca precisa de rede:
       a escada dela já veio no precos.json ao lado da estampada. */
    if (ehLiso(posicoes) || naMedidaPadrao(posicoes)) {
      const liso = ehLiso(posicoes);
      return {
        ...detalhe(produto, qtd, posicoes),
        escada: escada(produto, posicoes, qtd),
        aPartirDe: aPartirDe(produto, liso),
        liso,
      };
    }
    const medidas = posicoes
      .map(p => (typeof p === 'string' ? estampas[p] : p))
      .filter(e => e && e.larg > 0 && e.alt > 0)
      .map(e => `${e.larg}x${e.alt}`)
      .join(',');
    const url = `api/preco.php?id=${encodeURIComponent(produto.id)}&qtd=${qtd}&posicoes=${encodeURIComponent(medidas)}`;
    const r = await fetch(url);
    if (!r.ok) throw new Error('preço indisponível');
    const d = await r.json();
    return {
      faixa: d.faixa, qtd: d.qtd, unitario: d.unitario, total: d.total,
      escada: d.escada.map(l => ({ ...l, faixa: { rotulo: l.rotulo, min: l.min, max: l.max } })),
      aPartirDe: d.aPartirDe,
    };
  }

  /* --------- o pedido inteiro -------------------------------------
     A faixa sai do TOTAL de peças do pedido, não do item, então o
     carrinho não pode ser somado item a item aqui. Quem fecha a conta
     é o servidor, numa chamada só.                                    */
  async function consultarCarrinho(itens = [], cupom = null) {
    const corpo = itens.map(i => ({
      tipo: i.tipo, id: i.id ?? null, qtd: +i.qtd || 0,
        /* Peça da casa, estampada ou lisa, tem o preço refeito no servidor.
           Mandar número daqui seria deixar o preço editável no inspetor.
           Filme, arte e DTG mandam porque a conta deles é da página, e lá
           o servidor só limita o que chega. */
        unitario: (i.tipo === 'dtf' || i.tipo === 'peca') ? null : (+i.unitario || 0),
      posicoes: (i.posicoes || []).map(p => {
        const e = typeof p === 'string' ? estampas[p] : p;
        return e ? { larg: e.larg, alt: e.alt } : null;
      }).filter(Boolean),
    }));
    const r = await fetch(`api/carrinho.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      /* O cupom viaja como código e documento, nunca como valor. Quem
         diz quanto vale é o servidor — mandar o desconto daqui seria
         deixar o desconto editável no inspetor. */
      body: JSON.stringify({
        itens: corpo,
        cupom: cupom?.codigo || '',
        documento: cupom?.documento || '',
      }),
    });
    if (!r.ok) throw new Error('carrinho indisponível');
    const d = await r.json();
    /* devolve na mesma forma de antes, para o carrinho.js não mudar */
    return {
      linhas: itens.map((i, k) => ({ ...i, unitario: d.linhas[k].unitario, subtotal: d.linhas[k].subtotal })),
      pecasDTF: d.pecasDTF,
      faixa: d.faixa,
      total: d.total,
      cupom: d.cupom || null,
      desconto: d.desconto || 0,
      /* O que o frete grátis olha. Vem do servidor já descontado. */
      aPagar: d.aPagar ?? d.total,
      freteGratis: d.freteGratis,
      minimoAtingido: d.minimoAtingido,
      faltamPecas: d.faltamPecas,
      embalagem: embalagemPara(d.pecasDTF),
    };
  }

  /* --------- promessa de frete ao cliente -------------------------
     Isto é preço para o cliente, não custo: pode viver aqui.          */
  function ufDoCep(cep) {
    const n = parseInt(String(cep || '').replace(/\D/g, '').slice(0, 5), 10);
    if (!Number.isFinite(n)) return null;
    if (n >= 1000  && n <= 19999) return 'SP';
    if (n >= 20000 && n <= 28999) return 'RJ';
    if (n >= 80000 && n <= 87999) return 'PR';
    if (n >= 88000 && n <= 89999) return 'SC';
    return null;
  }

  function temFreteGratis(valorPedido, uf) {
    const r = venda.freteGratis;
    return valorPedido >= r.valor && r.estados.includes((uf || '').toUpperCase());
  }

  /* --------- impressão especial (DTG), preço fechado na tabela ----- */
  function precoDTG(modelagem, frente = '35x40', costas = 'sem') {
    const t = (TABELA?.dtg || {})[modelagem?.id ?? modelagem];
    if (!t) return { preco: 0 };
    let preco = t.preco;
    if (frente && t.porFrente?.[frente] != null) preco = t.porFrente[frente];
    if (costas && costas !== 'sem' && t.porCostas?.[costas] != null) preco = t.porCostas[costas];
    return { preco };
  }
  const precoEtiqueta = () => (TABELA?.dtg || {})._etiqueta ?? 0;

  /* --------- filme vendido avulso ---------------------------------
     O metro tem preço de tabela. O que fica no servidor é quanto ele
     custa para nós; o rendimento por metro é geometria do produto que
     o cliente compra, e ajuda ele a se planejar.                      */
  function precoFilmeMetro(metros) {
    const f = TABELA?.filme;
    const m = Math.max(1, Math.ceil(metros));
    if (!f) return { metros: m, total: 0, porMetro: 0 };
    const total = +(m * f.precoMetro).toFixed(2);
    return { metros: m, total, porMetro: f.precoMetro };
  }

  function filmeTemFreteGratis(metros, uf) {
    const r = CONFIG.filme?.freteGratis;
    if (!r) return false;
    return metros >= r.apartirDeMetros && (!uf || r.estados.includes(String(uf).toUpperCase()));
  }

  function filmePorArte(largCm, altCm, quantidade = 1) {
    const f = TABELA?.filme;
    const area = Math.max(0, largCm) * Math.max(0, altCm);
    const qtd = Math.max(1, Math.floor(quantidade));
    if (!area || !f) return null;
    const porLinha = Math.max(1, Math.floor(f.larguraCm * f.aproveitamento / largCm));
    const linhasPorMetro = Math.max(1, Math.floor(100 / altCm));
    const porMetro = Math.max(1, porLinha * linhasPorMetro);
    const metros = Math.max(1, Math.ceil(qtd / porMetro));
    const preco = precoFilmeMetro(metros);
    return {
      area, qtd, porMetro, metros,
      total: preco.total,
      porArte: +(preco.total / qtd).toFixed(2),
      sobra: porMetro * metros - qtd,
      aproveitamento: +((qtd / (porMetro * metros)) * 100).toFixed(0),
    };
  }

  /* --------- em que caixa isso cabe -------------------------------- */
  function embalagemPara(qtd) {
    const e = CONFIG.embalagem;
    if (!e || !qtd) return null;
    const volumes = Math.max(1, Math.ceil(qtd / e.porVolume));
    const porVolume = Math.ceil(qtd / volumes);
    const caixa = e.caixas.find(c => porVolume <= c.ate) || e.caixas[e.caixas.length - 1];
    const pesoG = porVolume * e.pesoPecaG + caixa.taraG;
    return {
      caixa: caixa.nome, dims: caixa.dims, volumes,
      cm: caixa.cm || [30, 20, 10],
      pesoKg: +((pesoG * volumes) / 1000).toFixed(2),
      rotulo: volumes > 1
        ? `${volumes} volumes · ${caixa.nome} (${caixa.dims}) · ${+((pesoG * volumes) / 1000).toFixed(2)} kg no total`
        : `${caixa.nome} (${caixa.dims}) · ${+(pesoG / 1000).toFixed(2)} kg`,
    };
  }

  return {
    carregar, pronto, faixaDe, areaEstampa, naMedidaPadrao,
    aPartirDe, escada, unitario, detalhe,
    consultar, consultarCarrinho,
    ufDoCep, temFreteGratis,
    precoDTG, precoEtiqueta,
    precoFilmeMetro, filmeTemFreteGratis, filmePorArte,
    embalagemPara,
  };
})();

/* --------- formatação de moeda -----------------------------------
   Vive aqui desde sempre e o site inteiro usa. Não é conta de preço:
   é como o número aparece na tela.                                 */
const BRL = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
const reais = v => BRL.format(v);

/* Sem centavos quando o valor é redondo: "R$ 1.500" lê melhor que
   "R$ 1.500,00" num aviso de frete grátis.                         */
const BRL_INTEIRO = new Intl.NumberFormat('pt-BR', {
  style: 'currency', currency: 'BRL', minimumFractionDigits: 0, maximumFractionDigits: 0
});
const reaisCurto = v => (Number.isInteger(v) ? BRL_INTEIRO : BRL).format(v);
