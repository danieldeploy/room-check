import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import { setTimeout as delay } from 'node:timers/promises';
import { needsSchedule } from './policy.mjs';

export class Registry {
  constructor(root, schedule = async () => {}) { this.root = root; this.schedule = schedule; }
  async locked(work) {
    await fs.mkdir(this.root, { recursive: true, mode: 0o700 });
    if ((await fs.lstat(this.root)).isSymbolicLink()) throw Error('unsafe_registry');
    const lock = path.join(this.root, 'registry.lock');
    let handle;
    const token = crypto.randomUUID();
    for (let i = 0; i < 40; i++) {
      try {
        handle = await fs.open(lock, 'wx', 0o600);
        await handle.writeFile(JSON.stringify({ pid: process.pid, token }));
        break;
      } catch (e) {
        if (e.code !== 'EEXIST') throw e;
        // Recovery only after proven owner death, serialized with another exclusive lock.
        try {
          const recovery = await fs.open(lock + '.recover', 'wx', 0o600);
          try {
            const old = JSON.parse(await fs.readFile(lock, 'utf8'));
            if (!Number.isSafeInteger(old.pid) || old.pid <= 0) throw Error('invalid_lock');
            let dead = false;
            try { process.kill(old.pid, 0); } catch (err) { dead = err.code === 'ESRCH'; }
            if (dead && JSON.parse(await fs.readFile(lock, 'utf8')).token === old.token) await fs.unlink(lock);
          } finally { await recovery.close(); await fs.unlink(lock + '.recover').catch(() => {}); }
        } catch { /* Live, incomplete or unverified lock: wait; never guess. */ }
        await delay(100);
      }
    }
    if (!handle) throw Error('registry_busy');
    try {
      const file = path.join(this.root, 'registry.json');
      let state;
      try {
        const st = await fs.lstat(file);
        if (!st.isFile() || st.isSymbolicLink() || st.size > 1024 * 1024) throw Error('invalid_registry');
        state = JSON.parse(await fs.readFile(file, 'utf8'));
      } catch (e) { if (e.code !== 'ENOENT') throw e; state = { version: 2, tasks: [], resources: [] }; }
      if (state.version !== 2 || !Array.isArray(state.resources) || !Array.isArray(state.tasks)) throw Error('invalid_registry');
      const result = await work(state);
      if (state.resources.length > 200 || state.tasks.length > 1000) throw Error('registry_limit');
      const tmp = file + '.' + token + '.tmp';
      await fs.writeFile(tmp, JSON.stringify(state), { mode: 0o600, flag: 'wx' });
      await fs.rename(tmp, file);
      await this.schedule(needsSchedule(state.resources));
      return result;
    } finally { await handle.close(); await fs.unlink(lock).catch(() => {}); }
  }
}
