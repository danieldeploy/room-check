import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';
import { prepareControlledBrowser } from '../browser-supervisor.mjs';

const input = { portal: 'booking', accountId: 1, action: 'collect', browserProfile: 'fresh_login' };
const channel = `browser-supervisor-${'a'.repeat(32)}`;
async function fixture(t) {
  const root = await fs.realpath(await fs.mkdtemp(path.join(os.tmpdir(), 'browser-supervisor-')));
  const directory = path.join(root, channel); await fs.mkdir(directory);
  t.after(() => fs.rm(root, { recursive: true, force: true }));
  return { root, directory };
}
async function respond(directory, code = 'ready', wrongId = false) {
  const until = performance.now() + 5000;
  while (performance.now() < until) {
    const id = await fs.readFile(path.join(directory, 'request'), 'utf8').catch(() => null);
    if (id) {
      assert.match(id, /^[a-f0-9]{32}$/);
      assert.equal((await fs.readdir(directory)).some(name => name.endsWith('.tmp')), false);
      await fs.writeFile(path.join(directory, 'reply.tmp'), JSON.stringify({id: wrongId ? '0'.repeat(32) : id, code}));
      await fs.rename(path.join(directory, 'reply.tmp'), path.join(directory, 'response'));
      return id;
    }
    await delay(5);
  }
  throw new Error('fixture_timeout');
}

test('each controlled task requests preparation with a fresh nonce and cleans its exchange', async t => {
  const { root, directory } = await fixture(t); const ids = [];
  for (const action of ['collect', 'verify', 'discover', 'login', 'collect']) {
    const [, id] = await Promise.all([
      prepareControlledBrowser({ ...input, action }, root, channel, {pollMs: 5}), respond(directory),
    ]);
    ids.push(id); assert.deepEqual(await fs.readdir(directory), []);
  }
  assert.equal(new Set(ids).size, ids.length);
});

for (const [label, code, wrongId] of [['launch failure', 'browser_unavailable', false], ['stale reply', 'ready', true], ['unknown reply', 'secret raw error', false]]) {
  test(`${label} produces only browser_unavailable`, async t => {
    const { root, directory } = await fixture(t);
    await Promise.all([
      assert.rejects(prepareControlledBrowser(input, root, channel, {pollMs: 5}), {message: 'browser_unavailable'}),
      respond(directory, code, wrongId),
    ]);
    assert.deepEqual(await fs.readdir(directory), []);
  });
}

test('missing launcher times out and a subsequent task can recover', async t => {
  const { root, directory } = await fixture(t);
  await assert.rejects(prepareControlledBrowser(input, root, channel, {timeoutMs: 20, pollMs: 5}), {message: 'browser_unavailable'});
  assert.deepEqual(await fs.readdir(directory), []);
  await Promise.all([prepareControlledBrowser(input, root, channel, {pollMs: 5}), respond(directory)]);
});

test('does not touch unrelated accounts, portals, actions or legacy direct runs', async () => {
  for (const value of [{...input, accountId: 2}, {...input, portal: 'airbnb'}, {...input, action: 'preflight'}, {...input, browserProfile: undefined}]) {
    await prepareControlledBrowser(value, 'unused', '../invalid');
  }
  await prepareControlledBrowser(input, 'unused', undefined);
});

test('rejects foreign channel paths and leaves unfinished exchanges untouched', async t => {
  const { root, directory } = await fixture(t);
  for (const value of ['../outside', '/tmp/other', '', null]) {
    await assert.rejects(prepareControlledBrowser(input, root, value), {message: 'browser_unavailable'});
  }
  await fs.writeFile(path.join(directory, 'request'), 'existing-request');
  await assert.rejects(prepareControlledBrowser(input, root, channel), {message: 'browser_unavailable'});
  assert.equal(await fs.readFile(path.join(directory, 'request'), 'utf8'), 'existing-request');
});

test('does not read through a linked response', async t => {
  if (process.platform === 'win32') return t.skip('Creating file symlinks requires Windows developer privileges');
  const { root, directory } = await fixture(t);
  const outside = path.join(root, 'unrelated'); await fs.writeFile(outside, 'private');
  const promise = prepareControlledBrowser(input, root, channel, {pollMs: 5});
  while (!await fs.stat(path.join(directory, 'request')).catch(() => null)) await delay(5);
  await fs.symlink(outside, path.join(directory, 'response'));
  await assert.rejects(promise, {message: 'browser_unavailable'});
  assert.equal(await fs.readFile(outside, 'utf8'), 'private');
});
