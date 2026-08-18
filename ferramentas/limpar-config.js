/* Tira do config.js público tudo que revela custo ou markup.
   O que sai daqui vive só em api/preco-config.php. */
const fs = require('fs'), path = require('path');
const p = path.join(__dirname, '..', 'assets/js/config.js');
let s = fs.readFileSync(p, 'utf8');

const antes = s.length;
/* blocos inteiros que são custo */
for (const chave of ['dtf', 'markup', 'freteFornecedor']) {
  const re = new RegExp(`\\n\\s*${chave}:\\s*\\{[\\s\\S]*?\\n\\s*\\},`, 'm');
  if (!re.test(s)) throw new Error('não achei o bloco ' + chave);
  s = s.replace(re, `\n  /* ${chave}: movido para api/preco-config.php, que não vai para o navegador */`);
}
/* desconto por faixa é custo; rótulo e limites ficam */
s = s.replace(/,\s*desconto:\s*[\d.]+/g, '');
/* custo das modelagens DTG e markup do DTG e do filme */
s = s.replace(/,\s*custo:\s*[\d.]+/g, '');
s = s.replace(/\n\s*markup:\s*[\d.]+,/g, '');
s = s.replace(/\n\s*markupMinimo:\s*[\d.]+,/g, '');
fs.writeFileSync(p, s);
console.log(`config.js: ${antes} -> ${s.length} bytes`);
