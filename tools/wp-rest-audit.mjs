#!/usr/bin/env node
/**
 * אבחון nama-c.com דרך ה-REST API של וורדפרס.
 *
 * נכתב מפני ש-cPGuard חוסם את `/wp-login.php` ב-CAPTCHA, אבל **לא** חוסם את
 * ה-REST API. ראה `docs/12-admin-access-2026-08-30.md`.
 *
 * שימוש:
 *   WP_USER=audit WP_APP_PASS='xxxx xxxx xxxx xxxx xxxx xxxx' node tools/wp-rest-audit.mjs
 *
 * הסקריפט **קורא בלבד**. הוא לא משנה, לא מוחק ולא מכבה כלום.
 *
 * אם הסביבה מאחורי proxy שחוסם TLS של דפדפן, אפשר להצביע על גשר מקומי:
 *   HTTPS_PROXY_BRIDGE=http://127.0.0.1:8888 node tools/wp-rest-audit.mjs
 */
import http from 'node:http';
import https from 'node:https';
import tls from 'node:tls';

const HOST = process.env.WP_HOST || 'nama-c.com';
const USER = process.env.WP_USER || 'audit';
const PASS = process.env.WP_APP_PASS;
const UPSTREAM = process.env.CCR_PROXY || '127.0.0.1:40391';
const PACE_MS = Number(process.env.PACE_MS || 4000);   // cPGuard חוסם על קצב. לא למהר.

if (!PASS) { console.error('חסר WP_APP_PASS (סיסמת יישום, 24 תווים עם רווחים)'); process.exit(1); }

const auth = 'Basic ' + Buffer.from(`${USER}:${PASS}`).toString('base64');
const sleep = ms => new Promise(r => setTimeout(r, ms));

function tunnel(cb) {
  const [h, p] = UPSTREAM.split(':');
  const req = http.request({ host: h, port: +p, method: 'CONNECT', path: `${HOST}:443`,
    headers: { Host: `${HOST}:443` }, agent: false });
  req.on('connect', (res, socket) => res.statusCode === 200
    ? cb(null, socket) : cb(new Error('CONNECT ' + res.statusCode)));
  req.on('error', cb);
  req.end();
}
const agent = new https.Agent({ keepAlive: true, maxSockets: 2,
  createConnection(opts, cb) {
    tunnel((e, raw) => {
      if (e) return cb(e);
      const t = tls.connect({ socket: raw, servername: HOST, rejectUnauthorized: false, ALPNProtocols: ['http/1.1'] });
      t.on('secureConnect', () => cb(null, t)); t.on('error', cb);
    });
  } });

async function api(path) {
  await sleep(PACE_MS);
  return new Promise((resolve) => {
    const r = https.request({ host: HOST, port: 443, path, method: 'GET', agent,
      headers: { Authorization: auth, 'User-Agent': 'nama-audit/1.0', Accept: 'application/json' } }, res => {
      let b = ''; res.on('data', d => b += d);
      res.on('end', () => { try { resolve({ status: res.statusCode, json: JSON.parse(b) }); }
                            catch { resolve({ status: res.statusCode, raw: b.slice(0, 200) }); } });
    });
    r.on('error', e => resolve({ status: 0, error: e.message }));
    r.end();
  });
}

const line = s => console.log('\n' + '='.repeat(70) + '\n' + s + '\n' + '='.repeat(70));

// 1. does the credential work at all?
line('1. אימות');
const me = await api('/wp-json/wp/v2/users/me');
if (me.status !== 200) {
  console.error('  ✗ אימות נכשל:', me.status, JSON.stringify(me.json || me.raw).slice(0, 200));
  if (me.status === 403) console.error('  ← 403 = cPGuard חוסם את ה-IP. להמתין ולנסות שוב.');
  if (me.status === 401) console.error('  ← 401 = הסיסמה אינה סיסמת יישום תקינה.');
  process.exit(1);
}
console.log(`  ✓ מחובר כ-${me.json.name} (id ${me.json.id}), תפקידים: ${(me.json.roles||[]).join(', ')}`);

