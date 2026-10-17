import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import { setTimeout as delay } from 'node:timers/promises';
import { controlledBrowserProfile, usesControlledBrowser } from './controlled-browser.mjs';

// The PowerShell owner launches persistent Chrome outside the kill-on-close
// worker job. Only a nonce/status crosses this private, per-launch channel.
export async function prepareControlledBrowser(input, root, channel, { timeoutMs = 60000, pollMs = 200 } = {}) {
  if (!usesControlledBrowser(input) || controlledBrowserProfile(input) !== 'fresh_login') return;
  // Compatibility for direct diagnostic invocations and older launchers.
  if (channel === undefined) return;
  let request; let response;
  try {
    if (typeof channel !== 'string' || !/^browser-supervisor-[a-f0-9]{32}$/.test(channel)) throw new Error();
    const directory = path.join(root, channel);
    const info = await fs.lstat(directory);
    if (!info.isDirectory() || info.isSymbolicLink() || path.dirname(await fs.realpath(directory)) !== root) throw new Error();
    const id = crypto.randomBytes(16).toString('hex');
    // One sequential task per agent. Existing files indicate an unfinished exchange.
    for (const name of ['request', 'response']) {
      if (await fs.lstat(path.join(directory, name)).catch(error => { if (error.code === 'ENOENT') return null; throw error; })) throw new Error();
    }
    request = path.join(directory, 'request'); response = path.join(directory, 'response');
    const temporary = path.join(directory, `${id}.tmp`);
    await fs.writeFile(temporary, id, { mode: 0o600, flag: 'wx' });
    await fs.rename(temporary, request);
    const deadline = performance.now() + timeoutMs;
    while (performance.now() < deadline) {
      const state = await fs.lstat(response).catch(error => { if (error.code === 'ENOENT') return null; throw error; });
      if (state) {
        if (!state.isFile() || state.isSymbolicLink() || state.size > 256) throw new Error();
        const result = JSON.parse((await fs.readFile(response, 'utf8')).replace(/^\uFEFF/, ''));
        if (result.id !== id || result.code !== 'ready') throw new Error();
        return;
      }
      await delay(pollMs);
    }
    throw new Error();
  } catch { throw new Error('browser_unavailable'); }
  finally {
    if (request) await fs.rm(request, { force: true }).catch(() => {});
    if (response) await fs.rm(response, { force: true }).catch(() => {});
  }
}
