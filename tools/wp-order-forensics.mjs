#!/usr/bin/env node
/**
 * חקירה עמוקה של הזמנות שבוטלו — האם באמת לא שולמו.
 *
 * הרקע: `wp-rest-audit.mjs` מצא ש-0 מתוך 150 הזמנות שבוטלו נושאות
 * `transaction_id`. אבל היעדר מזהה עסקה **אינו מוכיח** שלא שולם:
 *
 *   (א) הלקוח נטש לפני התשלום              -> אין מזהה עסקה
 *   (ב) הלקוח שילם, אבל הקולבק לא הגיע לאתר -> **גם אין מזהה עסקה**
 *
 * שני המצבים נראים זהים בשדה אחד. הסקריפט הזה מחפש את מה שכן מבדיל ביניהם:
 * הערות ההזמנה ו-meta, שבהן שער הסליקה משאיר עקבות גם כשהוא נכשל.
 *
 * שימוש:
 *   WP_APP_PASS='xxxx ...' node tools/wp-order-forensics.mjs
 *
 * קורא בלבד.
 */
import http from 'node:http';
import https from 'node:https';
import tls from 'node:tls';

const HOST = 'nama-c.com';
const USER = process.env.WP_USER || 'audit';
const PASS = process.env.WP_APP_PASS;
const UPSTREAM = process.env.CCR_PROXY || '127.0.0.1:40391';
const PACE = Number(process.env.PACE_MS || 5000);
if (!PASS) { console.error('חסר WP_APP_PASS'); process.exit(1); }

const auth = 'Basic ' + Buffer.from(`${USER}:${PASS}`).toString('base64');
const sleep = ms => new Promise(r => setTimeout(r, ms));
function tunnel(cb) {
  const [h, p] = UPSTREAM.split(':');
  const r = http.request({ host: h, port: +p, method: 'CONNECT', path: `${HOST}:443`, headers: { Host: `${HOST}:443` }, agent: false });
  r.on('connect', (res, s) => res.statusCode === 200 ? cb(null, s) : cb(new Error('CONNECT ' + res.statusCode)));
  r.on('error', cb); r.end();
}
const agent = new https.Agent({ keepAlive: true, maxSockets: 2, createConnection(o, cb) {
  tunnel((e, raw) => { if (e) return cb(e);
    const t = tls.connect({ socket: raw, servername: HOST, rejectUnauthorized: false, ALPNProtocols: ['http/1.1'] });
    t.on('secureConnect', () => cb(null, t)); t.on('error', cb); }); } });
async function api(path) {
  await sleep(PACE);
  return new Promise(res => {
    const r = https.request({ host: HOST, port: 443, path, method: 'GET', agent,
      headers: { Authorization: auth, 'User-Agent': 'nama-audit/1.0', Accept: 'application/json' } }, s => {
      let b = ''; s.on('data', d => b += d);
      s.on('end', () => { try { res({ status: s.statusCode, json: JSON.parse(b) }); } catch { res({ status: s.statusCode, raw: b.slice(0,200) }); } });
    });
    r.on('error', e => res({ status: 0, error: e.message })); r.end();
  });
}
const line = t => console.log('\n' + '='.repeat(70) + '\n' + t + '\n' + '='.repeat(70));

line('1. הזמנות שבוטלו — מבט מלא על 12 האחרונות');
const o = await api('/wp-json/wc/v3/orders?status=cancelled&per_page=12&orderby=date&order=desc');
if (o.status !== 200 || !Array.isArray(o.json)) { console.log('שגיאה', o.status, JSON.stringify(o.json||o.raw).slice(0,300)); process.exit(1); }

for (const ord of o.json) {
  console.log(`\n── הזמנה #${ord.number} · ${ord.date_created} · ${ord.total} ${ord.currency} ──`);
  console.log(`   שער: ${ord.payment_method || '(אין)'} / ${ord.payment_method_title || ''}`);
  console.log(`   transaction_id: ${JSON.stringify(ord.transaction_id)}   date_paid: ${JSON.stringify(ord.date_paid)}`);
  console.log(`   נוצרה: ${ord.date_created}  ->  שונתה: ${ord.date_modified}`);
  const meta = (ord.meta_data || []).filter(m => !String(m.key).startsWith('_wc_') );
  const interesting = meta.filter(m => /tranzila|payment|paid|token|confirm|index|response|error/i.test(m.key));
  if (interesting.length) {
    console.log('   meta רלוונטי:');
    for (const m of interesting.slice(0, 12)) console.log(`      ${m.key} = ${JSON.stringify(m.value).slice(0, 120)}`);
  } else {
    console.log('   meta רלוונטי: (אין שום שדה שקשור לסליקה)');
  }
  // order notes — this is where a gateway leaves a trace even when it fails
  const notes = await api(`/wp-json/wc/v3/orders/${ord.id}/notes?per_page=20`);
  if (notes.status === 200 && Array.isArray(notes.json)) {
    console.log(`   הערות (${notes.json.length}):`);
    for (const n of notes.json) console.log(`      [${n.date_created}] ${String(n.note).replace(/\s+/g,' ').slice(0, 160)}`);
  } else console.log('   הערות: שגיאה', notes.status);
}

line('2. כמה זמן עבר מיצירה עד ביטול');
const gaps = o.json.map(x => (new Date(x.date_modified) - new Date(x.date_created)) / 60000)
                   .filter(n => Number.isFinite(n)).sort((a,b)=>a-b);
if (gaps.length) {
  const med = gaps[Math.floor(gaps.length/2)];
  console.log(`  חציון: ${med.toFixed(1)} דקות · מינימום ${gaps[0].toFixed(1)} · מקסימום ${gaps[gaps.length-1].toFixed(1)}`);
  console.log(`  (אם כולן סביב 60 דקות — זו משימת "שמירת מלאי" האוטומטית, כצפוי)`);
}

line('3. לשם השוואה — הזמנה שהושלמה');
const done = await api('/wp-json/wc/v3/orders?status=completed&per_page=2&orderby=date&order=desc');
if (done.status === 200 && Array.isArray(done.json)) {
  for (const d of done.json) {
    console.log(`\n── #${d.number} · ${d.date_created} · ${d.total} ──`);
    console.log(`   transaction_id: ${JSON.stringify(d.transaction_id)}  date_paid: ${JSON.stringify(d.date_paid)}`);
    const im = (d.meta_data||[]).filter(m => /tranzila|payment|paid|confirm|index/i.test(m.key));
    for (const m of im.slice(0,10)) console.log(`      ${m.key} = ${JSON.stringify(m.value).slice(0,120)}`);
    const n2 = await api(`/wp-json/wc/v3/orders/${d.id}/notes?per_page=20`);
    if (n2.status === 200 && Array.isArray(n2.json))
      for (const n of n2.json) console.log(`      [${n.date_created}] ${String(n.note).replace(/\s+/g,' ').slice(0,160)}`);
  }
}
console.log('\nסיום. לא שונה דבר.\n');
