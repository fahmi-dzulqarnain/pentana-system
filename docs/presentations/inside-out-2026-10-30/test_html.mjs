// Behaviour test for the browser deck in real Chrome (file://, like on the day).
// Run: node test_html.mjs   -> prints "html tests OK".
// Covers: no JS errors, main path skips backups, B/Esc into and out of backups, the presenter window
// opened with P follows the audience, and keys pressed in the presenter drive the audience.
import { spawn } from 'node:child_process';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const url = `file://${here}/html/index.html#title`;
const port = 9334;
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', [
  '--headless=new', '--disable-gpu', '--disable-popup-blocking', '--allow-file-access-from-files',
  `--remote-debugging-port=${port}`, `--user-data-dir=${mkdtempSync(join(tmpdir(), 'th-'))}`, '--window-size=1600,900', url,
], { stdio: 'ignore' });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const fail = (m) => { chrome.kill(); console.error(`FAIL: ${m}`); process.exit(1); };

async function targets() { return (await (await fetch(`http://127.0.0.1:${port}/json`)).json()).filter((t) => t.type === 'page'); }
async function connect(t) {
  const ws = new WebSocket(t.webSocketDebuggerUrl);
  await new Promise((r) => ws.addEventListener('open', r));
  let id = 0; const errors = [];
  ws.addEventListener('message', (e) => {
    const m = JSON.parse(e.data);
    if (m.method === 'Runtime.exceptionThrown') errors.push(m.params.exceptionDetails.exception?.description || 'exception');
  });
  const send = (method, params = {}) => new Promise((resolve) => {
    const my = ++id;
    const on = (e) => { const m = JSON.parse(e.data); if (m.id === my) { ws.removeEventListener('message', on); resolve(m.result); } };
    ws.addEventListener('message', on); ws.send(JSON.stringify({ id: my, method, params }));
  });
  await send('Runtime.enable');
  const evaluate = async (expr) => (await send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true })).result.value;
  return { evaluate, errors, close: () => ws.close() };
}

let aud;
for (let i = 0; i < 50 && !aud; i++) { await sleep(200); try { aud = (await targets()).find((t) => t.url.startsWith('file:')); } catch {} }
if (!aud) fail('Chrome did not open the deck');
const A = await connect(aud);
await sleep(1200);
const key = (k) => A.evaluate(`dispatchEvent(new KeyboardEvent('keydown', {key: ${JSON.stringify(k)}})); new Promise(r => setTimeout(() => r(location.hash), 600))`);
const goto = async (n) => { for (const d of String(n)) await key(d); return key('Enter'); };

// Main path: from demo-bills (20), next skips its backups and lands on lunch-1.
if ((await goto(20)) !== '#demo-bills') fail('jump to 20 should land on demo-bills');
if ((await key('ArrowRight')) !== '#lunch-1') fail('next from demo-bills should skip backups to lunch-1');
if ((await key('ArrowLeft')) !== '#demo-bills') fail('back from lunch-1 should skip backups to demo-bills');
if ((await key('b')) !== '#backup-bills-1') fail('B on demo-bills should open backup-bills-1');
if ((await key('ArrowRight')) !== '#backup-bills-2') fail('next inside backups should step through them');
if ((await key('Escape')) !== '#demo-bills') fail('Esc in a backup should return to demo-bills');

// Presenter: P opens it; it follows the audience and drives it.
await key('p');
await sleep(1500);
const pt = (await targets()).find((t) => t.url.includes('presenter'));
if (!pt) fail('P did not open the presenter window');
const P = await connect(pt);
await sleep(800);
const shown = () => P.evaluate(`document.getElementById('p-id').textContent`);
if (!(await shown()).includes('demo-bills')) fail(`presenter should show demo-bills, shows ${await shown()}`);
await key('ArrowRight'); await sleep(500);
if (!(await shown()).includes('lunch-1')) fail(`presenter should follow to lunch-1, shows ${await shown()}`);
await P.evaluate(`dispatchEvent(new KeyboardEvent('keydown', {key: 'ArrowRight'})); new Promise(r => setTimeout(r, 800))`);
const audNow = await A.evaluate('location.hash');
if (audNow !== '#lunch-2') fail(`a key in the presenter should advance the audience to lunch-2, got ${audNow}`);
if (!(await shown()).includes('lunch-2')) fail('presenter should then show lunch-2');

// No layer build-up, no JS errors in either window.
const layers = await A.evaluate(`new Promise(r => setTimeout(() => r(document.querySelectorAll('#stage .slide').length), 700))`);
if (layers !== 1) fail(`expected 1 slide layer at rest, found ${layers}`);
const errs = [...A.errors, ...P.errors];
if (errs.length) fail(`JS errors: ${errs.join(' | ')}`);

A.close(); P.close(); chrome.kill();
console.log('html tests OK');
