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
