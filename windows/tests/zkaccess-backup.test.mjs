import test from 'node:test';
import assert from 'node:assert/strict';
import { dueAt, lisbonParts } from '../zkaccess-backup.mjs';

test('four AM and minute precision in Europe/Lisbon', () => {
  assert.equal(dueAt('04:00', { minute: 239 }), false);
  assert.equal(dueAt('04:00', { minute: 240 }), true);
  assert.equal(dueAt('04:00', { minute: 241 }), true);
  assert.throws(() => dueAt('4:00'), /invalid_backup_time/);
  assert.throws(() => dueAt('25:00'), /invalid_backup_time/);
});

test('winter and summer Lisbon offset are respected', () => {
  assert.deepEqual(lisbonParts(new Date('2026-01-15T04:00:00Z')),
    { date: '2026-01-15', minute: 240 });
  assert.deepEqual(lisbonParts(new Date('2026-07-15T03:00:00Z')),
    { date: '2026-07-15', minute: 240 });
});

test('DST switches do not create a duplicate date', () => {
  const before = lisbonParts(new Date('2026-10-25T00:30:00Z'));
  const after = lisbonParts(new Date('2026-10-25T01:30:00Z'));
  assert.equal(before.date, after.date);
  assert.equal(before.minute, after.minute);
  assert.equal(lisbonParts(new Date('2026-10-25T04:00:00Z')).minute, 240);
});


test('uploaded local files are removed immediately only with matching remote identity and size', async () => {
  const { cleanup } = await import('../zkaccess-backup.mjs');
  const { mkdtemp, writeFile, access, rm } = await import('node:fs/promises');
  const { tmpdir } = await import('node:os');
  const path = await import('node:path');
  const folder = await mkdtemp(path.join(tmpdir(), 'zk-cleanup-'));
  const id = 'a'.repeat(32);
  const filename = `zkeco_db_${id}.sql.gz`;
  const local = [{ id, filename, date: '2099-01-01', size: 123, status: 'ready' }];
  try {
    await writeFile(path.join(folder, filename), 'fixture');
    await cleanup(local, { backups: [{id, size: 124, status: 'uploaded'}] }, folder);
    await access(path.join(folder, filename));
    await cleanup(local, { backups: [{id, size: 123, status: 'ready'}] }, folder);
    await access(path.join(folder, filename));
    await cleanup(local, { backups: [{id, size: 123, status: 'uploaded'}] }, folder);
    await assert.rejects(access(path.join(folder, filename)), { code: 'ENOENT' });
    assert.equal(local[0].local_removed, true);
    await cleanup(local, { backups: [{id, size: 123, status: 'uploaded'}] }, folder);
    assert.equal(local[0].status, 'uploaded');
  } finally { await rm(folder, { recursive: true, force: true }); }
});
