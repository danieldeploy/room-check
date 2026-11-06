import test from 'node:test';
import assert from 'node:assert/strict';
let api;
try { api = await import('../../windows/managed-ui/policy.mjs'); } catch {}
const now = 1800000000000;
const idle = { owned: true, kind: 'chrome_tab', lastUsedAt: now - 31 * 60000 };
const safe = { exists: true, identity: true, safe: true, busy: false, lastActivity: 0 };

test('policy API exists', () => assert.equal(typeof api?.decision, 'function'));
for (const [name, resource, observation, expected] of [
  ['old owned safe tab expires', idle, safe, 'close'],
  ['manual resources preserved', { ...idle, owned: false }, safe, 'preserve'],
  ['unknown origin preserved', { ...idle, owned: undefined }, safe, 'preserve'],
  ['young resource retained', { ...idle, lastUsedAt: now - 29 * 60000 }, safe, 'preserve'],
  ['no timestamp is not idle', { ...idle, lastUsedAt: undefined }, safe, 'preserve'],
  ['future timestamp preserved', { ...idle, lastUsedAt: now + 1000 }, safe, 'preserve'],
  ['busy lease preserved', { ...idle, busyUntil: now + 1000 }, safe, 'preserve'],
  ['active connection protected', { ...idle, protected: true }, safe, 'preserve'],
  ['changed identity removed without closing', idle, { ...safe, identity: false }, 'forget'],
  ['missing resource removed without closing', idle, { exists: false }, 'forget'],
  ['inspection unavailable preserved', idle, null, 'preserve'],
  ['unknown inspection preserved', idle, { exists: true }, 'preserve'],
  ['unsaved input preserved', idle, { ...safe, safe: false }, 'preserve'],
  ['background work preserved', idle, { ...safe, busy: true }, 'preserve'],
  ['recent manual use preserved', idle, { ...safe, lastActivity: now - 100 }, 'preserve'],
]) test(name, () => assert.equal(api?.decision(resource, observation, now), expected));

test('completion closes a safe resource without waiting for idle timeout', () => {
  assert.equal(api?.decision({ ...idle, lastUsedAt: now }, safe, now, true), 'close');
  assert.equal(api?.decision(idle, { ...safe, safe: false }, now, true), 'preserve');
  assert.equal(api?.decision({ ...idle, protected: true }, safe, now, true), 'preserve');
});
test('pending resources activate scheduling; empty or protected-only sets do not', () => {
  assert.equal(api?.needsSchedule([]), false);
  assert.equal(api?.needsSchedule([idle]), true);
  assert.equal(api?.needsSchedule([{ ...idle, protected: true }]), false);
});
test('sweep preserves every unconfirmed close and continues after one failure', async () => {
  const rows = ['ok', 'unconfirmed', 'failure', 'manual'].map(id => ({ ...idle, id, owned: id !== 'manual' }));
  const attempts = [];
  const result = await api?.sweep(rows, {
    inspect: async () => safe,
    close: async item => { attempts.push(item.id); if (item.id === 'failure') throw Error('private'); return item.id === 'ok'; },
  }, now);
  assert.deepEqual(result?.remaining.map(r => r.id), ['unconfirmed', 'failure', 'manual']);
  assert.deepEqual(attempts, ['ok', 'unconfirmed', 'failure']);
  assert.equal(result?.closed, 1);
  assert.equal(JSON.stringify(result).includes('private'), false);
});
test('nonexistent resources stop scheduling without a close call', async () => {
  let called = false;
  const result = await api?.sweep([idle], { inspect: async () => ({ exists: false }), close: async () => { called = true; } }, now);
  assert.equal(called, false);
  assert.deepEqual(result?.remaining, []);
  assert.equal(api?.needsSchedule(result?.remaining), false);
});

import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { Registry } from '../../windows/managed-ui/store.mjs';
test('concurrent updates serialize and do not lose resource registrations', async t => {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'owned-ui-'));
  t.after(() => fs.rm(root, { recursive: true, force: true }));
  const schedules = [];
  const registry = new Registry(root, async flag => schedules.push(flag));
  await Promise.all(Array.from({ length: 8 }, (_, id) => registry.locked(async state => {
    await new Promise(resolve => setTimeout(resolve, 8));
    state.resources.push({ ...idle, id });
  })));
  const state = JSON.parse(await fs.readFile(path.join(root, 'registry.json'), 'utf8'));
  assert.equal(new Set(state.resources.map(r => r.id)).size, 8);
  assert.ok(schedules.every(flag => flag === true));
  await registry.locked(async state => { state.resources = []; });
  assert.equal(schedules.at(-1), false);
});
test('invalid registry never triggers deletion or silently resets state', async t => {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'owned-ui-'));
  t.after(() => fs.rm(root, { recursive: true, force: true }));
  const file = path.join(root, 'registry.json');
  await fs.writeFile(file, '{invalid');
  let ran = false;
  await assert.rejects(new Registry(root).locked(async () => { ran = true; }));
  assert.equal(ran, false);
  assert.equal(await fs.readFile(file, 'utf8'), '{invalid');
});

import { Manager } from '../../windows/managed-ui/managed-ui.mjs';
test('lifecycle starts scheduler on first creation, closes abandoned resource, then stops', async t => {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'ui-manager-'));
  t.after(() => fs.rm(root, { recursive: true, force: true }));
  let clock = now; const enabled = []; let closed = 0;
  const manager = new Manager({ root, clock: () => clock,
    native: async (_action, data) => { enabled.push(data.enabled); return { ok: true }; },
    browser: { inspect: async () => safe, close: async () => { closed++; return true; }, dispose() {} } });
  const task = await manager.begin();
  assert.equal(enabled.at(-1), false);
  await manager.register(task, { kind: 'chrome_tab' });
  assert.equal(enabled.at(-1), true);
  await manager.clean(); assert.equal(closed, 0);
  clock += 31 * 60000;
  const result = await manager.clean();
  assert.equal(result.closed, 1);
  assert.equal(enabled.at(-1), false);
  const next = await manager.begin(); await manager.register(next, { kind: 'chrome_tab' });
  assert.equal(enabled.at(-1), true);
});
test('activity renewal, live worker and explicit hold all prevent abandoned cleanup', async t => {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'ui-manager-'));
  t.after(() => fs.rm(root, { recursive: true, force: true }));
  let clock = now; let alive = true; let closed = 0;
  const manager = new Manager({ root, clock: () => clock,
    native: async action => action === 'process' ? { alive } : { ok: true },
    browser: { inspect: async () => safe, close: async () => { closed++; return true; }, dispose() {} } });
  const task = await manager.begin({ pid: 12, started: 'test' });
  await manager.register(task, { kind: 'chrome_tab' });
  clock += 40 * 60000; await manager.clean(); assert.equal(closed, 0);
  alive = false; await manager.touch(task, true); clock += 60000;
  await manager.clean(); assert.equal(closed, 0);
  await manager.protect(task); clock += 90 * 60000;
  await manager.clean(); assert.equal(closed, 0);
});
test('schedule failure retains registry and reports unsuccessful operation', async t => {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'ui-manager-'));
  t.after(() => fs.rm(root, { recursive: true, force: true }));
  const registry = new Registry(root, async () => { throw Error('scheduler_failed'); });
  await assert.rejects(registry.locked(async state => { state.resources.push(idle); }), /scheduler_failed/);
  assert.equal(JSON.parse(await fs.readFile(path.join(root, 'registry.json'), 'utf8')).resources.length, 1);
});
