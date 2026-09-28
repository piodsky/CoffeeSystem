// Shared helpers for the end-to-end tests: headless Edge driven through the DevTools protocol.
import { spawn } from 'node:child_process';
import { writeFileSync, mkdirSync } from 'node:fs';

export const BASE = 'http://localhost/CoffeeSystem';
const EDGE = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

export async function openBrowser(outDir, port = 9333) {
  mkdirSync(`${outDir}/edge-profile`, { recursive: true });
  const edge = spawn(EDGE, [
    '--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${outDir}/edge-profile`,
    '--no-first-run', '--disable-gpu', '--hide-scrollbars', 'about:blank',
  ], { stdio: 'ignore' });

  let target;
  for (let i = 0; i < 50 && !target; i++) {
    try {
      const list = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
      target = list.find((t) => t.type === 'page');
    } catch { await sleep(200); }
  }
  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((r) => ws.addEventListener('open', r));

  let seq = 0;
  const pending = new Map();
  const problems = [];
  ws.addEventListener('message', (ev) => {
    const msg = JSON.parse(ev.data);
    if (msg.id && pending.has(msg.id)) { pending.get(msg.id)(msg); pending.delete(msg.id); return; }
    if (msg.method === 'Runtime.exceptionThrown') problems.push('JS exception: ' + msg.params.exceptionDetails.exception?.description);
    if (msg.method === 'Log.entryAdded' && ['error', 'warning'].includes(msg.params.entry.level)) problems.push(`log ${msg.params.entry.level}: ${msg.params.entry.text}`);
    if (msg.method === 'Runtime.consoleAPICalled' && msg.params.type === 'error') problems.push('console.error: ' + JSON.stringify(msg.params.args.map((a) => a.value)));
  });

  const send = (method, params = {}) => new Promise((resolve, reject) => {
    const id = ++seq;
    pending.set(id, (m) => (m.error ? reject(new Error(method + ': ' + m.error.message)) : resolve(m.result)));
    ws.send(JSON.stringify({ id, method, params }));
  });
  const evaluate = async (expr) => {
    const r = await send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true });
    if (r.exceptionDetails) throw new Error('eval failed: ' + expr + ' → ' + r.exceptionDetails.exception?.description);
    return r.result.value;
  };
  const waitFor = async (expr, label, timeout = 8000) => {
    const end = Date.now() + timeout;
    while (Date.now() < end) { if (await evaluate(expr).catch(() => false)) return; await sleep(100); }
    throw new Error('Timed out waiting for: ' + label);
  };

  const b = {
    send, evaluate, waitFor, problems,
    shot: async (name) => {
      const { data } = await send('Page.captureScreenshot', { format: 'png' });
      writeFileSync(`${outDir}/${name}.png`, Buffer.from(data, 'base64'));
    },
    key: async (k, code, vk) => {
      await send('Input.dispatchKeyEvent', k === 'Enter'
        ? { type: 'keyDown', key: k, code, windowsVirtualKeyCode: vk, text: String.fromCharCode(13) }
        : { type: 'rawKeyDown', key: k, code, windowsVirtualKeyCode: vk });
      await send('Input.dispatchKeyEvent', { type: 'keyUp', key: k, code, windowsVirtualKeyCode: vk });
    },
    type: (text) => send('Input.insertText', { text }),
    size: (w, h) => send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: false }),
    nav: async (url) => {
      await send('Page.navigate', { url });
      await sleep(300);
      await waitFor('document.readyState === "complete"', 'load ' + url);
    },
    text: (sel) => evaluate(`document.querySelector(${JSON.stringify(sel)})?.textContent.trim()`),
    check: (cond, label) => { console.log(`${cond ? 'PASS' : 'FAIL'}  ${label}`); if (!cond) process.exitCode = 1; },
    /** Sign in through the real login form. */
    login: async (username, password) => {
      await b.nav(`${BASE}/login.php`);
      await evaluate(`localStorage.clear()`);
      await evaluate(`document.querySelector('[name=username]').value=${JSON.stringify(username)}; document.querySelector('[name=password]').value=${JSON.stringify(password)}; document.querySelector('.login__form').submit()`);
      await sleep(500);
      await waitFor(`location.pathname.endsWith('/pages/pos.php') && document.readyState==='complete'`, 'redirect to POS');
    },
    reportProblems: () => {
      console.log(problems.length ? 'BROWSER PROBLEMS:\n  ' + problems.join('\n  ') : 'PASS  no JS errors / CSP violations');
      if (problems.length) process.exitCode = 1;
    },
    close: () => { ws.close(); edge.kill(); },
  };

  await send('Page.enable'); await send('Runtime.enable'); await send('Log.enable');
  await send('DOM.enable');
  return b;
}
