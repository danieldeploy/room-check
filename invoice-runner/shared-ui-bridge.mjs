import fs from 'node:fs/promises';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

async function loadInstalledController() {
  if (process.platform !== 'win32' || !process.env.LOCALAPPDATA) return null;
  const local = process.env.LOCALAPPDATA;
  const file = path.join(local, 'ChatGPTAutomation', 'ManagedUI', 'managed-ui.mjs');
  const version = JSON.parse(await fs.readFile(path.join(path.dirname(file), 'version.json'), 'utf8').catch(() => '{}'));
  if (version.version !== 2) return null;
  const st = await fs.lstat(file).catch(() => null);
  if (!st?.isFile() || st.isSymbolicLink()) return null;
  const module = await import(pathToFileURL(file).href);
  if (typeof module.Manager !== 'function') return null;
  const manager = new module.Manager();
  const task = await manager.begin();
  return {
    async track(page) {
      const endpoint = page.browser().wsEndpoint();
      let profile;
      for (const [purpose, folder] of [['primary','controlled-booking-chrome'],['fresh_login','controlled-booking-login-chrome']]) {
        const file = path.join(local,'ManagementHub','Invoices','data',folder,'DevToolsActivePort');
        const text = await fs.readFile(file,'utf8').catch(() => '');
        const suffix = text.trim().split(/\r?\n/)[1];
        if (suffix && /^\/devtools\/browser\/[\w-]{8,80}$/.test(suffix) && endpoint.endsWith(suffix)) profile = purpose;
      }
      if (!profile) throw Error('unverified_browser_profile');
      await manager.register(task, await manager.browser.describe(page, profile));
    },
    async finish({ settled, humanChallenge }) {
      try {
        if (humanChallenge) await manager.protect(task);
        else if (settled) await manager.clean(task, true);
      } finally { manager.dispose(); }
    },
  };
}

// Optional integration: never break invoice collection when the helper is absent.
export function createSharedUiBridge(load = loadInstalledController) {
  let controller; let unavailable = false;
  const pending = []; const tracked = new Set();
  function ready() { return controller ||= Promise.resolve().then(load).catch(() => null); }
  return {
    track(page) {
      if (tracked.has(page)) return;
      tracked.add(page);
      const registration = ready().then(async backend => {
        if (!backend) { unavailable = true; return; }
        await backend.track(page);
      }).catch(() => { unavailable = true; });
      pending.push(registration);
      return registration;
    },
    async finish(options) {
      if (!controller) return false;
      await Promise.all(pending);
      const backend = await controller;
      if (!backend) return false;
      try { await backend.finish(options); return !unavailable; }
      catch { return false; }
    },
  };
}
