/* =============================================================
   OVR — comportamento compartilhado
   Menu mobile, links da marca, ticker e utilidades de página.
   ============================================================= */

/* ------------------------- menu mobile ------------------------- */
function iniciarMenu() {
  const menu = document.querySelector('[data-menu]');
  if (!menu) return;
  const abrir = document.querySelectorAll('[data-menu-abrir]');
  const fechar = menu.querySelectorAll('[data-menu-fechar]');
  let ultimoFoco = null;

  const setar = (aberto) => {
    menu.dataset.aberto = String(aberto);
    menu.setAttribute('aria-hidden', String(!aberto));
    document.body.style.overflow = aberto ? 'hidden' : '';
    if (aberto) {
      ultimoFoco = document.activeElement;
      menu.querySelector('a, button')?.focus();
    } else {
      ultimoFoco?.focus();
    }
  };

  abrir.forEach(b => b.addEventListener('click', () => setar(true)));
  fechar.forEach(b => b.addEventListener('click', () => setar(false)));
  menu.querySelectorAll('a').forEach(a => a.addEventListener('click', () => setar(false)));
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && menu.dataset.aberto === 'true') setar(false);
  });
  setar(false);
}

/* --------------------- dados da marca no DOM ------------------- */
/* Monta o link do WhatsApp já com a mensagem pronta para o cliente enviar */
function linkZap(texto) {
  const base = 'https://api.whatsapp.com/send/';
  const p = new URLSearchParams({ phone: CONFIG.marca.whatsapp, text: texto, type: 'phone_number', app_absent: '0' });
  return `${base}?${p}`;
}

function aplicarMarca() {
  const { marca, venda } = CONFIG;

  document.querySelectorAll('[data-zap]').forEach(el => {
    el.href = linkZap(el.dataset.zap || 'Oi! Vim pelo site da OVR e quero fazer um pedido.');
    el.target = '_blank';
    el.rel = 'noopener';
  });
  document.querySelectorAll('[data-instagram]').forEach(el => {
    el.href = `https://instagram.com/${marca.instagram}`;
    el.target = '_blank'; el.rel = 'noopener';
  });
  document.querySelectorAll('[data-email]').forEach(el => {
    el.href = `mailto:${marca.email}`;
    if (el.dataset.email === 'texto') el.textContent = marca.email;
  });
  document.querySelectorAll('[data-ano]').forEach(el => { el.textContent = new Date().getFullYear(); });
  document.querySelectorAll('[data-frete-gratis]').forEach(el => {
    el.textContent = `${reaisCurto(venda.freteGratis.valor)} para ${venda.freteGratis.estados.join(', ')}`;
  });
  document.querySelectorAll('[data-prazo]').forEach(el => { el.textContent = venda.prazoProducao; });
}

/* --------------------------- ticker ---------------------------- */
function iniciarTicker() {
  document.querySelectorAll('[data-ticker]').forEach(t => {
    const trilha = t.querySelector('.ticker__trilha');
    if (!trilha) return;
    trilha.innerHTML += trilha.innerHTML;   // duplica para o loop não dar salto
  });
}

/* ------------------- link ativo na navegação ------------------- */
function marcarPaginaAtual() {
  const aqui = location.pathname.split('/').pop() || 'index.html';
  document.querySelectorAll('.nav__links a, .menu__itens a').forEach(a => {
    if (a.getAttribute('href') === aqui) a.setAttribute('aria-current', 'page');
  });
}

/* =================== proteção do que vem de fora ================
   O catálogo é raspado do site do fornecedor. Se um nome de produto
   voltar com HTML dentro, ele não pode virar HTML aqui.             */

