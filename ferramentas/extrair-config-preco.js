/* Extrai do config.js só o que o motor de preço precisa e escreve como PHP.
   O que sai daqui NÃO pode voltar para o navegador: é custo e markup. */
const fs = require('fs'), vm = require('vm'), path = require('path');
const raiz = path.join(__dirname, '..');
const ctx = { window:{}, document:{querySelector:()=>null}, console }; ctx.globalThis = ctx;
vm.createContext(ctx);
vm.runInContext(fs.readFileSync(path.join(raiz,'assets/js/config.js'),'utf8'), ctx);
const C = vm.runInContext('CONFIG', ctx);
const priv = {
  dtf: C.dtf, estampas: C.estampas, faixas: C.faixas,
  markup: C.markup, venda: C.venda, freteFornecedor: C.freteFornecedor,
  dtg: C.dtg, filme: C.filme,
};
const php = "<?php\n"
  + "/* Gerado por ferramentas/extrair-config-preco.js. Não editar à mão.\n"
  + " * Custo, markup e rendimento de filme. Este arquivo nunca vai para o\n"
  + " * navegador e nunca entra no repositório. */\n"
  + "if (!defined('OVR_MOTOR')) { http_response_code(404); exit; }\n"
  + "return " + JSON.stringify(priv, null, 2)
      .replace(/"([^"]+)":/g, "'$1' =>")
      .replace(/\[/g,'[').replace(/\{/g,'[').replace(/\}/g,']')
  + ";\n";
fs.writeFileSync(path.join(raiz,'api/preco-config.php'), php);
console.log('escrito api/preco-config.php');
