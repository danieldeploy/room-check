import assert from 'node:assert/strict';
import { test } from 'node:test';
import os from 'node:os';
import path from 'node:path';
import fs from 'node:fs/promises';
import { writeTaskReceipt } from '../task-receipt.mjs';

test('accepted completion stores only a private atomic receipt', async () => {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'invoice-receipt-'));
  const secret = 'sensitive-' + Math.random();
  try {
    const job = { id: 42, input: { action: 'login', credentials: { password: secret } } };
    const result = { code: 'portal_changed', diagnostic: { location: secret }, session: { cookies: [secret] } };
    const reply = { accepted: true, state: 'failed', value: secret };
    await writeTaskReceipt(root, job, result, reply, () => new Date('2026-09-24T08:15:00.000Z'));
    const filename = path.join(root, 'task-42-receipt.json');
    const raw = await fs.readFile(filename, 'utf8');
    assert.deepEqual(JSON.parse(raw), { task_id: 42, action: 'login', code: 'portal_changed',
      state: 'failed', finished_at: '2026-09-24T08:15:00.000Z' });
    assert.equal(raw.includes(secret), false);
    assert.deepEqual(await fs.readdir(root), ['task-42-receipt.json']);
    if (process.platform !== 'win32') assert.equal((await fs.stat(filename)).mode & 0o077, 0);
    await assert.rejects(writeTaskReceipt(root, { ...job, id: 43 }, result, { accepted: false, state: 'failed' }));
    await assert.rejects(fs.access(path.join(root, 'task-43-receipt.json')));
    await assert.rejects(writeTaskReceipt(root, { ...job, id: 44 }, { code: secret }, reply));
    await assert.rejects(fs.access(path.join(root, 'task-44-receipt.json')));
    await writeTaskReceipt(root, { ...job, id: 45 }, { code: 'session_active' },
      { accepted: true, state: 'completed' }, () => new Date('2026-09-24T08:16:00.000Z'));
    assert.equal(JSON.parse(await fs.readFile(path.join(root, 'task-45-receipt.json'), 'utf8')).code,
      'session_active');
    await writeTaskReceipt(root, { ...job, id: 46 }, { code: 'human_verification' },
      { accepted: true, state: 'needs_auth' }, () => new Date('2026-09-24T08:17:00.000Z'));
    assert.equal(JSON.parse(await fs.readFile(path.join(root, 'task-46-receipt.json'), 'utf8')).state,
      'needs_auth');
    await assert.rejects(writeTaskReceipt(root, { ...job, id: 47, input: { action: 'collect' } }, result, reply));
    await assert.rejects(fs.access(path.join(root, 'task-47-receipt.json')));
  } finally { await fs.rm(root, { recursive: true, force: true }); }
});