/* Escapa texto antes de entrar no innerHTML */
function esc(v) {
  return String(v ?? '').replace(/[&<>"']/g, c =>
    ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
}

/* Só aceita cor no formato de hex. Qualquer outra coisa vira neutro. */
function corSegura(v) {
  return /^#[0-9a-f]{6}$/i.test(String(v || '')) ? v : '#d8d5cd';
}

/* ------------------------ peça colorida ------------------------ */
/* Monta a silhueta tingida pela cor do produto. Sem foto de fornecedor. */
/* Foto de verdade quando existe, silhueta tingida quando não existe.
   O mockup é uma fotografia da malha naquela cor: nada de máscara nem
   de mix-blend-mode em cima, que é justamente o que fazia a peça
   parecer plástico.                                                  */
let MOCKUPS = null;
async function carregarMockups() {
  if (MOCKUPS) return MOCKUPS;
  try {
    const r = await fetch(`dados/mockups.json?v=${CONFIG.versaoAssets}`);
    MOCKUPS = await r.json();
  } catch (e) { MOCKUPS = {}; }
  return MOCKUPS;
}
const mockupDe = produto => (MOCKUPS || {})[String(produto?.id)] || null;

/* `ansioso` para a imagem principal da página de peça: ela é o maior
   elemento acima da dobra, e adiar o carregamento dela é adiar a única
   coisa que o visitante veio ver. Miniatura e vitrine seguem preguiçosas. */
function pecaHTML(produto, alt, ansioso = false) {
  const foto = mockupDe(produto);
  if (foto) {
    return `<img class="peca__foto" src="assets/img/mockups/${esc(foto)}?v=${CONFIG.versaoAssets}"
                 alt="${esc(alt || produto.nome)}" width="1024" height="1024"
                 ${ansioso ? 'fetchpriority="high"' : 'loading="lazy"'} decoding="async">`;
  }
  const c = CONFIG.catalogo;
  const arquivo = c.silhuetas[produto.tipo] || c.silhuetaPadrao;
  /* URL absoluta: dentro de var() o url() resolveria contra o arquivo CSS */
  const url = new URL(`assets/img/silhuetas/${arquivo}?v=${CONFIG.versaoAssets}`, document.baseURI).href;
  const cor = corExibicao(corSegura(produto.hex));
  const claro = ehClaro(cor);
  return `
    <div class="peca" style="--cor:${esc(cor)}; --silhueta:url('${esc(url)}')">
      ${claro ? '' : '<div class="peca__cor"></div>'}
      <img class="peca__base" src="${esc(url)}" alt="${esc(alt || produto.nome)}" loading="lazy" decoding="async"
           ${claro ? 'style="mix-blend-mode:normal"' : ''}>
    </div>`;
}

/* O multiply achata cor muito escura: preto puro apaga as dobras do tecido
   e a peça vira silhueta. Levanta o piso para o preto virar carvão, que é
   como malha preta fotografa de verdade.                                   */
function corExibicao(hex) {
  const h = (hex || '').replace('#', '');
  if (h.length !== 6) return hex;
  let r = parseInt(h.slice(0, 2), 16) / 255,
      g = parseInt(h.slice(2, 4), 16) / 255,
      b = parseInt(h.slice(4, 6), 16) / 255;
  const max = Math.max(r, g, b), min = Math.min(r, g, b);
  let l = (max + min) / 2;
  const PISO = 0.17, TETO = 0.97;
  if (l >= PISO && l <= TETO) return hex;
  const alvo = Math.min(TETO, Math.max(PISO, l));
  const k = l === 0 ? 1 : alvo / l;
  const ajusta = v => Math.round(Math.min(255, (l === 0 ? alvo : v * k) * 255))
                        .toString(16).padStart(2, '0');
  return '#' + ajusta(r) + ajusta(g) + ajusta(b);
}

/* Cor quase branca dispensa tingimento: o mockup branco já é a peça */
function ehClaro(hex) {
  const h = (hex || '').replace('#', '');
  if (h.length !== 6) return false;
  const r = parseInt(h.slice(0, 2), 16), g = parseInt(h.slice(2, 4), 16), b = parseInt(h.slice(4, 6), 16);
  return (0.2126 * r + 0.7152 * g + 0.0722 * b) > 236;
}

/* --------------------------- catálogo -------------------------- */
let CATALOGO = [];
async function carregarCatalogo() {
  if (CATALOGO.length) return CATALOGO;
  /* A tabela de preços vem junto: sem ela o catálogo aparece sem valor.
     Uma requisição a mais no arranque, e nenhuma conta no navegador. */
  await Preco.carregar();
  await carregarMockups();
  const r = await fetch(`dados/catalogo.json?v=${CONFIG.versaoAssets}`);
  const d = await r.json();
  const excluir = CONFIG.catalogo.excluirGrupos;
  const semSilhueta = CONFIG.catalogo.tiposSemSilhueta || [];
  CATALOGO = (d.produtos || [])
    .filter(p => !p.grupos.some(g => excluir.includes(g)))   // nada de outlet
    .filter(p => !semSilhueta.includes(p.tipo))              // sem mockup, sem vitrine
    /* Antes este filtro olhava precoBase, que era o custo e saiu do
       catálogo público. O critério continua o mesmo: peça sem preço
       não vai para a vitrine. Agora quem responde isso é a tabela.  */
    .filter(p => Preco.aPartirDe(p) > 0);
  return CATALOGO;
}

/* --------------------------- medidas --------------------------- */
/* As medidas moram num arquivo à parte porque não vêm do mesmo lugar:
   o catálogo é raspado do fornecedor, isto aqui foi lido à mão dos
   banners de categoria. Separado, um não estraga o outro quando o
   catálogo for raspado de novo.                                     */
let MEDIDAS = null;
async function carregarMedidas() {
  if (MEDIDAS) return MEDIDAS;
  try {
    const r = await fetch(`dados/medidas.json?v=${CONFIG.versaoAssets}`);
    MEDIDAS = await r.json();
  } catch (e) { MEDIDAS = { tabelas: {}, porTipo: {} }; }
  return MEDIDAS;
}

/* A Básica tem duas tabelas — adulto e infantil — e quem decide é a
   grade: se os tamanhos são números, é peça de criança.             */
function tabelaDeMedidas(dados, tipo, grade = [], produto = {}) {
  const rota = dados.porTipo?.[tipo];
  if (!rota) return null;
  const tamanhos = grade.map(g => String(g.tam ?? g).trim().toUpperCase());
  const plusSize = tamanhos.length && tamanhos.every(t => /^G[1-4]$/.test(t));
  const grupos = produto.grupos || [];
  const nome = String(produto.nome || '');

  /* O fornecedor publica três modelagens próprias para plus size. A grade
     sozinha não separa feminina, algodão masculino e elastano, então a
     rota usa também o grupo e o tipo do produto. */
  if (plusSize && tipo === 'Básica') {
    return dados.tabelas?.[grupos.includes('Feminino') ? 'plus-feminina' : 'plus-algodao'] || null;
  }
  if (plusSize && tipo === 'Com Elastano') {
    return dados.tabelas?.['plus-elastano'] || null;
  }

  /* O capuz tem uma entrada explícita. Hoje as medidas coincidem com o
     moletom careca, mas não deixamos essa coincidência virar dependência. */
  if (tipo === 'Moletom' && /canguru.*capuz|capuz.*canguru/i.test(nome)) {
    return dados.tabelas?.['moletom-capuz'] || null;
  }
  const infantil = rota.infantil && grade.length &&
    grade.every(g => /^\d+$/.test(String(g.tam ?? g).trim()));
  return dados.tabelas?.[infantil ? rota.infantil : rota.tabela] || null;
}

/* Monta o HTML da tabela. Devolve string vazia quando não há dado —
   melhor não mostrar seção nenhuma do que mostrar uma tabela oca.   */
function medidasHTML(tabela, { titulo = true } = {}) {
  if (!tabela || !tabela.linhas?.length) return '';
  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[c]));
  const n = v => typeof v === 'number' ? String(v).replace('.', ',') : esc(v);
  return `
    <div class="medidas">
      ${titulo ? `<div class="medidas__topo">
        <h3>Tabela de medidas</h3>
        ${tabela.corte ? `<p class="t-meta">${esc(tabela.corte)}</p>` : ''}
      </div>` : ''}
      <div class="medidas__rolagem">
        <table>
          <thead><tr>${tabela.colunas.map((c, i) =>
            `<th scope="col"${i === 0 ? '' : ' class="num"'}>${esc(c)}</th>`).join('')}</tr></thead>
          <tbody>${tabela.linhas.map(l => `<tr>
            <th scope="row">${esc(l[0])}</th>
            ${l.slice(1).map(v => `<td class="num">${n(v)}</td>`).join('')}
          </tr>`).join('')}</tbody>
        </table>
      </div>
      <p class="t-meta medidas__nota">Medidas da peça em centímetros, não do corpo. Pode variar até 2 cm.</p>
    </div>`;
}

/* --------------------------- arranque -------------------------- */
document.addEventListener('DOMContentLoaded', () => {
  iniciarMenu();
  aplicarMarca();
  iniciarTicker();
  marcarPaginaAtual();

  /* Quantidade de peças vem do catálogo, não escrita à mão. Já errou uma
     vez: a home dizia 152 enquanto a vitrine mostrava 151, porque o número
     foi digitado e o filtro mudou depois. O HTML traz um valor de partida
     para quem está sem JavaScript; aqui ele é confirmado.               */
  if (document.querySelector('[data-total-bases]')) {
    carregarCatalogo().then(l => {
      document.querySelectorAll('[data-total-bases]').forEach(e => { e.textContent = l.length; });
    }).catch(() => {});
  }
});
