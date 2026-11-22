import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';
import fs from 'fs';
const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 1 } }));
php.mkdir('/app'); 
const walk = d => fs.readdirSync(d,{withFileTypes:true}).flatMap(e => e.isDirectory()? walk(d+'/'+e.name) : [d+'/'+e.name]);
const files = [...walk('../lib'),...walk('../api')].filter(f=>f.endsWith('.php'));
for (const f of files) {
  const rel = f.replace('..','/app'); php.mkdir(rel.substring(0,rel.lastIndexOf('/')));
  php.writeFile(rel, fs.readFileSync(f));
}
for (const f of files) {
  const rel = f.replace('..','/app');
  const out = await php.run({ code: `<?php
    $src = file_get_contents('${rel}');
    try { token_get_all($src, TOKEN_PARSE); echo "OK"; } catch (ParseError $e) { echo "PARSE ERROR line ".$e->getLine().": ".$e->getMessage(); }` });
  console.log(rel.replace('/app/',''), out.text);
}
process.exit(0);
