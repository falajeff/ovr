/* Congela o preço de tudo que o site calcula hoje, para servir de gabarito
   antes e depois de qualquer mexida no motor de preço. */
const fs = require('fs'), vm = require('vm'), path = require('path');
const raiz = path.join(__dirname, '..');
const ctx = { window: {}, document: { querySelector: () => null }, console };
ctx.globalThis = ctx;
vm.createContext(ctx);
for (const f of ['assets/js/config.js', 'assets/js/preco.js']) {
  vm.runInContext(fs.readFileSync(path.join(raiz, f), 'utf8'), ctx, { filename: f });
}
/* const no topo cria binding léxico, não propriedade do contexto: puxa por avaliação */
const P = vm.runInContext('Preco', ctx);
const CONFIG = vm.runInContext('CONFIG', ctx);
const CATALOGO = JSON.parse(fs.readFileSync(path.join(raiz, 'dados/catalogo.json'), 'utf8')).produtos;
const saida = { geradoEm: null, produtos: {}, dtg: {}, filme: {} };
for (const p of CATALOGO) {
  saida.produtos[p.id] = {
    nome: p.nome,
    aPartirDe: P.aPartirDe(p),
    escada: P.escada(p).map(l => ({ rotulo: l.rotulo, valor: l.valor, economia: l.economia })),
    unitarios: [1, 5, 10, 24, 25, 49, 50, 99, 100, 500].map(q => [q, P.unitario(p, q)]),
  };
}
for (const m of (CONFIG.dtg?.modelagens || [])) saida.dtg[m.id] = P.precoDTG(m);
for (const met of [1, 5, 10, 25, 50]) saida.filme[met] = P.precoFilmeMetro(met);
saida.frete = [1, 10, 50, 100].map(q => [q, P.fretePedido(q), P.fretePorPeca(q)]);
process.stdout.write(JSON.stringify(saida, null, 1));
