import { JSDOM } from 'jsdom'; import { build } from 'esbuild'; import fs from 'fs';
const FE='../public';
const prod = (i,o={}) => ({ id:`00000000-0000-4000-8000-00000000000${i}`, slug:'item-'+i, name:'Item '+i, description:'Desc', price:1000*i, sale_price:i%2?800*i:null, effective_price:800*i, stock:i===3?0:5, category:{name:'Vases',slug:'vases'}, category_id:'c1', images:[{url:'https://x/1.jpg',alt:''}], image:'https://x/1.jpg', colors:['Ivory'], specs:{Care:'Wipe'}, materials:'Clay', dimensions:'10cm', sku:'S'+i, rating_avg:4, rating_count:2, created_at:new Date().toISOString(), is_bestseller:true, collection:'natural-living', ...o });
const items=[1,2,3,4].map(i=>prod(i));
const routes = {
 '/settings': {announcement:{enabled:true,text:'Free shipping'},contact:{email:'a@b.c',phone:'1'},shipping:{flat_rate:199}},
 '/categories': {items:[{id:'c1',name:'Vases',slug:'vases',description:'d',image_url:'https://x/c.jpg',product_count:4}]},
 '/products': {items,total:4,page:1,per_page:12},
 '/products/item-1': {product:items[0],related:items.slice(1)},
 '/cart/quote': {ok:true,lines:[{product_id:items[0].id,slug:'item-1',name:'Item 1',image:null,unit_price:800,compare_at:1000,quantity:2,stock:5,line_total:1600,issue:null}],subtotal:1600,discount:0,shipping:199,total:1799,free_shipping_threshold:5000},
};
const pages = ['index','shop','product','cart','categories','login','register','wishlist','checkout','info'];
let fail=0;
for (const pg of pages) {
  const entry = pg==='index'?'home':['categories','collections','shop','product','cart','checkout','wishlist','info'].includes(pg)?pg:'auth';
  const out = (await build({ entryPoints:[`${FE}/js/pages/${entry}.js`], bundle:true, write:false, format:'iife', logLevel:'silent' })).outputFiles[0].text;
  const html = fs.readFileSync(`${FE}/${pg}.html`,'utf8').replace(/<script[\s\S]*?<\/script>/g,'');
  const q = pg==='product'?'?slug=item-1':pg==='info'?'?p=faq':'';
  const dom = new JSDOM(html,{url:`http://localhost:3000/${pg}.html${q}`,runScripts:'outside-only',pretendToBeVisual:true});
  const w = dom.window, errs=[]; w.AURELLE_API_URL='http://api';
  w.fetch = async (u,opt)=>{ const p=new URL(u).pathname; const r=routes[p]; return { ok:!!r, status:r?200:404, json:async()=>r||{error:'nf'} }; };
  w.IntersectionObserver = class{observe(){}unobserve(){}}; w.scrollTo=()=>{};
  w.addEventListener('error',e=>errs.push(e.message)); w.addEventListener('unhandledrejection',e=>errs.push('rej '+e.reason));
  try { w.eval(out); } catch(e){ errs.push('eval '+e.message); }
  await new Promise(r=>setTimeout(r,400));
  const main = w.document.querySelector('#main').textContent.replace(/\s+/g,' ').slice(0,90);
  const cards = w.document.querySelectorAll('.card').length;
  console.log(errs.length?'FAIL':'ok  ', pg, '| cards:',cards,'| nav:',!!w.document.querySelector('.nav'),'|',main, errs.join(' ; '));
  if (errs.length) fail++;
}
process.exit(fail?1:0);
