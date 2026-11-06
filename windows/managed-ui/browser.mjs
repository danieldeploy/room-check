import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import { decision } from './policy.mjs';
import { createRequire } from 'node:module';

// No timer is injected. Trusted input merely updates one timestamp inside owned pages.
function activityRecorder(key) {
  if (globalThis[key]) return;
  const data = { last: Date.now() };
  Object.defineProperty(globalThis, key, { value: data });
  for (const event of ['pointerdown', 'keydown', 'wheel', 'focus']) {
    addEventListener(event, e => { if (e.isTrusted) data.last = Date.now(); }, { capture: true, passive: true });
  }
}
export class BrowserAdapter {
  constructor(localAppData, start = null) { this.local = localAppData; this.start = start; this.connections = new Map(); }
  async connect(profile) {
    if (!['primary', 'fresh_login'].includes(profile)) throw Error('invalid_profile');
    if (this.connections.has(profile)) return this.connections.get(profile);
    const root = path.join(this.local, 'ManagementHub', 'Invoices');
    const name = profile === 'primary' ? 'controlled-booking-chrome' : 'controlled-booking-login-chrome';
    const file = path.join(root, 'data', name, 'DevToolsActivePort');
    const st = await fs.lstat(file);
    if (!st.isFile() || st.isSymbolicLink() || st.size > 256) throw Error('invalid_endpoint');
    const [port, suffix] = (await fs.readFile(file, 'utf8')).trim().split(/\r?\n/);
    if (!/^[1-9]\d{0,4}$/.test(port) || +port > 65535 || !/^\/devtools\/browser\/[\w-]{8,80}$/.test(suffix || '')) throw Error('invalid_endpoint');
    const require = createRequire(path.join(root, 'app', 'package.json'));
    const browser = await require('puppeteer').connect({ browserWSEndpoint: `ws://127.0.0.1:${port}${suffix}`, defaultViewport: null, protocolTimeout: 5000 });
    this.connections.set(profile, browser);
    return browser;
  }
  async describe(page, profile) {
    const client = await page.createCDPSession();
    let id;
    try { id = (await client.send('Target.getTargetInfo')).targetInfo.targetId; } finally { await client.detach(); }
    const key = '__managed_ui_' + crypto.randomBytes(12).toString('hex');
    await page.evaluateOnNewDocument(activityRecorder, key);
    await page.evaluate(activityRecorder, key);
    return { kind: 'chrome_tab', profile, target: id, instance: page.browser().wsEndpoint().split('/devtools/browser/')[1], activityKey: key };
  }
  async open(profile, register, urlText = 'about:blank') {
    const url = new URL(urlText);
    if (!['https:', 'http:'].includes(url.protocol) && url.href !== 'about:blank') throw Error('invalid_url');
    if (url.username || url.password) throw Error('invalid_url');
    let browser;
    try { browser = await this.connect(profile); }
    catch (e) {
      if (!this.start) throw e;
      await this.start(profile);
      for (let i = 0; i < 25 && !browser; i++) {
        await new Promise(resolve => setTimeout(resolve, 200));
        try { browser = await this.connect(profile); } catch { /* bounded startup wait */ }
      }
      if (!browser) throw Error('browser_unavailable');
    }
    const page = await browser.defaultBrowserContext().newPage();
    let item;
    try { item = await this.describe(page, profile); await register(item); }
    catch (e) { await page.close().catch(() => {}); throw e; }
    if (url.href !== 'about:blank') await page.goto(url.href, { waitUntil: 'domcontentloaded', timeout: 20000 }).catch(() => {});
    return item;
  }
  async target(item) {
    const browser = await this.connect(item.profile);
    if (browser.wsEndpoint().split('/devtools/browser/')[1] !== item.instance) return null;
    // Target IDs originate from CDP. Never identify a page using its title, URL or position.
    const target = browser.targets().find(t => t._targetId === item.target);
    return target ? await target.page() : null;
  }
  async inspect(item) {
    const page = await this.target(item);
    if (!page || page.isClosed()) return { exists: false };
    let safe = true, lastActivity = 0;
    const frames = page.frames();
    if (frames.length > 24) return { exists: true, identity: true, safe: false, busy: false };
    for (const frame of frames) {
      const result = await frame.evaluate(key => {
        const activity = globalThis[key];
        // Old/uninstrumented documents are not proof of idleness.
        if (!activity || !Number.isFinite(activity.last)) return { safe: false, last: 0 };
        const fields = 'textarea,[contenteditable=true],[role=textbox],input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=button]):not([type=submit])';
        const draft = [...document.querySelectorAll(fields)].some(el => el.getClientRects().length > 0 && String('value' in el ? el.value : el.textContent || '').trim().length > 0);
        const playing = [...document.querySelectorAll('audio,video')].some(el => !el.paused && !el.ended);
        const challenge = !!document.querySelector('iframe[src*="captcha"],iframe[src*="challenge"],[data-sitekey],input[autocomplete="one-time-code"],input[type=password]');
        return { safe: document.readyState !== 'loading' && !draft && !playing && !challenge, last: activity.last };
      }, item.activityKey);
      safe &&= result.safe === true;
      lastActivity = Math.max(lastActivity, result.last || 0);
    }
    return { exists: true, identity: true, safe, busy: false, lastActivity };
  }
  async close(item, completed = false) {
    const observation = await this.inspect(item);
    if (observation.exists === false) return true;
    if (decision(item, observation, Date.now(), completed) !== 'close') return false;
    const page = await this.target(item);
    if (!page) return true;
    // Do not force confirmations. A beforeunload dialog keeps the resource registered.
    await page.close({ runBeforeUnload: true });
    for (let i = 0; i < 10; i++) {
      if (page.isClosed()) return true;
      await new Promise(resolve => setTimeout(resolve, 150));
    }
    return false;
  }
  dispose() { for (const b of this.connections.values()) b.disconnect(); this.connections.clear(); }
}
