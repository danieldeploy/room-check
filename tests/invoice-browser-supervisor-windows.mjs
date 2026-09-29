import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';
import { prepareControlledBrowser } from './browser-supervisor.mjs';
import { controlledBrowserEndpoint } from './controlled-browser.mjs';

async function connect(url) {
  const socket = new WebSocket(url);
  await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
  let serial = 0;
  return {
    close: () => socket.close(),
    send(method, params = {}) {
      const id = ++serial;
      return new Promise((resolve, reject) => {
        const timer = setTimeout(() => { socket.removeEventListener('message', listener); reject(new Error('cdp_timeout')); }, 5000);
        const listener = event => {
          const reply = JSON.parse(event.data);
          if (reply.id !== id) return;
          clearTimeout(timer); socket.removeEventListener('message', listener);
          if (reply.error) reject(new Error('cdp_failed')); else resolve(reply.result);
        };
        socket.addEventListener('message', listener); socket.send(JSON.stringify({id, method, params}));
      });
    },
  };
}

if (process.argv[2] === 'survival') {
  // A separate process proves the endpoint is live after the launcher exits.
  const cdp = await connect(await controlledBrowserEndpoint(process.argv[3], 'fresh_login'));
  assert.ok((await cdp.send('Target.getTargets')).targetInfos.length > 0);
  await cdp.send('Browser.close'); cdp.close();
  console.log('Persistent Chrome endpoint remains live after launcher exit.');
} else {
let raw = ''; for await (const chunk of process.stdin) raw += chunk;
const config = JSON.parse(raw.replace(/^\uFEFF/, ''));
const input = { portal: 'booking', accountId: 1, action: 'collect', browserProfile: 'fresh_login' };
const prepare = () => prepareControlledBrowser(input, config.privateDir, config.browserSupervisor);
const endpoint = () => controlledBrowserEndpoint(config.privateDir, 'fresh_login');

// No portal traffic or credentials: about:blank and a synthetic cookie only.
await prepare();
const first = await endpoint();
let cdp = await connect(first);
await cdp.send('Storage.setCookies', {cookies: [{name: 'supervisor-fixture', value: 'preserved', domain: 'example.test', path: '/', expires: Math.floor(Date.now()/1000)+3600}]});
await prepare();
assert.equal(await endpoint(), first, 'Open Chrome must be reused');
await cdp.send('Browser.close'); cdp.close();
// Chrome needs time to finish writing its cookie store and release its lock.
await delay(1500);
const endpointFile = path.join(config.privateDir, 'controlled-booking-login-chrome', 'DevToolsActivePort');
await fs.writeFile(endpointFile, '1\n/devtools/browser/stale-endpoint-fixture\n');
await prepare();
const second = await endpoint(); assert.notEqual(second, first);
cdp = await connect(second);
const cookies = await cdp.send('Storage.getCookies');
assert.equal(cookies.cookies.find(cookie => cookie.name === 'supervisor-fixture')?.value, 'preserved');
assert.ok((await cdp.send('Target.getTargets')).targetInfos.every(target => target.type !== 'page' || target.url === 'about:blank'));
await prepare(); assert.equal(await endpoint(), second);
// A disabled/missing browser is a task failure, not a dead agent. Recovery on
// the next task uses this same launcher and preserves the still-open Chrome.
const enabledFile = path.join(config.privateDir, 'controlled-booking-enabled');
await fs.rm(enabledFile);
await assert.rejects(prepare(), {message: 'browser_unavailable'});
await fs.writeFile(enabledFile, 'enabled');
await prepare(); assert.equal(await endpoint(), second);
cdp.close();
await fs.writeFile(path.join(config.privateDir, 'supervisor-passed'), 'passed');
console.log('Real launcher reopened closed Chrome, reused open Chrome and preserved its profile.');
}