// 2. THE decisive question: do cancelled orders carry a transaction id?
line('2. ⭐ האם להזמנות שבוטלו יש מזהה עסקה — השאלה המכריעה');
let withTxn = 0, without = 0, total = 0;
const samples = [];
for (const page of [1, 2, 3]) {
  const o = await api(`/wp-json/wc/v3/orders?status=cancelled&per_page=50&page=${page}&_fields=id,number,date_created,total,transaction_id,payment_method,date_paid`);
  if (o.status !== 200 || !Array.isArray(o.json)) { console.log(`  עמוד ${page}: ${o.status}`); break; }
  if (!o.json.length) break;
  for (const ord of o.json) {
    total++;
    if (ord.transaction_id && String(ord.transaction_id).trim()) {
      withTxn++;
      if (samples.length < 15) samples.push(ord);
    } else without++;
  }
}
console.log(`  נבדקו ${total} הזמנות שבוטלו`);
console.log(`  ✗ עם מזהה עסקה  : ${withTxn}   ← אלה לקוחות ש**שילמו**`);
console.log(`  ✓ בלי מזהה עסקה : ${without}   ← נטישה לפני תשלום`);
if (withTxn) {
  console.log('\n  🔴 נמצאו הזמנות שבוטלו שנושאות מזהה עסקה. יש כסף להחזיר או הזמנות לשלוח:');
  for (const s of samples) console.log(`     #${s.number}  ${s.date_created}  ${s.total}  txn=${s.transaction_id}  paid=${s.date_paid || '—'}`);
} else if (total) {
  console.log('\n  🟢 אף הזמנה שבוטלה אינה נושאת מזהה עסקה — כלומר נטישה, לא תשלום שאבד.');
}

// 3. failed orders, same question
line('3. אותה בדיקה על הזמנות שנכשלו');
const f = await api('/wp-json/wc/v3/orders?status=failed&per_page=50&_fields=id,number,date_created,total,transaction_id,date_paid');
if (f.status === 200 && Array.isArray(f.json)) {
  const paid = f.json.filter(o => o.transaction_id && String(o.transaction_id).trim());
  console.log(`  ${f.json.length} נבדקו · ${paid.length} עם מזהה עסקה`);
  for (const s of paid.slice(0, 10)) console.log(`     #${s.number}  ${s.date_created}  ${s.total}  txn=${s.transaction_id}`);
} else console.log('  ', f.status);

// 4. plugins
line('4. תוספים');
const pl = await api('/wp-json/wp/v2/plugins');
if (pl.status === 200 && Array.isArray(pl.json)) {
  const on = pl.json.filter(p => p.status === 'active');
  console.log(`  ${pl.json.length} מותקנים · ${on.length} פעילים\n`);
  for (const p of on) console.log(`   [פעיל]  ${p.name}  ${p.version}   (${p.plugin})`);
  const off = pl.json.filter(p => p.status !== 'active');
  if (off.length) { console.log(''); for (const p of off) console.log(`   [כבוי]  ${p.name}  (${p.plugin})`); }
} else console.log('  ', pl.status, JSON.stringify(pl.json || pl.raw).slice(0, 200));

// 5. shop page + settings
line('5. הגדרות — עמוד החנות');
const st = await api('/wp-json/wp/v2/settings');
if (st.status === 200) console.log('  title:', st.json.title, '| posts_per_page:', st.json.posts_per_page);
else console.log('  ', st.status);

// 6. WooCommerce system status
line('6. דוח מצב ווקומרס');
const ss = await api('/wp-json/wc/v3/system_status');
if (ss.status === 200) {
  const e = ss.json.environment || {}, d = ss.json.database || {}, th = ss.json.theme || {};
  console.log(`  WP ${e.wp_version} · WC ${e.version} · PHP ${e.php_version} · ${e.server_info}`);
  console.log(`  memory: ${e.wp_memory_limit} · max_execution: ${e.max_execution_time}s`);
  console.log(`  theme: ${th.name} ${th.version}${th.is_child_theme ? ' (child)' : ''}`);
  console.log(`  db prefix: ${d.database_prefix} · size: ${JSON.stringify(d.database_size||{})}`);
  const pages = ss.json.pages || [];
  for (const p of pages) console.log(`  page ${p.page_name}: id=${p.page_id} set=${p.page_set} exists=${p.page_exists} visible=${p.page_visible}`);
  if (Array.isArray(th.outdated_templates_list) && th.outdated_templates_list.length)
    console.log('  outdated templates:', th.outdated_templates_list.length);
} else console.log('  ', ss.status);

console.log('\nסיום. הסקריפט לא שינה דבר.\n');
process.exit(0);
