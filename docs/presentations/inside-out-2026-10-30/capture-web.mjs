// Screenshot a public page with the PENTANA `locale=en` cookie set, so the language chooser stays closed.
// Usage: node capture-web.mjs <url> <out.png> [width=1440] [height=900]
import { spawn } from 'node:child_process';
import { writeFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const [url, out, width = '1440', height = '900'] = process.argv.slice(2);
const port = 9333;
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', [
  '--headless=new', '--disable-gpu', '--hide-scrollbars', `--remote-debugging-port=${port}`,
  `--user-data-dir=${mkdtempSync(join(tmpdir(), 'cw-'))}`, `--window-size=${width},${height}`, 'about:blank',
], { stdio: 'ignore' });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let target;
for (let i = 0; i < 50 && !target; i++) {
  await sleep(200);
  try { target = (await (await fetch(`http://127.0.0.1:${port}/json`)).json()).find((t) => t.type === 'page'); } catch {}
}
const ws = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((r) => ws.addEventListener('open', r));
let id = 0;
const send = (method, params = {}) => new Promise((resolve) => {
  const msgId = ++id;
  const onMsg = (e) => { const m = JSON.parse(e.data); if (m.id === msgId) { ws.removeEventListener('message', onMsg); resolve(m.result); } };
  ws.addEventListener('message', onMsg);
  ws.send(JSON.stringify({ id: msgId, method, params }));
});

const host = new URL(url).hostname;
await send('Network.enable');
await send('Network.setCookie', { name: 'locale', value: 'en', domain: host, path: '/' });
await send('Emulation.setDeviceMetricsOverride', { width: +width, height: +height, deviceScaleFactor: 2, mobile: false });
await send('Page.enable');
await send('Page.navigate', { url });
await sleep(4000);
const { data } = await send('Page.captureScreenshot', { format: 'png' });
writeFileSync(out, Buffer.from(data, 'base64'));
ws.close();
chrome.kill();
console.log(`saved ${out}`);
