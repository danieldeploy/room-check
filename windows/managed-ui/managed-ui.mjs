// Unified, nonresident controller. Only positively owned resources are eligible.
import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { execFile } from 'node:child_process';
import { Registry } from './store.mjs';
import { sweep, IDLE_MS } from './policy.mjs';
import { BrowserAdapter } from './browser.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const uuid = /^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/i;
export function platform(action, data = {}) {
  return new Promise((resolve, reject) => {
    const child = execFile('powershell.exe', ['-NoProfile', '-NonInteractive', '-WindowStyle', 'Hidden', '-ExecutionPolicy', 'RemoteSigned', '-File', path.join(here, 'platform.ps1'), '-Action', action],
      { windowsHide: true, timeout: 18000, maxBuffer: 65536 }, (error, stdout) => {
        if (error) { reject(Error('platform_unavailable')); return; }
        try { const response = JSON.parse(stdout.trim().replace(/^\uFEFF/, '')); if (!response.ok) throw Error(); resolve(response); }
        catch { reject(Error('platform_unavailable')); }
      });
    child.stdin.on('error', () => {});
    child.stdin.end(JSON.stringify(data));
  });
}
export class Manager {
  constructor({ root = here, native = platform, browser, clock = () => Date.now() } = {}) {
    this.native = native; this.clock = clock;
    this.browser = browser || new BrowserAdapter(process.env.LOCALAPPDATA, profile => native('start-chrome', { profile }),
      async profile => (await native('browser-present', { profile })).present);
    this.registry = new Registry(root, enabled => native('schedule', { enabled }));
  }
  task(state, id) {
    if (!uuid.test(id || '') || !state.tasks.some(t => t.id === id)) throw Error('unknown_task');
    return state.tasks.find(t => t.id === id);
  }
  async begin(owner = null) {
    const id = crypto.randomUUID();
    await this.registry.locked(async state => {
      state.tasks = state.tasks.filter(t => state.resources.some(r => r.task === t.id) || this.clock() - t.created < 86400000);
      state.tasks.push({ id, created: this.clock(), owner });
    });
    return id;
  }
  async register(task, resource) {
    const id = crypto.randomUUID();
    await this.registry.locked(async state => {
      this.task(state, task);
      state.resources.push({ ...resource, id, task, owned: true, createdAt: this.clock(), lastUsedAt: this.clock(), busyUntil: 0 });
    });
    return id;
  }
  async touch(task, busy = false) {
    await this.registry.locked(async state => {
      this.task(state, task);
      for (const item of state.resources.filter(r => r.task === task)) {
        item.lastUsedAt = this.clock(); item.busyUntil = busy ? this.clock() + 120000 : 0;
      }
    });
  }
  async protect(task) {
    await this.registry.locked(async state => {
      this.task(state, task);
      for (const item of state.resources.filter(r => r.task === task)) item.protected = true;
    });
  }
  async status(task) {
    return this.registry.locked(async state => {
      if (task) this.task(state, task);
      const resources = state.resources.filter(r => !task || r.task === task);
      return { tracked: resources.length, protected: resources.filter(r => r.protected).length, pending: resources.filter(r => !r.protected).length };
    });
  }
  async clean(task = null, completed = false) {
    return this.registry.locked(async state => {
      if (task) this.task(state, task);
      const selected = state.resources.filter(r => !task || r.task === task);
      if (selected.length > 40) throw Error('too_many_resources');
      const owners = new Map();
      const adapter = {
        inspect: async item => {
          const owner = state.tasks.find(t => t.id === item.task)?.owner;
          if (!completed && owner) {
            if (!owners.has(item.task)) owners.set(item.task, (await this.native('process', owner)).alive);
            if (owners.get(item.task)) return { exists: true, identity: true, safe: true, busy: true, lastActivity: this.clock() };
          }
          if (item.protected) return { exists: true, identity: true, safe: false, busy: false };
          return item.kind === 'chrome_tab' ? this.browser.inspect(item) : this.native('inspect', { resource: item, completed });
        },
        close: async item => item.kind === 'chrome_tab' ? this.browser.close(item, completed) : (await this.native('close', { resource: item, completed })).closed === true,
      };
      const result = await sweep(selected, adapter, this.clock(), completed);
      state.resources = [...state.resources.filter(r => task && r.task !== task), ...result.remaining];
      if (task && !state.resources.some(r => r.task === task)) state.tasks = state.tasks.filter(t => t.id !== task);
      state.lastCheck = { at: this.clock(), closed: result.closed, forgotten: result.forgotten, remaining: state.resources.length };
      return { ...state.lastCheck, idleMinutes: IDLE_MS / 60000 };
    });
  }
  dispose() { this.browser.dispose(); }
}
async function main() {
  if (process.platform !== 'win32') throw Error('windows_required');
  const [action, task, ...args] = process.argv.slice(2);
  if (action === 'install') return platform('install');
  if (action === 'scheduler') return platform('status');
  const manager = new Manager();
  try {
    if (action === 'begin') return { taskId: await manager.begin() };
    if (action === 'status') return await manager.status(task);
    if (action === 'touch') { await manager.touch(task, args[0] === 'busy'); return { updated: true }; }
    if (action === 'protect') { await manager.protect(task); return { protected: true }; }
    if (action === 'finish') return await manager.clean(task, true);
    if (action === 'sweep') return await manager.clean();
    // Register before navigation. Open commands must belong to a known task.
    await manager.status(task);
    if (action === 'open-tab') {
      const page = await manager.browser.open('fresh_login', item => manager.register(task, item), args[0]);
      return { registered: true, target: page.target };
    }
    if (action === 'open-explorer') {
      const result = await platform(action, { folder: args[0] });
      const id = await manager.register(task, result.resource);
      return { registered: true, resourceId: id };
    }
    throw Error('invalid_action');
  } finally { manager.dispose(); }
}
if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) {
  main().then(result => console.log(JSON.stringify({ ok: true, ...result })))
    .catch(() => { console.log(JSON.stringify({ ok: false, error: 'managed_ui_unavailable' })); process.exitCode = 1; });
}
