import { PGlite } from '@electric-sql/pglite';
import { pgcrypto } from '@electric-sql/pglite/contrib/pgcrypto';
import fs from 'fs';
const db = new PGlite({extensions:{pgcrypto}});
await db.exec(`
create schema auth; create schema storage;
create table auth.users (id uuid primary key default gen_random_uuid(), email text, raw_user_meta_data jsonb default '{}');
create function auth.uid() returns uuid language sql as $$ select nullif(current_setting('request.uid', true),'')::uuid $$;
create table storage.buckets (id text primary key, name text, public boolean);
create role anon; create role authenticated;
`);
const run = async (f) => { try { await db.exec(fs.readFileSync(f,'utf8')); console.log('OK', f); } catch (e) { console.log('FAIL', f, e.message); process.exit(1);} };
await run('../database/schema.sql');
await run('../database/seed.sql');
const q = async (s,p) => (await db.query(s,p)).rows;
console.log('products', (await q('select count(*) c from products'))[0].c);
await db.exec(`insert into auth.users(email,raw_user_meta_data) values ('a@x.com','{"full_name":"Test"}')`);
const u = (await q('select id from auth.users'))[0].id;
console.log('profile trigger', (await q('select full_name,role from profiles'))[0]);
const p = await q(`select id,stock from products where slug='twin-swan-brass-showpiece'`);
const items = JSON.stringify([{product_id:p[0].id, quantity:2}]);
let r = (await q(`select quote_cart($1::jsonb,'WELCOME10',$2) r`,[items,u]))[0].r; console.log('quote', r.subtotal, r.discount, r.shipping, r.total, r.ok);
const ship = JSON.stringify({name:'T',email:'a@x.com',phone:'+911234567890',address:'1 Road',city:'C',state:'S',postal_code:'360001',country:'India'});
r = (await q(`select place_order($1,$2::jsonb,'WELCOME10',$3::jsonb,'cod') r`,[u,items,ship]))[0].r; console.log('order', r);
console.log('stock after', (await q(`select stock,sold_count from products where id=$1`,[p[0].id]))[0], 'was', p[0].stock);
try { await q(`select place_order($1,$2::jsonb,'WELCOME10',$3::jsonb,'cod')`,[u,items,ship]); console.log('FAIL: reused coupon'); } catch(e){ console.log('coupon reuse blocked:', e.message); }
try { await q(`select place_order($1,$2::jsonb,null,$3::jsonb,'cod')`,[u,JSON.stringify([{product_id:p[0].id,quantity:999}]),ship]); console.log('FAIL oversell'); } catch(e){ console.log('oversell blocked:', e.message); }
await q(`select cancel_order($1,$2,false)`,[r.id,u]);
console.log('restocked', (await q(`select stock,sold_count from products where id=$1`,[p[0].id]))[0]);
console.log('stats keys', Object.keys((await q('select admin_stats() s'))[0].s));
