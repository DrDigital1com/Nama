// Local MITM proxy: Chromium -> here -> node CONNECT tunnel -> agent proxy -> internet.
// Exists because Chromium's TLS cannot traverse this session's proxy relay, but node's can.
import http from 'node:http';
import https from 'node:https';
import tls from 'node:tls';
import net from 'node:net';
import fs from 'node:fs';

const KEY = fs.readFileSync(new URL('./mitm.key', import.meta.url));
const CRT = fs.readFileSync(new URL('./mitm.crt', import.meta.url));
const UPSTREAM = { host: '127.0.0.1', port: 40391 };
const PORT = Number(process.env.MITM_PORT || 8888);

// Build a socket to `host:443` by CONNECTing through the agent proxy, then TLS.
function tunnelConnect(host, port, cb) {
  const req = http.request({
    host: UPSTREAM.host, port: UPSTREAM.port, method: 'CONNECT',
    path: `${host}:${port}`, headers: { Host: `${host}:${port}` },
    agent: false,
  });
  req.on('connect', (res, socket) => {
    if (res.statusCode !== 200) return cb(new Error(`CONNECT ${res.statusCode}`));
    cb(null, socket);
  });
  req.on('error', cb);
  req.end();
}

function makeAgent(host) {
  return new https.Agent({
    keepAlive: true,
    maxSockets: 8,
    createConnection(opts, cb) {
      tunnelConnect(host, 443, (err, raw) => {
        if (err) return cb(err);
        const t = tls.connect({ socket: raw, servername: host, rejectUnauthorized: false, ALPNProtocols: ['http/1.1'] });
        t.on('secureConnect', () => cb(null, t));
        t.on('error', cb);
      });
    },
  });
}
const agents = new Map();
const agentFor = h => { if (!agents.has(h)) agents.set(h, makeAgent(h)); return agents.get(h); };

let served = 0, failed = 0;

// Forward one browser request out to the real origin.
function forward(host, req, res) {
  const headers = { ...req.headers };
  delete headers['proxy-connection'];
  headers.host = host;
  headers['accept-encoding'] = 'gzip, deflate';   // skip zstd/br: keeps it simple and safe
  const out = https.request(
    { host, port: 443, method: req.method, path: req.url, headers, agent: agentFor(host) },
    upstream => {
      served++;
      const h = { ...upstream.headers };
      delete h['content-security-policy'];
      delete h['content-security-policy-report-only'];
      delete h['strict-transport-security'];
      res.writeHead(upstream.statusCode, h);
      upstream.pipe(res);
    }
  );
  out.on('error', e => { failed++; if (!res.headersSent) res.writeHead(502); res.end('mitm upstream error: ' + e.message); });
  req.pipe(out);
}

// Per-host TLS server that Chromium talks to after CONNECT.
const tlsServers = new Map();
function tlsServerFor(host) {
  if (tlsServers.has(host)) return tlsServers.get(host);
  const srv = https.createServer({ key: KEY, cert: CRT }, (req, res) => forward(host, req, res));
  const p = new Promise(resolve => srv.listen(0, '127.0.0.1', () => resolve(srv.address().port)));
  tlsServers.set(host, p);
  return p;
}

const proxy = http.createServer((req, res) => {
  // plain HTTP through the proxy
  try {
    const u = new URL(req.url);
    req.url = u.pathname + u.search;
    forward(u.hostname, req, res);
  } catch { res.writeHead(400); res.end('bad absolute-form request'); }
});

proxy.on('connect', async (req, clientSocket, head) => {
  const [host] = req.url.split(':');
  try {
    const port = await tlsServerFor(host);
    const local = net.connect(port, '127.0.0.1', () => {
      clientSocket.write('HTTP/1.1 200 Connection Established\r\n\r\n');
      if (head && head.length) local.write(head);
      local.pipe(clientSocket);
      clientSocket.pipe(local);
    });
    local.on('error', () => clientSocket.destroy());
    clientSocket.on('error', () => local.destroy());
  } catch { clientSocket.destroy(); }
});

proxy.listen(PORT, '127.0.0.1', () => console.log(`MITM proxy on 127.0.0.1:${PORT}`));
setInterval(() => console.log(`  [mitm] served=${served} failed=${failed}`), 15000).unref();
