/* Gera o sitemap.xml a partir do catálogo.
   Rode depois de mexer em dados/catalogo.json:

       node ferramentas/gerar-sitemap.js

   Por que gerar e não escrever à mão: as páginas de produto são 151 e
   mudam junto com o catálogo. Sitemap escrito à mão envelhece na
   primeira peça que entra, e aí ele passa a mentir para o Google sobre
   o que existe no site. */

const fs = require('fs');
const path = require('path');
const raiz = path.resolve(__dirname, '..');
const SITE = 'https://ovrcamisetas.com.br';

/* Institucionais, com a prioridade dizendo o que importa mais.
   Carrinho e 404 ficam de fora de propósito: carrinho é estado do
   visitante, não conteúdo, e 404 não deve ser indexado nunca.        */
const fixas = [
  ['/', '1.0', 'weekly'],
  ['/catalogo.html', '0.9', 'weekly'],
  ['/impressao-especial.html', '0.8', 'monthly'],
  ['/filme-dtf.html', '0.8', 'monthly'],
  ['/criacao-de-arte.html', '0.7', 'monthly'],
  ['/como-funciona.html', '0.6', 'monthly'],
  ['/guia-de-arte.html', '0.6', 'monthly'],
];

const PRECOS = JSON.parse(fs.readFileSync(path.join(raiz, 'dados/precos.json'), 'utf8'));
const dados = JSON.parse(fs.readFileSync(path.join(raiz, 'dados/catalogo.json'), 'utf8'));
const produtos = Array.isArray(dados) ? dados : (dados.produtos || []);

/* Os MESMOS três filtros que o app.js aplica ao montar a vitrine.
   Se um deles ficar de fora, o sitemap manda o Google numa página que
   o visitante não consegue alcançar, e isso conta contra o site. Ler
   do config em vez de repetir a lista aqui evita que os dois lados
   divirjam na próxima peça que entrar.                               */
const cfg = fs.readFileSync(path.join(raiz, 'assets/js/config.js'), 'utf8');
const lista = chave => (cfg.match(new RegExp(chave + ':\\s*\\[([^\\]]*)\\]')) || [, ''])[1]
  .split(',').map(s => s.trim().replace(/^['"]|['"]$/g, '')).filter(Boolean);

const excluir = lista('excluirGrupos');
const semSilhueta = lista('tiposSemSilhueta');

const visiveis = produtos.filter(p => {
  const grupos = [].concat(p.grupos || p.grupo || []);
  if (grupos.some(g => excluir.includes(g))) return false;   // nada de outlet
  if (semSilhueta.includes(p.tipo)) return false;            // sem mockup, sem vitrine
  /* precoBase era o custo e saiu do catálogo público. O critério é o
     mesmo de antes, mas quem responde agora é a tabela de preços.   */
  if (!(PRECOS.produtos[String(p.id)]?.aPartirDe > 0)) return false;
  return true;
});

const hoje = new Date().toISOString().slice(0, 10);
const linhas = [];
for (const [url, prio, freq] of fixas) {
  linhas.push(`  <url><loc>${SITE}${url}</loc><lastmod>${hoje}</lastmod><changefreq>${freq}</changefreq><priority>${prio}</priority></url>`);
}
for (const p of visiveis) {
  linhas.push(`  <url><loc>${SITE}/produto.html?id=${p.id}</loc><lastmod>${hoje}</lastmod><changefreq>monthly</changefreq><priority>0.5</priority></url>`);
}

const xml = `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
${linhas.join('\n')}
</urlset>
`;
fs.writeFileSync(path.join(raiz, 'sitemap.xml'), xml);

console.log(`sitemap.xml: ${fixas.length} institucionais + ${visiveis.length} produtos = ${linhas.length} URLs`);
if (produtos.length !== visiveis.length) {
  console.log(`(${produtos.length - visiveis.length} fora, dos grupos excluídos: ${excluir.join(', ')})`);
}
